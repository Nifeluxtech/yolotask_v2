<?php
declare(strict_types=1);
namespace YoloTask\Security;

use PDO;
use YoloTask\Messaging\Mailer;

/** Six-digit code plus one-tap link, valid for 30 minutes, five tries per code. */
final class EmailVerification
{
    private const TTL = 1800;
    private const MAX_ATTEMPTS = 5;
    public const RESEND_COOLDOWN = 60;

    /** Create a fresh code and email it. Returns false when rate-limited or mail fails. */
    public static function send(PDO $pdo, array $user, string $portalUrl): bool
    {
        $userId = (int) $user['id'];
        if (Throttle::tooMany($pdo, 'verify_send', (string) $userId, 5, 3600)) return false;
        $since = Throttle::secondsSinceLast($pdo, 'verify_send', (string) $userId);
        if ($since !== null && $since < self::RESEND_COOLDOWN) return false;

        $code = Crypto::code(6);
        $token = Crypto::token();
        $pdo->prepare('UPDATE email_verifications SET used_at = UTC_TIMESTAMP() WHERE user_id = :u AND used_at IS NULL')->execute(['u' => $userId]);
        $pdo->prepare('INSERT INTO email_verifications (user_id, code_hash, token_hash, expires_at) VALUES (:u, :c, :t, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . self::TTL . ' SECOND))')
            ->execute(['u' => $userId, 'c' => Crypto::hmac('code|' . $userId . '|' . $code), 't' => hash('sha256', $token)]);
        Throttle::hit($pdo, 'verify_send', (string) $userId);

        $first = trim((string) strtok((string) $user['display_name'], ' '));
        return Mailer::send(
            (string) $user['email'],
            'Your YOLOTASK verification code: ' . $code,
            'Confirm your email',
            [($first !== '' ? "Hi {$first}, " : '') . 'enter this code on YOLOTASK to confirm your email address. It expires in 30 minutes.'],
            ['label' => 'Confirm my email', 'url' => rtrim($portalUrl, '/') . '/verify.php?t=' . $token],
            $code,
            "If you didn't create a YOLOTASK account, you can ignore this email."
        );
    }

    /** Check a typed code for the signed-in user. */
    public static function verifyCode(PDO $pdo, int $userId, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        $stmt = $pdo->prepare('SELECT id, code_hash, attempts FROM email_verifications WHERE user_id = :u AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1');
        $stmt->execute(['u' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row) || (int) $row['attempts'] >= self::MAX_ATTEMPTS) return false;
        if (strlen($code) !== 6 || !hash_equals((string) $row['code_hash'], Crypto::hmac('code|' . $userId . '|' . $code))) {
            $pdo->prepare('UPDATE email_verifications SET attempts = attempts + 1 WHERE id = :id')->execute(['id' => (int) $row['id']]);
            return false;
        }
        self::markVerified($pdo, $userId, (int) $row['id']);
        return true;
    }

    /** Check an emailed link token. Returns the verified user id or null. */
    public static function verifyToken(PDO $pdo, string $token): ?int
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) return null;
        $stmt = $pdo->prepare('SELECT id, user_id FROM email_verifications WHERE token_hash = :t AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() LIMIT 1');
        $stmt->execute(['t' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!is_array($row)) return null;
        self::markVerified($pdo, (int) $row['user_id'], (int) $row['id']);
        return (int) $row['user_id'];
    }

    public static function isVerified(PDO $pdo, int $userId): bool
    {
        $stmt = $pdo->prepare('SELECT email_verified_at IS NOT NULL FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    private static function markVerified(PDO $pdo, int $userId, int $verificationId): void
    {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE email_verifications SET used_at = UTC_TIMESTAMP() WHERE user_id = :u AND used_at IS NULL')->execute(['u' => $userId]);
            $pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = :u')->execute(['u' => $userId]);
            Audit::record($pdo, $userId, 'auth.email_verified', 'user', $userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
