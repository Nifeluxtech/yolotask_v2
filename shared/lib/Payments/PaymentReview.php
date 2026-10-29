<?php
declare(strict_types=1);
namespace YoloTask\Payments;

use PDO;
use RuntimeException;
use YoloTask\Messaging\Mailer;
use YoloTask\Money\Ledger;
use YoloTask\Money\Money;
use YoloTask\Money\Wallet;
use YoloTask\Security\Audit;

/**
 * Admin queue for payments that need a person: an amount that differs from
 * the order (credit the actual paid amount, or reject), callbacks for orders
 * we do not know, and contradictory payout results (dismiss after checking).
 */
final class PaymentReview
{
    public static function list(PDO $pdo, string $status = 'open', int $limit = 50): array
    {
        $status = in_array($status, ['open', 'credited', 'rejected', 'dismissed'], true) ? $status : 'open';
        $stmt = $pdo->prepare('SELECT r.*, o.user_id, u.email, u.display_name, o.status AS order_status FROM payment_reviews r LEFT JOIN payment_orders o ON o.id = r.payment_order_id LEFT JOIN users u ON u.id = o.user_id WHERE r.status = :s ORDER BY r.id DESC LIMIT ' . (int) $limit);
        $stmt->execute(['s' => $status]);
        return $stmt->fetchAll() ?: [];
    }

    public static function openCount(PDO $pdo): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM payment_reviews WHERE status = 'open'")->fetchColumn();
    }

    /** Credit the amount the customer actually paid. */
    public static function credit(PDO $pdo, int $adminId, int $reviewId, string $note): int
    {
        $note = trim($note);
        if (mb_strlen($note) < 3 || mb_strlen($note) > 255) throw new RuntimeException('Add a note (3 to 255 characters) explaining the decision.');
        $stmt = $pdo->prepare('SELECT r.kind, r.payment_order_id, o.user_id FROM payment_reviews r LEFT JOIN payment_orders o ON o.id = r.payment_order_id WHERE r.id = :id');
        $stmt->execute(['id' => $reviewId]);
        $peek = $stmt->fetch();
        if (!is_array($peek)) throw new RuntimeException('That review item does not exist.');
        if ((string) $peek['kind'] !== 'amount_mismatch' || $peek['payment_order_id'] === null) throw new RuntimeException('Only amount mismatches on a known order can be credited.');
        $userId = (int) $peek['user_id'];

        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, $userId);
            $lock = $pdo->prepare('SELECT * FROM payment_reviews WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $reviewId]);
            $review = $lock->fetch();
            if ((string) $review['status'] !== 'open') throw new RuntimeException('This item was already resolved.');
            $olock = $pdo->prepare('SELECT * FROM payment_orders WHERE id = :id FOR UPDATE');
            $olock->execute(['id' => (int) $review['payment_order_id']]);
            $order = $olock->fetch();
            if ((string) $order['status'] !== 'review') throw new RuntimeException('The order is not waiting for review.');
            $amount = (int) ($review['paid_kobo'] ?? 0);
            if ($amount <= 0) throw new RuntimeException('The paid amount is unknown; reject this item and settle it with AfrStar.');
            $tx = Ledger::post($pdo, 'review-credit:' . $reviewId, 'topup_review', 'Reviewed top-up ' . $order['order_sn'] . ' (paid amount): ' . $note, [
                [Ledger::systemAccountId($pdo, Ledger::CLEARING), 'debit', $amount],
                [$wallet['available_account_id'], 'credit', $amount],
            ], 'payment_order', (int) $order['id'], $adminId);
            $pdo->prepare("UPDATE payment_orders SET status = 'paid', credited_kobo = :c, ledger_transaction_id = :t, paid_at = COALESCE(paid_at, UTC_TIMESTAMP()) WHERE id = :id")
                ->execute(['c' => $amount, 't' => $tx['id'], 'id' => (int) $order['id']]);
            $pdo->prepare("UPDATE payment_reviews SET status = 'credited', credited_kobo = :c, ledger_transaction_id = :t, resolved_by_user_id = :a, resolution_note = :n, resolved_at = UTC_TIMESTAMP() WHERE id = :id")
                ->execute(['c' => $amount, 't' => $tx['id'], 'a' => $adminId, 'n' => $note, 'id' => $reviewId]);
            Audit::record($pdo, $adminId, 'admin.payment_review_credited', 'payment_order', (int) $order['id']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $email = $pdo->prepare('SELECT email FROM users WHERE id = :id');
        $email->execute(['id' => $userId]);
        Mailer::send((string) $email->fetchColumn(), 'Your YOLOTASK wallet was funded', 'Top-up received',
            ['Your payment for top-up ' . $order['order_sn'] . ' was checked and ' . Money::format($amount) . ' (the amount you paid) was added to your wallet.'],
            null, null, 'Questions? Reply to YOLOTASK support from your account email.');
        return $amount;
    }

    /** Reject a mismatch (no credit) or dismiss an unmatched/conflict item. */
    public static function reject(PDO $pdo, int $adminId, int $reviewId, string $note): void
    {
        $note = trim($note);
        if (mb_strlen($note) < 3 || mb_strlen($note) > 255) throw new RuntimeException('Add a note (3 to 255 characters) explaining the decision.');
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT * FROM payment_reviews WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $reviewId]);
            $review = $lock->fetch();
            if (!is_array($review)) throw new RuntimeException('That review item does not exist.');
            if ((string) $review['status'] !== 'open') throw new RuntimeException('This item was already resolved.');
            $final = (string) $review['kind'] === 'amount_mismatch' ? 'rejected' : 'dismissed';
            if ($review['payment_order_id'] !== null) {
                $pdo->prepare("UPDATE payment_orders SET status = 'rejected', provider_message = :m WHERE id = :id AND status = 'review'")
                    ->execute(['m' => mb_substr('Rejected after review: ' . $note, 0, 255), 'id' => (int) $review['payment_order_id']]);
            }
            $pdo->prepare('UPDATE payment_reviews SET status = :s, resolved_by_user_id = :a, resolution_note = :n, resolved_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute(['s' => $final, 'a' => $adminId, 'n' => $note, 'id' => $reviewId]);
            Audit::record($pdo, $adminId, 'admin.payment_review_rejected', 'payment_review', $reviewId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
