<?php
declare(strict_types=1);
namespace YoloTask\Payments;

use PDO;
use RuntimeException;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Messaging\Mailer;
use YoloTask\Money\Fees;
use YoloTask\Money\Ledger;
use YoloTask\Money\Money;
use YoloTask\Money\Wallet;
use YoloTask\Security\Audit;
use YoloTask\Security\Crypto;

/**
 * Earner withdrawals: request (available -> held, pending_review), admin
 * approve (AfrStar payout, processing) or reject (hold released). The payout
 * callback finishes it (CallbackProcessor). Lock order everywhere: wallet
 * row first, then the withdrawal row.
 */
final class Withdrawals
{
    public const OPEN = ['pending_review', 'processing'];

    public static function limits(PDO $pdo): array
    {
        return ['min' => Settings::int($pdo, 'money.withdraw_min_kobo'), 'max' => Settings::int($pdo, 'money.withdraw_max_kobo'),
            'daily_count' => max(1, Settings::int($pdo, 'money.withdraw_daily_count')), 'daily_max' => Settings::int($pdo, 'money.withdraw_daily_max_kobo')];
    }

    /** Withdrawals in the last 24 hours that were not rejected (count and amount). */
    public static function velocity(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(amount_kobo), 0) a FROM withdrawals WHERE user_id = :u AND status <> 'rejected' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)");
        $stmt->execute(['u' => $userId]);
        $r = $stmt->fetch() ?: ['n' => 0, 'a' => 0];
        return ['count' => (int) $r['n'], 'amount' => (int) $r['a']];
    }

    private static function lagos(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Africa/Lagos'))->format('j M, g:i A') . ' WAT';
    }

    /** Decrypt the account number on rows read from withdrawals (strict: used before a payout). */
    private static function revealRow(array $row): array
    {
        if (array_key_exists('account_number', $row)) $row['account_number'] = Crypto::reveal((string) $row['account_number']);
        return $row;
    }

    /**
     * Display-only rows: a number this host cannot decrypt (legacy enc1: without
     * ext-sodium) shows as ••••last4 instead of breaking the whole page.
     */
    private static function displayRow(array $row): array
    {
        if (array_key_exists('account_number', $row)) {
            $fallback = '••••' . (string) ($row['account_last4'] ?? '');
            $row['account_number'] = Crypto::revealOr((string) $row['account_number'], $fallback);
        }
        return $row;
    }

    /** Request a withdrawal; a repeated $idemKey returns the original request id. */
    public static function request(PDO $pdo, array $user, int $amountKobo, string $idemKey): int
    {
        if ((string) $user['role'] !== 'earner') throw new RuntimeException('Only earner accounts can withdraw.');
        if (empty($user['email_verified_at'])) throw new RuntimeException('Confirm your email before withdrawing.');
        if (!AfrstarClient::isConfigured()) throw new RuntimeException('Payments are not available yet.');
        if (!preg_match('/^[a-f0-9]{32}$/', $idemKey)) throw new RuntimeException('Your form expired. Please try again.');
        $userId = (int) $user['id'];
        $limits = self::limits($pdo);
        if ($amountKobo < $limits['min']) throw new RuntimeException('The minimum withdrawal is ' . Money::format($limits['min']) . '.');
        if ($amountKobo > $limits['max']) throw new RuntimeException('The maximum withdrawal is ' . Money::format($limits['max']) . '.');
        $bank = BankAccounts::get($pdo, $userId);
        if ($bank === null) throw new RuntimeException('Add your bank account before withdrawing.');
        $cooldown = BankAccounts::cooldownUntil($pdo, $bank);
        if ($cooldown !== null) throw new RuntimeException('For your safety, withdrawals open again ' . self::lagos($cooldown) . ' because your bank account was changed recently.');
        Wallet::forUser($pdo, $userId);

        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, $userId);
            $dup = $pdo->prepare('SELECT id FROM withdrawals WHERE user_id = :u AND idempotency_key = :k');
            $dup->execute(['u' => $userId, 'k' => $idemKey]);
            $existing = $dup->fetchColumn();
            if ($existing !== false) { $pdo->commit(); return (int) $existing; }
            $open = $pdo->prepare("SELECT COUNT(*) FROM withdrawals WHERE user_id = :u AND status IN ('pending_review', 'processing')");
            $open->execute(['u' => $userId]);
            if ((int) $open->fetchColumn() > 0) throw new RuntimeException('You already have a withdrawal in progress. Wait for it to finish before requesting another.');
            if ($wallet['status'] !== 'active') throw new RuntimeException('Your wallet is on hold. Contact support.');
            if ($wallet['available_kobo'] < $amountKobo) throw new RuntimeException('Your available balance is ' . Money::format($wallet['available_kobo']) . '.');
            $velocity = self::velocity($pdo, $userId);
            if ($velocity['count'] >= $limits['daily_count']) throw new RuntimeException('You can make ' . $limits['daily_count'] . ' withdrawal' . ($limits['daily_count'] === 1 ? '' : 's') . ' in 24 hours. Please try again later.');
            if ($velocity['amount'] + $amountKobo > $limits['daily_max']) {
                throw new RuntimeException('Withdrawals are limited to ' . Money::format($limits['daily_max']) . ' in 24 hours. You can withdraw up to ' . Money::format(max(0, $limits['daily_max'] - $velocity['amount'])) . ' now.');
            }

            $pdo->prepare('INSERT INTO withdrawals (user_id, wallet_id, idempotency_key, amount_kobo, fee_kobo, bank_code, bank_name, account_number, account_last4, account_name) VALUES (:u, :w, :k, :a, :f, :bc, :bn, :an, :l4, :am)')
                ->execute(['u' => $userId, 'w' => $wallet['id'], 'k' => $idemKey, 'a' => $amountKobo, 'f' => Fees::withdrawal($amountKobo), 'bc' => $bank['bank_code'], 'bn' => $bank['bank_name'], 'an' => Crypto::seal($bank['account_number']), 'l4' => substr($bank['account_number'], -4), 'am' => $bank['account_name']]);
            $id = (int) $pdo->lastInsertId();
            $tx = Ledger::post($pdo, 'wd-hold:' . $id, 'withdrawal_hold', 'Withdrawal #' . $id . ' requested', [
                [$wallet['available_account_id'], 'debit', $amountKobo],
                [$wallet['held_account_id'], 'credit', $amountKobo],
            ], 'withdrawal', $id, $userId);
            $pdo->prepare('UPDATE withdrawals SET hold_transaction_id = :t WHERE id = :id')->execute(['t' => $tx['id'], 'id' => $id]);
            Audit::record($pdo, $userId, 'wallet.withdrawal_requested', 'withdrawal', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    /** Approve and send to AfrStar. Returns a message for the admin. */
    public static function approve(PDO $pdo, int $adminId, int $withdrawalId): string
    {
        if (!AfrstarClient::isConfigured()) throw new RuntimeException('Payments are not available yet.');
        $userId = self::ownerOf($pdo, $withdrawalId);
        $pdo->beginTransaction();
        try {
            Wallet::lock($pdo, $userId);
            $w = self::lockRow($pdo, $withdrawalId);
            if ((string) $w['status'] !== 'pending_review') throw new RuntimeException('This withdrawal is no longer waiting for review.');
            $attempt = (int) $w['attempts'] + 1;
            $orderSn = 'YTW' . str_pad((string) $withdrawalId, 8, '0', STR_PAD_LEFT) . 'A' . $attempt;
            $pdo->prepare("UPDATE withdrawals SET status = 'processing', attempts = :a, order_sn = :sn, reviewed_by_user_id = :r, reviewed_at = UTC_TIMESTAMP(), provider_message = NULL WHERE id = :id")
                ->execute(['a' => $attempt, 'sn' => $orderSn, 'r' => $adminId, 'id' => $withdrawalId]);
            Audit::record($pdo, $adminId, 'admin.withdrawal_approved', 'withdrawal', $withdrawalId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        // Committed as processing before the API call, so a double click can never pay twice.
        try {
            $res = AfrstarClient::payout($orderSn, (int) $w['amount_kobo'] - (int) $w['fee_kobo'], (string) $w['account_name'], (string) $w['account_number'], (string) $w['bank_code'], 'YOLOTASK withdrawal');
        } catch (AfrstarException $e) {
            if ($e->definitive) {
                $pdo->prepare("UPDATE withdrawals SET status = 'pending_review', order_sn = NULL, provider_message = :m WHERE id = :id AND status = 'processing' AND order_sn = :sn")
                    ->execute(['m' => mb_substr($e->getMessage(), 0, 255), 'id' => $withdrawalId, 'sn' => $orderSn]);
                throw new RuntimeException($e->getMessage() . ' The withdrawal is back in the review list; nothing was sent.');
            }
            $pdo->prepare('UPDATE withdrawals SET provider_message = :m WHERE id = :id')
                ->execute(['m' => 'Outcome unknown when sending; waiting for the AfrStar callback.', 'id' => $withdrawalId]);
            Logger::error('payments.payout_outcome_unknown', ['withdrawal_id' => $withdrawalId]);
            return 'AfrStar did not answer clearly. The withdrawal stays in processing until AfrStar confirms by callback; check the AfrStar portal for ' . $orderSn . '.';
        }
        $pdo->prepare('UPDATE withdrawals SET platform_osn = COALESCE(platform_osn, :o), provider_message = :m WHERE id = :id')
            ->execute(['o' => $res['platform_osn'] !== '' ? $res['platform_osn'] : null, 'm' => 'Sent to AfrStar (' . ($res['status'] ?: 'init') . ')', 'id' => $withdrawalId]);
        return 'Withdrawal approved and sent to AfrStar. It completes when AfrStar confirms.';
    }

    public static function reject(PDO $pdo, int $adminId, int $withdrawalId, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) throw new RuntimeException('Give the earner a reason (3 to 255 characters).');
        $userId = self::ownerOf($pdo, $withdrawalId);
        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, $userId);
            $w = self::lockRow($pdo, $withdrawalId);
            if ((string) $w['status'] !== 'pending_review') throw new RuntimeException('Only withdrawals waiting for review can be rejected.');
            $tx = Ledger::post($pdo, 'wd-release:' . $withdrawalId, 'withdrawal_release', 'Withdrawal #' . $withdrawalId . ' rejected; funds returned', [
                [$wallet['held_account_id'], 'debit', (int) $w['amount_kobo']],
                [$wallet['available_account_id'], 'credit', (int) $w['amount_kobo']],
            ], 'withdrawal', $withdrawalId, $adminId);
            $pdo->prepare("UPDATE withdrawals SET status = 'rejected', reject_reason = :r, reviewed_by_user_id = :a, reviewed_at = UTC_TIMESTAMP(), final_transaction_id = :t, completed_at = UTC_TIMESTAMP() WHERE id = :id")
                ->execute(['r' => $reason, 'a' => $adminId, 't' => $tx['id'], 'id' => $withdrawalId]);
            Audit::record($pdo, $adminId, 'admin.withdrawal_rejected', 'withdrawal', $withdrawalId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $email = $pdo->prepare('SELECT email FROM users WHERE id = :id');
        $email->execute(['id' => $userId]);
        Mailer::send((string) $email->fetchColumn(), 'Your YOLOTASK withdrawal was declined', 'Withdrawal declined',
            ['Your withdrawal request of ' . Money::format((int) $w['amount_kobo']) . ' was declined.', 'Reason: ' . $reason, 'The full amount is back in your YOLOTASK wallet.'],
            null, null, 'Questions? Reply to YOLOTASK support from your account email.');
    }

    public static function forUser(PDO $pdo, int $userId, int $limit = 10): array
    {
        $stmt = $pdo->prepare('SELECT id, amount_kobo, fee_kobo, bank_name, account_number, account_last4, status, reject_reason, created_at, completed_at FROM withdrawals WHERE user_id = :u ORDER BY id DESC LIMIT ' . (int) $limit);
        $stmt->execute(['u' => $userId]);
        return array_map([self::class, 'displayRow'], $stmt->fetchAll() ?: []);
    }

    public static function openForUser(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id = :u AND status IN ('pending_review', 'processing') ORDER BY id DESC LIMIT 1");
        $stmt->execute(['u' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? self::displayRow($row) : null;
    }

    public static function list(PDO $pdo, string $status, int $page, int $perPage = 25): array
    {
        $args = [];
        $where = '';
        if (in_array($status, ['pending_review', 'processing', 'paid', 'failed', 'rejected'], true)) { $where = ' WHERE w.status = :s'; $args['s'] = $status; }
        $count = $pdo->prepare('SELECT COUNT(*) FROM withdrawals w' . $where);
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $page = max(1, $page);
        $stmt = $pdo->prepare('SELECT w.*, u.email, u.display_name, wl.available_kobo FROM withdrawals w JOIN users u ON u.id = w.user_id JOIN wallets wl ON wl.id = w.wallet_id' . $where . ' ORDER BY w.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        $stmt->execute($args);
        return ['rows' => array_map([self::class, 'displayRow'], $stmt->fetchAll() ?: []), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public static function counts(PDO $pdo): array
    {
        $out = ['pending_review' => 0, 'processing' => 0];
        foreach ($pdo->query("SELECT status, COUNT(*) c FROM withdrawals WHERE status IN ('pending_review', 'processing') GROUP BY status") as $r) $out[(string) $r['status']] = (int) $r['c'];
        return $out;
    }

    private static function ownerOf(PDO $pdo, int $withdrawalId): int
    {
        $stmt = $pdo->prepare('SELECT user_id FROM withdrawals WHERE id = :id');
        $stmt->execute(['id' => $withdrawalId]);
        $uid = $stmt->fetchColumn();
        if ($uid === false) throw new RuntimeException('That withdrawal does not exist.');
        return (int) $uid;
    }

    private static function lockRow(PDO $pdo, int $withdrawalId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM withdrawals WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $withdrawalId]);
        return self::revealRow($stmt->fetch());
    }
}
