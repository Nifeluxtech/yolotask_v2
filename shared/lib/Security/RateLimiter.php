<?php
declare(strict_types=1);
namespace YoloTask\Security;

use PDO;
use RuntimeException;

final class RateLimiter
{
    private const MAX_FAILURES = 5;

    public static function isBlocked(PDO $pdo, string $email): bool
    {
        [$emailHash, $ipHash] = self::keys($email);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0 AND attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE) AND (email_hash = :email_hash OR ip_hash = :ip_hash)');
        $stmt->execute(['email_hash' => $emailHash, 'ip_hash' => $ipHash]);
        return (int) $stmt->fetchColumn() >= self::MAX_FAILURES;
    }

    public static function record(PDO $pdo, string $email, bool $succeeded): void
    {
        [$emailHash, $ipHash] = self::keys($email);
        $stmt = $pdo->prepare('INSERT INTO login_attempts (email_hash, ip_hash, succeeded) VALUES (:email_hash, :ip_hash, :succeeded)');
        $stmt->execute(['email_hash' => $emailHash, 'ip_hash' => $ipHash, 'succeeded' => $succeeded ? 1 : 0]);
    }

    private static function keys(string $email): array
    {
        $config = function_exists('yolotask_config') ? yolotask_config() : [];
        $keyValue = $config['security_key'] ?? null;
        $key = (string) ($keyValue ?: (getenv('YOLOTASK_SECURITY_KEY') ?: ''));
        if (strlen($key) < 32) throw new RuntimeException('Set a private YOLOTASK_SECURITY_KEY of at least 32 characters.');
        $emailHash = hash_hmac('sha256', strtolower(trim($email)), $key);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $ipHash = hash_hmac('sha256', $ip, $key);
        return [$emailHash, $ipHash];
    }
}
