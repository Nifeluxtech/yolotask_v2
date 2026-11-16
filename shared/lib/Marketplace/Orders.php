<?php
declare(strict_types=1);
namespace YoloTask\Marketplace;

use PDO;
use RuntimeException;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Messaging\Mailer;
use YoloTask\Messaging\Notifications;
use YoloTask\Money\Ledger;
use YoloTask\Money\Money;
use YoloTask\Payments\AfrstarException;
use YoloTask\Payments\Gateway;
use YoloTask\Payments\KorapayException;
use YoloTask\Security\Audit;
use YoloTask\Security\Throttle;

/**
 * Marketplace orders (migration 0022): product sales to anyone (name + email,
 * no account) and resell-rights purchases by signed-in earners/advertisers.
 *
 * Paid exactly like an account activation: a hosted payment on the active
 * pay-in gateway (AfrStar or Korapay), reference prefix YTM. The gateway's
 * callback/webhook (Payments\CallbackProcessor / KorapayProcessor), the
 * buyer's return page and the five-minute Korapay sweep all end in
 * settle(), which is idempotent:
 *  - paid the exact amount: one ledger posting (key mkt-sale:<order_sn>)
 *      Dr <gateway clearing> amount / Cr system:marketplace_revenue fee
 *      / Cr system:marketplace_hold commission + seller share;
 *    the order is marked paid, earnings rows are opened (released by
 *    Marketplace::releaseDue after marketplace.hold_hours), the coupon's use
 *    is counted, resell rights are granted, and the buyer gets the delivery
 *    (thank-you page + email).
 *  - paid a different amount: status review, nothing posted; an admin
 *    rejects it (refund outside the app) or accepts it when at least the
 *    order amount arrived (the excess is platform revenue).
 * AfrStar has no status API, so an admin can confirm a payment found in the
 * AfrStar portal (adminVerify, same ledger key as the callback).
 */
final class Orders
{
    public const PREFIX = 'YTM';

    public static function isRef(string $orderSn): bool
    {
        return str_starts_with($orderSn, self::PREFIX);
    }

    public static function findBySn(PDO $pdo, string $orderSn): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM mkt_orders WHERE order_sn = :sn');
        $stmt->execute(['sn' => $orderSn]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** Order by reference and its access token (buyer links), or null. */
    public static function findForBuyer(PDO $pdo, string $orderSn, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $o = self::findBySn($pdo, $orderSn);
        return $o !== null && hash_equals((string) $o['access_token'], $token) ? $o : null;
    }

    /** Public URL of the thank-you / delivery page for an order. */
    public static function orderUrl(array $o): string
    {
        return yolotask_portal_url('main') . '/order.php?o=' . rawurlencode((string) $o['order_sn']) . '&t=' . rawurlencode((string) $o['access_token']);
    }

    public static function downloadUrl(array $o): string
    {
        return yolotask_portal_url('main') . '/download.php?o=' . rawurlencode((string) $o['order_sn']) . '&t=' . rawurlencode((string) $o['access_token']);
    }

    /** Checkout quote shown before paying (and recomputed when paying). */
    public static function quote(PDO $pdo, array $product, string $buyerEmail, ?string $couponCode, ?array $affiliate, ?int $buyerUserId = null): array
    {
        $coupon = null;
        if ($couponCode !== null && trim($couponCode) !== '') $coupon = Coupons::validFor($pdo, $product, $couponCode);
        $q = Pricing::quote($product, Marketplace::feeBps($pdo), $coupon, $affiliate !== null ? (int) $affiliate['id'] : null,
            $affiliate !== null ? (string) $affiliate['email'] : null, $buyerEmail, $buyerUserId);
        $q['coupon'] = $coupon;
        return $q;
    }

    /**
     * Buyer checkout on the public product page. Returns the gateway payment link.
     * @return array{order_sn:string,pay_url:string}
     */
    public static function checkout(PDO $pdo, int $productId, string $name, string $email, string $phone, ?string $couponCode, ?string $affiliateCode, string $ip): array
    {
        $p = Products::find($pdo, $productId);
        if ($p === null || !Products::isBuyable($p)) throw new RuntimeException('This product is not available right now.');
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) throw new RuntimeException('Enter your name.');
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address. Your purchase is sent there.');
        if (Throttle::tooMany($pdo, 'mkt_checkout', $ip !== '' ? $ip : $email, 20, 3600)) throw new RuntimeException('Too many checkout attempts. Please try again in an hour.');
        Throttle::hit($pdo, 'mkt_checkout', $ip !== '' ? $ip : $email);
        $affiliate = Marketplace::affiliateByCode($pdo, $affiliateCode);
        $q = self::quote($pdo, $p, $email, $couponCode, $affiliate);
        return self::start($pdo, 'product', $p, $q, $name, $email, $phone, null);
    }

    /**
     * Signed-in earner/advertiser buys the resell rights of a live product.
     * @return array{order_sn:string,pay_url:string}
     */
    public static function buyResellRights(PDO $pdo, array $user, int $productId, string $phone): array
    {
        if (!in_array((string) $user['role'], ['earner', 'advertiser'], true)) throw new RuntimeException('Only earner and advertiser accounts can buy resell rights.');
        if (empty($user['email_verified_at'])) throw new RuntimeException('Confirm your email before buying resell rights.');
        $uid = (int) $user['id'];
        $p = Products::find($pdo, $productId);
        if ($p === null || !Products::isBuyable($p)) throw new RuntimeException('This product is not available right now.');
        if ($p['parent_product_id'] !== null || !$p['resell_allowed'] || $p['resell_price_kobo'] === null) throw new RuntimeException('This product does not offer resell rights.');
        if ($p['owner_user_id'] === $uid) throw new RuntimeException('You own this product already.');
        $has = $pdo->prepare('SELECT 1 FROM mkt_resell_rights WHERE product_id = :p AND user_id = :u');
        $has->execute(['p' => $productId, 'u' => $uid]);
        if ($has->fetchColumn() !== false) throw new RuntimeException('You already own the resell rights for this product.');
        // A waiting order for the same rights is reused, so a second tap never creates a second payment.
        $open = $pdo->prepare("SELECT order_sn, pay_url FROM mkt_orders WHERE kind = 'resell_rights' AND product_id = :p AND buyer_user_id = :u AND status = 'pending' AND pay_url IS NOT NULL
            AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL :h HOUR) ORDER BY id DESC LIMIT 1");
        $open->bindValue('p', $productId, PDO::PARAM_INT);
        $open->bindValue('u', $uid, PDO::PARAM_INT);
        $open->bindValue('h', max(1, Settings::int($pdo, 'money.payin_expiry_hours')), PDO::PARAM_INT);
        $open->execute();
        $row = $open->fetch();
        if (is_array($row)) return ['order_sn' => (string) $row['order_sn'], 'pay_url' => (string) $row['pay_url']];
        if (Throttle::tooMany($pdo, 'mkt_resell', (string) $uid, 10, 3600)) throw new RuntimeException('Too many payment attempts. Please try again in an hour.');
        Throttle::hit($pdo, 'mkt_resell', (string) $uid);
        $q = Pricing::resellQuote($p, Marketplace::feeBps($pdo));
        $q['coupon'] = null;
        return self::start($pdo, 'resell_rights', $p, $q, (string) $user['display_name'], (string) $user['email'], $phone !== '' ? $phone : (string) ($user['phone'] ?? ''), $uid);
    }

    /** @return array{order_sn:string,pay_url:string} */
    private static function start(PDO $pdo, string $kind, array $p, array $q, string $name, string $email, string $phone, ?int $buyerUserId): array
    {
        if (!Gateway::payinReady($pdo)) throw new RuntimeException('Payments are not available yet.');
        $gateway = Gateway::payin($pdo);
        $localPhone = '';
        $e164 = \YoloTask\Account\Account::normalisePhone($phone);
        if ($e164 !== null) $localPhone = str_starts_with($e164, '+234') ? '0' . substr($e164, 4) : ltrim($e164, '+');
        if ($gateway === Gateway::AFRSTAR && $localPhone === '') throw new RuntimeException('Enter your phone number for the payment page.');
        $orderSn = self::PREFIX . gmdate('ymdHis') . strtoupper(bin2hex(random_bytes(5)));
        $token = bin2hex(random_bytes(16));
        $coupon = $q['coupon'] ?? null;
        $pdo->prepare("INSERT INTO mkt_orders (order_sn, access_token, kind, product_id, seller_user_id, buyer_user_id, buyer_name, buyer_email, affiliate_user_id, coupon_id, coupon_code,
                list_price_kobo, discount_kobo, base_kobo, fee_bps, fee_bearer, fee_kobo, commission_kobo, seller_kobo, amount_kobo, status, gateway)
            VALUES (:sn, :tk, :k, :p, :s, :bu, :bn, :be, :a, :c, :cc, :lp, :d, :b, :fbps, :fb, :f, :cm, :sk, :am, 'created', :g)")
            ->execute(['sn' => $orderSn, 'tk' => $token, 'k' => $kind, 'p' => $p['id'], 's' => $p['owner_user_id'], 'bu' => $buyerUserId, 'bn' => mb_substr($name, 0, 80), 'be' => $email,
                'a' => $q['affiliate_id'], 'c' => $coupon['id'] ?? null, 'cc' => $coupon['code'] ?? null, 'lp' => $q['list'], 'd' => $q['discount'], 'b' => $q['base'], 'fbps' => $q['fee_bps'],
                'fb' => $q['fee_bearer'], 'f' => $q['fee'], 'cm' => $q['commission'], 'sk' => $q['seller'], 'am' => $q['amount'], 'g' => $gateway]);
        $id = (int) $pdo->lastInsertId();
        $order = ['order_sn' => $orderSn, 'access_token' => $token];
        $what = $kind === 'resell_rights' ? 'Resell rights: ' . $p['title'] : $p['title'];
        try {
            $res = Gateway::startPayin($gateway, $orderSn, (int) $q['amount'], mb_substr($name, 0, 80), $email, $localPhone !== '' ? $localPhone : '08000000000',
                mb_substr('YOLOTASK ' . $what, 0, 60), self::orderUrl($order));
        } catch (AfrstarException|KorapayException $e) {
            $pdo->prepare('UPDATE mkt_orders SET status = :s, provider_message = :m WHERE id = :id')
                ->execute(['s' => $e->definitive ? 'failed' : 'pending', 'm' => mb_substr($e->getMessage(), 0, 255), 'id' => $id]);
            throw new RuntimeException('We could not start the payment. Please try again shortly.');
        }
        $pdo->prepare("UPDATE mkt_orders SET status = 'pending', platform_osn = :o, pay_url = :u WHERE id = :id AND status = 'created'")
            ->execute(['o' => $res['platform_osn'] !== '' ? $res['platform_osn'] : null, 'u' => $res['pay_url'], 'id' => $id]);
        return ['order_sn' => $orderSn, 'pay_url' => $res['pay_url']];
    }

    public static function lock(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM mkt_orders WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new RuntimeException('Marketplace order not found.');
        return $row;
    }

    /**
     * Settle a confirmed payment for a marketplace order (any gateway). Same
     * contract as CallbackProcessor::settleActivation. Runs its own transaction.
     * @return array{outcome:string,after:list<callable>}
     */
    public static function settle(PDO $pdo, ?int $logId, int $orderId, ?int $paid, ?string $osn, ?string $ref, string $message, string $gateway): array
    {
        $osn = $osn !== null && $osn !== '' ? $osn : null;
        $pdo->beginTransaction();
        try {
            $o = self::lock($pdo, $orderId);
            if (in_array((string) $o['status'], ['paid', 'review', 'rejected'], true)) {
                $pdo->commit();
                return ['outcome' => 'duplicate', 'after' => []];
            }
            $amount = (int) $o['amount_kobo'];
            if ($paid !== null && $paid === $amount) {
                self::settlePaid($pdo, $o, $paid, 'payment', null, ['osn' => $osn, 'reference' => $ref, 'message' => mb_substr($message, 0, 255), 'clearing' => Gateway::clearingAccount($gateway)]);
                $outcome = 'paid';
            } else {
                $pdo->prepare("UPDATE mkt_orders SET status = 'review', amount_paid_kobo = :p, platform_osn = COALESCE(platform_osn, :o), provider_reference = :r, provider_message = :m WHERE id = :id")
                    ->execute(['p' => $paid, 'o' => $osn, 'r' => $ref, 'm' => mb_substr(Gateway::label($gateway) . ': paid ' . ($paid === null ? 'an unreadable amount' : Money::format($paid)) . ' for a ' . Money::format($amount) . ' order; held for review', 0, 255), 'id' => $orderId]);
                Audit::record($pdo, null, 'marketplace.order_amount_mismatch', 'mkt_order', $orderId);
                $outcome = 'review';
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['outcome' => $outcome, 'after' => $outcome === 'paid' ? [self::afterPaidJob($pdo, $orderId)] : []];
    }

    /**
     * Post a locked order as paid (inside the caller's transaction): ledger,
     * earnings, coupon use, sales count, resell rights. $paid >= amount; any
     * excess over the order amount is platform revenue.
     */
    public static function settlePaid(PDO $pdo, array $o, int $paid, string $source, ?int $actorId, array $fields = []): int
    {
        $commission = (int) $o['commission_kobo'];
        $seller = (int) $o['seller_kobo'];
        $hold = $commission + $seller;
        $revenue = $paid - $hold;
        if ($revenue < 0) throw new RuntimeException('The amount paid does not cover the order.');
        $clearing = (string) ($fields['clearing'] ?? Gateway::clearingAccount(Gateway::clean(isset($o['gateway']) ? (string) $o['gateway'] : null)));
        $legs = [[Ledger::systemAccountId($pdo, $clearing), 'debit', $paid]];
        if ($revenue > 0) $legs[] = [Ledger::systemAccountId($pdo, Ledger::MARKETPLACE_REVENUE), 'credit', $revenue];
        if ($hold > 0) $legs[] = [Ledger::systemAccountId($pdo, Ledger::MARKETPLACE_HOLD), 'credit', $hold];
        $tx = Ledger::post($pdo, 'mkt-sale:' . $o['order_sn'], 'marketplace_sale', 'Marketplace ' . ($o['kind'] === 'resell_rights' ? 'resell rights ' : 'sale ') . $o['order_sn']
            . ($source === 'payment' ? '' : ' (' . ($source === 'review' ? 'reviewed' : 'verified by admin') . ')'), $legs, 'mkt_order', (int) $o['id'], $actorId);
        $pdo->prepare("UPDATE mkt_orders SET status = 'paid', amount_paid_kobo = :p, ledger_transaction_id = :t, paid_at = COALESCE(paid_at, UTC_TIMESTAMP()),
                platform_osn = COALESCE(platform_osn, :o), provider_reference = COALESCE(:r, provider_reference), provider_message = COALESCE(:m, provider_message), verified_by_user_id = :v WHERE id = :id")
            ->execute(['p' => $paid, 't' => $tx['id'], 'o' => $fields['osn'] ?? null, 'r' => $fields['reference'] ?? null, 'm' => $fields['message'] ?? null,
                'v' => $source === 'payment' ? null : $actorId, 'id' => (int) $o['id']]);
        $releaseAt = gmdate('Y-m-d H:i:s', time() + Marketplace::holdHours($pdo) * 3600);
        $earn = $pdo->prepare('INSERT INTO mkt_earnings (order_id, user_id, role, amount_kobo, release_at) VALUES (:o, :u, :r, :a, :at)');
        if ($seller > 0) $earn->execute(['o' => (int) $o['id'], 'u' => (int) $o['seller_user_id'], 'r' => 'seller', 'a' => $seller, 'at' => $releaseAt]);
        if ($commission > 0 && $o['affiliate_user_id'] !== null) $earn->execute(['o' => (int) $o['id'], 'u' => (int) $o['affiliate_user_id'], 'r' => 'affiliate', 'a' => $commission, 'at' => $releaseAt]);
        if ($o['coupon_id'] !== null) $pdo->prepare('UPDATE mkt_coupons SET uses = uses + 1 WHERE id = :id')->execute(['id' => (int) $o['coupon_id']]);
        if ($o['kind'] === 'product') {
            $pdo->prepare('UPDATE mkt_products SET sales_count = sales_count + 1 WHERE id = :id')->execute(['id' => (int) $o['product_id']]);
        } elseif ($o['buyer_user_id'] !== null) {
            $pdo->prepare('INSERT IGNORE INTO mkt_resell_rights (product_id, user_id, order_id) VALUES (:p, :u, :o)')->execute(['p' => (int) $o['product_id'], 'u' => (int) $o['buyer_user_id'], 'o' => (int) $o['id']]);
        }
        Audit::record($pdo, $actorId, 'marketplace.order_paid', 'mkt_order', (int) $o['id']);
        return $tx['id'];
    }

    /** Admin: AfrStar has no status API; confirm a waiting/expired/failed order found paid in the gateway portal. */
    public static function adminVerify(PDO $pdo, int $adminId, int $orderId, string $reference, string $note): void
    {
        $reference = trim($reference);
        $note = trim($note);
        if (!preg_match('/^[A-Za-z0-9._\-]{4,120}$/', $reference)) throw new RuntimeException('Enter the gateway reference (order number, session id or UTR) you found the payment under.');
        if (mb_strlen($note) < 3 || mb_strlen($note) > 255) throw new RuntimeException('Add a note (3 to 255 characters) explaining how you verified it.');
        $pdo->beginTransaction();
        try {
            $o = self::lock($pdo, $orderId);
            if (in_array((string) $o['status'], ['paid', 'review', 'rejected'], true)) throw new RuntimeException((string) $o['status'] === 'review' ? 'This order is in review; accept or reject it instead.' : 'This order was already ' . $o['status'] . '.');
            self::settlePaid($pdo, $o, (int) $o['amount_kobo'], 'admin', $adminId, ['reference' => $reference, 'message' => mb_substr('Verified by an admin: ' . $note, 0, 255)]);
            Audit::record($pdo, $adminId, 'admin.marketplace_order_verified', 'mkt_order', $orderId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        (self::afterPaidJob($pdo, $orderId))();
    }

    /** Admin: decide an order held for review (amount mismatch). */
    public static function adminReview(PDO $pdo, int $adminId, int $orderId, bool $accept, string $note): void
    {
        $note = trim($note);
        if (mb_strlen($note) < 3 || mb_strlen($note) > 255) throw new RuntimeException('Add a note (3 to 255 characters).');
        $pdo->beginTransaction();
        try {
            $o = self::lock($pdo, $orderId);
            if ((string) $o['status'] !== 'review') throw new RuntimeException('This order is not waiting for review.');
            if ($accept) {
                $paid = (int) ($o['amount_paid_kobo'] ?? 0);
                if ($paid < (int) $o['amount_kobo']) throw new RuntimeException('Less than the order amount arrived. Reject it and refund the buyer outside the app.');
                self::settlePaid($pdo, $o, $paid, 'review', $adminId, ['message' => mb_substr('Accepted by an admin: ' . $note, 0, 255)]);
            } else {
                $pdo->prepare("UPDATE mkt_orders SET status = 'rejected', provider_message = :m, verified_by_user_id = :a WHERE id = :id")
                    ->execute(['m' => mb_substr('Rejected by an admin: ' . $note, 0, 255), 'a' => $adminId, 'id' => $orderId]);
            }
            Audit::record($pdo, $adminId, $accept ? 'admin.marketplace_order_accepted' : 'admin.marketplace_order_rejected', 'mkt_order', $orderId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if ($accept) (self::afterPaidJob($pdo, $orderId))();
    }

    /** Cron: waiting orders past money.payin_expiry_hours become expired. No money moves. */
    public static function expireStale(PDO $pdo): int
    {
        $stmt = $pdo->prepare("UPDATE mkt_orders SET status = 'expired' WHERE status IN ('created', 'pending') AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :h HOUR)");
        $stmt->bindValue('h', max(1, Settings::int($pdo, 'money.payin_expiry_hours')), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Work after a paid order commits: email the buyer the delivery, tell the
     * seller and the affiliate, release at once when the hold is 0 hours.
     * Never throws.
     */
    public static function afterPaidJob(PDO $pdo, int $orderId): callable
    {
        return static function () use ($pdo, $orderId): void {
            try {
                $stmt = $pdo->prepare('SELECT o.*, p.title FROM mkt_orders o JOIN mkt_products p ON p.id = o.product_id WHERE o.id = :id');
                $stmt->execute(['id' => $orderId]);
                $o = $stmt->fetch();
                if (!is_array($o) || (string) $o['status'] !== 'paid') return;
                $title = (string) $o['title'];
                if ((string) $o['kind'] === 'product') {
                    Mailer::send((string) $o['buyer_email'], 'Your purchase: ' . mb_substr($title, 0, 80), 'Thank you for your purchase', [
                        'Hi ' . $o['buyer_name'] . ', your payment of ' . Money::format((int) $o['amount_paid_kobo']) . ' for "' . $title . '" was received.',
                        'Open your purchase any time with the button below. Keep this email: the link is your access.',
                        'Order reference: ' . $o['order_sn'],
                    ], ['label' => 'Open my purchase', 'url' => self::orderUrl($o)], null, 'Bought on the YOLOTASK marketplace. Questions about the product? Contact YOLOTASK support with your order reference.');
                    Notifications::notify($pdo, (int) $o['seller_user_id'], 'marketplace', 'New sale: ' . $title,
                        'You sold "' . $title . '". Your share ' . Money::format((int) $o['seller_kobo']) . ' reaches your wallet after the holding period.', 'my-sales.php', 'mkt-sale-seller:' . $orderId);
                    if ($o['affiliate_user_id'] !== null && (int) $o['commission_kobo'] > 0) {
                        Notifications::notify($pdo, (int) $o['affiliate_user_id'], 'marketplace', 'Affiliate sale: ' . $title,
                            'Someone bought "' . $title . '" through your link. Your commission ' . Money::format((int) $o['commission_kobo']) . ' reaches your wallet after the holding period.', 'my-sales.php', 'mkt-sale-aff:' . $orderId);
                    }
                } else {
                    Notifications::notify($pdo, (int) $o['buyer_user_id'], 'marketplace', 'Resell rights bought: ' . $title,
                        'You can now list "' . $title . '" as your own in My Products.', 'my-products.php', 'mkt-rights:' . $orderId, [
                            'subject' => 'Your resell rights are ready', 'heading' => 'Resell rights bought',
                            'lines' => ['You now own the resell rights for "' . $title . '".', 'Create your listing in My Products: set your own price, and optionally your own title and cover. It goes live after a quick review.', 'Reference: ' . $o['order_sn']],
                        ]);
                    Notifications::notify($pdo, (int) $o['seller_user_id'], 'marketplace', 'Resell rights sold: ' . $title,
                        'Someone bought the resell rights for "' . $title . '". Your share ' . Money::format((int) $o['seller_kobo']) . ' reaches your wallet after the holding period.', 'my-sales.php', 'mkt-rights-seller:' . $orderId);
                }
                if (Marketplace::holdHours($pdo) === 0) Marketplace::releaseDue($pdo, 10, $orderId);
            } catch (\Throwable $e) {
                Logger::error('marketplace.after_paid_failed', ['order_id' => $orderId, 'exception' => get_class($e)]);
            }
        };
    }

    /** Admin order list. */
    public static function adminList(PDO $pdo, string $status, string $q, int $page, int $perPage = 25): array
    {
        $where = ["o.status <> 'created'"];
        $args = [];
        if (in_array($status, ['pending', 'paid', 'review', 'rejected', 'expired', 'failed'], true)) { $where[] = 'o.status = :s'; $args['s'] = $status; }
        if ($q !== '') { $where[] = '(o.order_sn = :q OR o.buyer_email LIKE :q2 OR p.title LIKE :q3)'; $like = '%' . addcslashes($q, '%_\\') . '%'; $args += ['q' => $q, 'q2' => $like, 'q3' => $like]; }
        $w = implode(' AND ', $where);
        return \YoloTask\Support\Pagination::query($pdo, "SELECT COUNT(*) FROM mkt_orders o JOIN mkt_products p ON p.id = o.product_id WHERE {$w}",
            "SELECT o.*, p.title, s.display_name seller_name, a.display_name affiliate_name FROM mkt_orders o JOIN mkt_products p ON p.id = o.product_id
             JOIN users s ON s.id = o.seller_user_id LEFT JOIN users a ON a.id = o.affiliate_user_id WHERE {$w} ORDER BY o.status = 'review' DESC, o.id DESC", $args, $page, $perPage);
    }
}
