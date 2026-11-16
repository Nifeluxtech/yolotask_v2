<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Marketplace\Marketplace;
use YoloTask\Marketplace\Orders;
use YoloTask\Marketplace\Pricing;
use YoloTask\Marketplace\Products;
use YoloTask\Money\Fees;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/tasks-helpers.php';
require_once __DIR__ . '/market-helpers.php';

/*
 * Admin → Tasks → Marketplace (migration 0022).
 *   ?tab=products  review queue: approve / reject products and resale listings (default)
 *   ?tab=orders    orders; verify a paid order the callback missed, accept / reject amount mismatches
 *   ?tab=sellers   freeze / unfreeze a seller (products hidden, earnings not released)
 *   ?tab=settings  platform fee (marketplace.fee_bps) and hold hours (marketplace.hold_hours)
 *   ?file=<id>     download a product's delivery file to review it
 */
if (yolotask_role() !== 'admin') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $admin = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('admin.marketplace_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$adminId = (int) $admin['id'];
$tab = in_array($_GET['tab'] ?? '', ['products', 'orders', 'sellers', 'settings'], true) ? (string) $_GET['tab'] : 'products';

if (isset($_GET['file'])) {
    $p = Products::find($pdo, (int) $_GET['file']);
    $path = $p !== null ? Products::privatePath('files', $p['delivery_path']) : null;
    if ($path === null) yolotask_error_page(404);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', Products::cleanName((string) ($p['delivery_name'] ?? 'file'))) . '"');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    readfile($path);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $back = 'marketplace.php?tab=' . $tab;
    yolotask_csrf_or_back($back);
    $action = yolotask_post_str('action');
    try {
        switch ($action) {
            case 'approve':
            case 'reject':
                Products::review($pdo, $adminId, (int) yolotask_post_str('product_id'), $action === 'approve', yolotask_post_str('reason'));
                yolotask_flash($action === 'approve' ? 'Product approved and live.' : 'Product rejected; the seller was told why.');
                break;
            case 'settings':
                $fee = Fees::parsePercent(yolotask_post_str('fee_percent'));
                if ($fee === null || $fee > Pricing::MAX_FEE_BPS) throw new RuntimeException('Enter the platform fee as a percent from 0 to ' . (Pricing::MAX_FEE_BPS / 100) . '.');
                $hold = yolotask_post_str('hold_hours');
                if (!preg_match('/^\d{1,3}$/', $hold) || (int) $hold > 720) throw new RuntimeException('Enter the hold as whole hours from 0 to 720.');
                Settings::save($pdo, $adminId, ['marketplace.fee_bps' => (string) $fee, 'marketplace.hold_hours' => (string) (int) $hold]);
                yolotask_flash('Marketplace settings saved. They apply to new orders; orders already paid keep their split and release time.');
                break;
            case 'freeze':
            case 'unfreeze':
                $email = trim(yolotask_post_str('email'));
                $uid = (int) yolotask_post_str('user_id');
                if ($uid <= 0 && $email !== '') {
                    $s = $pdo->prepare('SELECT id FROM users WHERE email = :e');
                    $s->execute(['e' => strtolower($email)]);
                    $uid = (int) $s->fetchColumn();
                }
                if ($uid <= 0) throw new RuntimeException('No account has that email.');
                Marketplace::setFrozen($pdo, $adminId, $uid, $action === 'freeze', yolotask_post_str('reason'));
                yolotask_flash($action === 'freeze' ? 'Seller frozen: their products are hidden and their earnings stay on hold.' : 'Seller unfrozen. Held earnings past their hold are released at the next hourly run.');
                break;
            case 'verify':
                Orders::adminVerify($pdo, $adminId, (int) yolotask_post_str('order_id'), yolotask_post_str('reference'), yolotask_post_str('note'));
                yolotask_flash('Order confirmed as paid; the buyer was sent their product.');
                break;
            case 'accept':
            case 'decline':
                Orders::adminReview($pdo, $adminId, (int) yolotask_post_str('order_id'), $action === 'accept', yolotask_post_str('note'));
                yolotask_flash($action === 'accept' ? 'Order accepted as paid; the buyer was sent their product.' : 'Order rejected. Refund the buyer outside the app.');
                break;
            default:
                throw new RuntimeException('Choose an action.');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.marketplace_action_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

try {
    $stats = Marketplace::adminStats($pdo);
    $status = is_string($_GET['status'] ?? null) ? (string) $_GET['status'] : '';
    $q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 80) : '';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $products = $tab === 'products' ? Products::adminList($pdo, $status === '' ? 'pending_review' : $status, $q, $page) : null;
    $orders = $tab === 'orders' ? Orders::adminList($pdo, $status, $q, $page) : null;
    $frozen = $tab === 'sellers' ? Marketplace::frozenList($pdo) : [];
    $feeBps = Marketplace::feeBps($pdo);
    $holdHours = Marketplace::holdHours($pdo);
} catch (Throwable $e) {
    Logger::error('admin.marketplace_page_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
$csrf = Csrf::token();
$hidden = '<input type="hidden" name="_csrf" value="' . h($csrf) . '">';
$tabUrl = static fn (string $t): string => 'marketplace.php?tab=' . $t;
ob_start();
?>
<?= yolotask_admin_market_tabs($pdo, 'marketplace') ?>
<section class="grid grid-4 dash-stats money-stats" aria-label="Marketplace overview">
  <article class="stat-card stat-warning"><span class="stat-label">Waiting for review</span><span class="stat-value"><?= number_format($stats['pending_products']) ?></span></article>
  <article class="stat-card stat-success"><span class="stat-label">Paid orders</span><span class="stat-value"><?= number_format($stats['orders']) ?></span><span class="text-xs text-faint"><?= h(yolotask_money($stats['gross'])) ?> paid in</span></article>
  <article class="stat-card"><span class="stat-label">Platform fees</span><span class="stat-value"><?= h(yolotask_money($stats['fees'])) ?></span></article>
  <article class="stat-card"><span class="stat-label">Earnings on hold</span><span class="stat-value"><?= h(yolotask_money($stats['held'])) ?></span><span class="text-xs text-faint"><?= $stats['review'] ?> order<?= $stats['review'] === 1 ? '' : 's' ?> in payment review</span></article>
</section>
<nav class="card money-tabs" aria-label="Marketplace admin">
  <?php foreach (['products' => 'Products', 'orders' => 'Orders', 'sellers' => 'Sellers', 'settings' => 'Settings'] as $k => $label): ?>
    <a href="<?= h($tabUrl($k)) ?>"<?= $k === $tab ? ' class="active" aria-current="page"' : '' ?>><?= h($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'products'): ?>
  <section class="card">
    <form method="get" action="marketplace.php" class="copy-field"><input type="hidden" name="tab" value="products">
      <select class="input" name="status" aria-label="Status"><?php foreach (['pending_review' => 'Waiting for review', 'live' => 'Live', 'paused' => 'Paused', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $label): ?><option value="<?= h($k) ?>"<?= ($status === '' ? 'pending_review' : $status) === $k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select>
      <input class="input" name="q" value="<?= h($q) ?>" placeholder="Title or seller email"><button class="btn btn-secondary btn-sm" type="submit">Filter</button></form>
  </section>
  <?php if ($products['rows'] === []): ?>
    <section class="card"><div class="empty-state"><p class="text-faint">Nothing here.</p></div></section>
  <?php endif; ?>
  <?php foreach ($products['rows'] as $p): $pid = (int) $p['id']; ?>
    <section class="card" data-admin-product="<?= $pid ?>">
      <div class="card-header"><h2 class="text-break"><?= h((string) $p['title']) ?></h2><?= yolotask_mkt_status_badge((string) $p['status']) ?></div>
      <div class="mkt-product">
        <div><?= yolotask_mkt_cover($p, yolotask_mkt_has_cover($p)) ?></div>
        <div class="stack">
          <p class="text-sm">Seller <strong><?= h((string) $p['owner_name']) ?></strong> (<?= h((string) $p['owner_email']) ?>, <?= h((string) $p['owner_role']) ?>)<?= !empty($p['owner_frozen']) ? ' <span class="badge badge-danger">Frozen</span>' : '' ?></p>
          <?php if ($p['parent_product_id'] !== null): ?><p class="text-sm"><span class="badge badge-info">Resale listing</span> of “<?= h((string) $p['parent_title']) ?>” (#<?= (int) $p['parent_product_id'] ?>)</p><?php endif; ?>
          <?= yolotask_mkt_price($p) ?>
          <p class="text-xs text-faint">Fee paid by <?= h((string) $p['fee_bearer']) ?> · affiliates <?= (int) $p['affiliate_percent'] ?>%<?= $p['resell_allowed'] ? ' · resell rights ' . h(yolotask_money($p['resell_price_kobo'])) : '' ?> · <?= (int) $p['sales_count'] ?> sold · created <?= h(yolotask_wat((string) $p['created_at'])) ?></p>
          <details><summary class="text-sm">Description</summary><div class="mkt-desc text-sm"><?= h((string) $p['description']) ?></div></details>
          <?php if ($p['parent_product_id'] === null): ?>
            <details><summary class="text-sm">Delivery (<?= $p['delivery_type'] === 'file' ? 'file' : 'link / text' ?>)</summary>
              <?php if ($p['delivery_path'] !== null): ?><p><a class="btn btn-ghost btn-sm" href="marketplace.php?file=<?= $pid ?>">Download <?= h((string) $p['delivery_name']) ?> (<?= number_format((int) $p['delivery_bytes'] / 1048576, 1) ?> MB)</a></p><?php endif; ?>
              <?php if (!empty($p['delivery_text'])): ?><div class="mkt-delivery text-sm"><?= h((string) $p['delivery_text']) ?></div><?php endif; ?></details>
          <?php else: ?><p class="text-xs text-faint">Delivers the original product's content.</p><?php endif; ?>
          <?php if ($p['status'] === 'live'): ?><p class="text-xs"><a href="<?= h(Marketplace::productUrl($pid)) ?>" target="_blank" rel="noopener">Open public page</a></p><?php endif; ?>
          <?php if ($p['status'] === 'rejected' && $p['reject_reason']): ?><p class="text-xs text-danger">Rejected: <?= h((string) $p['reject_reason']) ?></p><?php endif; ?>
          <?php if ($p['status'] === 'pending_review'): ?>
            <form method="post" action="marketplace.php?tab=products" class="stack"><?= $hidden ?><input type="hidden" name="product_id" value="<?= $pid ?>">
              <input class="input" name="reason" maxlength="255" placeholder="Reason shown to the seller (needed to reject)" aria-label="Reject reason">
              <div class="hero-actions"><button class="btn btn-primary btn-sm" type="submit" name="action" value="approve">Approve</button><button class="btn btn-ghost btn-sm" type="submit" name="action" value="reject">Reject</button></div></form>
          <?php endif; ?>
        </div>
      </div>
    </section>
  <?php endforeach; ?>
  <?php if ($products['pages'] > 1): ?><p class="text-center text-sm"><?php if ($page > 1): ?><a href="marketplace.php?tab=products&status=<?= h(rawurlencode($status)) ?>&q=<?= h(rawurlencode($q)) ?>&page=<?= $page - 1 ?>">Previous</a> · <?php endif; ?>Page <?= $page ?> of <?= (int) $products['pages'] ?><?php if ($page < $products['pages']): ?> · <a href="marketplace.php?tab=products&status=<?= h(rawurlencode($status)) ?>&q=<?= h(rawurlencode($q)) ?>&page=<?= $page + 1 ?>">Next</a><?php endif; ?></p><?php endif; ?>

<?php elseif ($tab === 'orders'): ?>
  <section class="card">
    <form method="get" action="marketplace.php" class="copy-field"><input type="hidden" name="tab" value="orders">
      <select class="input" name="status" aria-label="Status"><?php foreach (['' => 'All', 'review' => 'Payment review', 'pending' => 'Waiting', 'paid' => 'Paid', 'expired' => 'Expired', 'failed' => 'Failed', 'rejected' => 'Rejected'] as $k => $label): ?><option value="<?= h($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select>
      <input class="input" name="q" value="<?= h($q) ?>" placeholder="Order, buyer email or product"><button class="btn btn-secondary btn-sm" type="submit">Filter</button></form>
  </section>
  <section class="card">
    <?php if ($orders['rows'] === []): ?><div class="empty-state"><p class="text-faint">No orders.</p></div><?php else: ?>
      <ul class="tx-list">
        <?php foreach ($orders['rows'] as $o): $oid = (int) $o['id']; ?>
          <li class="tx-row" data-admin-order="<?= h((string) $o['order_sn']) ?>">
            <div class="tx-meta"><strong class="mono"><?= h((string) $o['order_sn']) ?></strong>
              <span class="text-xs text-faint text-break"><?= $o['kind'] === 'resell_rights' ? 'Resell rights · ' : '' ?><?= h((string) $o['title']) ?> · seller <?= h((string) $o['seller_name']) ?> · buyer <?= h((string) $o['buyer_name']) ?> &lt;<?= h((string) $o['buyer_email']) ?>&gt; · <?= h(yolotask_wat((string) $o['created_at'])) ?> · <?= h(\YoloTask\Payments\Gateway::label((string) $o['gateway'])) ?></span>
              <span class="text-xs text-faint">Price <?= h(yolotask_money($o['base_kobo'])) ?><?= (int) $o['discount_kobo'] > 0 ? ' (coupon ' . h((string) $o['coupon_code']) . ' −' . h(yolotask_money($o['discount_kobo'])) . ')' : '' ?> · fee <?= h(yolotask_money($o['fee_kobo'])) ?> (<?= h((string) $o['fee_bearer']) ?>) · affiliate <?= h(yolotask_money($o['commission_kobo'])) ?><?= $o['affiliate_name'] ? ' (' . h((string) $o['affiliate_name']) . ')' : '' ?> · seller <?= h(yolotask_money($o['seller_kobo'])) ?><?= $o['amount_paid_kobo'] !== null ? ' · paid ' . h(yolotask_money($o['amount_paid_kobo'])) : '' ?></span>
              <?php if ($o['provider_message']): ?><span class="text-xs text-faint"><?= h((string) $o['provider_message']) ?></span><?php endif; ?>
              <?php if ($o['status'] === 'review'): ?>
                <form method="post" action="marketplace.php?tab=orders" class="copy-field" style="margin-top:6px"><?= $hidden ?><input type="hidden" name="order_id" value="<?= $oid ?>">
                  <input class="input" name="note" maxlength="255" required minlength="3" placeholder="Note" aria-label="Note">
                  <button class="btn btn-primary btn-sm" type="submit" name="action" value="accept">Accept</button><button class="btn btn-ghost btn-sm" type="submit" name="action" value="decline">Reject</button></form>
              <?php elseif (in_array($o['status'], ['pending', 'expired', 'failed'], true)): ?>
                <details><summary class="text-xs">Payment found in the gateway portal?</summary>
                  <form method="post" action="marketplace.php?tab=orders" class="stack"><?= $hidden ?><input type="hidden" name="order_id" value="<?= $oid ?>"><input type="hidden" name="action" value="verify">
                    <input class="input" name="reference" maxlength="120" required placeholder="Gateway reference (order no., session id or UTR)" aria-label="Reference">
                    <input class="input" name="note" maxlength="255" required minlength="3" placeholder="How you verified it" aria-label="Note">
                    <button class="btn btn-secondary btn-sm" type="submit" data-confirm="Confirm this order as paid? The buyer gets the product and earnings are posted.">Confirm as paid</button></form></details>
              <?php endif; ?>
            </div>
            <div class="tx-amount"><span><?= h(yolotask_money($o['amount_kobo'])) ?></span><?= yolotask_money_badge((string) $o['status']) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($orders['pages'] > 1): ?><p class="text-center text-sm"><?php if ($page > 1): ?><a href="marketplace.php?tab=orders&status=<?= h(rawurlencode($status)) ?>&q=<?= h(rawurlencode($q)) ?>&page=<?= $page - 1 ?>">Previous</a> · <?php endif; ?>Page <?= $page ?> of <?= (int) $orders['pages'] ?><?php if ($page < $orders['pages']): ?> · <a href="marketplace.php?tab=orders&status=<?= h(rawurlencode($status)) ?>&q=<?= h(rawurlencode($q)) ?>&page=<?= $page + 1 ?>">Next</a><?php endif; ?></p><?php endif; ?>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'sellers'): ?>
  <section class="card">
    <div class="card-header"><h2>Freeze a seller</h2></div>
    <p class="text-sm text-faint">A frozen seller's products are hidden from the marketplace and their product page, they cannot create products or coupons, and earnings on their orders (their share and their affiliates' commissions) stay on hold until you unfreeze them.</p>
    <form method="post" action="marketplace.php?tab=sellers" class="stack"><?= $hidden ?><input type="hidden" name="action" value="freeze">
      <input class="input" name="email" type="email" required placeholder="Seller account email" aria-label="Seller email">
      <input class="input" name="reason" maxlength="255" required minlength="3" placeholder="Reason (kept for the audit trail)" aria-label="Reason">
      <button class="btn btn-primary btn-sm" type="submit">Freeze seller</button></form>
  </section>
  <section class="card">
    <div class="card-header"><h2>Frozen sellers</h2><span class="badge badge-info"><?= count($frozen) ?></span></div>
    <?php if ($frozen === []): ?><div class="empty-state"><p class="text-faint">No frozen sellers.</p></div><?php else: ?>
      <ul class="tx-list"><?php foreach ($frozen as $f): ?>
        <li class="tx-row"><div class="tx-meta"><strong><?= h((string) $f['display_name']) ?></strong><span class="text-xs text-faint"><?= h((string) $f['email']) ?> · <?= h((string) $f['role']) ?> · <?= h(yolotask_wat((string) $f['frozen_at'])) ?> · <?= h((string) $f['reason']) ?></span></div>
          <div class="tx-amount"><form method="post" action="marketplace.php?tab=sellers"><?= $hidden ?><input type="hidden" name="user_id" value="<?= (int) $f['user_id'] ?>"><button class="btn btn-ghost btn-sm" type="submit" name="action" value="unfreeze">Unfreeze</button></form></div></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
  </section>

<?php else: ?>
  <section class="card">
    <div class="card-header"><h2>Marketplace settings</h2></div>
    <form method="post" action="marketplace.php?tab=settings" class="stack"><?= $hidden ?><input type="hidden" name="action" value="settings">
      <div class="field"><label class="label" for="fee_percent">Platform fee per sale (%)</label>
        <input class="input" id="fee_percent" name="fee_percent" inputmode="decimal" required value="<?= h(rtrim(Fees::percent($feeBps), '%')) ?>">
        <p class="input-hint">0 to <?= Pricing::MAX_FEE_BPS / 100 ?>%. Charged on the price actually paid (bonus price, after coupons). Each product decides whether the buyer pays it on top or the seller pays it from their share. Default 5%.</p></div>
      <div class="field"><label class="label" for="hold_hours">Hold before earnings reach wallets (hours)</label>
        <input class="input" id="hold_hours" name="hold_hours" inputmode="numeric" required value="<?= $holdHours ?>">
        <p class="input-hint">0 to 720. Seller shares and affiliate commissions wait this long after payment, then the hourly job adds them to wallets. Default 72.</p></div>
      <button class="btn btn-primary" type="submit">Save</button></form>
  </section>
<?php endif; ?>
<?php
yolotask_render_app($admin, 'Marketplace', (string) ob_get_clean(), 'market');
