<?php
declare(strict_types=1);
namespace YoloTask\Marketplace;

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
 * Marketplace settings, affiliates, the earnings hold and its release,
 * seller freezes, advertiser withdrawable earnings and reconciliation
 * (migration 0022). Products, coupons and orders live in their own classes.
 *
 * Earnings: every paid order opens one mkt_earnings row per payee (seller
 * share, affiliate commission) held in system:marketplace_hold until
 * release_at (paid time + marketplace.hold_hours). releaseDue() (hourly cron,
 * and at once when the hold is 0) moves each due row to the payee's wallet:
 * Dr system:marketplace_hold / Cr wallet available, key mkt-release:<id>.
 * Rows on orders of a frozen seller wait until the seller is unfrozen.
 *
 * Advertiser withdrawals (Payments\Withdrawals): an advertiser's wallet also
 * holds campaign deposits, which are never withdrawable. They may withdraw
 * up to withdrawableEarnings(): released marketplace earnings minus every
 * earlier withdrawal of theirs that was not rejected or failed (those came
 * back), capped by the wallet's available balance.
 */
final class Marketplace
{
    public const REF_COOKIE_DAYS = 30;

    public static function feeBps(PDO $pdo): int
    {
        return max(0, min(Pricing::MAX_FEE_BPS, Settings::int($pdo, 'marketplace.fee_bps')));
    }

    public static function holdHours(PDO $pdo): int
    {
        return max(0, min(720, Settings::int($pdo, 'marketplace.hold_hours')));
    }

    public static function isFrozen(PDO $pdo, int $userId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM mkt_frozen_sellers WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Admin: freeze a seller (products hidden, earnings not released) or unfreeze them. */
    public static function setFrozen(PDO $pdo, int $adminId, int $userId, bool $freeze, string $reason = ''): void
    {
        $reason = trim($reason);
        if ($freeze) {
            if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) throw new RuntimeException('Give a reason (3 to 255 characters).');
            $role = $pdo->prepare('SELECT role FROM users WHERE id = :id');
            $role->execute(['id' => $userId]);
            if (!in_array((string) $role->fetchColumn(), ['earner', 'advertiser'], true)) throw new RuntimeException('Only earner and advertiser accounts sell on the marketplace.');
            $pdo->prepare('INSERT INTO mkt_frozen_sellers (user_id, reason, frozen_by_user_id) VALUES (:u, :r, :a) ON DUPLICATE KEY UPDATE reason = VALUES(reason), frozen_by_user_id = VALUES(frozen_by_user_id)')
                ->execute(['u' => $userId, 'r' => $reason, 'a' => $adminId]);
        } else {
            $pdo->prepare('DELETE FROM mkt_frozen_sellers WHERE user_id = :u')->execute(['u' => $userId]);
        }
        Audit::record($pdo, $adminId, $freeze ? 'admin.marketplace_seller_frozen' : 'admin.marketplace_seller_unfrozen', 'user', $userId);
    }

    public static function frozenList(PDO $pdo): array
    {
        return $pdo->query('SELECT f.*, u.display_name, u.email, u.role FROM mkt_frozen_sellers f JOIN users u ON u.id = f.user_id ORDER BY f.frozen_at DESC LIMIT 200')->fetchAll() ?: [];
    }

    /** Affiliate (an active earner) for a referral code, or null. */
    public static function affiliateByCode(PDO $pdo, ?string $code): ?array
    {
        $code = \YoloTask\Growth\Referrals::cleanCode($code);
        if ($code === null) return null;
        $stmt = $pdo->prepare("SELECT id, display_name, email, role FROM users WHERE referral_code = :c AND status = 'active' AND role = 'earner'");
        $stmt->execute(['c' => $code]);
        $row = $stmt->fetch();
        if (!is_array($row) || !\YoloTask\Account\Activation::canWork($pdo, (int) $row['id'])) return null;
        return $row;
    }

    /** The earner's personal link for a product (creates their link row so clicks can be counted). */
    public static function affiliateLink(PDO $pdo, int $userId, int $productId): string
    {
        $pdo->prepare('INSERT IGNORE INTO mkt_affiliate_links (product_id, affiliate_user_id) VALUES (:p, :u)')->execute(['p' => $productId, 'u' => $userId]);
        return self::productUrl($productId, \YoloTask\Growth\Referrals::codeFor($pdo, $userId));
    }

    public static function productUrl(int $productId, ?string $refCode = null): string
    {
        return yolotask_portal_url('main') . '/p.php?id=' . $productId . ($refCode !== null && $refCode !== '' ? '&ref=' . rawurlencode($refCode) : '');
    }

    public static function recordClick(PDO $pdo, int $productId, int $affiliateId): void
    {
        $pdo->prepare('INSERT INTO mkt_affiliate_links (product_id, affiliate_user_id, clicks) VALUES (:p, :u, 1) ON DUPLICATE KEY UPDATE clicks = clicks + 1')
            ->execute(['p' => $productId, 'u' => $affiliateId]);
    }

    /**
     * Release due earnings to wallets (hourly cron; also right after a sale
     * when the hold is 0). Idempotent: each row posts once (mkt-release:<id>).
     */
    public static function releaseDue(PDO $pdo, int $limit = 500, ?int $orderId = null): int
    {
        $sql = "SELECT e.id, e.user_id FROM mkt_earnings e JOIN mkt_orders o ON o.id = e.order_id
                WHERE e.status = 'held' AND e.release_at <= UTC_TIMESTAMP() AND NOT EXISTS (SELECT 1 FROM mkt_frozen_sellers f WHERE f.user_id = o.seller_user_id)"
            . ($orderId !== null ? ' AND e.order_id = ' . (int) $orderId : '') . ' ORDER BY e.id LIMIT ' . max(1, $limit);
        $done = 0;
        foreach ($pdo->query($sql)->fetchAll() ?: [] as $r) {
            try {
                if (self::releaseOne($pdo, (int) $r['id'], (int) $r['user_id'])) $done++;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                Logger::error('marketplace.release_failed', ['earning_id' => (int) $r['id'], 'exception' => get_class($e), 'message' => $e->getMessage()]);
            }
        }
        return $done;
    }

    private static function releaseOne(PDO $pdo, int $earningId, int $userId): bool
    {
        Wallet::forUser($pdo, $userId);
        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, $userId);
            $stmt = $pdo->prepare('SELECT e.*, p.title FROM mkt_earnings e JOIN mkt_orders o ON o.id = e.order_id JOIN mkt_products p ON p.id = o.product_id WHERE e.id = :id FOR UPDATE');
            $stmt->execute(['id' => $earningId]);
            $e = $stmt->fetch();
            if (!is_array($e) || (string) $e['status'] !== 'held') { $pdo->commit(); return false; }
            $amount = (int) $e['amount_kobo'];
            $tx = Ledger::post($pdo, 'mkt-release:' . $earningId, 'marketplace_release', ((string) $e['role'] === 'affiliate' ? 'Affiliate commission: ' : 'Marketplace sale: ') . mb_substr((string) $e['title'], 0, 120), [
                [Ledger::systemAccountId($pdo, Ledger::MARKETPLACE_HOLD), 'debit', $amount],
                [$wallet['available_account_id'], 'credit', $amount],
            ], 'mkt_earning', $earningId);
            $pdo->prepare("UPDATE mkt_earnings SET status = 'released', released_at = UTC_TIMESTAMP(), ledger_transaction_id = :t WHERE id = :id")->execute(['t' => $tx['id'], 'id' => $earningId]);
            $pdo->commit();
        } catch (\Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $ex;
        }
        Notifications::notify($pdo, $userId, 'marketplace', ((string) $e['role'] === 'affiliate' ? 'Commission added: ' : 'Sale earnings added: ') . Money::format($amount),
            Money::format($amount) . ' from "' . mb_substr((string) $e['title'], 0, 80) . '" is now in your wallet.', 'wallet.php', 'mkt-release:' . $earningId);
        return true;
    }

    /** Released marketplace earnings (all time) for a user. */
    public static function releasedTotal(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_kobo), 0) FROM mkt_earnings WHERE user_id = :u AND status = 'released'");
        $stmt->execute(['u' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * How much an advertiser may withdraw: released marketplace earnings minus
     * their withdrawals that were not rejected or failed, never more than the
     * wallet's available balance. Campaign deposits are never withdrawable.
     */
    public static function withdrawableEarnings(PDO $pdo, int $userId, ?int $availableKobo = null): int
    {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_kobo), 0) FROM withdrawals WHERE user_id = :u AND status NOT IN ('rejected', 'failed')");
        $stmt->execute(['u' => $userId]);
        $left = max(0, self::releasedTotal($pdo, $userId) - (int) $stmt->fetchColumn());
        if ($availableKobo === null) $availableKobo = Wallet::forUser($pdo, $userId)['available_kobo'];
        return max(0, min($left, $availableKobo));
    }

    /** Earnings summary for My Sales: held and released, by role. */
    public static function earningsSummary(PDO $pdo, int $userId): array
    {
        $out = ['seller' => ['held' => 0, 'released' => 0], 'affiliate' => ['held' => 0, 'released' => 0]];
        $stmt = $pdo->prepare('SELECT role, status, COALESCE(SUM(amount_kobo), 0) a FROM mkt_earnings WHERE user_id = :u GROUP BY role, status');
        $stmt->execute(['u' => $userId]);
        foreach ($stmt->fetchAll() ?: [] as $r) $out[(string) $r['role']][(string) $r['status']] = (int) $r['a'];
        return $out;
    }

    /** Affiliate stats per product: clicks, sales, commission. */
    public static function affiliateStats(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare("SELECT p.id, p.title, p.status, l.clicks,
                (SELECT COUNT(*) FROM mkt_orders o WHERE o.product_id = p.id AND o.affiliate_user_id = l.affiliate_user_id AND o.status = 'paid') sales,
                (SELECT COALESCE(SUM(o.commission_kobo), 0) FROM mkt_orders o WHERE o.product_id = p.id AND o.affiliate_user_id = l.affiliate_user_id AND o.status = 'paid') commission
            FROM mkt_affiliate_links l JOIN mkt_products p ON p.id = l.product_id WHERE l.affiliate_user_id = :u ORDER BY l.created_at DESC LIMIT 200");
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    /** Recent paid orders where the user is the seller or the affiliate. */
    public static function recentSales(PDO $pdo, int $userId, int $limit = 30): array
    {
        $stmt = $pdo->prepare("SELECT o.order_sn, o.kind, o.base_kobo, o.amount_kobo, o.fee_kobo, o.fee_bearer, o.commission_kobo, o.seller_kobo, o.coupon_code, o.paid_at, o.seller_user_id, o.affiliate_user_id, p.title,
                (SELECT e.status FROM mkt_earnings e WHERE e.order_id = o.id AND e.user_id = :u3 ORDER BY e.id LIMIT 1) earning_status,
                (SELECT e.release_at FROM mkt_earnings e WHERE e.order_id = o.id AND e.user_id = :u4 ORDER BY e.id LIMIT 1) release_at
            FROM mkt_orders o JOIN mkt_products p ON p.id = o.product_id WHERE o.status = 'paid' AND (o.seller_user_id = :u OR o.affiliate_user_id = :u2) ORDER BY o.id DESC LIMIT " . (int) $limit);
        $stmt->execute(['u' => $userId, 'u2' => $userId, 'u3' => $userId, 'u4' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    /** Resell rights a user owns (with their listing, if any). */
    public static function resellRightsFor(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare('SELECT r.*, p.title, p.price_kobo, l.status listing_status, l.title listing_title FROM mkt_resell_rights r JOIN mkt_products p ON p.id = r.product_id
            LEFT JOIN mkt_products l ON l.id = r.listing_product_id WHERE r.user_id = :u ORDER BY r.id DESC');
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    public static function ownsRights(PDO $pdo, int $userId, int $productId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM mkt_resell_rights WHERE product_id = :p AND user_id = :u');
        $stmt->execute(['p' => $productId, 'u' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    public static function adminStats(PDO $pdo): array
    {
        $r = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(amount_paid_kobo), 0) gross, COALESCE(SUM(amount_paid_kobo - commission_kobo - seller_kobo), 0) fees FROM mkt_orders WHERE status = 'paid'")->fetch() ?: [];
        $held = (int) $pdo->query("SELECT COALESCE(SUM(amount_kobo), 0) FROM mkt_earnings WHERE status = 'held'")->fetchColumn();
        $review = (int) $pdo->query("SELECT COUNT(*) FROM mkt_orders WHERE status = 'review'")->fetchColumn();
        return ['orders' => (int) ($r['n'] ?? 0), 'gross' => (int) ($r['gross'] ?? 0), 'fees' => (int) ($r['fees'] ?? 0), 'held' => $held, 'review' => $review, 'pending_products' => Products::pendingCount($pdo)];
    }

    /**
     * Ledger checks (part of Money\Ledger::reconcile): marketplace revenue =
     * what paid orders brought in beyond their payees' shares; the hold =
     * earnings not yet released; every paid order and released earning has
     * its posting; earnings per order equal its commission + seller share.
     * @return list<string>
     */
    public static function reconcile(PDO $pdo): array
    {
        if (!(bool) $pdo->query("SHOW TABLES LIKE 'mkt_orders'")->fetchColumn()) return [];
        $problems = [];
        $bal = static function (string $code) use ($pdo): int {
            $s = $pdo->prepare('SELECT COALESCE((SELECT balance_kobo FROM ledger_accounts WHERE code = :c), 0)');
            $s->execute(['c' => $code]);
            return (int) $s->fetchColumn();
        };
        $rev = $bal(Ledger::MARKETPLACE_REVENUE);
        $fees = (int) $pdo->query("SELECT COALESCE(SUM(amount_paid_kobo - commission_kobo - seller_kobo), 0) FROM mkt_orders WHERE status = 'paid'")->fetchColumn();
        if ($rev !== $fees) $problems[] = "Marketplace revenue {$rev} != fees of paid marketplace orders {$fees}";
        $hold = $bal(Ledger::MARKETPLACE_HOLD);
        $held = (int) $pdo->query("SELECT COALESCE(SUM(amount_kobo), 0) FROM mkt_earnings WHERE status = 'held'")->fetchColumn();
        if ($hold !== $held) $problems[] = "Marketplace hold {$hold} != earnings waiting for release {$held}";
        foreach ($pdo->query("SELECT o.order_sn FROM mkt_orders o LEFT JOIN ledger_transactions t ON t.id = o.ledger_transaction_id
                WHERE (o.status = 'paid' AND (t.id IS NULL OR t.amount_kobo <> o.amount_paid_kobo OR t.type <> 'marketplace_sale')) OR (o.status <> 'paid' AND o.ledger_transaction_id IS NOT NULL) LIMIT 50") as $r) {
            $problems[] = "Marketplace order {$r['order_sn']} does not match its ledger posting";
        }
        foreach ($pdo->query("SELECT o.order_sn FROM mkt_orders o LEFT JOIN (SELECT order_id, SUM(amount_kobo) a FROM mkt_earnings GROUP BY order_id) e ON e.order_id = o.id
                WHERE o.status = 'paid' AND COALESCE(e.a, 0) <> o.commission_kobo + o.seller_kobo LIMIT 50") as $r) {
            $problems[] = "Marketplace order {$r['order_sn']} earnings do not equal its commission + seller share";
        }
        foreach ($pdo->query("SELECT e.id FROM mkt_earnings e LEFT JOIN ledger_transactions t ON t.id = e.ledger_transaction_id
                WHERE (e.status = 'released' AND (t.id IS NULL OR t.amount_kobo <> e.amount_kobo OR t.type <> 'marketplace_release')) OR (e.status = 'held' AND e.ledger_transaction_id IS NOT NULL) LIMIT 50") as $r) {
            $problems[] = "Marketplace earning #{$r['id']} does not match its ledger posting";
        }
        return $problems;
    }
}
