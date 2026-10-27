<?php
declare(strict_types=1);
namespace YoloTask\Security;

use PDO;
use PDOException;
use RuntimeException;

final class Auth
{
    public static function registerableRoles(): array
    {
        return ['earner', 'advertiser'];
    }

    public static function validateRegistration(string $displayName, string $email, string $password): ?string
    {
        $displayName = trim($displayName);
        $email = strtolower(trim($email));
        $nameLength = function_exists('mb_strlen') ? mb_strlen($displayName, 'UTF-8') : strlen($displayName);
        if ($displayName === '' || $nameLength > 80) return 'Enter a name of 1 to 80 characters.';
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Enter a valid email address.';
        if (strlen($password) < 12 || strlen($password) > 72) return 'Use a password of at least 12 characters and no more than 72 bytes.';
        return null;
    }

    public static function register(PDO $pdo, string $displayName, string $email, string $password, string $role): int
    {
        if (!in_array($role, self::registerableRoles(), true)) {
            throw new RuntimeException('Self-registration is limited to earner and advertiser accounts.');
        }
        $displayName = trim($displayName);
        $email = strtolower(trim($email));
        $validationError = self::validateRegistration($displayName, $email, $password);
        if ($validationError !== null) throw new RuntimeException($validationError);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) throw new RuntimeException('Could not secure the password.');

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO users (display_name, email, password_hash, role, status) VALUES (:name, :email, :password_hash, :role, \'active\')');
            $stmt->execute(['name' => $displayName, 'email' => $email, 'password_hash' => $passwordHash, 'role' => $role]);
            $userId = (int) $pdo->lastInsertId();
            Audit::record($pdo, $userId, 'auth.registered', 'user', $userId);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() === '23000') throw new RuntimeException('An account could not be created with those details.');
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $userId;
    }

    public static function login(PDO $pdo, string $email, string $password, string $expectedRole): bool
    {
        $email = strtolower(trim($email));
        if (!in_array($expectedRole, ['earner', 'advertiser', 'reviewer', 'admin'], true)) return false;
        if (RateLimiter::isBlocked($pdo, $email)) return false;

        $stmt = $pdo->prepare('SELECT id, display_name, email, password_hash, role, status FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        $valid = is_array($user)
            && (string) $user['status'] === 'active'
            && (string) $user['role'] === $expectedRole
            && password_verify($password, (string) $user['password_hash']);

        RateLimiter::record($pdo, $email, $valid);
        if (!$valid) return false;

        $pdo->beginTransaction();
        try {
            if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
                $update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
                $update->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int) $user['id']]);
            }
            Session::authenticate($pdo, (int) $user['id']);
            $update = $pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id');
            $update->execute(['id' => (int) $user['id']]);
            Audit::record($pdo, (int) $user['id'], 'auth.login', 'user', (int) $user['id']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return true;
    }

    public static function currentUser(PDO $pdo): ?array
    {
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        $sessionHash = $_SESSION['session_hash'] ?? null;
        if (!$userId || !is_string($sessionHash) || !preg_match('/^[a-f0-9]{64}$/', $sessionHash)) return null;
        $stmt = $pdo->prepare('SELECT u.id, u.display_name, u.email, u.role, u.status FROM users u INNER JOIN user_sessions s ON s.user_id = u.id WHERE u.id = :user_id AND s.session_hash = :session_hash AND s.revoked_at IS NULL AND s.expires_at > UTC_TIMESTAMP() AND u.status = \'active\' LIMIT 1');
        $stmt->execute(['user_id' => $userId, 'session_hash' => $sessionHash]);
        $user = $stmt->fetch();
        if (!is_array($user)) {
            $_SESSION = [];
            return null;
        }
        return $user;
    }

    public static function logout(PDO $pdo): void
    {
        Session::revokeCurrent($pdo);
        Audit::record($pdo, isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, 'auth.logout');
        Session::destroy();
    }
}
