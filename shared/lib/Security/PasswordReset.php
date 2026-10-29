<?php
declare(strict_types=1);
namespace YoloTask\Security;

use PDO;
use YoloTask\Messaging\Mailer;

/**
 * Single-use password links. "reset" links last 30 minutes; staff "invite"
 * links last 48 hours and also confirm the email address when used.
 */
final class PasswordReset
{
    /** Always behaves the same to the caller, whether or not the account exists. */
    public static function request(PDO $pdo, string $email, string $role, string $portalUrl): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return;
        if (Throttle::tooMany($pdo, 'reset_ip', Crypto::clientIp(), 10, 3600)) return;
        Throttle::hit($pdo, 'reset_ip', Crypto::clientIp());
        if (Throttle::tooMany($pdo, 'reset_email', $email, 3, 3600)) return;
        Throttle::hit($pdo, 'reset_email', $email);

        $stmt = $pdo->prepare('SELECT id, display_name, email FROM users WHERE email = :e AND role = :r AND status = \'active\' LIMIT 1');
        $stmt->execute(['e' => $email, 'r' => $role]);
        $user = $stmt->fetch();
        if (!is_array($user)) return;

        $token = self::issue($pdo, (int) $user['id'], 'reset', 1800);
        $first = trim((string) strtok((string) $user['display_name'], ' '));
        Mailer::send(
            (string) $user['email'],
            'Reset your YOLOTASK password',
            'Reset your password',
            [($first !== '' ? "Hi {$first}, w" : 'W') . 'e received a request to reset the password for your YOLOTASK account. This link works once and expires in 30 minutes.'],
            ['label' => 'Choose a new password', 'url' => rtrim($portalUrl, '/') . '/reset-password.php?t=' . $token],
            null,
            "If you didn't ask for this, ignore this email. Your password stays the same."
        );
        Audit::record($pdo, (int) $user['id'], 'auth.password_reset_requested', 'user', (int) $user['id']);
    }

    public static function issue(PDO $pdo, int $userId, string $purpose, int $ttlSeconds): string
    {
        $token = Crypto::token();
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = :u AND used_at IS NULL')->execute(['u' => $userId]);
        $stmt = $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, purpose, expires_at) VALUES (:u, :t, :p, DATE_ADD(UTC_TIMESTAMP(), INTERVAL :s SECOND))');
        $stmt->bindValue('u', $userId, PDO::PARAM_INT);
        $stmt->bindValue('t', hash('sha256', $token));
        $stmt->bindValue('p', $purpose === 'invite' ? 'invite' : 'reset');
        $stmt->bindValue('s', $ttlSeconds, PDO::PARAM_INT);
        $stmt->execute();
        return $token;
    }

    /** Look up a live token for the given portal role. */
    public static function find(PDO $pdo, string $token, string $role): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) return null;
        $stmt = $pdo->prepare('SELECT t.id AS token_id, t.purpose, u.id, u.display_name, u.email FROM password_reset_tokens t INNER JOIN users u ON u.id = t.user_id WHERE t.token_hash = :t AND t.used_at IS NULL AND t.expires_at > UTC_TIMESTAMP() AND u.role = :r AND u.status = \'active\' LIMIT 1');
        $stmt->execute(['t' => hash('sha256', $token), 'r' => $role]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** Set the new password, burn the token and sign out every device. */
    public static function complete(PDO $pdo, array $found, string $password): void
    {
        $userId = (int) $found['id'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = :u AND used_at IS NULL')->execute(['u' => $userId]);
            $sql = 'UPDATE users SET password_hash = :h, password_changed_at = UTC_TIMESTAMP()'
                . ($found['purpose'] === 'invite' ? ', email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP())' : '')
                . ' WHERE id = :u';
            $pdo->prepare($sql)->execute(['h' => password_hash($password, PASSWORD_DEFAULT), 'u' => $userId]);
            $pdo->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE user_id = :u AND revoked_at IS NULL')->execute(['u' => $userId]);
            Audit::record($pdo, $userId, $found['purpose'] === 'invite' ? 'auth.invite_accepted' : 'auth.password_reset', 'user', $userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if ($found['purpose'] !== 'invite') {
            Mailer::send((string) $found['email'], 'Your YOLOTASK password was changed', 'Your password was changed',
                ['The password for your YOLOTASK account was just reset, and every device was signed out.'],
                null, null, "If this wasn't you, reset your password again straight away and contact YOLOTASK support.");
        }
    }
}
