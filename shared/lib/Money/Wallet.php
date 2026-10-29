<?php
declare(strict_types=1);
namespace YoloTask\Money;

use PDO;
use PDOException;
use RuntimeException;

/**
 * One NGN wallet per user, created lazily with its two ledger accounts
 * (available and held). Balances shown to users come from the cached
 * columns, which only Ledger::post changes.
 */
final class Wallet
{
    public const PAGE_SIZE = 20;

    /** Wallet row for a user, created on first use. */
    public static function forUser(PDO $pdo, int $userId): array
    {
        $row = self::find($pdo, $userId);
        if ($row !== null) return $row;
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            try {
                $pdo->prepare("INSERT INTO wallets (user_id, currency) VALUES (:u, 'NGN')")->execute(['u' => $userId]);
                $walletId = (int) $pdo->lastInsertId();
                $acct = $pdo->prepare('INSERT INTO ledger_accounts (code, name, kind, normal_side, wallet_id) VALUES (:c, :n, :k, \'credit\', :w)');
                $acct->execute(['c' => "wallet:{$walletId}:available", 'n' => "Wallet #{$walletId} available", 'k' => 'wallet_available', 'w' => $walletId]);
                $acct->execute(['c' => "wallet:{$walletId}:held", 'n' => "Wallet #{$walletId} held", 'k' => 'wallet_held', 'w' => $walletId]);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e; // another request created it first
            }
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $row = self::find($pdo, $userId);
        if ($row === null) throw new RuntimeException('Could not open the wallet.');
        return $row;
    }

    public static function find(PDO $pdo, int $userId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT w.id, w.user_id, w.currency, w.available_kobo, w.held_kobo, w.status,
                       (SELECT id FROM ledger_accounts WHERE wallet_id = w.id AND kind = 'wallet_available') AS available_account_id,
                       (SELECT id FROM ledger_accounts WHERE wallet_id = w.id AND kind = 'wallet_held') AS held_account_id
                FROM wallets w WHERE w.user_id = :u AND w.currency = 'NGN'" . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['u' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) return null;
        foreach (['id', 'user_id', 'available_kobo', 'held_kobo', 'available_account_id', 'held_account_id'] as $k) $row[$k] = (int) $row[$k];
        return $row;
    }

    /** Lock a user's wallet row for the rest of the caller's transaction. */
    public static function lock(PDO $pdo, int $userId): array
    {
        if (!$pdo->inTransaction()) throw new RuntimeException('Wallet locks need a transaction.');
        $row = self::find($pdo, $userId, true);
        if ($row === null) throw new RuntimeException('Wallet not found.');
        return $row;
    }

    /** Wallet history: entries on the available balance, newest first (holds show as a debit, releases as a credit). */
    public static function history(PDO $pdo, int $walletId, int $page = 1, int $perPage = self::PAGE_SIZE): array
    {
        $page = max(1, $page);
        $count = $pdo->prepare('SELECT COUNT(*) FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id WHERE a.wallet_id = :w AND a.kind = \'wallet_available\'');
        $count->execute(['w' => $walletId]);
        $total = (int) $count->fetchColumn();
        $stmt = $pdo->prepare('SELECT e.id, e.direction, e.amount_kobo, e.balance_after_kobo, e.created_at, a.kind, t.type, t.description, t.reference_type, t.reference_id
            FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id JOIN ledger_transactions t ON t.id = e.transaction_id
            WHERE a.wallet_id = :w AND a.kind = \'wallet_available\' ORDER BY e.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (($page - 1) * (int) $perPage));
        $stmt->execute(['w' => $walletId]);
        return ['rows' => $stmt->fetchAll() ?: [], 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    /** Admin manual adjustment: ledgered against system:adjustments, reason mandatory, audited. */
    public static function adjust(PDO $pdo, int $adminId, int $userId, string $direction, int $amountKobo, string $reason, string $idemKey): int
    {
        $reason = trim($reason);
        if (!in_array($direction, ['credit', 'debit'], true)) throw new RuntimeException('Choose credit or debit.');
        if ($amountKobo <= 0) throw new RuntimeException('Enter an amount greater than zero.');
        if ($amountKobo > 100000000) throw new RuntimeException('Adjustments above ₦1,000,000 must be split and reviewed.');
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 200) throw new RuntimeException('A reason of 5 to 200 characters is required.');
        if (!preg_match('/^[a-f0-9]{32}$/', $idemKey)) throw new RuntimeException('Your form expired. Please try again.');
        $role = $pdo->prepare('SELECT role FROM users WHERE id = :id');
        $role->execute(['id' => $userId]);
        if (!in_array((string) $role->fetchColumn(), ['earner', 'advertiser'], true)) throw new RuntimeException('Only earner and advertiser wallets can be adjusted.');
        self::forUser($pdo, $userId);
        $pdo->beginTransaction();
        try {
            $wallet = self::lock($pdo, $userId);
            $adj = Ledger::systemAccountId($pdo, Ledger::ADJUSTMENTS);
            $legs = $direction === 'credit'
                ? [[$adj, 'debit', $amountKobo], [$wallet['available_account_id'], 'credit', $amountKobo]]
                : [[$wallet['available_account_id'], 'debit', $amountKobo], [$adj, 'credit', $amountKobo]];
            $tx = Ledger::post($pdo, 'adj:' . $adminId . ':' . $idemKey, 'adjustment', ucfirst($direction) . ': ' . $reason, $legs, 'wallet', $wallet['id'], $adminId);
            if ($tx['created']) \YoloTask\Security\Audit::record($pdo, $adminId, $direction === 'credit' ? 'admin.wallet_credited' : 'admin.wallet_debited', 'wallet', $wallet['id']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $tx['id'];
    }

    /** Admin wallet directory: users with their balances. */
    public static function search(PDO $pdo, string $q, int $page, int $perPage = 25): array
    {
        $where = "WHERE u.role IN ('earner', 'advertiser')";
        $args = [];
        if ($q !== '') { $where .= ' AND (u.email LIKE :q OR u.display_name LIKE :q2)'; $like = '%' . addcslashes($q, '%_\\') . '%'; $args = ['q' => $like, 'q2' => $like]; }
        $count = $pdo->prepare("SELECT COUNT(*) FROM users u {$where}");
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $page = max(1, $page);
        $stmt = $pdo->prepare("SELECT u.id, u.display_name, u.email, u.role, u.status, w.id wallet_id, COALESCE(w.available_kobo, 0) available_kobo, COALESCE(w.held_kobo, 0) held_kobo
            FROM users u LEFT JOIN wallets w ON w.user_id = u.id AND w.currency = 'NGN' {$where} ORDER BY COALESCE(w.available_kobo, 0) + COALESCE(w.held_kobo, 0) DESC, u.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage));
        $stmt->execute($args);
        return ['rows' => $stmt->fetchAll() ?: [], 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    /** Platform totals for the admin overview. */
    public static function totals(PDO $pdo): array
    {
        $w = $pdo->query('SELECT COALESCE(SUM(available_kobo), 0) a, COALESCE(SUM(held_kobo), 0) h, COUNT(*) n FROM wallets')->fetch();
        $sys = [];
        foreach ($pdo->query("SELECT code, balance_kobo FROM ledger_accounts WHERE kind = 'system'") as $r) $sys[(string) $r['code']] = (int) $r['balance_kobo'];
        return ['available' => (int) $w['a'], 'held' => (int) $w['h'], 'wallets' => (int) $w['n'], 'clearing' => $sys[Ledger::CLEARING] ?? 0, 'adjustments' => $sys[Ledger::ADJUSTMENTS] ?? 0];
    }

    /** Label for a ledger entry from the wallet owner's point of view. */
    public static function entryLabel(array $e): string
    {
        $held = (string) $e['kind'] === 'wallet_held';
        return match ((string) $e['type']) {
            'topup' => 'Wallet top-up',
            'topup_review' => 'Wallet top-up (reviewed)',
            'withdrawal_hold' => $held ? 'Withdrawal on hold' : 'Withdrawal requested',
            'withdrawal_release' => $held ? 'Hold released' : 'Withdrawal returned',
            'withdrawal_paid' => 'Withdrawal paid out',
            'adjustment' => 'Adjustment by YOLOTASK',
            default => 'Wallet update',
        };
    }
}
