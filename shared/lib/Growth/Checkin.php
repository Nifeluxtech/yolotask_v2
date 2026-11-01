<?php
declare(strict_types=1);
namespace YoloTask\Growth;

use PDO;
use RuntimeException;
use YoloTask\Core\Clock;
use YoloTask\Core\Settings;
use YoloTask\Money\Ledger;
use YoloTask\Money\Wallet;
use YoloTask\Security\Audit;
use YoloTask\Security\Throttle;

/**
 * Daily check-in (migration 0014).
 *
 * An earner with a confirmed email may check in once per Africa/Lagos
 * calendar day (00:00-23:59 WAT) and is paid the reward set on their current
 * level (earner_levels.checkin_reward_kobo). It is available only while the
 * global switch checkin.enabled = 1 and that amount is above ₦0; with levels
 * switched off or no level reached there is no reward, so no check-in.
 *
 * Once per day is enforced by the unique key (user_id, lagos_date) and the
 * wallet row lock; the ledger key checkin:<userId>:<YYYY-MM-DD> makes a
 * replay post nothing. Ledger: Dr system:checkin_rewards / Cr earner
 * available. The streak is the number of consecutive Lagos days with a
 * check-in, ending today (or yesterday while today is still open); a missed
 * day starts it again at 1.
 */
final class Checkin
{
    public const ACCOUNT = Ledger::CHECKIN_REWARDS;

    public static function enabled(PDO $pdo): bool
    {
        return Settings::get($pdo, 'checkin.enabled', '0') === '1';
    }

    /**
     * Reward for this earner today: their level's amount (0 when levels are
     * off or no level is reached).
     * @return array{amount:int,level_id:?int,level_name:?string}
     */
    public static function rewardFor(PDO $pdo, int $earnerId): array
    {
        $info = Levels::forEarner($pdo, $earnerId);
        $level = $info['enabled'] ? $info['level'] : null;
        if ($level === null) return ['amount' => 0, 'level_id' => null, 'level_name' => null];
        return ['amount' => max(0, (int) $level['checkin_reward_kobo']), 'level_id' => (int) $level['id'], 'level_name' => (string) $level['name']];
    }

    private static function row(PDO $pdo, int $userId, string $date): ?array
    {
        $stmt = $pdo->prepare('SELECT id, amount_kobo, streak, lagos_date FROM daily_checkins WHERE user_id = :u AND lagos_date = :d');
        $stmt->execute(['u' => $userId, 'd' => $date]);
        $r = $stmt->fetch();
        return is_array($r) ? $r : null;
    }

    /**
     * Dashboard state.
     * @return array{show:bool,available:bool,reason:?string,amount:int,today:string,checked_today:bool,today_amount:int,streak:int,level_name:?string}
     */
    public static function status(PDO $pdo, array $user): array
    {
        $userId = (int) $user['id'];
        $today = Clock::lagosDate();
        $out = ['show' => false, 'available' => false, 'reason' => null, 'amount' => 0, 'today' => $today, 'checked_today' => false, 'today_amount' => 0, 'streak' => 0, 'level_name' => null];
        try {
            $todayRow = self::row($pdo, $userId, $today);
            $yesterday = $todayRow === null ? self::row($pdo, $userId, Clock::shiftDate($today, -1)) : null;
        } catch (\PDOException) {
            return $out; // migration 0014 not applied yet
        }
        if ($todayRow !== null) {
            $out['checked_today'] = true;
            $out['today_amount'] = (int) $todayRow['amount_kobo'];
            $out['streak'] = (int) $todayRow['streak'];
        } elseif ($yesterday !== null) {
            $out['streak'] = (int) $yesterday['streak'];
        }
        $reward = self::rewardFor($pdo, $userId);
        $out['amount'] = $reward['amount'];
        $out['level_name'] = $reward['level_name'];
        $on = self::enabled($pdo) && $reward['amount'] > 0;
        $out['show'] = $on || $out['checked_today'];
        if (!$on) $out['reason'] = 'off';
        elseif (empty($user['email_verified_at'])) $out['reason'] = 'unverified';
        elseif ($out['checked_today']) $out['reason'] = 'done';
        else $out['available'] = true;
        return $out;
    }

    /**
     * Check in for today (Lagos). Throws a RuntimeException with a message for
     * the earner when it is not allowed or already done.
     * @return array{id:int,amount:int,streak:int,date:string}
     */
    public static function checkIn(PDO $pdo, array $user): array
    {
        if ((string) $user['role'] !== 'earner') throw new RuntimeException('Only earner accounts can check in.');
        if ((string) ($user['status'] ?? 'active') !== 'active') throw new RuntimeException('Your account is not active.');
        if (empty($user['email_verified_at'])) throw new RuntimeException('Confirm your email to use the daily check-in.');
        if (!self::enabled($pdo)) throw new RuntimeException('The daily check-in is not available right now.');
        $userId = (int) $user['id'];
        if (Throttle::tooMany($pdo, 'checkin', (string) $userId, 20, 3600)) throw new RuntimeException('Too many attempts. Please try again later.');
        Throttle::hit($pdo, 'checkin', (string) $userId);
        $reward = self::rewardFor($pdo, $userId);
        if ($reward['amount'] <= 0) throw new RuntimeException('The daily check-in is not available for your level yet.');
        $date = Clock::lagosDate();
        Wallet::forUser($pdo, $userId);

        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, $userId); // serialises check-ins for this earner
            if (self::row($pdo, $userId, $date) !== null) throw new RuntimeException('You already checked in today. Come back tomorrow.');
            $prev = self::row($pdo, $userId, Clock::shiftDate($date, -1));
            $streak = $prev === null ? 1 : (int) $prev['streak'] + 1;
            $tx = Ledger::post($pdo, 'checkin:' . $userId . ':' . $date, 'checkin_reward', 'Daily check-in ' . $date . ' (day ' . $streak . ')', [
                [Ledger::systemAccountId($pdo, self::ACCOUNT), 'debit', $reward['amount']],
                [$wallet['available_account_id'], 'credit', $reward['amount']],
            ], 'user', $userId, $userId);
            if (!$tx['created']) throw new RuntimeException('You already checked in today. Come back tomorrow.');
            $pdo->prepare('INSERT INTO daily_checkins (user_id, lagos_date, level_id, amount_kobo, streak, ledger_transaction_id, created_at) VALUES (:u, :d, :l, :a, :s, :t, :c)')
                ->execute(['u' => $userId, 'd' => $date, 'l' => $reward['level_id'], 'a' => $reward['amount'], 's' => $streak, 't' => $tx['id'], 'c' => Clock::utcSql()]);
            $id = (int) $pdo->lastInsertId();
            Audit::record($pdo, $userId, 'checkin.claimed', 'daily_checkin', $id);
            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ((string) $e->getCode() === '23000') throw new RuntimeException('You already checked in today. Come back tomorrow.');
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'amount' => $reward['amount'], 'streak' => $streak, 'date' => $date];
    }

    /** Admin: global switch (audited). */
    public static function setEnabled(PDO $pdo, int $adminId, bool $on): void
    {
        if (self::enabled($pdo) === $on) return;
        Settings::save($pdo, $adminId, ['checkin.enabled' => $on ? '1' : '0']);
        Audit::record($pdo, $adminId, $on ? 'admin.checkin_enabled' : 'admin.checkin_disabled', 'settings', null);
    }

    /** Admin card numbers: check-ins today (Lagos) and all-time paid. */
    public static function stats(PDO $pdo): array
    {
        try {
            $today = $pdo->prepare('SELECT COUNT(*) n, COALESCE(SUM(amount_kobo), 0) a FROM daily_checkins WHERE lagos_date = :d');
            $today->execute(['d' => Clock::lagosDate()]);
            $t = $today->fetch();
            $all = $pdo->query('SELECT COUNT(*) n, COALESCE(SUM(amount_kobo), 0) a FROM daily_checkins')->fetch();
            return ['today_count' => (int) $t['n'], 'today_kobo' => (int) $t['a'], 'total_count' => (int) $all['n'], 'total_kobo' => (int) $all['a']];
        } catch (\PDOException) {
            return ['today_count' => 0, 'today_kobo' => 0, 'total_count' => 0, 'total_kobo' => 0];
        }
    }

    /**
     * Reconciliation (part of Ledger::reconcile): the expense account equals
     * the rewards paid, and every check-in matches its ledger transaction.
     * @return list<string>
     */
    public static function reconcile(PDO $pdo): array
    {
        if (!(bool) $pdo->query("SHOW TABLES LIKE 'daily_checkins'")->fetchColumn()) return [];
        $problems = [];
        $bal = (int) $pdo->query("SELECT COALESCE(MAX(balance_kobo), 0) FROM ledger_accounts WHERE code = '" . self::ACCOUNT . "'")->fetchColumn();
        $sum = (int) $pdo->query('SELECT COALESCE(SUM(amount_kobo), 0) FROM daily_checkins')->fetchColumn();
        if ($bal !== $sum) $problems[] = 'Account ' . self::ACCOUNT . " {$bal} != check-in rewards paid {$sum}";
        $sql = "SELECT c.id FROM daily_checkins c LEFT JOIN ledger_transactions t ON t.id = c.ledger_transaction_id
                WHERE t.id IS NULL OR t.amount_kobo <> c.amount_kobo OR c.amount_kobo <= 0 OR c.streak < 1
                   OR t.idempotency_key <> CONCAT('checkin:', c.user_id, ':', DATE_FORMAT(c.lagos_date, '%Y-%m-%d')) LIMIT 50";
        foreach ($pdo->query($sql) as $r) $problems[] = "Daily check-in #{$r['id']} does not match its ledger transaction";
        $orphans = (int) $pdo->query("SELECT COUNT(*) FROM ledger_transactions t LEFT JOIN daily_checkins c ON c.ledger_transaction_id = t.id WHERE t.type = 'checkin_reward' AND c.id IS NULL")->fetchColumn();
        if ($orphans > 0) $problems[] = "{$orphans} check-in ledger transaction(s) have no daily_checkins row";
        return $problems;
    }
}
