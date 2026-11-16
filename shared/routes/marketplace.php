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
<section class="card" aria-labelledby="mkt-intro">
  <div class="card-header"><h2 id="mkt-intro"><?= $isEarner ? 'Promote products and earn' : 'Digital products' ?></h2><span class="badge badge-info"><?= number_format($list['total']) ?> live</span></div>
  <p class="text-sm text-faint"><?= $isEarner
      ? 'Get your personal link for any product below and share it. When someone buys through your link, you earn the commission shown. Commissions reach your wallet after a short holding period.'
      : 'Products sold on YOLOTASK. Sell your own in My Products, or buy resell rights where a seller offers them and list the product as your own.' ?></p>
  <form method="get" action="marketplace.php" class="copy-field" role="search">
    <input class="input" type="search" name="q" maxlength="80" placeholder="Search products" value="<?= h($q) ?>" aria-label="Search products">
    <button class="btn btn-secondary btn-sm" type="submit">Search</button>
  </form>
</section>

<?php if ($list['rows'] === []): ?>
  <section class="card"><div class="empty-state"><p class="text-faint"><?= $q !== '' ? 'No live products match your search.' : 'No products are live yet. Check back soon.' ?></p></div></section>
<?php else: ?>
  <div class="mkt-grid">
    <?php foreach ($list['rows'] as $p): $pid = (int) $p['id']; $own = $p['owner_user_id'] === $uid; $earn = Pricing::affiliateEarns($p, $feeBps); ?>
      <article class="card mkt-card" id="product-<?= $pid ?>">
        <?= yolotask_mkt_cover($p, yolotask_mkt_has_cover($p)) ?>
        <h3 class="text-break"><a href="<?= h(Marketplace::productUrl($pid)) ?>" target="_blank" rel="noopener"><?= h((string) $p['title']) ?></a></h3>
        <p class="text-xs text-faint">By <?= h((string) $p['owner_name']) ?><?= $p['sales_count'] > 0 ? ' · ' . number_format($p['sales_count']) . ' sold' : '' ?></p>
        <?= yolotask_mkt_price($p) ?>
        <?php if ($isEarner && !$own): ?>
          <?php if ($earn > 0): ?>
            <p class="mkt-earn" data-earn="<?= $earn ?>">You earn <?= h(yolotask_money($earn)) ?> per sale</p>
            <?php if ($myLink !== null && $linkFor === $pid): ?>
              <div class="copy-field"><input class="input" id="link-<?= $pid ?>" type="text" readonly value="<?= h($myLink) ?>"><button type="button" class="btn btn-secondary btn-sm" data-copy="#link-<?= $pid ?>">Copy</button></div>
            <?php elseif (!$locked): ?>
              <form method="post" action="marketplace.php"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="link"><input type="hidden" name="product_id" value="<?= $pid ?>">
                <button class="btn btn-primary btn-block btn-sm" type="submit">Get my link</button></form>
            <?php endif; ?>
          <?php else: ?>
            <p class="text-xs text-faint">This product does not pay affiliates.</p>
          <?php endif; ?>
        <?php elseif ($own): ?>
          <p class="text-xs text-faint">Your product · <a href="my-products.php">Manage</a></p>
        <?php endif; ?>
        <?php if (!$own && $p['parent_product_id'] === null && $p['resell_allowed'] && $p['resell_price_kobo'] !== null): ?>
          <?php if (!empty($rights[$pid])): ?>
            <p class="text-xs"><span class="badge badge-success">Resell rights owned</span> <a href="my-products.php#rights">List it</a></p>
          <?php elseif (!$locked): ?>
            <details>
              <summary class="text-sm">Resell rights: <?= h(yolotask_money($p['resell_price_kobo'])) ?></summary>
              <p class="text-xs text-faint">Buy the right to sell this product as your own: set your own price and keep what you sell it for, minus the platform fee.<?= $p['fee_bearer'] === 'customer' ? ' A ' . h(\YoloTask\Money\Fees::percent($feeBps)) . ' platform fee is added at checkout.' : '' ?></p>
              <form method="post" action="marketplace.php" class="stack"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="resell"><input type="hidden" name="product_id" value="<?= $pid ?>">
                <input class="input" name="phone" type="tel" inputmode="tel" maxlength="20" autocomplete="tel" placeholder="Phone for the payment page" value="<?= h($phone) ?>" aria-label="Phone number">
                <button class="btn btn-secondary btn-block btn-sm" type="submit">Buy resell rights</button></form>
            </details>
          <?php endif; ?>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if ($list['pages'] > 1): ?>
    <nav class="flex-between" aria-label="Pages">
      <?php if ($list['page'] > 1): ?><a class="btn btn-ghost btn-sm" href="marketplace.php?q=<?= h(rawurlencode($q)) ?>&page=<?= $list['page'] - 1 ?>">Previous</a><?php else: ?><span></span><?php endif; ?>
      <span class="text-sm text-faint">Page <?= (int) $list['page'] ?> of <?= (int) $list['pages'] ?></span>
      <?php if ($list['page'] < $list['pages']): ?><a class="btn btn-ghost btn-sm" href="marketplace.php?q=<?= h(rawurlencode($q)) ?>&page=<?= $list['page'] + 1 ?>">Next</a><?php else: ?><span></span><?php endif; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>
<?php
yolotask_render_app($user, 'Marketplace', (string) ob_get_clean(), 'marketplace');
