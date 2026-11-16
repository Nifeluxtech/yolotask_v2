<?php
declare(strict_types=1);

/*
 * Marketplace smoke test (migration 0022). Needs only the php CLI (no database):
 *   php tests/smoke/marketplace.php
 * 1. php -l on every marketplace file.
 * 2. Pure money rules in YoloTask\Marketplace\Pricing: bonus price, coupons,
 *    fee split (customer vs seller bears the fee), affiliate commission on the
 *    discounted price, self-referral, resale proceeds, resell rights, rounding
 *    and the invariant amount = fee + commission + seller.
 */

$root = dirname(__DIR__, 2);
require $root . '/shared/autoload.php';

use YoloTask\Marketplace\Pricing;

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    if ($ok) { echo "PASS: {$label}\n"; return; }
    $failures++;
    fwrite(STDERR, "FAIL: {$label}\n");
};

// 1. Lint.
$files = array_merge(
    glob($root . '/shared/lib/Marketplace/*.php') ?: [],
    glob($root . '/shared/routes/*marketplace*.php') ?: [],
    glob($root . '/shared/routes/market-*.php') ?: [],
    array_map(static fn (string $f): string => $root . '/' . $f, array_filter([
        'apps/main/public/p.php', 'apps/main/public/order.php', 'apps/main/public/download.php', 'apps/main/public/cover.php',
        'apps/earner/public/marketplace.php', 'apps/earner/public/my-sales.php', 'apps/earner/public/my-products.php', 'apps/earner/public/product-edit.php',
        'apps/advertiser/public/marketplace.php', 'apps/advertiser/public/my-sales.php', 'apps/advertiser/public/my-products.php', 'apps/advertiser/public/product-edit.php',
        'apps/advertiser/public/withdraw.php', 'apps/advertiser/public/bank-account.php', 'apps/admin/public/marketplace.php',
        'shared/lib/Payments/Withdrawals.php', 'shared/lib/Payments/CallbackProcessor.php', 'shared/lib/Payments/KorapayProcessor.php',
        'shared/lib/Payments/BankAccounts.php', 'shared/lib/Money/Ledger.php', 'shared/lib/Core/Settings.php', 'shared/bootstrap.php',
        'shared/views/layouts/authenticated.php', 'shared/routes/withdraw.php', 'shared/routes/bank-account.php', 'shared/routes/wallet.php', 'cron/hourly.php',
    ], static fn (string $f): bool => is_file($root . '/' . $f)))
);
foreach (array_unique($files) as $f) {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $check('php -l ' . substr($f, strlen($root) + 1), $rc === 0);
}
$check('migration 0022 exists', is_file($root . '/database/migrations/0022_marketplace.sql'));

$inv = static fn (array $q): bool => $q['amount'] === $q['fee'] + $q['commission'] + $q['seller'] && $q['seller'] >= 0 && $q['commission'] >= 0;
$product = static fn (array $over = []): array => $over + ['owner_user_id' => 10, 'price_kobo' => 1000000, 'bonus_price_kobo' => null, 'fee_bearer' => 'customer', 'affiliate_percent' => 30, 'resell_price_kobo' => 5000000];

// 2a. Bonus price.
$check('list price is the normal price without a bonus', Pricing::listPrice(1000000, null) === 1000000);
$check('list price is the bonus price when lower', Pricing::listPrice(1000000, 750000) === 750000);
$check('a bonus price not below normal is ignored', Pricing::listPrice(1000000, 1000000) === 1000000 && Pricing::listPrice(1000000, 1200000) === 1000000);

// 2b. Fee split: customer bears the fee (₦10,000, 5%, 30% affiliate).
$q = Pricing::split(1000000, 500, 'customer', 30, true);
$check('customer bears: fee 5% = ₦500', $q['fee'] === 50000);
$check('customer bears: buyer pays price + fee', $q['amount'] === 1050000);
$check('customer bears: commission 30% of price', $q['commission'] === 300000);
$check('customer bears: seller gets price - commission', $q['seller'] === 700000);
$check('customer bears: invariant', $inv($q));

// 2c. Seller bears the fee.
$q = Pricing::split(1000000, 500, 'seller', 30, true);
$check('seller bears: buyer pays the price only', $q['amount'] === 1000000);
$check('seller bears: fee taken from the seller', $q['fee'] === 50000 && $q['seller'] === 650000);
$check('seller bears: commission still on the price', $q['commission'] === 300000);
$check('seller bears: invariant', $inv($q));

// 2d. No affiliate / affiliates off.
$q = Pricing::split(1000000, 500, 'customer', 30, false);
$check('no affiliate: no commission, seller keeps the price', $q['commission'] === 0 && $q['seller'] === 1000000 && $inv($q));
$q = Pricing::quote($product(['affiliate_percent' => 0]), 500, null, 77, 'aff@example.com', 'buyer@example.com');
$check('affiliate % 0: link earns nothing', $q['commission'] === 0 && $q['affiliate_id'] === null);

// 2e. Rounding down (kobo) and the cap.
$q = Pricing::split(33333, 500, 'customer', 33, true);
$check('fee rounds down', $q['fee'] === 1666);
$check('commission rounds down', $q['commission'] === 10999);
$check('rounding keeps the invariant', $inv($q));
$q = Pricing::split(1000000, 2000, 'seller', 90, true);
$check('commission is capped at the seller pool (90% + 20% seller fee)', $q['commission'] === 800000 && $q['seller'] === 0 && $inv($q));
$q = Pricing::split(1000000, 99999, 'customer', 0, false);
$check('fee bps clamped to the 20% cap', $q['fee'] === 200000);

// 2f. Self-referral.
$check('affiliate = seller is self-referral', Pricing::isSelfReferral(10, 10, 'a@x.com', 'b@x.com'));
$check('affiliate email = buyer email is self-referral (case-insensitive)', Pricing::isSelfReferral(11, 10, 'Me@X.com', ' me@x.com '));
$check('affiliate = signed-in buyer is self-referral', Pricing::isSelfReferral(11, 10, 'a@x.com', 'b@x.com', 11));
$check('a different affiliate is not self-referral', !Pricing::isSelfReferral(11, 10, 'a@x.com', 'b@x.com'));
$q = Pricing::quote($product(), 500, null, 10, 'seller@x.com', 'b@x.com');
$check('quote: seller sharing their own link earns no commission', $q['commission'] === 0 && $q['affiliate_id'] === null && $q['seller'] === 1000000);
$q = Pricing::quote($product(), 500, null, 11, 'me@x.com', 'ME@x.com');
$check('quote: affiliate buying through their own link earns nothing', $q['commission'] === 0 && $q['affiliate_id'] === null);
$q = Pricing::quote($product(), 500, null, 11, 'aff@x.com', 'b@x.com');
$check('quote: real affiliate sale', $q['affiliate_id'] === 11 && $q['commission'] === 300000 && $q['amount'] === 1050000 && $inv($q));

// 2g. Coupons.
$check('percent coupon 10% (1000 bps)', Pricing::couponDiscount(1000000, 'percent', 1000) === 100000);
$check('fixed coupon ₦2,000', Pricing::couponDiscount(1000000, 'fixed', 200000) === 200000);
$check('coupon never takes the price below ₦100', Pricing::couponDiscount(1000000, 'fixed', 5000000) === 1000000 - Pricing::MIN_PRICE);
$check('percent coupon capped at 90%', Pricing::couponDiscount(1000000, 'percent', 10000) === 900000);
$check('unknown coupon kind gives nothing', Pricing::couponDiscount(1000000, 'bogus', 500) === 0);
$q = Pricing::quote($product(['bonus_price_kobo' => 800000]), 500, ['kind' => 'percent', 'value' => 2500], 11, 'aff@x.com', 'b@x.com');
$check('bonus + coupon: list = bonus price', $q['list'] === 800000);
$check('bonus + coupon: 25% off the bonus price', $q['discount'] === 200000 && $q['base'] === 600000);
$check('bonus + coupon: fee on the discounted price', $q['fee'] === 30000 && $q['amount'] === 630000);
$check('bonus + coupon: commission on the discounted price', $q['commission'] === 180000 && $q['seller'] === 420000 && $inv($q));
$q = Pricing::quote($product(['fee_bearer' => 'seller']), 500, ['kind' => 'fixed', 'value' => 300000], null, null, 'b@x.com');
$check('fixed coupon, seller bears fee, no affiliate', $q['base'] === 700000 && $q['amount'] === 700000 && $q['fee'] === 35000 && $q['seller'] === 665000 && $inv($q));

$now = strtotime('2026-11-16 12:00:00 UTC');
$coupon = ['owner_user_id' => 10, 'product_id' => null, 'status' => 'active', 'expires_at' => null, 'max_uses' => null, 'uses' => 0];
$check('coupon for all owner products is valid', Pricing::couponProblem($coupon, 5, 10, $now) === null);
$check('coupon of another seller is refused', Pricing::couponProblem($coupon, 5, 99, $now) !== null);
$check('coupon scoped to another product is refused', Pricing::couponProblem(['product_id' => 6] + $coupon, 5, 10, $now) !== null);
$check('coupon scoped to this product is valid', Pricing::couponProblem(['product_id' => 5] + $coupon, 5, 10, $now) === null);
$check('disabled coupon is refused', Pricing::couponProblem(['status' => 'disabled'] + $coupon, 5, 10, $now) !== null);
$check('expired coupon is refused', Pricing::couponProblem(['expires_at' => '2026-11-16 11:59:59'] + $coupon, 5, 10, $now) !== null);
$check('coupon before expiry is valid', Pricing::couponProblem(['expires_at' => '2026-11-17 00:00:00'] + $coupon, 5, 10, $now) === null);
$check('used-up coupon is refused', Pricing::couponProblem(['max_uses' => 3, 'uses' => 3] + $coupon, 5, 10, $now) !== null);
$check('coupon with uses left is valid', Pricing::couponProblem(['max_uses' => 3, 'uses' => 2] + $coupon, 5, 10, $now) === null);

// 2h. Resell rights and resale proceeds.
$r = Pricing::resellQuote($product(), 500);
$check('resell rights: buyer pays resell price + fee (customer bears)', $r['amount'] === 5250000 && $r['seller'] === 5000000 && $r['commission'] === 0 && $inv($r));
$r = Pricing::resellQuote($product(['fee_bearer' => 'seller']), 500);
$check('resell rights: seller bears the fee', $r['amount'] === 5000000 && $r['seller'] === 4750000 && $inv($r));
$listing = ['owner_user_id' => 20, 'price_kobo' => 1500000, 'bonus_price_kobo' => null, 'fee_bearer' => 'seller', 'affiliate_percent' => 10];
$q = Pricing::quote($listing, 500, null, 11, 'aff@x.com', 'b@x.com');
$check('resale listing: reseller keeps proceeds minus fee and commission', $q['amount'] === 1500000 && $q['fee'] === 75000 && $q['commission'] === 150000 && $q['seller'] === 1275000 && $inv($q));
$q = Pricing::quote($listing, 500, null, 10, 'orig@x.com', 'b@x.com');
$check('resale listing: the original seller sharing it is an ordinary affiliate', $q['affiliate_id'] === 10 && $q['commission'] === 150000);
$q = Pricing::quote($listing, 500, null, 20, 'res@x.com', 'b@x.com');
$check('resale listing: reseller self-referral earns nothing extra', $q['affiliate_id'] === null && $q['seller'] === 1425000);

// 2i. Affiliate card amount.
$check('"You earn" uses the bonus price', Pricing::affiliateEarns($product(['bonus_price_kobo' => 500000]), 500) === 150000);
$check('"You earn" is 0 when affiliates are off', Pricing::affiliateEarns($product(['affiliate_percent' => 0]), 500) === 0);

// 2j. Settings defaults.
$d = \YoloTask\Core\Settings::DEFAULTS;
$check('marketplace.fee_bps default 500', ($d['marketplace.fee_bps'] ?? null) === '500');
$check('marketplace.hold_hours default 72', ($d['marketplace.hold_hours'] ?? null) === '72');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} check(s) failed\n");
    exit(1);
}
echo "All marketplace checks passed\n";
