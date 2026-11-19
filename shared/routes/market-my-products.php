<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Marketplace\Coupons;
use YoloTask\Marketplace\Marketplace;
use YoloTask\Marketplace\Products;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/market-helpers.php';

/*
 * Earner / advertiser: My Products (migration 0022).
 * Advertisers create products; anyone who bought resell rights creates a
 * resale listing here. Owners pause/resume live items and manage coupons
 * for their own products and listings.
 */
$role = yolotask_role();
if (!in_array($role, ['earner', 'advertiser'], true)) yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('marketplace.products_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$uid = (int) $user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        yolotask_flash('Your form expired. Please try again.', 'error');
        yolotask_redirect('my-products.php');
    }
    $action = yolotask_mkt_post('action', 20);
    $back = 'my-products.php';
    try {
        switch ($action) {
            case 'pause':
            case 'resume':
                Products::setPaused($pdo, $user, (int) yolotask_mkt_post('product_id', 20), $action === 'pause');
                yolotask_flash($action === 'pause' ? 'Product paused. Buyers cannot buy it until you resume it.' : 'Product is live again.');
                break;
            case 'coupon':
                $id = Coupons::create($pdo, $user, $_POST);
                yolotask_flash('Coupon created.');
                $back = 'my-products.php#coupons';
                break;
            case 'coupon_on':
            case 'coupon_off':
                Coupons::setActive($pdo, $user, (int) yolotask_mkt_post('coupon_id', 20), $action === 'coupon_on');
                yolotask_flash($action === 'coupon_on' ? 'Coupon turned on.' : 'Coupon turned off.');
                $back = 'my-products.php#coupons';
                break;
            default:
                throw new RuntimeException('Choose an action.');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('marketplace.products_action_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

try {
    $products = Products::listForOwner($pdo, $uid);
    $rights = Marketplace::resellRightsFor($pdo, $uid);
    $coupons = Coupons::forOwner($pdo, $uid);
    $frozen = Marketplace::isFrozen($pdo, $uid);
} catch (Throwable $e) {
    Logger::error('marketplace.products_page_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
$csrf = Csrf::token();
$canCreate = $role === 'advertiser';
$lagosMin = (new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos')))->format('Y-m-d\TH:i');
ob_start();
?>
<?= yolotask_mkt_tabs('products') ?>
<?php if ($frozen): ?>
  <section class="card notice-card notice-warning" role="alert"><span class="verify-icon"><?= yolotask_nav_icon('shield') ?></span>
    <div><strong>Your marketplace selling is on hold</strong><p class="text-sm text-faint">Your products are hidden and sales earnings are paused while our team checks your account. Contact support.</p></div></section>
<?php endif; ?>

<section class="card mk-panel" aria-labelledby="prod-title">
  <div class="card-header"><h2 id="prod-title">My Products</h2><?php if ($canCreate && !$frozen): ?><a class="btn btn-primary btn-sm" href="product-edit.php">New product</a><?php endif; ?></div>
  <?php if (!$canCreate): ?><p class="text-sm text-faint">Buy resell rights from the marketplace to list a product as your own. Your listings show here.</p><?php endif; ?>
  <?php if ($products === []): ?>
    <div class="empty-state"><p class="text-faint"><?= $canCreate ? 'No products yet. Create one: it goes live after a quick review.' : 'No listings yet.' ?></p></div>
  <?php else: ?>
    <ul class="tx-list mk-rows">
      <?php foreach ($products as $p): $pid = (int) $p['id']; ?>
        <li class="tx-row mk-row" data-product="<?= $pid ?>">
          <span class="mk-thumb"><?= yolotask_mkt_cover($p, yolotask_mkt_has_cover($p), '', true) ?></span>
          <div class="tx-meta">
            <strong class="text-break"><?= h((string) $p['title']) ?></strong>
            <span class="text-xs text-faint"><?= $p['parent_product_id'] !== null ? 'Resale listing · ' : '' ?><?= (int) $p['paid_orders'] ?> sold · you earned <?= h(yolotask_money($p['seller_total'])) ?> · affiliates <?= (int) $p['affiliate_percent'] ?>% · fee paid by <?= $p['fee_bearer'] === 'seller' ? 'you' : 'buyer' ?></span>
            <?php if ($p['status'] === 'rejected' && !empty($p['reject_reason'])): ?><span class="text-xs text-danger">Not approved: <?= h((string) $p['reject_reason']) ?></span><?php endif; ?>
            <?php if ($p['status'] === 'live'): ?>
              <div class="copy-field" style="margin-top:6px"><input class="input" id="plink-<?= $pid ?>" type="text" readonly value="<?= h(Marketplace::productUrl($pid)) ?>" aria-label="Product link"><button type="button" class="btn btn-secondary btn-sm" data-copy="#plink-<?= $pid ?>">Copy</button></div>
            <?php endif; ?>
          </div>
          <div class="tx-amount mk-row-side">
            <?= yolotask_mkt_price($p) ?> <?= yolotask_mkt_status_badge((string) $p['status']) ?>
            <div class="hero-actions mk-row-actions">
              <a class="btn btn-ghost btn-sm" href="product-edit.php?id=<?= $pid ?>">Edit</a>
              <?php if (in_array($p['status'], ['live', 'paused'], true)): ?>
                <form method="post" action="my-products.php"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="product_id" value="<?= $pid ?>">
                  <button class="btn btn-ghost btn-sm" type="submit" name="action" value="<?= $p['status'] === 'live' ? 'pause' : 'resume' ?>"><?= $p['status'] === 'live' ? 'Pause' : 'Resume' ?></button></form>
              <?php endif; ?>
            </div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="card mk-panel" id="rights" aria-labelledby="rights-title">
  <div class="card-header"><h2 id="rights-title">Resell rights</h2><span class="badge badge-info"><?= count($rights) ?></span></div>
  <?php if ($rights === []): ?>
    <p class="text-sm text-faint">When a seller allows it, you can buy a product's resell rights from the <a href="marketplace.php">marketplace</a> and sell it as your own.</p>
  <?php else: ?>
    <ul class="tx-list">
      <?php foreach ($rights as $r): ?>
        <li class="tx-row"><div class="tx-meta"><strong class="text-break"><?= h((string) $r['title']) ?></strong><span class="text-xs text-faint">Bought <?= h(yolotask_wat((string) $r['created_at'], 'j M Y')) ?></span></div>
          <div class="tx-amount"><?php if ($r['listing_product_id'] === null): ?><a class="btn btn-primary btn-sm" href="product-edit.php?resale=<?= (int) $r['product_id'] ?>">Create my listing</a><?php else: ?><?= yolotask_mkt_status_badge((string) $r['listing_status']) ?> <a class="btn btn-ghost btn-sm" href="product-edit.php?id=<?= (int) $r['listing_product_id'] ?>">Edit listing</a><?php endif; ?></div></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="card mk-panel" id="coupons" aria-labelledby="coupon-title">
  <div class="card-header"><h2 id="coupon-title">Coupons</h2><span class="badge badge-info"><?= count($coupons) ?></span></div>
  <?php if ($products === []): ?>
    <p class="text-sm text-faint">Coupons give buyers a discount on your products. Create a product or listing first.</p>
  <?php else: ?>
    <form method="post" action="my-products.php" class="stack" data-coupon-form>
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="coupon">
      <div class="price-grid">
        <div class="field"><label class="label" for="c-code">Code</label><input class="input" id="c-code" name="code" maxlength="32" pattern="[A-Za-z0-9_\-]{4,32}" placeholder="Leave empty to generate"></div>
        <div class="field"><label class="label" for="c-product">Applies to</label>
          <select class="input" id="c-product" name="product_id"><option value="0">All my products</option>
            <?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"><?= h(mb_strimwidth((string) $p['title'], 0, 60, '…')) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label class="label" for="c-kind">Discount type</label>
          <select class="input" id="c-kind" name="kind"><option value="percent">Percent off</option><option value="fixed">Naira off</option></select></div>
        <div class="field"><label class="label" for="c-value">Discount</label><input class="input" id="c-value" name="value" inputmode="decimal" required maxlength="12" placeholder="10 (%) or 500 (₦)"></div>
        <div class="field"><label class="label" for="c-exp">Expires (Lagos time, optional)</label><input class="input" id="c-exp" name="expires" type="datetime-local" min="<?= h($lagosMin) ?>"></div>
        <div class="field"><label class="label" for="c-max">Maximum uses (optional)</label><input class="input" id="c-max" name="max_uses" inputmode="numeric" pattern="[0-9]*" maxlength="7" placeholder="No limit"></div>
      </div>
      <p class="input-hint">The discount comes off the price charged (the bonus price when you set one), before the platform fee. Affiliate commission is worked out on the discounted price. A coupon never takes a price below ₦100. Uses count only when a payment is confirmed.</p>
      <button class="btn btn-primary" type="submit"<?= $frozen ? ' disabled' : '' ?>>Create coupon</button>
    </form>
    <?php if ($coupons !== []): ?>
      <ul class="tx-list">
        <?php foreach ($coupons as $c): $expired = $c['expires_at'] !== null && strtotime((string) $c['expires_at'] . ' UTC') <= time(); ?>
          <li class="tx-row" data-coupon="<?= h((string) $c['code']) ?>">
            <div class="tx-meta"><strong class="mono"><?= h((string) $c['code']) ?></strong>
              <span class="text-xs text-faint"><?= h(Coupons::label($c)) ?> · <?= $c['product_id'] === null ? 'all my products' : h((string) $c['product_title']) ?> · used <?= (int) $c['uses'] ?><?= $c['max_uses'] !== null ? ' of ' . (int) $c['max_uses'] : '' ?><?= $c['expires_at'] !== null ? ' · ' . ($expired ? 'expired ' : 'expires ') . h(yolotask_wat((string) $c['expires_at'])) : '' ?></span></div>
            <div class="tx-amount"><?= $c['status'] === 'active' && !$expired ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-info">' . ($expired ? 'Expired' : 'Off') . '</span>' ?>
              <form method="post" action="my-products.php"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="coupon_id" value="<?= (int) $c['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit" name="action" value="<?= $c['status'] === 'active' ? 'coupon_off' : 'coupon_on' ?>"><?= $c['status'] === 'active' ? 'Turn off' : 'Turn on' ?></button></form></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($user, 'My Products', (string) ob_get_clean(), 'marketplace');
