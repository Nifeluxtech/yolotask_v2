<?php
declare(strict_types=1);
namespace YoloTask\Money;

use PDO;
use YoloTask\Core\Settings;
use YoloTask\Growth\Levels;

/**
 * Fee policy. Every fee is calculated here and posted to its own ledger
 * account by the caller.
 *
 * Top-ups: no fee.
 *
 * Earner withdrawals (migration 0014): base rate money.withdraw_fee_bps
 * (default 1000 = 10 %, admin 0-50 %), optionally lowered per earner level
 * (earner_levels.withdrawal_fee_bps; NULL = base; a level rate above the
 * current base is capped at the base). fee = floor(amount x bps / 10000):
 * rounded down to the kobo, in the earner's favour. The withdrawal row
 * stores the rate and fee it was requested at; nothing recalculates them.
 */
final class Fees
{
    public const MAX_WITHDRAWAL_BPS = 5000; // 50 %
    public const WITHDRAWAL_FEE_ACCOUNT = Ledger::WITHDRAWAL_FEES;

    public static function topup(int $amountKobo): int
    {
        return 0;
    }

    /** Fee on a gross amount at a rate in basis points, rounded down. */
    public static function withdrawal(int $amountKobo, int $bps = 0): int
    {
        if ($amountKobo <= 0 || $bps <= 0) return 0;
        return intdiv($amountKobo * min($bps, self::MAX_WITHDRAWAL_BPS), 10000);
    }

    /** The admin base withdrawal fee in basis points (0-5000). */
    public static function withdrawalBaseBps(PDO $pdo): int
    {
        return max(0, min(self::MAX_WITHDRAWAL_BPS, Settings::int($pdo, 'money.withdraw_fee_bps')));
    }

    /**
     * The rate that applies to this earner right now: their level's override
     * (when levels are on, they hold a level and it has one), never above the
     * base rate.
     * @return array{bps:int,base_bps:int,level_id:?int,level_name:?string,from_level:bool}
     */
    public static function withdrawalRateFor(PDO $pdo, int $earnerId): array
    {
        $base = self::withdrawalBaseBps($pdo);
        $out = ['bps' => $base, 'base_bps' => $base, 'level_id' => null, 'level_name' => null, 'from_level' => false];
        $info = Levels::forEarner($pdo, $earnerId);
        $level = $info['enabled'] ? $info['level'] : null;
        if ($level !== null) {
            $out['level_id'] = (int) $level['id'];
            $out['level_name'] = (string) $level['name'];
            if ($level['withdrawal_fee_bps'] !== null) {
                $out['bps'] = min($base, max(0, (int) $level['withdrawal_fee_bps']));
                $out['from_level'] = $out['bps'] < $base;
            }
        }
        return $out;
    }

    /**
     * Server-side preview for an earner: gross, fee, net and the rate used.
     * @return array{amount:int,fee:int,net:int,bps:int,base_bps:int,level_id:?int,level_name:?string,from_level:bool}
     */
    public static function withdrawalQuote(PDO $pdo, int $earnerId, int $amountKobo): array
    {
        $rate = self::withdrawalRateFor($pdo, $earnerId);
        $fee = self::withdrawal($amountKobo, $rate['bps']);
        return ['amount' => $amountKobo, 'fee' => $fee, 'net' => $amountKobo - $fee] + $rate;
    }

    /** "10%", "7.5%", "0.25%" from basis points. */
    public static function percent(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.') . '%';
    }

    /** Parse an admin percentage (0-50, up to two decimals) into basis points; null when invalid. */
    public static function parsePercent(string $percent): ?int
    {
        $percent = trim(str_replace('%', '', $percent));
        if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $percent)) return null;
        $bps = (int) round((float) $percent * 100);
        return $bps > self::MAX_WITHDRAWAL_BPS ? null : $bps;
    }

    /**
     * Reconciliation (part of Ledger::reconcile): the fee account equals the
     * fees of paid withdrawals; every paid withdrawal sent amount - fee
     * through clearing and booked its fee; every stored fee is the rounded-
     * down rate on the gross amount.
     * @return list<string>
     */
    public static function reconcileWithdrawals(PDO $pdo): array
    {
        try {
            $acct = $pdo->query("SELECT id, balance_kobo FROM ledger_accounts WHERE code = '" . self::WITHDRAWAL_FEE_ACCOUNT . "'")->fetch();
            if (!is_array($acct)) return []; // migration 0014 not applied yet
            $problems = [];
            $sum = (int) $pdo->query("SELECT COALESCE(SUM(fee_kobo), 0) FROM withdrawals WHERE status = 'paid'")->fetchColumn();
            if ((int) $acct['balance_kobo'] !== $sum) $problems[] = 'Account ' . self::WITHDRAWAL_FEE_ACCOUNT . " {$acct['balance_kobo']} != fees of paid withdrawals {$sum}";
            $clearing = Ledger::systemAccountId($pdo, Ledger::CLEARING);
            $sql = "SELECT w.id FROM withdrawals w
                    LEFT JOIN ledger_transactions t ON t.id = w.final_transaction_id
                    LEFT JOIN (SELECT transaction_id, SUM(CASE WHEN account_id = {$clearing} AND direction = 'credit' THEN amount_kobo ELSE 0 END) net,
                                      SUM(CASE WHEN account_id = " . (int) $acct['id'] . " AND direction = 'credit' THEN amount_kobo ELSE 0 END) fee
                               FROM ledger_entries GROUP BY transaction_id) e ON e.transaction_id = w.final_transaction_id
                    WHERE (w.status = 'paid' AND (t.id IS NULL OR t.idempotency_key <> CONCAT('wd-paid:', w.id) OR t.amount_kobo <> w.amount_kobo
                           OR COALESCE(e.net, 0) <> w.amount_kobo - w.fee_kobo OR COALESCE(e.fee, 0) <> w.fee_kobo))
                       OR w.fee_bps > " . self::MAX_WITHDRAWAL_BPS . " OR w.fee_kobo >= w.amount_kobo
                       OR w.fee_kobo <> FLOOR(w.amount_kobo * w.fee_bps / 10000) LIMIT 50";
            foreach ($pdo->query($sql) as $r) $problems[] = "Withdrawal #{$r['id']} fee does not match its rate or its ledger transaction";
            $sql = "SELECT w.id FROM withdrawals w LEFT JOIN ledger_transactions t ON t.id = w.final_transaction_id
                    WHERE w.status IN ('rejected', 'failed') AND (t.id IS NULL OR t.idempotency_key <> CONCAT('wd-release:', w.id) OR t.amount_kobo <> w.amount_kobo) LIMIT 50";
            foreach ($pdo->query($sql) as $r) $problems[] = "Withdrawal #{$r['id']} was not refunded in full (fee included)";
            return $problems;
        } catch (\PDOException $e) {
            return ['Withdrawal fee check failed: ' . get_class($e)];
        }
    }
}
