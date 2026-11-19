<?php
declare(strict_types=1);

use YoloTask\Account\Activation;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Marketplace\Marketplace;
use YoloTask\Marketplace\Orders;
use YoloTask\Marketplace\Pricing;
use YoloTask\Marketplace\Products;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/market-helpers.php';

/*
 * Earner / advertiser: the marketplace tab (migration 0022).
 * Earners (affiliates) see every live product with its price and "You earn
 * ₦X per sale", and get their personal link in one tap. Both roles can buy a
 * product's resell rights when the seller allows it (paid on the active
 * pay-in gateway; the gateway returns to the main site's order page).
 * While account activation is required, an earner who has not activated
 * cannot get links or buy rights (same rule as tasks).
 */
$role = yolotask_role();
if (!in_array($role, ['earner', 'advertiser'], true)) yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('marketplace.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$uid = (int) $user['id'];
$isEarner = $role === 'earner';
$locked = $isEarner && !Activation::canWork($pdo, $uid);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        yolotask_flash('Your form expired. Please try again.', 'error');
        yolotask_redirect('marketplace.php');
    }
    $action = yolotask_mkt_post('action', 20);
    $pid = (int) yolotask_mkt_post('product_id', 20);
    try {
        if ($locked) throw new RuntimeException('Activate your account to use the marketplace.');
        $p = Products::find($pdo, $pid);
        if ($p === null || !Products::isBuyable($p)) throw new RuntimeException('This product is not available right now.');
        if ($action === 'link') {
            if (!$isEarner) throw new RuntimeException('Affiliate links are for earner accounts.');
            if ($p['owner_user_id'] === $uid) throw new RuntimeException('This is your own product. Share its link from My Products; self-referrals earn no commission.');
            if ($p['affiliate_percent'] <= 0) throw new RuntimeException('This product does not pay affiliates.');
            Marketplace::affiliateLink($pdo, $uid, $pid);
            yolotask_redirect('marketplace.php?link=' . $pid . '#product-' . $pid);
        }
        if ($action === 'resell') {
            $order = Orders::buyResellRights($pdo, $user, $pid, yolotask_mkt_post('phone', 20));
            yolotask_redirect($order['pay_url']);
        }
        throw new RuntimeException('Choose an action.');
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('marketplace.action_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect('marketplace.php');
}

try {
    $q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 80) : '';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $list = Products::liveList($pdo, $q, $page);
    $feeBps = Marketplace::feeBps($pdo);
    $linkFor = (int) ($_GET['link'] ?? 0);
    $myLink = null;
    if ($isEarner && !$locked && $linkFor > 0) {
        $lp = Products::find($pdo, $linkFor);
        if ($lp !== null && Products::isBuyable($lp) && $lp['owner_user_id'] !== $uid && $lp['affiliate_percent'] > 0) $myLink = Marketplace::affiliateLink($pdo, $uid, $linkFor);
    }
    $rights = [];
    foreach (Marketplace::resellRightsFor($pdo, $uid) as $r) $rights[(int) $r['product_id']] = true;
} catch (Throwable $e) {
    Logger::error('marketplace.page_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
$csrf = Csrf::token();
$phone = (string) ($user['phone'] ?? '');
if (str_starts_with($phone, '+234')) $phone = '0' . substr($phone, 4);
ob_start();
?>
<?= yolotask_mkt_tabs('browse') ?>
<?php if ($locked): ?>
  <section class="card notice-card notice-warning" role="status">
    <span class="verify-icon"><?= yolotask_nav_icon('shield') ?></span>
    <div><strong>Activate your account to earn from the marketplace</strong><p class="text-sm text-faint">Affiliate links and resell rights open once your account is activated.</p></div>
    <a class="btn btn-primary btn-sm" href="activate.php">Activate</a>
  </section>
<?php endif; ?>
<section class="card mk-hero" aria-labelledby="mkt-intro">
  <div class="mk-hero-head">
    <div class="mk-hero-copy">
      <p class="mk-eyebrow"><?= $isEarner ? 'Affiliate marketplace' : 'Marketplace' ?></p>
      <h2 id="mkt-intro"><?= $isEarner ? 'Promote products and earn' : 'Digital products' ?></h2>
    </div>
    <span class="badge badge-info mk-count"><span class="mk-live-dot" aria-hidden="true"></span><?= number_format($list['total']) ?> live</span>
  </div>
  <p class="mk-lede"><?= $isEarner
      ? 'Get your personal link for any product below and share it. When someone buys through your link, you earn the commission shown. Commissions reach your wallet after a short holding period.'
      : 'Products sold on YOLOTASK. Sell your own in My Products, or buy resell rights where a seller offers them and list the product as your own.' ?></p>
  <form method="get" action="marketplace.php" class="mk-search" role="search">
    <span class="mk-search-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></span>
    <input class="input mk-search-input" type="search" name="q" maxlength="80" placeholder="Search products" value="<?= h($q) ?>" aria-label="Search products" enterkeyhint="search">
    <?php if ($q !== ''): ?><a class="mk-search-clear" href="marketplace.php" aria-label="Clear search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></a><?php endif; ?>
    <button class="btn btn-primary mk-search-btn" type="submit">Search</button>
  </form>
  <?php if ($q !== ''): ?><p class="mk-results" role="status"><?= number_format($list['total']) ?> result<?= $list['total'] === 1 ? '' : 's' ?> for “<?= h($q) ?>”</p><?php endif; ?>
</section>

<?php if ($list['rows'] === []): ?>
  <section class="card mk-empty"><div class="empty-state">
    <span class="mk-empty-icon" aria-hidden="true"><?= yolotask_nav_icon('bag') ?></span>
    <p class="mk-empty-title"><?= $q !== '' ? 'No live products match your search.' : 'No products are live yet. Check back soon.' ?></p>
    <?php if ($q !== ''): ?><a class="btn btn-secondary btn-sm" href="marketplace.php">Show all products</a><?php endif; ?>
  </div></section>
<?php else: ?>
  <div class="mkt-grid mk-grid">
    <?php foreach ($list['rows'] as $p): $pid = (int) $p['id']; $own = $p['owner_user_id'] === $uid; $earn = Pricing::affiliateEarns($p, $feeBps); $url = Marketplace::productUrl($pid); ?>
      <article class="mkt-card mk-card" id="product-<?= $pid ?>" aria-labelledby="mk-title-<?= $pid ?>">
        <?= yolotask_mkt_media($p, yolotask_mkt_has_cover($p), $url) ?>
        <div class="mk-body">
          <?= yolotask_mkt_chips_html($p, $isEarner && !$own) ?>
          <h3 class="mk-title text-break" id="mk-title-<?= $pid ?>"><a href="<?= h($url) ?>" target="_blank" rel="noopener"><?= h((string) $p['title']) ?><span class="mk-sr"> (opens in a new tab)</span></a></h3>
          <?= yolotask_mkt_seller($p) ?>
          <div class="mk-price-row"><?= yolotask_mkt_price($p) ?></div>
        </div>
        <div class="mk-actions">
        <?php if ($isEarner && !$own): ?>
          <?php if ($earn > 0): ?>
            <p class="mkt-earn mk-earn" data-earn="<?= $earn ?>"><span class="mk-earn-label">You earn</span> <strong><?= h(yolotask_money($earn)) ?></strong> <span class="mk-earn-label">per sale</span></p>
            <?php if ($myLink !== null && $linkFor === $pid): ?>
              <div class="copy-field mk-copy"><input class="input" id="link-<?= $pid ?>" type="text" readonly value="<?= h($myLink) ?>" aria-label="Your affiliate link"><button type="button" class="btn btn-secondary btn-sm" data-copy="#link-<?= $pid ?>">Copy</button></div>
            <?php elseif (!$locked): ?>
              <form method="post" action="marketplace.php"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="link"><input type="hidden" name="product_id" value="<?= $pid ?>">
                <button class="btn btn-primary btn-block mk-cta" type="submit">Get my link</button></form>
            <?php endif; ?>
          <?php else: ?>
            <p class="mk-note">This product does not pay affiliates.</p>
          <?php endif; ?>
        <?php elseif ($own): ?>
          <p class="mk-note"><span class="badge badge-info">Your product</span> <a href="my-products.php">Manage</a></p>
        <?php else: ?>
          <a class="btn btn-secondary btn-block mk-cta" href="<?= h($url) ?>" target="_blank" rel="noopener">View product<span class="mk-sr"> <?= h((string) $p['title']) ?> (opens in a new tab)</span></a>
        <?php endif; ?>
        <?php if (!$own && $p['parent_product_id'] === null && $p['resell_allowed'] && $p['resell_price_kobo'] !== null): ?>
          <?php if (!empty($rights[$pid])): ?>
            <p class="mk-note"><span class="badge badge-success">Resell rights owned</span> <a href="my-products.php#rights">List it</a></p>
          <?php elseif (!$locked): ?>
            <details class="mk-resell">
              <summary><span>Resell rights</span> <strong><?= h(yolotask_money($p['resell_price_kobo'])) ?></strong></summary>
              <div class="mk-resell-body">
              <p class="text-xs text-faint">Buy the right to sell this product as your own: set your own price and keep what you sell it for, minus the platform fee.<?= $p['fee_bearer'] === 'customer' ? ' A ' . h(\YoloTask\Money\Fees::percent($feeBps)) . ' platform fee is added at checkout.' : '' ?></p>
              <form method="post" action="marketplace.php" class="stack"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="resell"><input type="hidden" name="product_id" value="<?= $pid ?>">
                <input class="input" name="phone" type="tel" inputmode="tel" maxlength="20" autocomplete="tel" placeholder="Phone for the payment page" value="<?= h($phone) ?>" aria-label="Phone number">
                <button class="btn btn-secondary btn-block" type="submit">Buy resell rights</button></form>
              </div>
            </details>
          <?php endif; ?>
        <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if ($list['pages'] > 1): ?>
    <nav class="mk-pager" aria-label="Pages">
      <?php if ($list['page'] > 1): ?><a class="btn btn-secondary btn-sm" href="marketplace.php?q=<?= h(rawurlencode($q)) ?>&page=<?= $list['page'] - 1 ?>" rel="prev">Previous</a><?php else: ?><span></span><?php endif; ?>
      <span class="mk-pager-status">Page <?= (int) $list['page'] ?> of <?= (int) $list['pages'] ?></span>
      <?php if ($list['page'] < $list['pages']): ?><a class="btn btn-secondary btn-sm" href="marketplace.php?q=<?= h(rawurlencode($q)) ?>&page=<?= $list['page'] + 1 ?>" rel="next">Next</a><?php else: ?><span></span><?php endif; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>
<?php
yolotask_render_app($user, 'Marketplace', (string) ob_get_clean(), 'marketplace');
