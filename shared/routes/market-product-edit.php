<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Marketplace\Marketplace;
use YoloTask\Marketplace\Pricing;
use YoloTask\Marketplace\Products;
use YoloTask\Money\Fees;
use YoloTask\Money\Money;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/market-helpers.php';

/*
 * Earner / advertiser: create or edit a product (advertisers), or create /
 * edit a resale listing (anyone who bought the resell rights). Migration 0022.
 *   product-edit.php            new product (advertiser)
 *   product-edit.php?id=N       edit own product or listing
 *   product-edit.php?resale=N   new listing from resell rights of product N
 */
$role = yolotask_role();
if (!in_array($role, ['earner', 'advertiser'], true)) yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('marketplace.edit_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$uid = (int) $user['id'];
$id = (int) ($_GET['id'] ?? 0);
$resaleOf = (int) ($_GET['resale'] ?? 0);
$product = $id > 0 ? Products::forOwner($pdo, $uid, $id) : null;
if ($id > 0 && $product === null) yolotask_error_page(404);
$parent = null;
if ($resaleOf > 0) {
    if (!Marketplace::ownsRights($pdo, $uid, $resaleOf)) yolotask_error_page(404);
    $parent = Products::find($pdo, $resaleOf);
    if ($parent === null) yolotask_error_page(404);
}
if ($product === null && $parent === null && $role !== 'advertiser') {
    yolotask_flash('Only advertiser accounts create products. Buy resell rights to list a product as your own.', 'warning');
    yolotask_redirect('my-products.php');
}
$isResale = $parent !== null || ($product !== null && $product['parent_product_id'] !== null);
$self = 'product-edit.php' . ($product !== null ? '?id=' . $product['id'] : ($parent !== null ? '?resale=' . $parent['id'] : ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        yolotask_flash('That upload is too large for the server. Use a smaller file or a private link.', 'error');
        yolotask_redirect($self);
    }
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        yolotask_flash('Your form expired. Please try again.', 'error');
        yolotask_redirect($self);
    }
    $in = [];
    foreach (['title' => 120, 'description' => 5000, 'price' => 20, 'bonus_price' => 20, 'fee_bearer' => 10, 'affiliate_percent' => 3, 'resell_price' => 20, 'delivery_type' => 10, 'delivery_text' => 5000] as $k => $max) {
        $in[$k] = yolotask_mkt_post($k, $max + 10);
    }
    $in['resell_allowed'] = !empty($_POST['resell_allowed']);
    try {
        if ($parent !== null) {
            Products::createResale($pdo, $user, (int) $parent['id'], $in, $_FILES);
            yolotask_flash('Listing submitted. It goes live after a quick review.');
        } else {
            $wasLive = $product !== null && $product['status'] === 'live';
            $savedId = Products::save($pdo, $user, $product['id'] ?? null, $in, $_FILES);
            $now = Products::find($pdo, $savedId);
            yolotask_flash($product === null ? 'Product submitted. It goes live after a quick review.'
                : ($wasLive && $now !== null && $now['status'] === 'pending_review' ? 'Saved. Your changes go back for a quick review; the product is hidden until it is approved.' : 'Saved.'));
        }
        yolotask_redirect('my-products.php');
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
        $_SESSION['_mkt_form'] = array_diff_key($in, ['delivery_text' => 1]);
    } catch (Throwable $e) {
        Logger::error('marketplace.edit_save_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($self);
}

$old = is_array($_SESSION['_mkt_form'] ?? null) ? $_SESSION['_mkt_form'] : [];
unset($_SESSION['_mkt_form']);
$src = $product ?? $parent;
$val = static function (string $k, string $fallback = '') use ($old): string {
    return isset($old[$k]) && is_string($old[$k]) ? $old[$k] : $fallback;
};
$naira = static fn (?int $kobo): string => $kobo === null ? '' : rtrim(rtrim(Money::decimal($kobo), '0'), '.');
$title = $val('title', $product !== null ? (string) $product['title'] : '');
$description = $val('description', $product !== null ? (string) $product['description'] : '');
$price = $val('price', $product !== null ? $naira($product['price_kobo']) : '');
$bonus = $val('bonus_price', $product !== null ? $naira($product['bonus_price_kobo']) : '');
$feeBearer = $val('fee_bearer', $product !== null ? (string) $product['fee_bearer'] : 'customer');
$affiliate = $val('affiliate_percent', $product !== null ? (string) $product['affiliate_percent'] : '0');
$resellAllowed = $product !== null ? (bool) $product['resell_allowed'] : false;
$resellPrice = $val('resell_price', $product !== null ? $naira($product['resell_price_kobo']) : '');
$deliveryType = $val('delivery_type', $product !== null ? (string) $product['delivery_type'] : 'link');
$deliveryText = $product !== null ? (string) ($product['delivery_text'] ?? '') : '';
$feeBps = Marketplace::feeBps($pdo);
$csrf = Csrf::token();
$pageTitle = $parent !== null ? 'Create your listing' : ($product === null ? 'New product' : ($isResale ? 'Edit listing' : 'Edit product'));
ob_start();
?>
<?= yolotask_mkt_tabs('products') ?>
<div class="wallet-grid">
<?php if ($product !== null): ?>
  <section class="card notice-card" role="status"><span class="verify-icon"><?= yolotask_nav_icon('bag') ?></span>
    <div><strong><?= h((string) $product['title']) ?></strong> <?= yolotask_mkt_status_badge((string) $product['status']) ?>
      <p class="text-sm text-faint">Changes to the title, description, cover or delivery go back to review before the product is live again. Price, fee and affiliate changes apply at once.</p></div></section>
<?php endif; ?>
<?php if ($parent !== null): ?>
  <section class="card notice-card" role="status"><span class="verify-icon"><?= yolotask_nav_icon('bag') ?></span>
    <div><strong>Reselling “<?= h((string) $parent['title']) ?>”</strong>
      <p class="text-sm text-faint">Buyers of your listing get the same product content. Set your own price; your own title, description and cover are optional (leave them empty to use the original's). You keep each sale minus the platform fee and any affiliate commission you offer.</p></div></section>
<?php endif; ?>

<form method="post" action="<?= h($self) ?>" enctype="multipart/form-data" class="card stack" data-product-form>
  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
  <div class="field"><label class="label" for="title">Title<?= $parent !== null ? ' (optional)' : '' ?></label>
    <input class="input" id="title" name="title" maxlength="120"<?= $parent !== null ? '' : ' required minlength="3"' ?> value="<?= h($title) ?>" placeholder="<?= $parent !== null ? h((string) $parent['title']) : 'e.g. Instagram Growth Playbook (PDF)' ?>"></div>
  <div class="field"><label class="label" for="cover">Cover image<?= $parent !== null || $product !== null ? ' (optional)' : '' ?></label>
    <?php if ($product !== null && yolotask_mkt_has_cover($product)): ?><?= yolotask_mkt_cover($product, true) ?><?php endif; ?>
    <input class="input" id="cover" name="cover" type="file" accept="image/jpeg,image/png,image/webp">
    <p class="input-hint">JPEG, PNG or WebP up to 3 MB. A 4:3 image looks best.</p></div>
  <div class="field"><label class="label" for="description">Description<?= $parent !== null ? ' (optional)' : '' ?></label>
    <textarea class="input" id="description" name="description" rows="7" maxlength="5000"<?= $parent !== null ? '' : ' required minlength="10"' ?> placeholder="<?= $parent !== null ? 'Leave empty to use the original description' : 'What the buyer gets and why it helps them' ?>"><?= h($description) ?></textarea></div>

  <div class="price-grid">
    <div class="field"><label class="label" for="price">Normal price (₦)</label>
      <input class="input" id="price" name="price" inputmode="decimal" required maxlength="14" placeholder="5000" value="<?= h($price) ?>"></div>
    <div class="field"><label class="label" for="bonus_price">Bonus price (₦, optional)</label>
      <input class="input" id="bonus_price" name="bonus_price" inputmode="decimal" maxlength="14" placeholder="Promo price" value="<?= h($bonus) ?>"></div>
  </div>
  <p class="input-hint">With a bonus price, buyers see the normal price struck through and pay the bonus price. It must be lower than the normal price. Minimum price ₦100.</p>

  <fieldset class="field"><legend class="label">Platform fee (<?= h(Fees::percent($feeBps)) ?> per sale)</legend>
    <label class="radio"><input type="radio" name="fee_bearer" value="customer"<?= $feeBearer !== 'seller' ? ' checked' : '' ?>> The buyer pays it (added at checkout)</label>
    <label class="radio"><input type="radio" name="fee_bearer" value="seller"<?= $feeBearer === 'seller' ? ' checked' : '' ?>> I pay it (taken from my share)</label></fieldset>

  <div class="field"><label class="label" for="affiliate_percent">Affiliate commission (%)</label>
    <input class="input" id="affiliate_percent" name="affiliate_percent" inputmode="numeric" pattern="[0-9]{1,2}" maxlength="2" value="<?= h($affiliate) ?>">
    <p class="input-hint">0 turns affiliates off. Up to <?= Pricing::MAX_AFFILIATE_PERCENT ?>%. Earners who share your product earn this percent of the price charged (after any coupon) on each sale they bring.</p></div>

  <?php if (!$isResale): ?>
    <fieldset class="field"><legend class="label">Resell rights</legend>
      <label class="checkbox"><input type="checkbox" name="resell_allowed" value="1"<?= $resellAllowed ? ' checked' : '' ?>> Allow others to buy resell rights and sell this product as their own</label>
      <label class="label" for="resell_price">Resell rights price (₦)</label>
      <input class="input" id="resell_price" name="resell_price" inputmode="decimal" maxlength="14" placeholder="e.g. 25000" value="<?= h($resellPrice) ?>">
      <p class="input-hint">You receive the resell rights price (minus the platform fee if you pay it). Resellers keep the sales of their own listings.</p></fieldset>

    <fieldset class="field"><legend class="label">Delivery after payment</legend>
      <label class="radio"><input type="radio" name="delivery_type" value="link"<?= $deliveryType !== 'file' ? ' checked' : '' ?>> Private link or text</label>
      <label class="radio"><input type="radio" name="delivery_type" value="file"<?= $deliveryType === 'file' ? ' checked' : '' ?>> File download</label>
      <label class="label" for="delivery_file">File<?= $product !== null && $product['delivery_path'] !== null ? ' (current: ' . h((string) $product['delivery_name']) . '; upload to replace)' : '' ?></label>
      <input class="input" id="delivery_file" name="delivery_file" type="file">
      <p class="input-hint">Up to <?= Products::FILE_MAX_BYTES / 1048576 ?> MB (PDF, ZIP, EPUB, Office, audio, video, images). Larger files: use a private link (Google Drive, Dropbox …).</p>
      <label class="label" for="delivery_text">Private link or text</label>
      <textarea class="input" id="delivery_text" name="delivery_text" rows="4" maxlength="5000" placeholder="https://… or the access instructions buyers see after paying"><?= h($deliveryText) ?></textarea>
      <p class="input-hint">Shown only to buyers after payment, on their order page and in their email. With a file, this is an optional note.</p></fieldset>
  <?php endif; ?>

  <button class="btn btn-primary btn-block" type="submit"><?= $product === null ? 'Submit for review' : 'Save' ?></button>
  <a class="btn btn-ghost btn-block" href="my-products.php">Cancel</a>
</form>
</div>
<?php
yolotask_render_app($user, $pageTitle, (string) ob_get_clean(), 'marketplace');
