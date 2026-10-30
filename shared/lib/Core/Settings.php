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
    /** Money rules (kobo, migration 0003) and task/campaign rules (migration 0005). */
    public const DEFAULTS = [
        'money.topup_min_kobo' => '10000',        // ₦100
        'money.topup_max_kobo' => '50000000',     // ₦500,000
        'money.withdraw_min_kobo' => '100000',    // ₦1,000
        'money.withdraw_max_kobo' => '50000000',  // ₦500,000
        'money.payin_expiry_hours' => '24',
        // Phase 3 hardening: withdrawal velocity, bank-change cooldown, stuck payouts.
        'money.withdraw_daily_count' => '3',
        'money.withdraw_daily_max_kobo' => '50000000', // ₦500,000
        'money.bank_change_cooldown_hours' => '24',
        'money.payout_stuck_hours' => '6',
        // Phase 4 task and campaign rules (rows seeded by migration 0005).
        'tasks.reservation_minutes' => '60',
        'tasks.max_active_reservations' => '3',
        'tasks.reward_hold_hours' => '0',
        'tasks.resubmission_cap' => '1',
        'tasks.stale_hours' => '24',
        'tasks.auto_approve_default_hours' => '48',
        'tasks.auto_approve_min_hours' => '12',
        'tasks.auto_approve_max_hours' => '336',
        'tasks.proof_max_bytes' => '2097152',
        'campaigns.min_quantity' => '1',
        'campaigns.max_quantity' => '100000',
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
