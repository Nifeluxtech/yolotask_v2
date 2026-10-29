<?php
declare(strict_types=1);
namespace YoloTask\Core;

use PDO;
use RuntimeException;
use YoloTask\Security\Audit;

/**
 * Admin-editable platform settings (key/value rows in `settings`).
 * Values are cached for the request; defaults apply when a row is missing.
 */
final class Settings
{
    /** Money rules, in kobo. Keys match the rows seeded by migration 0003. */
    public const DEFAULTS = [
        'money.topup_min_kobo' => '10000',        // ₦100
        'money.topup_max_kobo' => '50000000',     // ₦500,000
        'money.withdraw_min_kobo' => '100000',    // ₦1,000
        'money.withdraw_max_kobo' => '50000000',  // ₦500,000
        'money.payin_expiry_hours' => '24',
    ];

    private static ?array $cache = null;

    public static function all(PDO $pdo): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            try {
                foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $row) {
                    self::$cache[(string) $row['setting_key']] = (string) $row['setting_value'];
                }
            } catch (\PDOException $e) {
                Logger::error('settings.load_failed', ['exception' => get_class($e)]);
            }
        }
        return self::$cache;
    }

    public static function get(PDO $pdo, string $key, ?string $default = null): ?string
    {
        return self::all($pdo)[$key] ?? $default;
    }

    public static function int(PDO $pdo, string $key): int
    {
        return (int) (self::get($pdo, $key, self::DEFAULTS[$key] ?? '0'));
    }

    /** Save several values at once (admin only; caller checks role and CSRF). */
    public static function save(PDO $pdo, int $adminId, array $values): void
    {
        $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value, updated_by_user_id) VALUES (:k, :v, :u) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by_user_id = VALUES(updated_by_user_id)');
        $pdo->beginTransaction();
        try {
            foreach ($values as $key => $value) {
                if (!array_key_exists($key, self::DEFAULTS)) throw new RuntimeException('Unknown setting.');
                $stmt->execute(['k' => $key, 'v' => (string) $value, 'u' => $adminId]);
            }
            Audit::record($pdo, $adminId, 'admin.settings_updated', 'settings', null);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
