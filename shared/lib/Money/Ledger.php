<?php
declare(strict_types=1);
namespace YoloTask\Money;

use PDO;
use RuntimeException;
use YoloTask\Core\Logger;

/**
 * The double-entry ledger. This is the only code that changes balances.
 *
 * post() must run inside a caller's database transaction. It refuses an
 * unbalanced transaction, locks every touched account row (in id order, so
 * concurrent postings cannot deadlock on each other), refuses to take a
 * wallet account below zero, writes the entries, and updates the cached
 * balances on ledger_accounts and wallets. A repeated idempotency key
 * returns the original transaction without posting anything.
 */
final class Ledger
{
    public const CLEARING = 'system:afrstar_clearing';
    public const ADJUSTMENTS = 'system:adjustments';

    /**
     * @param list<array{0:int,1:string,2:int}> $legs [accountId, 'debit'|'credit', amountKobo]
     * @return array{id:int,created:bool}
     */
    public static function post(PDO $pdo, string $idempotencyKey, string $type, string $description, array $legs, ?string $refType = null, ?int $refId = null, ?int $actorId = null): array
    {
        if (!$pdo->inTransaction()) throw new RuntimeException('Ledger postings must run inside a database transaction.');
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) throw new RuntimeException('A ledger idempotency key is required.');

        $existing = $pdo->prepare('SELECT id FROM ledger_transactions WHERE idempotency_key = :k');
        $existing->execute(['k' => $idempotencyKey]);
        $found = $existing->fetchColumn();
        if ($found !== false) return ['id' => (int) $found, 'created' => false];

        $debits = 0;
        $credits = 0;
        $byAccount = [];
        foreach ($legs as $leg) {
            [$accountId, $direction, $amount] = $leg;
            if (!is_int($amount) || $amount <= 0) throw new RuntimeException('Ledger amounts must be positive integers (kobo).');
            if ($direction === 'debit') $debits += $amount;
            elseif ($direction === 'credit') $credits += $amount;
            else throw new RuntimeException('Ledger direction must be debit or credit.');
            $byAccount[(int) $accountId] = true;
        }
        if (count($legs) < 2 || $debits !== $credits) throw new RuntimeException('Ledger transaction is not balanced.');

        $ids = array_keys($byAccount);
        sort($ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $lock = $pdo->prepare("SELECT id, kind, normal_side, wallet_id, balance_kobo, allow_negative FROM ledger_accounts WHERE id IN ({$placeholders}) ORDER BY id FOR UPDATE");
        $lock->execute($ids);
        $accounts = [];
        foreach ($lock->fetchAll() as $row) $accounts[(int) $row['id']] = $row;
        if (count($accounts) !== count($ids)) throw new RuntimeException('Unknown ledger account.');

        $pdo->prepare('INSERT INTO ledger_transactions (idempotency_key, type, description, reference_type, reference_id, amount_kobo, created_by_user_id) VALUES (:k, :t, :d, :rt, :ri, :a, :u)')
            ->execute(['k' => $idempotencyKey, 't' => $type, 'd' => mb_substr($description, 0, 255), 'rt' => $refType, 'ri' => $refId, 'a' => $debits, 'u' => $actorId]);
        $txId = (int) $pdo->lastInsertId();

        $balances = [];
        foreach ($accounts as $id => $row) $balances[$id] = (int) $row['balance_kobo'];
        $entry = $pdo->prepare('INSERT INTO ledger_entries (transaction_id, account_id, direction, amount_kobo, balance_after_kobo) VALUES (:t, :a, :d, :m, :b)');
        foreach ($legs as [$accountId, $direction, $amount]) {
            $acct = $accounts[(int) $accountId];
            $delta = $direction === (string) $acct['normal_side'] ? $amount : -$amount;
            $balances[(int) $accountId] += $delta;
            $entry->execute(['t' => $txId, 'a' => (int) $accountId, 'd' => $direction, 'm' => $amount, 'b' => $balances[(int) $accountId]]);
        }

        $updAccount = $pdo->prepare('UPDATE ledger_accounts SET balance_kobo = :b WHERE id = :id');
        $updAvailable = $pdo->prepare('UPDATE wallets SET available_kobo = :b WHERE id = :w');
        $updHeld = $pdo->prepare('UPDATE wallets SET held_kobo = :b WHERE id = :w');
        foreach ($balances as $id => $balance) {
            $acct = $accounts[$id];
            if ($balance < 0 && !(int) $acct['allow_negative']) throw new RuntimeException('Insufficient balance.');
            $updAccount->execute(['b' => $balance, 'id' => $id]);
            if ((string) $acct['kind'] === 'wallet_available') $updAvailable->execute(['b' => $balance, 'w' => (int) $acct['wallet_id']]);
            if ((string) $acct['kind'] === 'wallet_held') $updHeld->execute(['b' => $balance, 'w' => (int) $acct['wallet_id']]);
        }
        return ['id' => $txId, 'created' => true];
    }

    public static function systemAccountId(PDO $pdo, string $code): int
    {
        static $cache = [];
        if (isset($cache[$code])) return $cache[$code];
        $stmt = $pdo->prepare('SELECT id FROM ledger_accounts WHERE code = :c');
        $stmt->execute(['c' => $code]);
        $id = $stmt->fetchColumn();
        if ($id === false) throw new RuntimeException('System ledger account ' . $code . ' is missing.');
        return $cache[$code] = (int) $id;
    }

    /**
     * Full consistency check, used by the daily cron and the admin page.
     * @return list<string> human-readable problems; empty when balanced.
     */
    public static function reconcile(PDO $pdo): array
    {
        $problems = [];
        $totals = $pdo->query("SELECT COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount_kobo ELSE 0 END), 0) d, COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount_kobo ELSE 0 END), 0) c FROM ledger_entries")->fetch();
        if ((string) $totals['d'] !== (string) $totals['c']) $problems[] = "Total debits {$totals['d']} != total credits {$totals['c']}";

        foreach ($pdo->query("SELECT transaction_id, SUM(CASE WHEN direction = 'debit' THEN amount_kobo ELSE -CAST(amount_kobo AS SIGNED) END) diff FROM ledger_entries GROUP BY transaction_id HAVING diff <> 0 LIMIT 50") as $r) {
            $problems[] = "Transaction #{$r['transaction_id']} is unbalanced by {$r['diff']} kobo";
        }
        foreach ($pdo->query("SELECT t.id FROM ledger_transactions t LEFT JOIN ledger_entries e ON e.transaction_id = t.id GROUP BY t.id HAVING COUNT(e.id) < 2 LIMIT 50") as $r) {
            $problems[] = "Transaction #{$r['id']} has fewer than two entries";
        }

        $sql = "SELECT a.id, a.code, a.balance_kobo, COALESCE(SUM(CASE WHEN e.direction = a.normal_side THEN CAST(e.amount_kobo AS SIGNED) ELSE -CAST(e.amount_kobo AS SIGNED) END), 0) computed
                FROM ledger_accounts a LEFT JOIN ledger_entries e ON e.account_id = a.id GROUP BY a.id, a.code, a.balance_kobo, a.normal_side HAVING computed <> a.balance_kobo LIMIT 50";
        foreach ($pdo->query($sql) as $r) {
            $problems[] = "Account {$r['code']} cached {$r['balance_kobo']} != entries {$r['computed']}";
        }

        $sql = "SELECT w.id, w.available_kobo, w.held_kobo, av.balance_kobo av_bal, hd.balance_kobo hd_bal
                FROM wallets w
                LEFT JOIN ledger_accounts av ON av.wallet_id = w.id AND av.kind = 'wallet_available'
                LEFT JOIN ledger_accounts hd ON hd.wallet_id = w.id AND hd.kind = 'wallet_held'
                WHERE av.id IS NULL OR hd.id IS NULL OR w.available_kobo <> av.balance_kobo OR w.held_kobo <> hd.balance_kobo OR w.available_kobo < 0 OR w.held_kobo < 0 LIMIT 50";
        foreach ($pdo->query($sql) as $r) {
            $problems[] = "Wallet #{$r['id']} cache (available {$r['available_kobo']}, held {$r['held_kobo']}) does not match its ledger accounts";
        }

        // Money on hold must equal open withdrawals.
        $held = (int) $pdo->query('SELECT COALESCE(SUM(held_kobo), 0) FROM wallets')->fetchColumn();
        $open = (int) $pdo->query("SELECT COALESCE(SUM(amount_kobo), 0) FROM withdrawals WHERE status IN ('pending_review', 'processing')")->fetchColumn();
        if ($held !== $open) $problems[] = "Held balances {$held} != open withdrawals {$open}";

        if ($problems !== []) Logger::error('ledger.reconciliation_mismatch', ['count' => count($problems), 'first' => $problems[0]]);
        return $problems;
    }
}
