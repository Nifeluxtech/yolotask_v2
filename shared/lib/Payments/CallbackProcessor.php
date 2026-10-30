<?php
declare(strict_types=1);
namespace YoloTask\Payments;

use PDO;
use YoloTask\Core\Logger;
use YoloTask\Messaging\Mailer;
use YoloTask\Money\Ledger;
use YoloTask\Money\Money;
use YoloTask\Money\Wallet;

/**
 * Applies AfrStar collection and payout callbacks.
 *
 * Every callback is logged raw first. A result is acknowledged with HTTP 200
 * and the exact body SUCCESS only after it is persisted; anything we cannot
 * verify or persist gets a non-200 so AfrStar retries. Replays are no-ops.
 *
 * Returns ['status' => int, 'body' => string, 'after' => list<callable>];
 * `after` holds slow work (emails) to run once the response is sent.
 */
final class CallbackProcessor
{
    private const MAX_BODY = 65536;

    public static function collection(PDO $pdo, string $raw, array $post = [], string $ip = '', ?int $logId = null): array
    {
        $logId ??= self::log($pdo, 'collection', $raw, $ip);
        $payload = CallbackVerifier::parse($raw, $post);
        $orderSn = substr(trim((string) ($payload['order_sn'] ?? '')), 0, 64);
        $osn = substr(trim((string) ($payload['platform_osn'] ?? '')), 0, 64);
        $status = trim((string) ($payload['status'] ?? ''));
        self::note($pdo, $logId, ['order_sn' => $orderSn ?: null, 'platform_osn' => $osn ?: null, 'provider_status' => substr($status, 0, 10) ?: null]);

        $pre = self::precheck($pdo, $logId, $ip);
        if ($pre !== null) return $pre;
        $config = AfrstarClient::config();
        if ($orderSn === '' || $osn === '' || $status === '') return self::finish($pdo, $logId, 400, 'malformed', null);

        $stmt = $pdo->prepare('SELECT id, user_id, amount_kobo, status, platform_osn FROM payment_orders WHERE order_sn = :sn');
        $stmt->execute(['sn' => $orderSn]);
        $order = $stmt->fetch();
        if (!is_array($order)) {
            if (CallbackVerifier::merchantMatches($payload, $config)) {
                self::openReview($pdo, 'unmatched:collection:' . $orderSn . ':' . $osn, 'unmatched_callback', null, null, $logId, $orderSn, $osn, null,
                    CallbackVerifier::amount($payload['amount_paid'] ?? ($payload['amount'] ?? null)), 'Collection callback for an order we do not have (signature cannot be checked).');
            }
            return self::finish($pdo, $logId, 404, 'unknown_order', null);
        }
        $orderAmount = (int) $order['amount_kobo'];
        if (!CallbackVerifier::verifyCollection($payload, $config, $orderAmount)) return self::finish($pdo, $logId, 400, 'bad_signature', false);
        if ($order['platform_osn'] !== null && !hash_equals((string) $order['platform_osn'], $osn)) return self::finish($pdo, $logId, 400, 'osn_mismatch', true);

        if ($status !== '1') {
            $pdo->prepare('UPDATE payment_orders SET provider_message = :m WHERE id = :id')
                ->execute(['m' => mb_substr('Status ' . $status . ': ' . (string) ($payload['message'] ?? ''), 0, 255), 'id' => (int) $order['id']]);
            return self::finish($pdo, $logId, 200, 'ignored_status', true);
        }

        $paid = CallbackVerifier::amount($payload['amount_paid'] ?? null) ?? CallbackVerifier::amount($payload['amount'] ?? null);
        $after = [];
        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, (int) $order['user_id']);
            $lock = $pdo->prepare('SELECT * FROM payment_orders WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => (int) $order['id']]);
            $order = $lock->fetch();
            if (in_array((string) $order['status'], ['paid', 'review', 'rejected'], true)) {
                $pdo->commit();
                return self::finish($pdo, $logId, 200, 'duplicate', true);
            }
            $ref = mb_substr((string) (($payload['utr'] ?? '') ?: ($payload['session_id'] ?? '')), 0, 120) ?: null;
            if ($paid !== null && $paid === $orderAmount) {
                $tx = Ledger::post($pdo, 'payin:' . $order['order_sn'], 'topup', 'AfrStar top-up ' . $order['order_sn'], [
                    [Ledger::systemAccountId($pdo, Ledger::CLEARING), 'debit', $orderAmount],
                    [$wallet['available_account_id'], 'credit', $orderAmount],
                ], 'payment_order', (int) $order['id']);
                $pdo->prepare("UPDATE payment_orders SET status = 'paid', amount_paid_kobo = :p, credited_kobo = :c, platform_osn = COALESCE(platform_osn, :o), provider_reference = :r, ledger_transaction_id = :t, paid_at = UTC_TIMESTAMP(), provider_message = :m WHERE id = :id")
                    ->execute(['p' => $paid, 'c' => $orderAmount, 'o' => $osn, 'r' => $ref, 't' => $tx['id'], 'm' => mb_substr((string) ($payload['message'] ?? 'Paid'), 0, 255), 'id' => (int) $order['id']]);
                $outcome = 'credited';
                $after[] = self::mailLater($pdo, (int) $order['user_id'], 'Your YOLOTASK wallet was funded', 'Top-up received',
                    ['We received your payment of ' . Money::format($orderAmount) . ' and added it to your YOLOTASK wallet.', 'Reference: ' . $order['order_sn']]);
            } else {
                $pdo->prepare("UPDATE payment_orders SET status = 'review', amount_paid_kobo = :p, platform_osn = COALESCE(platform_osn, :o), provider_reference = :r, provider_message = :m WHERE id = :id")
                    ->execute(['p' => $paid, 'o' => $osn, 'r' => $ref, 'm' => 'Paid amount differs from the order; held for review', 'id' => (int) $order['id']]);
                self::openReview($pdo, 'mismatch:' . $order['id'], 'amount_mismatch', (int) $order['id'], null, $logId, (string) $order['order_sn'], $osn, $orderAmount, $paid,
                    'Customer paid ' . ($paid === null ? 'an unreadable amount' : Money::format($paid)) . ' for a ' . Money::format($orderAmount) . ' order.');
                $outcome = 'review';
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('payments.collection_callback_failed', ['callback_id' => $logId, 'exception' => get_class($e), 'message' => $e->getMessage()]);
            return self::finish($pdo, $logId, 500, 'error', true);
        }
        return self::finish($pdo, $logId, 200, $outcome, true, $after);
    }

    public static function payout(PDO $pdo, string $raw, array $post = [], string $ip = '', ?int $logId = null): array
    {
        $logId ??= self::log($pdo, 'payout', $raw, $ip);
        $payload = CallbackVerifier::parse($raw, $post);
        $orderSn = substr(trim((string) ($payload['order_sn'] ?? '')), 0, 64);
        $osn = substr(trim((string) ($payload['platform_osn'] ?? '')), 0, 64);
        $status = trim((string) ($payload['status'] ?? ''));
        self::note($pdo, $logId, ['order_sn' => $orderSn ?: null, 'platform_osn' => $osn ?: null, 'provider_status' => substr($status, 0, 10) ?: null]);

        $pre = self::precheck($pdo, $logId, $ip);
        if ($pre !== null) return $pre;
        $config = AfrstarClient::config();
        if ($orderSn === '' || $osn === '' || $status === '') return self::finish($pdo, $logId, 400, 'malformed', null);

        $stmt = $pdo->prepare('SELECT id, user_id, amount_kobo, fee_kobo, status, platform_osn FROM withdrawals WHERE order_sn = :sn');
        $stmt->execute(['sn' => $orderSn]);
        $w = $stmt->fetch();
        if (!is_array($w)) {
            if (CallbackVerifier::merchantMatches($payload, $config)) {
                self::openReview($pdo, 'unmatched:payout:' . $orderSn . ':' . $osn, 'unmatched_callback', null, null, $logId, $orderSn, $osn, null,
                    CallbackVerifier::amount($payload['amount'] ?? null), 'Payout callback for a withdrawal we do not have.');
            }
            return self::finish($pdo, $logId, 404, 'unknown_order', null);
        }
        $payoutAmount = (int) $w['amount_kobo'] - (int) $w['fee_kobo'];
        if (!CallbackVerifier::verifyPayout($payload, $config, $payoutAmount)) return self::finish($pdo, $logId, 400, 'bad_signature', false);
        if (CallbackVerifier::amount($payload['amount'] ?? null) !== $payoutAmount) return self::finish($pdo, $logId, 400, 'amount_mismatch', true);
        if ($w['platform_osn'] !== null && !hash_equals((string) $w['platform_osn'], $osn)) return self::finish($pdo, $logId, 400, 'osn_mismatch', true);
        if ($status !== '1' && $status !== '9') return self::finish($pdo, $logId, 200, 'ignored_status', true);

        $after = [];
        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, (int) $w['user_id']);
            $lock = $pdo->prepare('SELECT * FROM withdrawals WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => (int) $w['id']]);
            $w = $lock->fetch();
            $ref = mb_substr((string) ($payload['utr'] ?? ''), 0, 120) ?: null;
            $msg = mb_substr((string) ($payload['message'] ?? ''), 0, 255) ?: null;
            $current = (string) $w['status'];
            if (($current === 'paid' && $status === '1') || ($current === 'failed' && $status === '9')) {
                $pdo->commit();
                return self::finish($pdo, $logId, 200, 'duplicate', true);
            }
            if ($current !== 'processing') {
                self::openReview($pdo, 'conflict:' . $w['id'] . ':' . $status, 'payout_conflict', null, (int) $w['id'], $logId, $orderSn, $osn, $payoutAmount, $payoutAmount,
                    'AfrStar reported status ' . $status . ' for a withdrawal that is ' . $current . '.');
                $pdo->commit();
                return self::finish($pdo, $logId, 200, 'conflict', true);
            }
            $amount = (int) $w['amount_kobo']; // the held amount (fees are zero today; a fee would get its own leg)
            if ($status === '1') {
                $tx = Ledger::post($pdo, 'wd-paid:' . $w['id'], 'withdrawal_paid', 'Withdrawal ' . $orderSn . ' paid by AfrStar', [
                    [$wallet['held_account_id'], 'debit', $amount],
                    [Ledger::systemAccountId($pdo, Ledger::CLEARING), 'credit', $amount],
                ], 'withdrawal', (int) $w['id']);
                $pdo->prepare("UPDATE withdrawals SET status = 'paid', platform_osn = COALESCE(platform_osn, :o), provider_reference = :r, provider_message = :m, final_transaction_id = :t, completed_at = UTC_TIMESTAMP() WHERE id = :id")
                    ->execute(['o' => $osn, 'r' => $ref, 'm' => $msg, 't' => $tx['id'], 'id' => (int) $w['id']]);
                $after[] = self::mailLater($pdo, (int) $w['user_id'], 'Your YOLOTASK withdrawal was paid', 'Withdrawal paid',
                    [Money::format($payoutAmount) . ' was sent to ' . $w['bank_name'] . ' ' . self::mask(\YoloTask\Security\Crypto::reveal((string) $w['account_number'])) . '.', 'Reference: ' . $orderSn]);
                $outcome = 'paid';
            } else {
                $tx = Ledger::post($pdo, 'wd-release:' . $w['id'], 'withdrawal_release', 'Withdrawal ' . $orderSn . ' failed at AfrStar; funds returned', [
                    [$wallet['held_account_id'], 'debit', $amount],
                    [$wallet['available_account_id'], 'credit', $amount],
                ], 'withdrawal', (int) $w['id']);
                $pdo->prepare("UPDATE withdrawals SET status = 'failed', platform_osn = COALESCE(platform_osn, :o), provider_reference = :r, provider_message = :m, final_transaction_id = :t, completed_at = UTC_TIMESTAMP() WHERE id = :id")
                    ->execute(['o' => $osn, 'r' => $ref, 'm' => $msg, 't' => $tx['id'], 'id' => (int) $w['id']]);
                $after[] = self::mailLater($pdo, (int) $w['user_id'], 'Your YOLOTASK withdrawal could not be paid', 'Withdrawal failed',
                    ['Your withdrawal of ' . Money::format($payoutAmount) . ' to ' . $w['bank_name'] . ' ' . self::mask(\YoloTask\Security\Crypto::reveal((string) $w['account_number'])) . ' could not be completed by the bank.', 'The full amount is back in your YOLOTASK wallet. Check your bank details and try again.']);
                $outcome = 'failed_refunded';
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('payments.payout_callback_failed', ['callback_id' => $logId, 'exception' => get_class($e), 'message' => $e->getMessage()]);
            return self::finish($pdo, $logId, 500, 'error', true);
        }
        return self::finish($pdo, $logId, 200, $outcome, true, $after);
    }

    /**
     * Cron (every five minutes): re-run callbacks that were logged but never
     * finished (the request crashed or the database was briefly unavailable).
     * The stored raw body goes through the same verification; outcomes are
     * idempotent, so a callback AfrStar also re-sent is never applied twice.
     * A callback that fails again is marked retry_failed for a person to check.
     * @return array{retried:int,failed:int}
     */
    public static function retryUnfinished(PDO $pdo, int $limit = 50): array
    {
        $rows = $pdo->query("SELECT id, kind, raw_body, remote_ip FROM payment_callbacks WHERE outcome = 'received' AND processed_at IS NULL
            AND received_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE) AND received_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY) ORDER BY id LIMIT " . max(1, $limit))->fetchAll() ?: [];
        $out = ['retried' => 0, 'failed' => 0];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            try {
                $raw = (string) $r['raw_body'];
                parse_str($raw, $form);
                $post = json_decode($raw, true) === null && is_array($form) ? $form : [];
                $res = (string) $r['kind'] === 'payout'
                    ? self::payout($pdo, $raw, $post, (string) $r['remote_ip'], $id)
                    : self::collection($pdo, $raw, $post, (string) $r['remote_ip'], $id);
                foreach ($res['after'] as $job) $job();
                $out['retried']++;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $pdo->prepare("UPDATE payment_callbacks SET outcome = 'retry_failed', processed_at = UTC_TIMESTAMP() WHERE id = :id")->execute(['id' => $id]);
                Logger::error('payments.callback_retry_failed', ['callback_id' => $id, 'exception' => get_class($e)]);
                $out['failed']++;
            }
        }
        return $out;
    }

    /** Cron: withdrawals sent to AfrStar with no callback after money.payout_stuck_hours go to the review queue. */
    public static function flagStuckPayouts(PDO $pdo): int
    {
        $hours = max(1, \YoloTask\Core\Settings::int($pdo, 'money.payout_stuck_hours'));
        $stmt = $pdo->prepare("SELECT id, order_sn, platform_osn, amount_kobo, fee_kobo FROM withdrawals WHERE status = 'processing' AND reviewed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :h HOUR)");
        $stmt->bindValue('h', $hours, PDO::PARAM_INT);
        $stmt->execute();
        $n = 0;
        foreach ($stmt->fetchAll() ?: [] as $w) {
            $before = (int) $pdo->query('SELECT COUNT(*) FROM payment_reviews')->fetchColumn();
            $amount = (int) $w['amount_kobo'] - (int) $w['fee_kobo'];
            self::openReview($pdo, 'stuck:' . $w['id'] . ':' . $w['order_sn'], 'payout_conflict', null, (int) $w['id'], null, (string) $w['order_sn'], (string) ($w['platform_osn'] ?? ''), $amount, null,
                "No AfrStar callback {$hours}+ hours after sending withdrawal #{$w['id']}. Check {$w['order_sn']} in the AfrStar portal; the hold stays until AfrStar confirms.");
            if ((int) $pdo->query('SELECT COUNT(*) FROM payment_reviews')->fetchColumn() > $before) $n++;
        }
        return $n;
    }

    public static function mask(string $account): string
    {
        return strlen($account) >= 4 ? '••••' . substr($account, -4) : '••••';
    }

    /** Insert a review item once (dedupe_key is unique). */
    public static function openReview(PDO $pdo, string $key, string $kind, ?int $orderId, ?int $withdrawalId, ?int $callbackId, ?string $orderSn, ?string $osn, ?int $expected, ?int $paid, string $detail): void
    {
        $pdo->prepare('INSERT IGNORE INTO payment_reviews (dedupe_key, kind, payment_order_id, withdrawal_id, callback_id, order_sn, platform_osn, expected_kobo, paid_kobo, detail) VALUES (:k, :kind, :o, :w, :c, :sn, :osn, :e, :p, :d)')
            ->execute(['k' => substr($key, 0, 160), 'kind' => $kind, 'o' => $orderId, 'w' => $withdrawalId, 'c' => $callbackId, 'sn' => $orderSn ?: null, 'osn' => $osn ?: null, 'e' => $expected, 'p' => $paid, 'd' => mb_substr($detail, 0, 255)]);
    }

    private static function precheck(PDO $pdo, int $logId, string $ip): ?array
    {
        if (!AfrstarClient::isConfigured()) return self::finish($pdo, $logId, 503, 'not_configured', null);
        $allowed = AfrstarClient::config()['callback_ips'];
        if ($allowed !== [] && !in_array($ip, $allowed, true)) return self::finish($pdo, $logId, 403, 'ip_not_allowed', null);
        return null;
    }

    private static function log(PDO $pdo, string $kind, string $raw, string $ip): int
    {
        $pdo->prepare('INSERT INTO payment_callbacks (kind, remote_ip, raw_body) VALUES (:k, :ip, :b)')
            ->execute(['k' => $kind, 'ip' => substr($ip, 0, 45) ?: null, 'b' => substr($raw, 0, self::MAX_BODY)]);
        return (int) $pdo->lastInsertId();
    }

    private static function note(PDO $pdo, int $logId, array $fields): void
    {
        $pdo->prepare('UPDATE payment_callbacks SET order_sn = :order_sn, platform_osn = :platform_osn, provider_status = :provider_status WHERE id = :id')
            ->execute($fields + ['id' => $logId]);
    }

    private static function finish(PDO $pdo, int $logId, int $http, string $outcome, ?bool $signatureValid, array $after = []): array
    {
        try {
            $pdo->prepare('UPDATE payment_callbacks SET outcome = :o, http_status = :h, signature_valid = :s, processed_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute(['o' => $outcome, 'h' => $http, 's' => $signatureValid === null ? null : (int) $signatureValid, 'id' => $logId]);
        } catch (\Throwable $e) {
            Logger::error('payments.callback_log_failed', ['callback_id' => $logId]);
            if ($http === 200) { $http = 500; $after = []; }
        }
        return ['status' => $http, 'body' => $http === 200 ? 'SUCCESS' : strtoupper($outcome), 'after' => $after];
    }

    private static function mailLater(PDO $pdo, int $userId, string $subject, string $heading, array $paragraphs): callable
    {
        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $email = (string) $stmt->fetchColumn();
        return static function () use ($email, $subject, $heading, $paragraphs): void {
            try {
                Mailer::send($email, $subject, $heading, $paragraphs, null, null, 'Questions? Reply to YOLOTASK support from your account email.');
            } catch (\Throwable $e) {
                Logger::error('payments.mail_failed', ['exception' => get_class($e)]);
            }
        };
    }
}
