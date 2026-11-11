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
    /** Money rules (kobo, migration 0003), task/campaign rules (migration 0005), appeal/review rules (migration 0008) and referral/level/notification rules (migration 0009) deposit cashback (migration 0013) the withdrawal fee / daily check-in (migration 0014) and YOLOTASK tasks / account activation (migration 0019). */
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
        // Custom tasks (rows seeded by migration 0016).
        'campaigns.custom_enabled' => '1',          // advertisers may use Other app / website and Custom task
        'campaigns.custom_min_price_naira' => '100', // lowest price per custom task, whole naira
        'campaigns.custom_reward_percent' => '80',   // earner share of a custom task price (1-99), rounded down
        // Phase 5 appeals, reviewer queues and advertiser quality flag (rows seeded by migration 0008).
        'appeals.window_hours' => '72',
        'reviews.claim_minutes' => '30',
        'appeals.flag_min_overturns' => '3',
        'appeals.flag_rate_percent' => '30',
        'appeals.flag_window_days' => '30',
        // Phase 6 referrals, levels and notifications (rows seeded by migration 0009).
        'referrals.enabled' => '1',
        'referrals.commission_bps' => '500',   // 5% of each confirmed advertiser deposit (0-5000 bps)
        'levels.enabled' => '1',
        'notifications.retention_days' => '90',
        'referrals.ip_allowance' => '3',       // referred sign-ups per IP address / device per referrer before admin review (1-100)
        'referrals.activation_bonus_kobo' => '10000', // paid to the referrer once when a referred earner activates their account, ₦100 (0 = off; migration 0020)
        'referrals.activation_bonus_since' => '2026-11-11 11:00:00', // UTC; activations before this earn no bonus (not editable)
        // Advertiser deposit cashback (rows seeded by migration 0013).
        'cashback.rate_bps' => '0',            // % of each confirmed AfrStar deposit paid back to the advertiser (0 = off, 0-5000 bps)
        'cashback.min_deposit_kobo' => '0',    // deposits below this get no cashback (0 = no minimum)
        // Earner withdrawal fee and daily check-in (rows seeded by migration 0014).
        'money.withdraw_fee_bps' => '1000',    // base earner withdrawal fee, 10% (0-5000 bps); levels may set a lower rate
        'checkin.enabled' => '0',              // daily check-in switch; amounts are set per level (0 = off for that level)
        // Account activation and YOLOTASK tasks (rows seeded by migration 0019).
        'activation.required' => '0',          // 1 = earners must pay the one-time activation fee before they can see or start any task; 0 = off (default)
        'activation.fee_kobo' => '50000',      // one-time account activation fee, ₦500 (₦100 to ₦100,000)
        'platform_tasks.reward_percent' => '50', // YOLOTASK task reward = this % of the task type's normal reward, rounded down to the kobo (1-100)
        'platform_tasks.owner_user_id' => '0', // the closed system account "YOLOTASK" that owns YOLOTASK tasks (set by migration 0019; not editable)
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
