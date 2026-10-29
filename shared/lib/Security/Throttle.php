<?php
declare(strict_types=1);
namespace YoloTask\Security;

use PDO;

/** Generic sliding-window limiter backed by the rate_limits table. */
final class Throttle
{
    public static function tooMany(PDO $pdo, string $bucket, string $key, int $max, int $windowSeconds): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM rate_limits WHERE bucket = :b AND key_hash = :k AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :w SECOND)');
        $stmt->bindValue('b', $bucket);
        $stmt->bindValue('k', Crypto::hmac($bucket . '|' . strtolower($key)));
        $stmt->bindValue('w', $windowSeconds, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn() >= $max;
    }

    public static function hit(PDO $pdo, string $bucket, string $key): void
    {
        $stmt = $pdo->prepare('INSERT INTO rate_limits (bucket, key_hash, created_at) VALUES (:b, :k, UTC_TIMESTAMP())');
        $stmt->execute(['b' => $bucket, 'k' => Crypto::hmac($bucket . '|' . strtolower($key))]);
    }

    /** Seconds since the newest hit in a bucket for a key, or null if none in the window. */
    public static function secondsSinceLast(PDO $pdo, string $bucket, string $key): ?int
    {
        $stmt = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), UTC_TIMESTAMP()) FROM rate_limits WHERE bucket = :b AND key_hash = :k');
        $stmt->execute(['b' => $bucket, 'k' => Crypto::hmac($bucket . '|' . strtolower($key))]);
        $v = $stmt->fetchColumn();
        return $v === null || $v === false ? null : (int) $v;
    }
}
