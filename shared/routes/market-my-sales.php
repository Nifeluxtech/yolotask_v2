<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Marketplace\Marketplace;

require_once __DIR__ . '/market-helpers.php';

/*
 * Earner / advertiser: My Sales (migration 0022). Seller earnings (own
 * products, resale listings, resell rights sold), affiliate links with
 * clicks / sales / commission, and recent paid orders with their hold state.
 */
$role = yolotask_role();
if (!in_array($role, ['earner', 'advertiser'], true)) yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
    $uid = (int) $user['id'];
    $summary = Marketplace::earningsSummary($pdo, $uid);
    $links = $role === 'earner' ? Marketplace::affiliateStats($pdo, $uid) : [];
    $sales = Marketplace::recentSales($pdo, $uid, 30);
    $holdHours = Marketplace::holdHours($pdo);
    $code = $role === 'earner' ? \YoloTask\Growth\Referrals::codeFor($pdo, $uid) : null;
} catch (Throwable $e) {
    Logger::error('marketplace.sales_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
$clicks = array_sum(array_map(static fn (array $l): int => (int) $l['clicks'], $links));
$affSales = array_sum(array_map(static fn (array $l): int => (int) $l['sales'], $links));
ob_start();
?>
<?= yolotask_mkt_tabs('sales') ?>
<div class="grid grid-4 dash-stats money-stats">
  <div class="stat-card stat-success"><span class="stat-label">Seller earnings paid</span><span class="stat-value"><?= h(yolotask_money($summary['seller']['released'])) ?></span></div>
  <div class="stat-card stat-warning"><span class="stat-label">Seller earnings on hold</span><span class="stat-value"><?= h(yolotask_money($summary['seller']['held'])) ?></span></div>
  <div class="stat-card stat-success"><span class="stat-label">Commission paid</span><span class="stat-value"><?= h(yolotask_money($summary['affiliate']['released'])) ?></span></div>
  <div class="stat-card stat-warning"><span class="stat-label">Commission on hold</span><span class="stat-value"><?= h(yolotask_money($summary['affiliate']['held'])) ?></span></div>
</div>
<p class="text-sm text-faint">Earnings are held for <?= $holdHours ?> hour<?= $holdHours === 1 ? '' : 's' ?> after each sale, then added to your wallet<?= $role === 'advertiser' ? ', where you can withdraw them' : '' ?>.</p>

<?php if ($role === 'earner'): ?>
<section class="card" aria-labelledby="aff-title">
  <div class="card-header"><h2 id="aff-title">My affiliate links</h2><span class="badge badge-info"><?= number_format($clicks) ?> clicks · <?= number_format($affSales) ?> sales</span></div>
  <?php if ($links === []): ?>
    <div class="empty-state"><p class="text-faint">No links yet. Open the <a href="marketplace.php">marketplace</a> and tap “Get my link” on a product.</p></div>
  <?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Product</th><th>Clicks</th><th>Sales</th><th>Commission</th><th>Link</th></tr></thead><tbody>
      <?php foreach ($links as $l): ?>
        <tr><td class="text-break"><?= h((string) $l['title']) ?> <?= $l['status'] !== 'live' ? yolotask_mkt_status_badge((string) $l['status']) : '' ?></td><td><?= number_format((int) $l['clicks']) ?></td><td><?= number_format((int) $l['sales']) ?></td><td><?= h(yolotask_money($l['commission'])) ?></td>
          <td><div class="copy-field"><input class="input" id="al-<?= (int) $l['id'] ?>" type="text" readonly value="<?= h(Marketplace::productUrl((int) $l['id'], $code)) ?>" aria-label="Affiliate link"><button type="button" class="btn btn-secondary btn-sm" data-copy="#al-<?= (int) $l['id'] ?>">Copy</button></div></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <p class="text-xs text-faint">A click counts once per browser for 30 days. Buying through your own link, or sharing a product you sell, earns no commission.</p>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="sales-title">
  <div class="card-header"><h2 id="sales-title">Recent sales</h2><span class="badge badge-info">Latest 30</span></div>
  <?php if ($sales === []): ?>
    <div class="empty-state"><p class="text-faint">No sales yet.</p></div>
  <?php else: ?>
    <ul class="tx-list">
      <?php foreach ($sales as $s): $asSeller = (int) $s['seller_user_id'] === $uid; $mine = $asSeller ? (int) $s['seller_kobo'] : (int) $s['commission_kobo']; ?>
        <li class="tx-row">
          <div class="tx-meta"><strong class="text-break"><?= h((string) $s['title']) ?></strong>
            <span class="text-xs text-faint"><?= $s['kind'] === 'resell_rights' ? 'Resell rights · ' : '' ?><?= $asSeller ? 'Your sale' : 'Affiliate commission' ?> · <?= h(yolotask_wat((string) $s['paid_at'])) ?> · price <?= h(yolotask_money($s['base_kobo'])) ?><?= $s['coupon_code'] ? ' (coupon ' . h((string) $s['coupon_code']) . ')' : '' ?><?= $asSeller ? ' · fee ' . h(yolotask_money($s['fee_kobo'])) . ' paid by ' . ($s['fee_bearer'] === 'seller' ? 'you' : 'buyer') . ((int) $s['commission_kobo'] > 0 ? ' · affiliate ' . h(yolotask_money($s['commission_kobo'])) : '') : '' ?></span></div>
          <div class="tx-amount"><span>+<?= h(yolotask_money($mine)) ?></span>
            <?= $s['earning_status'] === 'released' ? '<span class="badge badge-success">In wallet</span>' : ($mine > 0 ? '<span class="badge badge-warning">On hold until ' . h(yolotask_wat((string) $s['release_at'], 'j M, g:i A')) . '</span>' : '') ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($user, 'My Sales', (string) ob_get_clean(), 'marketplace');
