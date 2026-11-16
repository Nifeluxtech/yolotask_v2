<?php
declare(strict_types=1);
namespace YoloTask\Marketplace;

/**
 * Marketplace money math (migration 0022). Pure functions, no database, so
 * tests/smoke/marketplace.php can check every rule. All amounts are kobo.
 *
 *   list price  = bonus price when set (must be below the normal price), else the normal price
 *   discount    = coupon on the list price (percent rounded down, or a fixed amount),
 *                 never taking the price below MIN_PRICE
 *   base        = list - discount: the price actually charged for the product
 *   fee         = base x fee_bps / 10000, rounded down (platform fee)
 *   customer bears the fee: buyer pays base + fee, seller pool = base
 *   seller bears the fee:   buyer pays base,       seller pool = base - fee
 *   commission  = base x affiliate % / 100, rounded down, capped at the pool
 *                 (0 without a valid affiliate; self-referral earns nothing)
 *   seller      = pool - commission
 * Invariant: amount = fee + commission + seller.
 */
final class Pricing
{
    public const MIN_PRICE = 10000;          // ₦100: the smallest price the gateways take
    public const MAX_PRICE = 1000000000;     // ₦10,000,000
    public const MAX_AFFILIATE_PERCENT = 90;
    public const MAX_FEE_BPS = 2000;         // admin cap: 20%
    public const MAX_COUPON_BPS = 9000;      // 90% off at most

    /** The price charged before coupons: the bonus price when set and valid, else the normal price. */
    public static function listPrice(int $priceKobo, ?int $bonusKobo): int
    {
        return $bonusKobo !== null && $bonusKobo > 0 && $bonusKobo < $priceKobo ? $bonusKobo : $priceKobo;
    }

    /** Coupon discount on a list price, never taking the price under MIN_PRICE. */
    public static function couponDiscount(int $listKobo, string $kind, int $value): int
    {
        if ($value <= 0) return 0;
        $d = $kind === 'percent' ? intdiv($listKobo * min($value, self::MAX_COUPON_BPS), 10000) : ($kind === 'fixed' ? $value : 0);
        return max(0, min($d, $listKobo - self::MIN_PRICE));
    }

    /**
     * Why a coupon cannot be used on this product, or null when it can.
     * $coupon: owner_user_id, product_id (?int), status, expires_at (?string UTC), max_uses (?int), uses.
     */
    public static function couponProblem(array $coupon, int $productId, int $productOwnerId, int $nowTs): ?string
    {
        if ((int) $coupon['owner_user_id'] !== $productOwnerId) return 'That coupon is not valid for this product.';
        if ($coupon['product_id'] !== null && (int) $coupon['product_id'] !== $productId) return 'That coupon is not valid for this product.';
        if ((string) $coupon['status'] !== 'active') return 'That coupon is no longer active.';
        if (!empty($coupon['expires_at']) && strtotime((string) $coupon['expires_at'] . ' UTC') <= $nowTs) return 'That coupon has expired.';
        if ($coupon['max_uses'] !== null && (int) $coupon['uses'] >= (int) $coupon['max_uses']) return 'That coupon has been used up.';
        return null;
    }

    /**
     * Does this sale count as a self-referral (no commission)? The affiliate
     * is the seller, or the buyer (same account, or the same email address).
     */
    public static function isSelfReferral(?int $affiliateId, int $sellerId, ?string $affiliateEmail, string $buyerEmail, ?int $buyerUserId = null): bool
    {
        if ($affiliateId === null) return false;
        if ($affiliateId === $sellerId) return true;
        if ($buyerUserId !== null && $buyerUserId === $affiliateId) return true;
        return $affiliateEmail !== null && self::normEmail($affiliateEmail) === self::normEmail($buyerEmail);
    }

    /**
     * Split a charged base price.
     * @return array{base:int,fee:int,amount:int,commission:int,seller:int,fee_bps:int,fee_bearer:string}
     */
    public static function split(int $baseKobo, int $feeBps, string $feeBearer, int $affiliatePercent, bool $withAffiliate): array
    {
        if ($baseKobo <= 0) throw new \InvalidArgumentException('The price must be positive.');
        $feeBps = max(0, min(self::MAX_FEE_BPS, $feeBps));
        $feeBearer = $feeBearer === 'seller' ? 'seller' : 'customer';
        $fee = intdiv($baseKobo * $feeBps, 10000);
        $amount = $feeBearer === 'customer' ? $baseKobo + $fee : $baseKobo;
        $pool = $feeBearer === 'customer' ? $baseKobo : $baseKobo - $fee;
        $pct = max(0, min(self::MAX_AFFILIATE_PERCENT, $affiliatePercent));
        $commission = $withAffiliate && $pct > 0 ? min($pool, intdiv($baseKobo * $pct, 100)) : 0;
        return ['base' => $baseKobo, 'fee' => $fee, 'amount' => $amount, 'commission' => $commission, 'seller' => $pool - $commission, 'fee_bps' => $feeBps, 'fee_bearer' => $feeBearer];
    }

    /**
     * Full checkout quote for a product (or a resale listing: the reseller is the seller).
     * $product: owner_user_id, price_kobo, bonus_price_kobo, fee_bearer, affiliate_percent.
     * $coupon: kind, value (already validated with couponProblem), or null.
     * @return array{list:int,discount:int,base:int,fee:int,amount:int,commission:int,seller:int,fee_bps:int,fee_bearer:string,affiliate_id:?int}
     */
    public static function quote(array $product, int $feeBps, ?array $coupon, ?int $affiliateId, ?string $affiliateEmail, string $buyerEmail, ?int $buyerUserId = null): array
    {
        $list = self::listPrice((int) $product['price_kobo'], isset($product['bonus_price_kobo']) ? (int) $product['bonus_price_kobo'] : null);
        $discount = $coupon !== null ? self::couponDiscount($list, (string) $coupon['kind'], (int) $coupon['value']) : 0;
        $sellerId = (int) $product['owner_user_id'];
        if ((int) $product['affiliate_percent'] <= 0 || self::isSelfReferral($affiliateId, $sellerId, $affiliateEmail, $buyerEmail, $buyerUserId)) $affiliateId = null;
        $split = self::split($list - $discount, $feeBps, (string) $product['fee_bearer'], (int) $product['affiliate_percent'], $affiliateId !== null);
        if ($split['commission'] === 0) $affiliateId = null;
        return ['list' => $list, 'discount' => $discount, 'affiliate_id' => $affiliateId] + $split;
    }

    /** What an affiliate earns per sale at the current price (no coupon). */
    public static function affiliateEarns(array $product, int $feeBps): int
    {
        if ((int) $product['affiliate_percent'] <= 0) return 0;
        $list = self::listPrice((int) $product['price_kobo'], isset($product['bonus_price_kobo']) ? (int) $product['bonus_price_kobo'] : null);
        return self::split($list, $feeBps, (string) $product['fee_bearer'], (int) $product['affiliate_percent'], true)['commission'];
    }

    /**
     * Resell-rights purchase: the seller of the original product gets the
     * resell price minus the platform fee when they bear it; no affiliate.
     */
    public static function resellQuote(array $product, int $feeBps): array
    {
        return ['list' => (int) $product['resell_price_kobo'], 'discount' => 0, 'affiliate_id' => null]
            + self::split((int) $product['resell_price_kobo'], $feeBps, (string) $product['fee_bearer'], 0, false);
    }

    public static function normEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
