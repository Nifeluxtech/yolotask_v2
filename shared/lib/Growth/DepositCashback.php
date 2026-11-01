<?php
declare(strict_types=1);
namespace YoloTask\Growth;

use PDO;
use RuntimeException;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Messaging\Notifications;
use YoloTask\Money\Ledger;
use YoloTask\Money\Money;
use YoloTask\Money\Wallet;
use YoloTask\Security\Audit;

/**
 * Advertiser deposit cashback (migration 0013).
 *
 * On every confirmed AfrStar deposit (the collection callback crediting an
 * order, or an admin crediting a reviewed amount-mismatch payment) the
 * depositing advertiser gets cashback.rate_bps of the credited amount,
 * rounded down to the kobo (intdiv, like referral commission), into the same
 * available balance the deposit credited. Deposits below
 * cashback.min_deposit_kobo (0 = no minimum) get nothing.
 *
 * Ledger: Dr system:deposit_cashback / Cr advertiser available, idempotency
 * key cashback:<paymentOrderId>; the deposit_cashbacks row (unique per order)
 * records the decision, so a redelivered callback, an admin review and the
 * hourly catch-up all pay at most once.
 *
 * Only payment_orders reach this code: admin wallet credits, adjustments and
 * bonuses never earn cashback, and cashback is not a payment order, so it
 * never earns referral commission or further cashback.
 *
 * Nothing here throws into the deposit path (failures are logged, like the
 * referral commission).
 */
final class DepositCashback
{
    public const ACCOUNT = 'system:deposit_cashback';
    public const MAX_BPS = 5000; // 50%
    public const MAX_MIN_DEPOSIT_KOBO = 50000000; // ₦500,000 (the top-up maximum)
    /** The hourly catch-up only looks at deposits confirmed this recently. */
    public const SWEEP_HOURS = 48;

    public static function rateBps(PDO $pdo): int
    {
        return max(0, min(self::MAX_BPS, Settings::int($pdo, 'cashback.rate_bps')));
    }

    public static function minDepositKobo(PDO $pdo): int
    {
        return max(0, Settings::int($pdo, 'cashback.min_deposit_kobo'));
    }

    public static function enabled(PDO $pdo): bool
    {
        return self::rateBps($pdo) > 0;
    }

    /** Cashback on a credited amount: floor to the kobo. */
    public static function amountFor(int $baseKobo, int $bps): int
    {
        if ($baseKobo <= 0 || $bps <= 0) return 0;
        return intdiv($baseKobo * min($bps, self::MAX_BPS), 10000);
    }

    /** Right after a top-up is credited (callback or admin review). Never throws. */
    public static function afterTopupCredited(PDO $pdo, int $orderId): bool
    {
        try {
            return self::pay($pdo, $orderId);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('cashback.failed', ['order_id' => $orderId, 'exception' => get_class($e), 'message' => $e->getMessage()]);
            return false;
        }
    }

    private static function pay(PDO $pdo, int $orderId): bool
    {
        $stmt = $pdo->prepare("SELECT o.id, o.order_sn, o.user_id, o.status, o.credited_kobo, u.role, u.status user_status
            FROM payment_orders o JOIN users u ON u.id = o.user_id WHERE o.id = :id");
        $stmt->execute(['id' => $orderId]);
        $o = $stmt->fetch();
        if (!is_array($o) || (string) $o['status'] !== 'paid' || (string) $o['role'] !== 'advertiser') return false;
        $userId = (int) $o['user_id'];
        Wallet::forUser($pdo, $userId);
        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, $userId);
            $lock = $pdo->prepare('SELECT id, status, credited_kobo, order_sn FROM payment_orders WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $orderId]);
            $order = $lock->fetch();
            if (!is_array($order) || (string) $order['status'] !== 'paid') { $pdo->commit(); return false; }
            $done = $pdo->prepare('SELECT id FROM deposit_cashbacks WHERE payment_order_id = :o');
            $done->execute(['o' => $orderId]);
            if ($done->fetchColumn() !== false) { $pdo->commit(); return false; }

            $base = (int) $order['credited_kobo'];
            $bps = self::rateBps($pdo);
            $min = self::minDepositKobo($pdo);
            $amount = self::amountFor($base, $bps);
            $skip = match (true) {
                $bps <= 0 => 'off',
                $base < $min => 'below_minimum',
                (string) $o['user_status'] !== 'active' => 'account_not_active',
                $amount <= 0 => 'too_small',
                default => null,
            };
            $txId = null;
            if ($skip === null) {
                $tx = Ledger::post($pdo, 'cashback:' . $orderId, 'deposit_cashback',
                    'Deposit cashback ' . Referrals::percent($bps) . ' on top-up ' . $order['order_sn'], [
                        [Ledger::systemAccountId($pdo, self::ACCOUNT), 'debit', $amount],
                        [$wallet['available_account_id'], 'credit', $amount],
                    ], 'payment_order', $orderId);
                $txId = $tx['id'];
            } else {
                $amount = 0;
            }
            $pdo->prepare('INSERT INTO deposit_cashbacks (payment_order_id, user_id, base_kobo, rate_bps, min_deposit_kobo, amount_kobo, status, skip_reason, ledger_transaction_id, created_at)
                VALUES (:o, :u, :b, :r, :m, :a, :s, :k, :t, UTC_TIMESTAMP())')
                ->execute(['o' => $orderId, 'u' => $userId, 'b' => $base, 'r' => $bps, 'm' => $min, 'a' => $amount, 's' => $skip === null ? 'paid' : 'skipped', 'k' => $skip, 't' => $txId]);
            $rowId = (int) $pdo->lastInsertId();
            if ($skip === null) Audit::record($pdo, null, 'cashback.paid', 'deposit_cashback', $rowId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if ($skip !== null) return false;
        Notifications::notify($pdo, $userId, 'rewards', 'Deposit cashback: ' . Money::format($amount),
            'You got ' . Referrals::percent($bps) . ' cashback (' . Money::format($amount) . ') on your ' . Money::format($base) . ' top-up ' . $order['order_sn'] . '. It is in your wallet balance.',
            'wallet.php', 'cashback:' . $orderId);
        return true;
    }

    /**
     * Hourly catch-up: deposits confirmed in the last SWEEP_HOURS with no
     * cashback decision yet (a crash between the deposit commit and the
     * cashback). Older deposits are never revisited, so switching cashback
     * on does not pay for past deposits. Idempotent.
     */
    public static function sweep(PDO $pdo, int $limit = 500): int
    {
        $n = 0;
        try {
            $ids = $pdo->query("SELECT o.id FROM payment_orders o JOIN users u ON u.id = o.user_id
                WHERE o.status = 'paid' AND u.role = 'advertiser' AND o.paid_at >= UTC_TIMESTAMP() - INTERVAL " . self::SWEEP_HOURS . " HOUR
                AND NOT EXISTS (SELECT 1 FROM deposit_cashbacks c WHERE c.payment_order_id = o.id) ORDER BY o.id LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            Logger::error('cashback.sweep_failed', ['exception' => get_class($e)]);
            return 0;
        }
        foreach ($ids as $id) {
            if (self::afterTopupCredited($pdo, (int) $id)) $n++;
        }
        return $n;
    }

    /**
     * Admin settings: percentage (0-50, up to two decimals) and minimum
     * deposit in kobo (0 = none). Audited per change.
     */
    public static function saveSettings(PDO $pdo, int $adminId, string $percent, ?int $minKobo): void
    {
        $percent = trim($percent);
        if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $percent) || (float) $percent > self::MAX_BPS / 100) throw new RuntimeException('Cashback must be a percentage from 0 to 50 (up to two decimals).');
        if ($minKobo === null || $minKobo < 0) throw new RuntimeException('Enter the minimum deposit in naira (0 for no minimum).');
        if ($minKobo > self::MAX_MIN_DEPOSIT_KOBO) throw new RuntimeException('The minimum deposit can be at most ' . Money::format(self::MAX_MIN_DEPOSIT_KOBO) . '.');
        $bps = (int) round((float) $percent * 100);
        $oldBps = self::rateBps($pdo);
        $oldMin = self::minDepositKobo($pdo);
        Settings::save($pdo, $adminId, ['cashback.rate_bps' => (string) $bps, 'cashback.min_deposit_kobo' => (string) $minKobo]);
        if ($bps !== $oldBps) Audit::record($pdo, $adminId, 'admin.cashback_rate_changed', 'settings', $bps);
        if ($minKobo !== $oldMin) Audit::record($pdo, $adminId, 'admin.cashback_min_deposit_changed', 'settings', $minKobo);
    }

    /** Admin reversal of a paid cashback (same rules as a referral payout reversal). */
    public static function reverse(PDO $pdo, int $adminId, int $cashbackId, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) throw new RuntimeException('A reason of 5 to 255 characters is required.');
        $peek = $pdo->prepare('SELECT user_id FROM deposit_cashbacks WHERE id = :id');
        $peek->execute(['id' => $cashbackId]);
        $userId = (int) $peek->fetchColumn();
        if ($userId <= 0) throw new RuntimeException('Cashback not found.');
        Wallet::forUser($pdo, $userId);
        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, $userId);
            $lock = $pdo->prepare('SELECT c.*, o.order_sn FROM deposit_cashbacks c JOIN payment_orders o ON o.id = c.payment_order_id WHERE c.id = :id FOR UPDATE');
            $lock->execute(['id' => $cashbackId]);
            $c = $lock->fetch();
            if ((string) $c['status'] !== 'paid') throw new RuntimeException('Only paid cashback can be reversed.');
            $amount = (int) $c['amount_kobo'];
            if ($wallet['available_kobo'] < $amount) throw new RuntimeException('The advertiser no longer has ' . Money::format($amount) . ' available. Recover it with a wallet adjustment first, then reverse.');
            $tx = Ledger::post($pdo, 'cashback-reverse:' . $cashbackId, 'deposit_cashback_reversal', 'Deposit cashback on top-up ' . $c['order_sn'] . ' reversed: ' . $reason, [
                [$wallet['available_account_id'], 'debit', $amount],
                [Ledger::systemAccountId($pdo, self::ACCOUNT), 'credit', $amount],
            ], 'payment_order', (int) $c['payment_order_id'], $adminId);
            $pdo->prepare("UPDATE deposit_cashbacks SET status = 'reversed', reverse_transaction_id = :t, reversed_by_user_id = :a, reversed_at = UTC_TIMESTAMP(), reverse_reason = :r WHERE id = :id")
                ->execute(['t' => $tx['id'], 'a' => $adminId, 'r' => $reason, 'id' => $cashbackId]);
            Audit::record($pdo, $adminId, 'admin.cashback_reversed', 'deposit_cashback', $cashbackId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        Notifications::notify($pdo, $userId, 'rewards', 'Deposit cashback reversed', Money::format($amount) . ' cashback on top-up ' . $c['order_sn'] . ' was taken back from your wallet. Reason: ' . $reason,
            'wallet.php', 'cashback-reverse:' . $cashbackId);
    }

    /** Admin list: paid and reversed cashback, newest first. */
    public static function recent(PDO $pdo, int $page, int $perPage = 25): array
    {
        return \YoloTask\Support\Pagination::query($pdo, "SELECT COUNT(*) FROM deposit_cashbacks c WHERE c.status <> 'skipped'",
            "SELECT c.*, o.order_sn, u.display_name, u.email FROM deposit_cashbacks c JOIN payment_orders o ON o.id = c.payment_order_id JOIN users u ON u.id = c.user_id
             WHERE c.status <> 'skipped' ORDER BY c.id DESC", [], $page, $perPage);
    }

    /** Totals for the admin card. */
    public static function stats(PDO $pdo): array
    {
        $r = $pdo->query("SELECT COALESCE(SUM(status = 'paid'), 0) n, COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_kobo END), 0) amt FROM deposit_cashbacks")->fetch();
        $bal = (int) $pdo->query("SELECT COALESCE(MAX(balance_kobo), 0) FROM ledger_accounts WHERE code = '" . self::ACCOUNT . "'")->fetchColumn();
        return ['paid_count' => (int) $r['n'], 'paid_kobo' => (int) $r['amt'], 'account' => $bal];
    }

    /**
     * Reconciliation: the expense account equals standing cashback, every row
     * agrees with its ledger transactions, and cashback only sits on paid
     * deposits.
     * @return list<string>
     */
    public static function reconcile(PDO $pdo): array
    {
        if (!(bool) $pdo->query("SHOW TABLES LIKE 'deposit_cashbacks'")->fetchColumn()) return [];
        $problems = [];
        $bal = (int) $pdo->query("SELECT COALESCE(MAX(balance_kobo), 0) FROM ledger_accounts WHERE code = '" . self::ACCOUNT . "'")->fetchColumn();
        $sum = (int) $pdo->query("SELECT COALESCE(SUM(amount_kobo), 0) FROM deposit_cashbacks WHERE status = 'paid'")->fetchColumn();
        if ($bal !== $sum) $problems[] = 'Account ' . self::ACCOUNT . " {$bal} != paid deposit cashback {$sum}";
        $sql = "SELECT c.id FROM deposit_cashbacks c LEFT JOIN ledger_transactions t ON t.id = c.ledger_transaction_id LEFT JOIN ledger_transactions rt ON rt.id = c.reverse_transaction_id
                LEFT JOIN payment_orders o ON o.id = c.payment_order_id
                WHERE (c.status IN ('paid', 'reversed') AND (t.id IS NULL OR t.amount_kobo <> c.amount_kobo OR c.amount_kobo <= 0 OR t.idempotency_key <> CONCAT('cashback:', c.payment_order_id)))
                   OR (c.status = 'reversed' AND (rt.id IS NULL OR rt.amount_kobo <> c.amount_kobo))
                   OR (c.status = 'skipped' AND (c.ledger_transaction_id IS NOT NULL OR c.amount_kobo <> 0))
                   OR o.status <> 'paid' OR c.base_kobo <> o.credited_kobo OR c.user_id <> o.user_id
                   OR c.amount_kobo * 2 > c.base_kobo OR c.rate_bps > " . self::MAX_BPS . " LIMIT 50";
        foreach ($pdo->query($sql) as $r) $problems[] = "Deposit cashback #{$r['id']} does not match its deposit or ledger transactions";
        return $problems;
    }
}
