<?php
declare(strict_types=1);
namespace YoloTask\Payments;

use PDO;
use YoloTask\Account\Activation;
use YoloTask\Core\Logger;

/**
 * Korapay webhooks and status checks (migration 0021).
 *
 * The webhook body is only a hint: after its HMAC signature checks out we
 * look the reference up and ask Korapay's verify endpoint for the real
 * status and amount, then settle through the same code AfrStar uses
 * (CallbackProcessor::settleTopup / settleActivation / settlePayout), so the
 * ledger, idempotency keys, review queue and notifications are identical.
 *
 * The same verify-and-settle step runs when a customer lands back on the
 * return page and from cron, so a missed webhook never leaves money stuck.
 *
 * Webhook responses: 200 once the result is stored (or there is nothing to
 * do), non-200 only when Korapay should retry (we could not verify or
 * persist yet). Korapay retries non-200s for 72 hours.
 */
final class KorapayProcessor
{
    private const MAX_BODY = 65536;

    /** @return array{status:int,body:string,after:list<callable>} */
    public static function webhook(PDO $pdo, string $raw, string $signature, string $ip = '', ?int $logId = null): array
    {
        $json = json_decode($raw, true);
        $event = is_array($json) ? strtolower((string) ($json['event'] ?? '')) : '';
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $reference = substr(trim((string) ($data['reference'] ?? '')), 0, 64);
        $kind = str_starts_with($event, 'transfer.') ? 'payout' : 'collection';
        $logId ??= self::log($pdo, $kind, $raw, $ip, $signature);
        self::note($pdo, $logId, $reference, substr((string) ($data['status'] ?? ''), 0, 10));

        if (!KorapayClient::isConfigured()) return self::finish($pdo, $logId, 503, 'not_configured', null);
        if (!KorapayClient::verifySignature($raw, $signature)) return self::finish($pdo, $logId, 401, 'bad_signature', false);
        if ($reference === '' || $event === '') return self::finish($pdo, $logId, 200, 'malformed', true);

        try {
            if (str_starts_with($event, 'charge.')) {
                $res = self::reconcileCharge($pdo, $reference, $logId);
            } elseif (str_starts_with($event, 'transfer.')) {
                $res = self::reconcilePayout($pdo, $reference, $logId);
            } else {
                return self::finish($pdo, $logId, 200, 'ignored_event', true);
            }
        } catch (KorapayException $e) {
            // Could not ask Korapay for the real status: let Korapay retry.
            Logger::error('korapay.webhook_verify_failed', ['callback_id' => $logId, 'message' => $e->getMessage()]);
            return self::finish($pdo, $logId, 502, 'verify_failed', true);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('korapay.webhook_failed', ['callback_id' => $logId, 'exception' => get_class($e), 'message' => $e->getMessage()]);
            return self::finish($pdo, $logId, 500, 'error', true);
        }
        return self::finish($pdo, $logId, 200, $res['outcome'], true, $res['after']);
    }

    /**
     * Verify a pay-in reference with Korapay and settle it (top-up YTP… or
     * activation YTA…). Throws KorapayException when Korapay cannot be asked.
     * @return array{outcome:string,after:list<callable>}
     */
    public static function reconcileCharge(PDO $pdo, string $reference, ?int $logId = null): array
    {
        $isActivation = Activation::isActivationRef($reference);
        $row = $isActivation ? Activation::findBySn($pdo, $reference) : self::order($pdo, $reference);
        if ($row === null) {
            CallbackProcessor::openReview($pdo, 'unmatched:korapay:' . $reference, 'unmatched_callback', null, null, $logId, $reference, null, null, null,
                'Korapay charge webhook for a reference we do not have.');
            return ['outcome' => 'unknown_order', 'after' => []];
        }
        if (($row['gateway'] ?? Gateway::AFRSTAR) !== Gateway::KORAPAY) return ['outcome' => 'wrong_gateway', 'after' => []];
        if (in_array((string) $row['status'], ['paid', 'review', 'rejected'], true)) return ['outcome' => 'duplicate', 'after' => []];

        $v = KorapayClient::verifyCharge($reference);
        $table = $isActivation ? 'activation_payments' : 'payment_orders';
        if ($v['status'] !== 'success') {
            if ($v['status'] !== '' && $v['status'] !== 'processing' && $v['status'] !== 'pending') {
                $pdo->prepare("UPDATE {$table} SET provider_message = :m WHERE id = :id AND status IN ('created', 'pending', 'expired')")
                    ->execute(['m' => mb_substr('Korapay ' . $v['status'] . ($v['message'] !== '' ? ': ' . $v['message'] : ''), 0, 255), 'id' => (int) $row['id']]);
            }
            return ['outcome' => 'status_' . preg_replace('/[^a-z_]/', '', $v['status'] ?: 'unknown'), 'after' => []];
        }
        if ($v['currency'] !== '' && $v['currency'] !== 'NGN') {
            $paid = null; // a non-naira payment is never auto-credited
        } else {
            $paid = $v['amount_paid_kobo'] ?? $v['amount_kobo'];
        }
        $ref = $v['payment_reference'] !== '' ? $v['payment_reference'] : null;
        $msg = $v['message'] !== '' ? $v['message'] : 'Paid via Korapay';
        return $isActivation
            ? CallbackProcessor::settleActivation($pdo, $logId, (int) $row['id'], $paid, null, $ref, $msg, Gateway::KORAPAY)
            : CallbackProcessor::settleTopup($pdo, $logId, (int) $row['id'], $paid, null, $ref, $msg, Gateway::KORAPAY);
    }

    /**
     * Verify a payout reference (withdrawal order_sn) with Korapay and settle it.
     * @return array{outcome:string,after:list<callable>}
     */
    public static function reconcilePayout(PDO $pdo, string $reference, ?int $logId = null): array
    {
        $stmt = $pdo->prepare('SELECT id, amount_kobo, fee_kobo, status, gateway FROM withdrawals WHERE order_sn = :sn');
        $stmt->execute(['sn' => $reference]);
        $w = $stmt->fetch();
        if (!is_array($w)) {
            CallbackProcessor::openReview($pdo, 'unmatched:korapay-payout:' . $reference, 'unmatched_callback', null, null, $logId, $reference, null, null, null,
                'Korapay transfer webhook for a withdrawal we do not have.');
            return ['outcome' => 'unknown_order', 'after' => []];
        }
        if ((string) $w['gateway'] !== Gateway::KORAPAY) return ['outcome' => 'wrong_gateway', 'after' => []];
        $v = KorapayClient::verifyPayout($reference);
        $net = (int) $w['amount_kobo'] - (int) $w['fee_kobo'];
        if ($v['status'] === 'success') {
            if ($v['amount_kobo'] !== null && $v['amount_kobo'] !== $net) {
                CallbackProcessor::openReview($pdo, 'korapay-amount:' . $w['id'] . ':' . $reference, 'payout_conflict', null, (int) $w['id'], $logId, $reference, null, $net, $v['amount_kobo'],
                    'Korapay reports a different payout amount than the withdrawal; check the Korapay dashboard.');
                return ['outcome' => 'amount_mismatch', 'after' => []];
            }
            return CallbackProcessor::settlePayout($pdo, $logId, (int) $w['id'], true, null, $v['trace_id'] !== '' ? $v['trace_id'] : null, $v['message'] !== '' ? $v['message'] : 'Payout successful', Gateway::KORAPAY, 'success');
        }
        if ($v['status'] === 'failed' || $v['status'] === 'reversed') {
            return CallbackProcessor::settlePayout($pdo, $logId, (int) $w['id'], false, null, $v['trace_id'] !== '' ? $v['trace_id'] : null, $v['message'] !== '' ? $v['message'] : 'Payout failed', Gateway::KORAPAY, $v['status']);
        }
        return ['outcome' => 'status_' . preg_replace('/[^a-z_]/', '', $v['status'] ?: 'unknown'), 'after' => []];
    }

    /**
     * Customer is back on a return page: check once with Korapay (short
     * timeout) so the page can show the result without waiting for the
     * webhook. Never throws; runs any notifications inline.
     */
    public static function checkOnReturn(PDO $pdo, string $reference): void
    {
        if (!KorapayClient::isConfigured()) return;
        try {
            $res = self::reconcileCharge($pdo, $reference);
            foreach ($res['after'] as $job) $job();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('korapay.return_check_failed', ['exception' => get_class($e)]);
        }
    }

    /**
     * Cron (every five minutes): verify Korapay pay-ins still waiting after
     * two minutes, and Korapay payouts still processing after five, so a lost
     * webhook never strands money. Bounded per run.
     * @return array{checked:int,settled:int,failed:int}
     */
    public static function sweep(PDO $pdo, int $limit = 25): array
    {
        $out = ['checked' => 0, 'settled' => 0, 'failed' => 0];
        if (!KorapayClient::isConfigured()) return $out;
        $hours = max(1, \YoloTask\Core\Settings::int($pdo, 'money.payin_expiry_hours')) + 24;
        $refs = [];
        foreach (['payment_orders', 'activation_payments'] as $table) {
            $stmt = $pdo->prepare("SELECT order_sn FROM {$table} WHERE gateway = 'korapay' AND status IN ('pending', 'expired')
                AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE) AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL :h HOUR) ORDER BY id DESC LIMIT " . max(1, $limit));
            $stmt->bindValue('h', $hours, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $sn) $refs[] = ['charge', (string) $sn];
        }
        $wd = $pdo->query("SELECT order_sn FROM withdrawals WHERE gateway = 'korapay' AND status = 'processing' AND order_sn IS NOT NULL
            AND reviewed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) ORDER BY id LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($wd as $sn) $refs[] = ['payout', (string) $sn];
        foreach ($refs as [$type, $sn]) {
            $out['checked']++;
            try {
                $res = $type === 'payout' ? self::reconcilePayout($pdo, $sn) : self::reconcileCharge($pdo, $sn);
                foreach ($res['after'] as $job) $job();
                if (in_array($res['outcome'], ['credited', 'activated', 'paid', 'failed_refunded', 'review'], true)) $out['settled']++;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $out['failed']++;
                Logger::error('korapay.sweep_failed', ['type' => $type, 'exception' => get_class($e)]);
            }
        }
        return $out;
    }

    private static function order(PDO $pdo, string $orderSn): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM payment_orders WHERE order_sn = :sn');
        $stmt->execute(['sn' => $orderSn]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private static function log(PDO $pdo, string $kind, string $raw, string $ip, string $signature): int
    {
        $pdo->prepare("INSERT INTO payment_callbacks (kind, gateway, remote_ip, raw_body, signature) VALUES (:k, 'korapay', :ip, :b, :s)")
            ->execute(['k' => $kind, 'ip' => substr($ip, 0, 45) ?: null, 'b' => substr($raw, 0, self::MAX_BODY), 's' => substr($signature, 0, 128) ?: null]);
        return (int) $pdo->lastInsertId();
    }

    private static function note(PDO $pdo, int $logId, string $reference, string $status): void
    {
        $pdo->prepare('UPDATE payment_callbacks SET order_sn = :sn, provider_status = :st WHERE id = :id')
            ->execute(['sn' => $reference !== '' ? $reference : null, 'st' => $status !== '' ? $status : null, 'id' => $logId]);
    }

    private static function finish(PDO $pdo, int $logId, int $http, string $outcome, ?bool $signatureValid, array $after = []): array
    {
        try {
            $pdo->prepare('UPDATE payment_callbacks SET outcome = :o, http_status = :h, signature_valid = :s, processed_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute(['o' => substr($outcome, 0, 40), 'h' => $http, 's' => $signatureValid === null ? null : (int) $signatureValid, 'id' => $logId]);
        } catch (\Throwable $e) {
            Logger::error('korapay.callback_log_failed', ['callback_id' => $logId]);
            if ($http === 200) { $http = 500; $after = []; }
        }
        return ['status' => $http, 'body' => $http === 200 ? 'OK' : strtoupper($outcome), 'after' => $after];
    }
}
