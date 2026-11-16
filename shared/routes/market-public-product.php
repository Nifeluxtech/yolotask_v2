<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Marketplace\Marketplace;
use YoloTask\Marketplace\Orders;
use YoloTask\Marketplace\Products;
use YoloTask\Money\Fees;
use YoloTask\Payments\Gateway;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/market-helpers.php';

/*
 * Main site: public product page /p.php?id=<product>[&ref=<affiliate code>]
 * (migration 0022). A valid affiliate code is kept for 30 days in a cookie
 * for this product (and in the session); a browser's first visit through a
 * link counts one click. Buyers enter name, email (+ phone for AfrStar) and
 * an optional coupon; the server quotes the price (bonus price, coupon,
 * platform fee) and sends them to the active pay-in gateway. No account.
 */
if (yolotask_role() !== 'main') yolotask_error_page(404);
header('Cache-Control: no-store, private');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');
try {
    $pdo = Database::connection();
} catch (Throwable $e) {
    Logger::error('marketplace.public_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$id = (int) ($_GET['id'] ?? 0);
$product = $id > 0 ? Products::find($pdo, $id) : null;
if ($product === null || !Products::isBuyable($product)) yolotask_error_page(404);

// Affiliate attribution.
$cookieName = 'yt_mref_' . $id;
$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if (is_string($_GET['ref'] ?? null) && $_GET['ref'] !== '') {
    try {
        $aff = Marketplace::affiliateByCode($pdo, $_GET['ref']);
        if ($aff !== null && (int) $aff['id'] !== $product['owner_user_id'] && $product['affiliate_percent'] > 0) {
            $code = (string) \YoloTask\Growth\Referrals::cleanCode($_GET['ref']);
            if (($_COOKIE[$cookieName] ?? '') !== $code) {
                Marketplace::recordClick($pdo, $id, (int) $aff['id']);
                setcookie($cookieName, $code, ['expires' => time() + Marketplace::REF_COOKIE_DAYS * 86400, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
                $_COOKIE[$cookieName] = $code;
            }
            $_SESSION['mkt_ref'][$id] = $code;
        }
    } catch (Throwable $e) {
        Logger::error('marketplace.ref_failed', ['exception' => get_class($e)]);
    }
}
$refCode = is_string($_COOKIE[$cookieName] ?? null) ? $_COOKIE[$cookieName] : (is_string($_SESSION['mkt_ref'][$id] ?? null) ? $_SESSION['mkt_ref'][$id] : null);

$flash = null;
$flashType = 'error';
$form = ['name' => '', 'email' => '', 'phone' => '', 'coupon' => is_string($_GET['coupon'] ?? null) ? mb_substr($_GET['coupon'], 0, 32) : ''];
$quote = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach (['name' => 80, 'email' => 254, 'phone' => 20, 'coupon' => 32] as $k => $max) $form[$k] = trim(yolotask_mkt_post($k, $max));
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        $flash = 'Your form expired. Please try again.';
    } else {
        try {
            if (yolotask_mkt_post('action', 10) === 'pay') {
                $order = Orders::checkout($pdo, $id, $form['name'], $form['email'], $form['phone'], $form['coupon'] !== '' ? $form['coupon'] : null, $refCode, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
                yolotask_redirect($order['pay_url']);
            }
            $quote = Orders::quote($pdo, $product, $form['email'], $form['coupon'] !== '' ? $form['coupon'] : null, Marketplace::affiliateByCode($pdo, $refCode));
            if ($quote['coupon'] !== null) { $flash = 'Coupon ' . $quote['coupon']['code'] . ' applied.'; $flashType = 'success'; }
        } catch (RuntimeException $e) {
            $flash = $e->getMessage();
        } catch (Throwable $e) {
            Logger::error('marketplace.checkout_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
            $flash = 'Something went wrong. Please try again.';
        }
    }
}
try {
    if ($quote === null) {
        try {
            $quote = Orders::quote($pdo, $product, $form['email'], $form['coupon'] !== '' ? $form['coupon'] : null, null);
        } catch (RuntimeException $e) {
            $flash ??= $e->getMessage();
            $form['coupon'] = '';
            $quote = Orders::quote($pdo, $product, '', null, null);
        }
    }
    $gateway = Gateway::payin($pdo);
    $configured = Gateway::isConfigured($gateway);
    $feeBps = Marketplace::feeBps($pdo);
    $description = $product['description'];
    $hasCover = Products::coverOwner($pdo, $product) !== null;
} catch (Throwable $e) {
    Logger::error('marketplace.public_page_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
$csrf = Csrf::token();
$self = 'p.php?id=' . $id;
ob_start();
?>
<div class="mkt-product" data-product="<?= $id ?>">
  <section class="card">
    <?= yolotask_mkt_cover($product, $hasCover) ?>
    <h1 class="text-break" style="margin-top:1rem"><?= h((string) $product['title']) ?></h1>
    <p class="text-sm text-faint">By <?= h((string) $product['owner_name']) ?><?= $product['sales_count'] > 0 ? ' · ' . number_format($product['sales_count']) . ' sold' : '' ?></p>
    <?= yolotask_mkt_price($product) ?>
    <div class="mkt-desc" style="margin-top:1rem"><?= h((string) $description) ?></div>
  </section>

  <section class="card" aria-labelledby="buy-title">
    <div class="card-header"><h2 id="buy-title">Buy now</h2><span class="badge badge-info"><?= $product['delivery_type'] === 'file' ? 'Instant download' : 'Instant access' ?></span></div>
    <dl class="receipt-list" data-quote>
      <?php if ($quote['list'] < $product['price_kobo']): ?><div><dt>Normal price</dt><dd><s><?= h(yolotask_money($product['price_kobo'])) ?></s></dd></div><?php endif; ?>
      <div><dt><?= $quote['list'] < $product['price_kobo'] ? 'Bonus price' : 'Price' ?></dt><dd data-list><?= h(yolotask_money($quote['list'])) ?></dd></div>
      <?php if ($quote['discount'] > 0): ?><div><dt>Coupon <?= h((string) ($quote['coupon']['code'] ?? '')) ?></dt><dd data-discount>− <?= h(yolotask_money($quote['discount'])) ?></dd></div><?php endif; ?>
      <?php if ($quote['fee_bearer'] === 'customer' && $quote['fee'] > 0): ?><div><dt>Platform fee (<?= h(Fees::percent($quote['fee_bps'])) ?>)</dt><dd data-fee><?= h(yolotask_money($quote['fee'])) ?></dd></div><?php endif; ?>
      <div><dt><strong>You pay</strong></dt><dd><strong data-amount><?= h(yolotask_money($quote['amount'])) ?></strong></dd></div>
    </dl>
    <?php if (!$configured): ?>
      <p class="text-sm text-faint">Checkout is not available right now. Please try again later.</p>
    <?php else: ?>
      <form method="post" action="<?= h($self) ?>" class="stack" data-checkout>
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <div class="field"><label class="label" for="name">Your name</label><input class="input" id="name" name="name" maxlength="80" autocomplete="name" value="<?= h($form['name']) ?>"></div>
        <div class="field"><label class="label" for="email">Email</label><input class="input" id="email" name="email" type="email" maxlength="254" autocomplete="email" value="<?= h($form['email']) ?>">
          <p class="input-hint">Your purchase is sent to this address.</p></div>
        <div class="field"><label class="label" for="phone">Phone number<?= $gateway === Gateway::KORAPAY ? ' (optional)' : '' ?></label><input class="input" id="phone" name="phone" type="tel" inputmode="tel" maxlength="20" autocomplete="tel" placeholder="0803 123 4567" value="<?= h($form['phone']) ?>">
          <p class="input-hint">Passed to <?= h(Gateway::label($gateway)) ?> for the payment page.</p></div>
        <div class="field"><label class="label" for="coupon">Coupon code (optional)</label>
          <div class="copy-field"><input class="input" id="coupon" name="coupon" maxlength="32" autocomplete="off" value="<?= h($form['coupon']) ?>"><button class="btn btn-secondary btn-sm" type="submit" name="action" value="quote" formnovalidate>Apply</button></div></div>
        <button class="btn btn-primary btn-block" type="submit" name="action" value="pay">Pay <?= h(yolotask_money($quote['amount'])) ?></button>
        <p class="text-xs text-faint">You pay on <?= h(Gateway::label($gateway)) ?>'s secure page. As soon as the payment is confirmed you get your product on the next page and by email. No account needed.</p>
      </form>
    <?php endif; ?>
  </section>
</div>
<?php
$mktBody = (string) ob_get_clean();
$mktTitle = (string) $product['title'];
$mktDescription = (string) $description;
$mktImage = $hasCover ? yolotask_mkt_cover_url($id) : '';
$mktFlash = $flash;
$mktFlashType = $flashType;
require YOLOTASK_APP_ROOT . '/shared/views/layouts/market.php';
exit;
