<?php
declare(strict_types=1);
namespace YoloTask\Account;

use PDO;
use RuntimeException;
use YoloTask\Core\Settings;
use YoloTask\Messaging\Notifications;
use YoloTask\Money\Ledger;
use YoloTask\Money\Money;
use YoloTask\Payments\AfrstarClient;
use YoloTask\Payments\AfrstarException;
use YoloTask\Security\Audit;
use YoloTask\Security\Throttle;

/**
 * Account activation (migration 0019): an optional one-time fee
 * (activation.fee_kobo, default ₦500) an earner pays to access tasks.
 *
 * Switched by activation.required (default 0 = OFF). While OFF every earner
 * sees and can do every task and nothing mentions activation. While ON, an
 * earner who has not activated cannot see or start any task (advertisers'
 * or YOLOTASK's); work they already started (a held slot, a submitted proof,
 * a resubmission or an appeal) can still be finished. Activated earners stay
 * activated whatever the switch says later.
 *
 * Earners cannot deposit, so the fee is paid by bank transfer through an
 * AfrStar collection order, exactly like an advertiser top-up, with its own
 * reference prefix YTA (top-ups are YTP, withdrawals YTW; the callback hub
 * never forwards any of them). The collection callback
 * (Payments\CallbackProcessor) routes YTA orders here:
 *  - paid the exact amount: Dr system:afrstar_clearing / Cr
 *    system:activation_revenue (key activation:<order_sn>), the payment is
 *    marked paid and the earner activated. The earner wallet is never touched.
 *  - paid a different amount: the payment goes to the admin payment review
 *    queue (kind amount_mismatch); an admin activates (revenue = what was
 *    paid, key activation-review:<reviewId>) or rejects it.
 *  - paid again by an already activated earner: the money is still revenue
 *    (it reached the merchant) and a review item (activation_duplicate) tells
 *    admins to refund it outside the app.
 * AfrStar has no status-query API, so a payment whose callback never came
 * can be confirmed by an admin after checking the AfrStar portal
 * (adminVerify, same ledger key as the callback, so a late callback is a
 * duplicate). Replays and retries are no-ops.
 */
final class Activation
{
    public const PREFIX = 'YTA';
    public const REVENUE_ACCOUNT = 'system:activation_revenue';

    public static function fee(PDO $pdo): int
    {
        return max(100, Settings::int($pdo, 'activation.fee_kobo'));
    }

    /** Is the activation requirement switched on? */
    public static function required(PDO $pdo): bool
    {
        return Settings::get($pdo, 'activation.required', '0') === '1';
    }

    /** Has this earner activated (paid once, or confirmed by an admin)? */
    public static function isActivated(PDO $pdo, int $userId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM account_activations WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    public static function activation(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare('SELECT aa.*, ap.order_sn FROM account_activations aa LEFT JOIN activation_payments ap ON ap.id = aa.activation_payment_id WHERE aa.user_id = :u');
        $stmt->execute(['u' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** May this earner see and start tasks? (Always, unless activation is required and they have not activated.) */
    public static function canWork(PDO $pdo, int $userId): bool
    {
        return !self::required($pdo) || self::isActivated($pdo, $userId);
    }

    /**
     * What the activation card needs, or null when it must not show (switch
     * off, or already activated): the fee, the tasks waiting for this earner
     * and their total reward, and the referral bonus for invited earners who
     * activate (0 = none).
     * @return array{fee:int,tasks:int,reward:int,referral_bonus:int}|null
     */
    public static function offer(PDO $pdo, int $userId): ?array
    {
        if (self::canWork($pdo, $userId)) return null;
        $avail = \YoloTask\Tasks\Tasks::availableSummary($pdo, $userId);
        return ['fee' => self::fee($pdo), 'tasks' => $avail['count'], 'reward' => $avail['reward'],
            'referral_bonus' => \YoloTask\Growth\Referrals::enabled($pdo) ? \YoloTask\Growth\Referrals::activationBonus($pdo) : 0];
    }

    public static function isActivationRef(string $orderSn): bool
    {
        return str_starts_with($orderSn, self::PREFIX);
    }

    /**
     * Start (or continue) the activation payment. Returns the AfrStar payment link.
     * @return array{order_sn:string,pay_url:string,reused:bool}
     */
    public static function create(PDO $pdo, array $user, string $phone, string $returnBaseUrl): array
    {
        if ((string) $user['role'] !== 'earner') throw new RuntimeException('Only earner accounts need activation.');
        if (empty($user['email_verified_at'])) throw new RuntimeException('Confirm your email before activating your account.');
        if (!self::required($pdo)) throw new RuntimeException('Your account does not need activation right now. Every task is open to you.');
        if (!\YoloTask\Payments\Gateway::payinReady($pdo)) throw new RuntimeException('Payments are not available yet.');
        $userId = (int) $user['id'];
        if (self::isActivated($pdo, $userId)) throw new RuntimeException('Your account is already activated.');
        $inReview = $pdo->prepare("SELECT COUNT(*) FROM activation_payments WHERE user_id = :u AND status = 'review'");
        $inReview->execute(['u' => $userId]);
        if ((int) $inReview->fetchColumn() > 0) throw new RuntimeException('Your earlier payment is being checked by our team. You will get a message when it is resolved.');
        $amount = self::fee($pdo);
        // A waiting order for the same fee is reused, so a second tap never creates a second transfer.
        $hours = max(1, Settings::int($pdo, 'money.payin_expiry_hours'));
        $open = $pdo->prepare("SELECT order_sn, pay_url FROM activation_payments WHERE user_id = :u AND status = 'pending' AND amount_kobo = :a AND gateway = :g AND pay_url IS NOT NULL
            AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL :h HOUR) ORDER BY id DESC LIMIT 1");
        $open->execute(['u' => $userId, 'a' => $amount, 'g' => \YoloTask\Payments\Gateway::payin($pdo), 'h' => $hours]);
        $row = $open->fetch();
        if (is_array($row)) return ['order_sn' => (string) $row['order_sn'], 'pay_url' => (string) $row['pay_url'], 'reused' => true];

        $e164 = Account::normalisePhone($phone);
        if ($e164 === null) throw new RuntimeException('Enter your phone number for the payment page.');
        if (Throttle::tooMany($pdo, 'activation', (string) $userId, 10, 3600)) throw new RuntimeException('Too many payment attempts. Please try again in an hour.');
        Throttle::hit($pdo, 'activation', (string) $userId);
        if (empty($user['phone'])) $pdo->prepare('UPDATE users SET phone = :p WHERE id = :id AND phone IS NULL')->execute(['p' => $e164, 'id' => $userId]);
        $localPhone = str_starts_with($e164, '+234') ? '0' . substr($e164, 4) : ltrim($e164, '+');

        $orderSn = self::PREFIX . gmdate('ymdHis') . strtoupper(bin2hex(random_bytes(5)));
        $gateway = \YoloTask\Payments\Gateway::payin($pdo);
        $pdo->prepare("INSERT INTO activation_payments (order_sn, user_id, amount_kobo, status, gateway) VALUES (:sn, :u, :a, 'created', :g)")->execute(['sn' => $orderSn, 'u' => $userId, 'a' => $amount, 'g' => $gateway]);
        $id = (int) $pdo->lastInsertId();
        Audit::record($pdo, $userId, 'account.activation_started', 'activation_payment', $id);
        try {
            $res = \YoloTask\Payments\Gateway::startPayin($gateway, $orderSn, $amount, mb_substr((string) $user['display_name'], 0, 80), (string) $user['email'], $localPhone,
                'YOLOTASK account activation', rtrim($returnBaseUrl, '/') . '/activate.php?o=' . rawurlencode($orderSn));
        } catch (AfrstarException|\YoloTask\Payments\KorapayException $e) {
            // Definitive refusal: nothing exists at the gateway. Unknown outcome: keep it pending so a late callback can still activate.
            $pdo->prepare('UPDATE activation_payments SET status = :s, provider_message = :m WHERE id = :id')
                ->execute(['s' => $e->definitive ? 'failed' : 'pending', 'm' => mb_substr($e->getMessage(), 0, 255), 'id' => $id]);
            throw new RuntimeException('We could not start the payment. Please try again shortly.');
        }
        $pdo->prepare("UPDATE activation_payments SET status = 'pending', platform_osn = :p, pay_url = :u WHERE id = :id AND status = 'created'")
            ->execute(['p' => $res['platform_osn'] !== '' ? $res['platform_osn'] : null, 'u' => $res['pay_url'], 'id' => $id]);
        return ['order_sn' => $orderSn, 'pay_url' => $res['pay_url'], 'reused' => false];
    }

    public static function findBySn(PDO $pdo, string $orderSn): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM activation_payments WHERE order_sn = :sn');
        $stmt->execute(['sn' => $orderSn]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function findForUser(PDO $pdo, int $userId, string $orderSn): ?array
    {
        $row = self::findBySn($pdo, $orderSn);
        return $row !== null && (int) $row['user_id'] === $userId ? $row : null;
    }

    public static function recentForUser(PDO $pdo, int $userId, int $limit = 5): array
    {
        $stmt = $pdo->prepare("SELECT order_sn, amount_kobo, amount_paid_kobo, status, gateway, pay_url, provider_message, created_at, paid_at FROM activation_payments WHERE user_id = :u AND status <> 'created' ORDER BY id DESC LIMIT " . (int) $limit);
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Mark a locked payment paid, post the fee as revenue and activate the
     * earner (inside the caller's transaction, payment row locked).
     * @return array{tx_id:int,activated:bool}
     */
    public static function settlePaid(PDO $pdo, array $p, int $revenue, string $key, string $source, ?int $actorId, array $fields = []): array
    {
        $tx = Ledger::post($pdo, $key, 'activation_fee', 'Account activation ' . $p['order_sn'] . ($source === 'payment' ? '' : ' (' . ($source === 'review' ? 'reviewed' : 'verified by admin') . ')'), [
            [Ledger::systemAccountId($pdo, (string) ($fields['clearing'] ?? \YoloTask\Payments\Gateway::clearingAccount(\YoloTask\Payments\Gateway::clean(isset($p['gateway']) ? (string) $p['gateway'] : null)))), 'debit', $revenue],
            [Ledger::systemAccountId($pdo, self::REVENUE_ACCOUNT), 'credit', $revenue],
        ], 'activation_payment', (int) $p['id'], $actorId);
        $pdo->prepare("UPDATE activation_payments SET status = 'paid', revenue_kobo = :r, ledger_transaction_id = :t, paid_at = COALESCE(paid_at, UTC_TIMESTAMP()),
                amount_paid_kobo = COALESCE(:ap, amount_paid_kobo), platform_osn = COALESCE(platform_osn, :o), provider_reference = COALESCE(:ref, provider_reference),
                provider_message = COALESCE(:m, provider_message), verified_by_user_id = :v WHERE id = :id")
            ->execute(['r' => $revenue, 't' => $tx['id'], 'ap' => $fields['amount_paid'] ?? null, 'o' => $fields['osn'] ?? null, 'ref' => $fields['reference'] ?? null,
                'm' => $fields['message'] ?? null, 'v' => $source === 'payment' ? null : $actorId, 'id' => (int) $p['id']]);
        $ins = $pdo->prepare('INSERT IGNORE INTO account_activations (user_id, activation_payment_id, source, amount_kobo, activated_by_user_id, note) VALUES (:u, :p, :s, :a, :by, :n)');
        $ins->execute(['u' => (int) $p['user_id'], 'p' => (int) $p['id'], 's' => $source, 'a' => $revenue, 'by' => $source === 'payment' ? null : $actorId, 'n' => $fields['note'] ?? null]);
        $activated = $ins->rowCount() === 1;
        Audit::record($pdo, $actorId, $activated ? 'account.activated' : 'account.activation_duplicate_payment', 'activation_payment', (int) $p['id']);
        return ['tx_id' => $tx['id'], 'activated' => $activated];
    }

    /**
     * After an earner is activated (every path, after its transaction
     * commits): inbox item + email, then the referrer's activation bonus
     * (migration 0020). Never throws.
     */
    public static function notifyActivated(PDO $pdo, int $userId, int $paymentId, string $orderSn, int $amount): void
    {
        \YoloTask\Growth\Referrals::afterActivation($pdo, $userId);
        try {
            Notifications::notify($pdo, $userId, 'payments', 'Account activated', 'Your ' . Money::format($amount) . ' activation was received (' . $orderSn . '). Every task is now open to you.',
                'tasks.php', 'account-activated:' . $userId, [
                    'subject' => 'Your YOLOTASK account is activated', 'heading' => 'Account activated',
                    'lines' => ['We received your one-time activation payment of ' . Money::format($amount) . '.', 'Every task in your feed is now open to you. Each one is paid as soon as it is approved.', 'Reference: ' . $orderSn],
                    'button' => ['label' => 'Find tasks', 'url' => yolotask_portal_url('earner') . '/tasks.php'],
                    'footnote' => 'Questions? Reply to YOLOTASK support from your account email.',
                ]);
        } catch (\Throwable $e) {
            \YoloTask\Core\Logger::error('account.activation_notify_failed', ['payment_id' => $paymentId, 'exception' => get_class($e)]);
        }
    }

    /** Admin: activate an amount-mismatch review item (revenue = what was actually paid). */
    public static function creditReview(PDO $pdo, int $adminId, int $reviewId, string $note): int
    {
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT * FROM payment_reviews WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $reviewId]);
            $review = $lock->fetch();
            if (!is_array($review) || $review['activation_payment_id'] === null) throw new RuntimeException('That review item does not exist.');
            if ((string) $review['status'] !== 'open') throw new RuntimeException('This item was already resolved.');
            if ((string) $review['kind'] !== 'amount_mismatch') throw new RuntimeException('Only amount mismatches can be activated. Dismiss this item after settling it.');
            $p = self::lockPayment($pdo, (int) $review['activation_payment_id']);
            if ((string) $p['status'] !== 'review') throw new RuntimeException('The payment is not waiting for review.');
            $amount = (int) ($review['paid_kobo'] ?? 0);
            if ($amount <= 0) throw new RuntimeException('The paid amount is unknown; reject this item and settle it with AfrStar.');
            $res = self::settlePaid($pdo, $p, $amount, 'activation-review:' . $reviewId, 'review', $adminId, ['note' => mb_substr($note, 0, 255)]);
            $pdo->prepare("UPDATE payment_reviews SET status = 'credited', credited_kobo = :c, ledger_transaction_id = :t, resolved_by_user_id = :a, resolution_note = :n, resolved_at = UTC_TIMESTAMP() WHERE id = :id")
                ->execute(['c' => $amount, 't' => $res['tx_id'], 'a' => $adminId, 'n' => $note, 'id' => $reviewId]);
            Audit::record($pdo, $adminId, 'admin.activation_review_credited', 'activation_payment', (int) $p['id']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if ($res['activated']) self::notifyActivated($pdo, (int) $p['user_id'], (int) $p['id'], (string) $p['order_sn'], $amount);
        return $amount;
    }

    /**
     * Admin manual verify: AfrStar has no status-query API, so an admin who
     * finds the transfer in the AfrStar portal for a waiting, expired or
     * failed order confirms it here (the order's amount becomes revenue).
     */
    public static function adminVerify(PDO $pdo, int $adminId, int $paymentId, string $reference, string $note): void
    {
        $reference = trim($reference);
        $note = trim($note);
        if (!preg_match('/^[A-Za-z0-9._\-]{4,120}$/', $reference)) throw new RuntimeException('Enter the AfrStar reference (order number, session id or UTR) you found the payment under.');
        if (mb_strlen($note) < 3 || mb_strlen($note) > 255) throw new RuntimeException('Add a note (3 to 255 characters) explaining how you verified it.');
        $pdo->beginTransaction();
        try {
            $p = self::lockPayment($pdo, $paymentId);
            if (in_array((string) $p['status'], ['paid', 'review', 'rejected'], true)) throw new RuntimeException((string) $p['status'] === 'review' ? 'This payment is in the review queue; decide it there.' : 'This payment was already ' . $p['status'] . '.');
            $res = self::settlePaid($pdo, $p, (int) $p['amount_kobo'], 'activation:' . $p['order_sn'], 'admin', $adminId, ['reference' => $reference, 'message' => 'Verified by an admin', 'note' => mb_substr($note, 0, 255)]);
            Audit::record($pdo, $adminId, 'admin.activation_verified', 'activation_payment', $paymentId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if ($res['activated']) self::notifyActivated($pdo, (int) $p['user_id'], $paymentId, (string) $p['order_sn'], (int) $p['amount_kobo']);
    }

    public static function lockPayment(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM activation_payments WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new RuntimeException('Activation payment not found.');
        return $row;
    }

    /** Cron: waiting orders past money.payin_expiry_hours become expired. No money moves. */
    public static function expireStale(PDO $pdo): int
    {
        $stmt = $pdo->prepare("UPDATE activation_payments SET status = 'expired' WHERE status IN ('created', 'pending') AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :h HOUR)");
        $stmt->bindValue('h', max(1, Settings::int($pdo, 'money.payin_expiry_hours')), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    public static function listPayments(PDO $pdo, string $status, string $q, int $page, int $perPage = 25): array
    {
        $where = ['1 = 1'];
        $args = [];
        if (in_array($status, ['pending', 'paid', 'review', 'rejected', 'expired', 'failed'], true)) { $where[] = 'ap.status = :s'; $args['s'] = $status; }
        if ($q !== '') { $where[] = '(ap.order_sn = :q OR ap.platform_osn = :q2 OR u.email LIKE :q3)'; $args += ['q' => $q, 'q2' => $q, 'q3' => '%' . addcslashes($q, '%_\\') . '%']; }
        $w = ' WHERE ' . implode(' AND ', $where) . " AND ap.status <> 'created'";
        return \YoloTask\Support\Pagination::query($pdo, 'SELECT COUNT(*) FROM activation_payments ap JOIN users u ON u.id = ap.user_id' . $w,
            'SELECT ap.*, u.email, u.display_name FROM activation_payments ap JOIN users u ON u.id = ap.user_id' . $w . ' ORDER BY ap.id DESC', $args, $page, $perPage);
    }

    public static function listActivated(PDO $pdo, string $q, int $page, int $perPage = 25): array
    {
        $where = 'WHERE 1 = 1';
        $args = [];
        if ($q !== '') { $where .= ' AND (u.email LIKE :q OR u.display_name LIKE :q2)'; $like = '%' . addcslashes($q, '%_\\') . '%'; $args = ['q' => $like, 'q2' => $like]; }
        return \YoloTask\Support\Pagination::query($pdo, "SELECT COUNT(*) FROM account_activations pa JOIN users u ON u.id = pa.user_id {$where}",
            "SELECT pa.*, u.email, u.display_name, ap.order_sn,
                (SELECT COUNT(*) FROM submissions s WHERE s.earner_user_id = pa.user_id AND s.status = 'approved') tasks_approved
             FROM account_activations pa JOIN users u ON u.id = pa.user_id LEFT JOIN activation_payments ap ON ap.id = pa.activation_payment_id {$where} ORDER BY pa.activated_at DESC, pa.user_id DESC", $args, $page, $perPage);
    }

    /**
     * Ledger checks (part of Money\Ledger::reconcile): activation revenue =
     * revenue of paid activation payments, each paid payment carries its
     * posting, every paid activation points at a paid payment, and the
     * platform's YOLOTASK task payouts = rewards of approved YOLOTASK tasks.
     * Same rules as database/verify/platform_tasks_reconciliation.sql.
     * @return list<string>
     */
    public static function reconcile(PDO $pdo): array
    {
        if (!(bool) $pdo->query("SHOW TABLES LIKE 'activation_payments'")->fetchColumn()) return [];
        $problems = [];
        $rev = (int) $pdo->query("SELECT COALESCE((SELECT balance_kobo FROM ledger_accounts WHERE code = '" . self::REVENUE_ACCOUNT . "'), 0)")->fetchColumn();
        $paid = (int) $pdo->query("SELECT COALESCE(SUM(revenue_kobo), 0) FROM activation_payments WHERE status = 'paid'")->fetchColumn();
        if ($rev !== $paid) $problems[] = "Activation revenue {$rev} != paid activation payments {$paid}";
        foreach ($pdo->query("SELECT ap.id, ap.order_sn FROM activation_payments ap LEFT JOIN ledger_transactions t ON t.id = ap.ledger_transaction_id
                WHERE (ap.status = 'paid' AND (t.id IS NULL OR t.amount_kobo <> ap.revenue_kobo OR t.type <> 'activation_fee')) OR (ap.status <> 'paid' AND ap.ledger_transaction_id IS NOT NULL) LIMIT 50") as $r) {
            $problems[] = "Activation payment {$r['order_sn']} does not match its ledger posting";
        }
        foreach ($pdo->query("SELECT pa.user_id FROM account_activations pa LEFT JOIN activation_payments ap ON ap.id = pa.activation_payment_id
                WHERE ap.id IS NULL OR ap.status <> 'paid' OR ap.user_id <> pa.user_id LIMIT 50") as $r) {
            $problems[] = "Account activation of user #{$r['user_id']} has no paid activation payment";
        }
        $out = (int) $pdo->query("SELECT COALESCE((SELECT balance_kobo FROM ledger_accounts WHERE code = '" . \YoloTask\PlatformTasks\PlatformTasks::PAYOUT_ACCOUNT . "'), 0)")->fetchColumn();
        $owed = (int) $pdo->query("SELECT COALESCE(SUM(s.unit_reward_kobo), 0) FROM submissions s JOIN campaigns c ON c.id = s.campaign_id WHERE c.is_platform = 1 AND s.status = 'approved'")->fetchColumn();
        if ($out !== $owed) $problems[] = "YOLOTASK task payouts {$out} != rewards of approved YOLOTASK tasks {$owed}";
        foreach ($pdo->query("SELECT id FROM campaigns WHERE is_platform = 1 AND (escrow_account_id IS NOT NULL OR funded_kobo <> 0 OR refunded_kobo <> 0 OR unit_price_kobo <> unit_reward_kobo) LIMIT 50") as $r) {
            $problems[] = "YOLOTASK task #{$r['id']} carries escrow or a margin";
        }
        return $problems;
    }
}
