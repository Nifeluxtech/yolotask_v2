<?php
declare(strict_types=1);
namespace YoloTask\Account;

use PDO;
use RuntimeException;
use YoloTask\Messaging\Mailer;
use YoloTask\Security\Audit;
use YoloTask\Security\Session;

/** Self-service profile, password and account closure. */
final class Account
{
    /** Normalise a Nigerian or international number to E.164, or null for empty. */
    public static function normalisePhone(string $phone): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', trim($phone)) ?? '';
        if ($digits === '') return null;
        if (preg_match('/^0[789][01]\d{8}$/', $digits)) return '+234' . substr($digits, 1);
        if (preg_match('/^234[789][01]\d{8}$/', $digits)) return '+' . $digits;
        if (preg_match('/^\+[1-9]\d{7,14}$/', $digits)) return $digits;
        throw new RuntimeException('Enter a valid phone number, for example 0803 123 4567.');
    }

    public static function updateProfile(PDO $pdo, int $userId, string $displayName, string $phone): void
    {
        $displayName = trim($displayName);
        $len = function_exists('mb_strlen') ? mb_strlen($displayName, 'UTF-8') : strlen($displayName);
        if ($displayName === '' || $len > 80) throw new RuntimeException('Enter a name of 1 to 80 characters.');
        $normalised = self::normalisePhone($phone);
        $pdo->prepare('UPDATE users SET display_name = :n, phone = :p WHERE id = :id')->execute(['n' => $displayName, 'p' => $normalised, 'id' => $userId]);
        Audit::record($pdo, $userId, 'account.profile_updated', 'user', $userId);
    }

    public static function changePassword(PDO $pdo, int $userId, string $current, string $new, string $confirm): void
    {
        $stmt = $pdo->prepare('SELECT email, password_hash FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row) || !password_verify($current, (string) $row['password_hash'])) throw new RuntimeException('Your current password is incorrect.');
        if (strlen($new) < 12 || strlen($new) > 72) throw new RuntimeException('Use a new password of at least 12 characters and no more than 72 bytes.');
        if (!hash_equals($new, $confirm)) throw new RuntimeException('The new passwords do not match.');
        if (password_verify($new, (string) $row['password_hash'])) throw new RuntimeException('Choose a password you have not used on this account.');
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET password_hash = :h, password_changed_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['h' => password_hash($new, PASSWORD_DEFAULT), 'id' => $userId]);
            $pdo->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE user_id = :u AND revoked_at IS NULL AND session_hash <> :cur')->execute(['u' => $userId, 'cur' => (string) ($_SESSION['session_hash'] ?? '')]);
            Audit::record($pdo, $userId, 'account.password_changed', 'user', $userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        Mailer::send((string) $row['email'], 'Your YOLOTASK password was changed', 'Your password was changed',
            ['The password for your YOLOTASK account was just changed. Other devices were signed out.'],
            null, null, "If this wasn't you, reset your password straight away and contact YOLOTASK support.");
    }

    public static function close(PDO $pdo, int $userId, string $password, string $confirmWord): void
    {
        if (strtoupper(trim($confirmWord)) !== 'DELETE') throw new RuntimeException('Type DELETE to confirm.');
        $stmt = $pdo->prepare('SELECT email, password_hash, role FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row) || !password_verify($password, (string) $row['password_hash'])) throw new RuntimeException('Your password is incorrect.');
        if ((string) $row['role'] === 'admin') throw new RuntimeException('Admin accounts are closed by another administrator.');
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET status = \'closed\', closed_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['id' => $userId]);
            $pdo->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE user_id = :u AND revoked_at IS NULL')->execute(['u' => $userId]);
            Audit::record($pdo, $userId, 'account.closed', 'user', $userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        Mailer::send((string) $row['email'], 'Your YOLOTASK account was closed', 'Your account is closed',
            ['Your YOLOTASK account has been closed and every device was signed out.'],
            null, null, 'If you did not do this, contact YOLOTASK support straight away.');
        Session::destroy();
    }
}
