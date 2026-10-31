<?php
declare(strict_types=1);
namespace YoloTask\Money;

use PDO;
use RuntimeException;
use YoloTask\Security\Audit;
use YoloTask\Support\Pagination;

/**
 * Advertiser debts (Phase 5). When an earner wins an appeal after the
 * campaign's budget was used and the advertiser's wallet cannot cover the
 * price, the platform fronts the shortfall from the debit-normal
 * system:advertiser_receivable account and one debt row is opened. Admins
 * see open debts (Appeals → Advertiser debts, user list) and can collect a
 * debt from the advertiser's wallet (Dr wallet / Cr receivable, key
 * debt-collect:<id>) or write it off (Dr system:adjustments / Cr receivable,
 * key debt-writeoff:<id>). No automatic penalties. Reconciliation checks
 * receivable balance = sum of open debts.
 */
final class AdvertiserDebts
{
    /** Inside the caller's transaction (appeal overturn). */
    public static function open(PDO $pdo, int $advertiserId, int $campaignId, int $appealId, int $amount, int $txId): int
    {
        $pdo->prepare("INSERT INTO advertiser_debts (advertiser_user_id, campaign_id, appeal_id, amount_kobo, status, ledger_transaction_id) VALUES (:a, :c, :ap, :m, 'open', :t)")
            ->execute(['a' => $advertiserId, 'c' => $campaignId, 'ap' => $appealId, 'm' => $amount, 't' => $txId]);
        $id = (int) $pdo->lastInsertId();
        Audit::record($pdo, null, 'appeal.advertiser_debt_opened', 'advertiser_debt', $id);
        return $id;
    }

    /**
     * An overturn is reversed (inside the caller's transaction): tells the
     * caller where the fronted amount goes back to — 'receivable' (debt was
     * open, now cancelled), 'adjustments' (it was written off) or
     * 'advertiser' (the advertiser had already paid it).
     */
    public static function cancelForAppeal(PDO $pdo, int $appealId, int $adminId): string
    {
        $stmt = $pdo->prepare('SELECT id, status FROM advertiser_debts WHERE appeal_id = :a FOR UPDATE');
        $stmt->execute(['a' => $appealId]);
        $d = $stmt->fetch();
        if (!is_array($d)) return 'receivable';
        $to = match ((string) $d['status']) { 'open' => 'receivable', 'written_off' => 'adjustments', default => 'advertiser' };
        $pdo->prepare("UPDATE advertiser_debts SET status = 'cancelled', resolved_by_user_id = :u, resolved_at = UTC_TIMESTAMP(), note = 'Appeal overturn reversed' WHERE id = :id")
            ->execute(['u' => $adminId, 'id' => $d['id']]);
        Audit::record($pdo, $adminId, 'admin.advertiser_debt_cancelled', 'advertiser_debt', (int) $d['id']);
        return $to;
    }

    /** Admin: collect an open debt in full from the advertiser's available balance. */
    public static function collect(PDO $pdo, int $adminId, int $debtId): void
    {
        $d = self::find($pdo, $debtId);
        if ($d === null) throw new RuntimeException('Debt not found.');
        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, (int) $d['advertiser_user_id']);
            $row = self::lock($pdo, $debtId);
            if ((string) $row['status'] !== 'open') throw new RuntimeException('This debt is already settled.');
            $amount = (int) $row['amount_kobo'];
            if ($wallet['available_kobo'] < $amount) throw new RuntimeException('The advertiser\'s wallet has ' . Money::format($wallet['available_kobo']) . '; the debt is ' . Money::format($amount) . '. Try again after they top up.');
            $tx = Ledger::post($pdo, 'debt-collect:' . $debtId, 'debt_collect', 'Appeal payout repaid (debt #' . $debtId . ')', [
                [$wallet['available_account_id'], 'debit', $amount],
                [Ledger::systemAccountId($pdo, Ledger::ADVERTISER_RECEIVABLE), 'credit', $amount],
            ], 'advertiser_debt', $debtId, $adminId);
            $pdo->prepare("UPDATE advertiser_debts SET status = 'collected', resolve_transaction_id = :t, resolved_by_user_id = :u, resolved_at = UTC_TIMESTAMP(), note = 'Collected from wallet' WHERE id = :id")
                ->execute(['t' => $tx['id'], 'u' => $adminId, 'id' => $debtId]);
            Audit::record($pdo, $adminId, 'admin.advertiser_debt_collected', 'advertiser_debt', $debtId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Admin: write an open debt off (the platform absorbs it). */
    public static function writeOff(PDO $pdo, int $adminId, int $debtId, string $note): void
    {
        $note = trim($note);
        if (mb_strlen($note) < 5 || mb_strlen($note) > 255) throw new RuntimeException('Give a reason of 5 to 255 characters.');
        $pdo->beginTransaction();
        try {
            $row = self::lock($pdo, $debtId);
            if ((string) $row['status'] !== 'open') throw new RuntimeException('This debt is already settled.');
            $amount = (int) $row['amount_kobo'];
            $tx = Ledger::post($pdo, 'debt-writeoff:' . $debtId, 'debt_writeoff', 'Appeal debt #' . $debtId . ' written off: ' . $note, [
                [Ledger::systemAccountId($pdo, Ledger::ADJUSTMENTS), 'debit', $amount],
                [Ledger::systemAccountId($pdo, Ledger::ADVERTISER_RECEIVABLE), 'credit', $amount],
            ], 'advertiser_debt', $debtId, $adminId);
            $pdo->prepare("UPDATE advertiser_debts SET status = 'written_off', resolve_transaction_id = :t, resolved_by_user_id = :u, resolved_at = UTC_TIMESTAMP(), note = :n WHERE id = :id")
                ->execute(['t' => $tx['id'], 'u' => $adminId, 'n' => $note, 'id' => $debtId]);
            Audit::record($pdo, $adminId, 'admin.advertiser_debt_written_off', 'advertiser_debt', $debtId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT d.*, u.display_name advertiser_name, u.email advertiser_email, c.title campaign_title FROM advertiser_debts d JOIN users u ON u.id = d.advertiser_user_id JOIN campaigns c ON c.id = d.campaign_id WHERE d.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function listForAdmin(PDO $pdo, string $status, int $page): array
    {
        $where = 'WHERE 1 = 1';
        $args = [];
        if (in_array($status, ['open', 'collected', 'written_off', 'cancelled'], true)) { $where .= ' AND d.status = :st'; $args['st'] = $status; }
        return Pagination::query($pdo, "SELECT COUNT(*) FROM advertiser_debts d {$where}",
            "SELECT d.*, u.display_name advertiser_name, u.email advertiser_email, c.title campaign_title, COALESCE(w.available_kobo, 0) wallet_kobo FROM advertiser_debts d
             JOIN users u ON u.id = d.advertiser_user_id JOIN campaigns c ON c.id = d.campaign_id LEFT JOIN wallets w ON w.user_id = d.advertiser_user_id AND w.currency = 'NGN' {$where} ORDER BY d.id DESC", $args, $page, 20);
    }

    /** advertiser id => open debt total (kobo) for the given ids. */
    public static function openTotals(PDO $pdo, array $advertiserIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $advertiserIds)));
        if ($ids === []) return [];
        $stmt = $pdo->prepare("SELECT advertiser_user_id, SUM(amount_kobo) total FROM advertiser_debts WHERE status = 'open' AND advertiser_user_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY advertiser_user_id');
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $r) $out[(int) $r['advertiser_user_id']] = (int) $r['total'];
        return $out;
    }

    public static function openSummary(PDO $pdo): array
    {
        $r = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(amount_kobo), 0) total FROM advertiser_debts WHERE status = 'open'")->fetch() ?: [];
        return ['count' => (int) ($r['n'] ?? 0), 'total' => (int) ($r['total'] ?? 0)];
    }

    /** @return list<string> reconciliation problems */
    public static function reconcile(PDO $pdo): array
    {
        if (!(bool) $pdo->query("SHOW TABLES LIKE 'advertiser_debts'")->fetchColumn()) return [];
        $bal = $pdo->query("SELECT balance_kobo FROM ledger_accounts WHERE code = 'system:advertiser_receivable'")->fetchColumn();
        if ($bal === false) return ['System account system:advertiser_receivable is missing'];
        $open = (int) $pdo->query("SELECT COALESCE(SUM(amount_kobo), 0) FROM advertiser_debts WHERE status = 'open'")->fetchColumn();
        return (int) $bal !== $open ? ["Advertiser receivable {$bal} != open advertiser debts {$open}"] : [];
    }

    private static function lock(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM advertiser_debts WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new RuntimeException('Debt not found.');
        return $row;
    }
}
