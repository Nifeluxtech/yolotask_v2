<?php
declare(strict_types=1);
namespace YoloTask\Security;

use PDO;

/** Readable device names and per-session control for the Devices page. */
final class Devices
{
    public static function label(string $ua): string
    {
        if ($ua === '') return 'Unknown device';
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'CriOS') || str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'CrOS') => 'Chromebook',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'device',
        };
        return $browser . ' on ' . $os;
    }

    /** Active sessions, newest first, with a flag for the current one. */
    public static function list(PDO $pdo, int $userId): array
    {
        $current = (string) ($_SESSION['session_hash'] ?? '');
        $stmt = $pdo->prepare('SELECT id, session_hash, device_label, created_at, COALESCE(last_seen_at, created_at) AS last_seen_at FROM user_sessions WHERE user_id = :u AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP() ORDER BY COALESCE(last_seen_at, created_at) DESC LIMIT 20');
        $stmt->execute(['u' => $userId]);
        $rows = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $row['is_current'] = $current !== '' && hash_equals((string) $row['session_hash'], $current);
            unset($row['session_hash']);
            $rows[] = $row;
        }
        return $rows;
    }

    public static function revoke(PDO $pdo, int $userId, int $sessionId): bool
    {
        $stmt = $pdo->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE id = :id AND user_id = :u AND revoked_at IS NULL AND session_hash <> :cur');
        $stmt->execute(['id' => $sessionId, 'u' => $userId, 'cur' => (string) ($_SESSION['session_hash'] ?? '')]);
        if ($stmt->rowCount() > 0) { Audit::record($pdo, $userId, 'auth.device_signed_out', 'user_session', $sessionId); return true; }
        return false;
    }

    public static function revokeOthers(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE user_id = :u AND revoked_at IS NULL AND session_hash <> :cur');
        $stmt->execute(['u' => $userId, 'cur' => (string) ($_SESSION['session_hash'] ?? '')]);
        $n = $stmt->rowCount();
        if ($n > 0) Audit::record($pdo, $userId, 'auth.other_devices_signed_out', 'user', $userId);
        return $n;
    }

    /** True when this device name has never signed in to the account before. */
    public static function isNew(PDO $pdo, int $userId, string $label): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id = :u AND device_label = :l');
        $stmt->execute(['u' => $userId, 'l' => $label]);
        return (int) $stmt->fetchColumn() === 0;
    }
}
