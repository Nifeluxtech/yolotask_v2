<?php
declare(strict_types=1);
namespace YoloTask\Security;

use PDO;
use RuntimeException;

final class Session
{
    public static function authenticate(PDO $pdo, int $userId): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) throw new RuntimeException('Session is not active.');
        if (!session_regenerate_id(true)) throw new RuntimeException('Could not rotate the session ID.');
        $sessionHash = hash('sha256', session_id());
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 43200);
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ipHash = self::privacyHash((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $agentHash = self::privacyHash($ua);
        $stmt = $pdo->prepare('INSERT INTO user_sessions (user_id, session_hash, expires_at, ip_hash, user_agent_hash, device_label, last_seen_at) VALUES (:user_id, :session_hash, :expires_at, :ip_hash, :agent_hash, :label, UTC_TIMESTAMP())');
        $stmt->execute(['user_id' => $userId, 'session_hash' => $sessionHash, 'expires_at' => $expiresAt, 'ip_hash' => $ipHash, 'agent_hash' => $agentHash, 'label' => Devices::label($ua)]);
        $_SESSION = ['user_id' => $userId, 'session_hash' => $sessionHash, '_csrf' => bin2hex(random_bytes(32))];
    }

    public static function revokeCurrent(PDO $pdo): void
    {
        $sessionHash = $_SESSION['session_hash'] ?? null;
        if (is_string($sessionHash) && preg_match('/^[a-f0-9]{64}$/', $sessionHash)) {
            $stmt = $pdo->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE session_hash = :hash AND revoked_at IS NULL');
            $stmt->execute(['hash' => $sessionHash]);
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $params['path'] ?: '/', 'domain' => $params['domain'], 'secure' => (bool) $params['secure'], 'httponly' => (bool) $params['httponly'], 'samesite' => $params['samesite'] ?? 'Lax']);
        }
        session_destroy();
    }

    private static function privacyHash(string $value): ?string
    {
        if ($value === '') return null;
        return Crypto::hmac($value);
    }
}
