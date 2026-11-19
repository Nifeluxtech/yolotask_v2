<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Marketplace\Orders;
use YoloTask\Marketplace\Products;
use YoloTask\Payments\Gateway;
use YoloTask\Payments\KorapayProcessor;

require_once __DIR__ . '/market-helpers.php';

/*
 * Main site: order / thank-you / delivery page /order.php?o=<order_sn>&t=<token>
 * (migration 0022). The gateway returns the buyer here; Korapay orders are
 * checked with Korapay on arrival. Once paid, the delivery is shown (file
 * download button and/or the private link or text). The same link is in the
 * buyer's email; the token is the access, no account needed.
 */
if (yolotask_role() !== 'main') yolotask_error_page(404);
header('Cache-Control: no-store, private');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
$sn = is_string($_GET['o'] ?? null) ? substr($_GET['o'], 0, 64) : '';
$token = is_string($_GET['t'] ?? null) ? substr($_GET['t'], 0, 64) : '';
try {
    $pdo = Database::connection();
    $order = $sn !== '' ? Orders::findForBuyer($pdo, $sn, $token) : null;
    if ($order === null) yolotask_error_page(404);
    if (($order['gateway'] ?? '') === Gateway::KORAPAY && in_array((string) $order['status'], ['created', 'pending', 'expired'], true)) {
        KorapayProcessor::checkOnReturn($pdo, (string) $order['order_sn']);
        $order = Orders::findForBuyer($pdo, $sn, $token);
    }
    $product = Products::find($pdo, (int) $order['product_id']);
    if ($product === null) yolotask_error_page(404);
    $source = Products::deliverySource($pdo, $product);
} catch (Throwable $e) {
    Logger::error('marketplace.order_page_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
$status = (string) $order['status'];
$waiting = in_array($status, ['created', 'pending'], true);
$isRights = (string) $order['kind'] === 'resell_rights';
ob_start();
?>
<div class="wallet-grid mk-order" data-order="<?= h($status) ?>">
  <section class="card card-glow dash-hero" aria-labelledby="ord-title">
    <p class="text-xs fw-bold text-primary">ORDER <?= h((string) $order['order_sn']) ?> <?= yolotask_money_badge($status) ?></p>
    <h1 id="ord-title" class="text-break"><?= h(match ($status) {
        'paid' => $isRights ? 'Resell rights are yours' : 'Thank you! Your purchase is ready',
        'review' => 'We are checking your payment',
        'rejected' => 'This payment needs support',
        'expired' => 'This payment expired',
        'failed' => 'This payment could not start',
        default => 'Waiting for payment confirmation',
    }) ?></h1>
    <p class="text-faint"><?= h((string) $product['title']) ?> · <?= h(yolotask_money($order['amount_kobo'])) ?></p>
    <p class="text-sm"><?= h(match ($status) {
        'paid' => $isRights ? 'Create your listing in My Products: set your own price, and your own title and cover if you like.' : 'We also emailed this page to ' . $order['buyer_email'] . '. Keep that email: its link is your access.',
        'review' => 'The amount the payment provider reported is different from the order, so a person is checking it. We will email you.',
        'rejected' => 'Our team could not match this payment. Contact support with the order reference above.',
        'expired' => 'We did not receive confirmation in time. If you paid, contact support with the order reference above.',
        'failed' => 'Nothing was charged. Go back to the product and try again.',
        default => 'If you completed the payment, confirmation usually takes a minute or two. This page refreshes on its own.',
    }) ?></p>
    <div class="hero-actions">
      <?php if ($waiting): ?><a class="btn btn-secondary btn-sm" href="<?= h(Orders::orderUrl($order)) ?>">Refresh status</a><?php endif; ?>
      <?php if ($waiting && !empty($order['pay_url'])): ?><a class="btn btn-ghost btn-sm" href="<?= h((string) $order['pay_url']) ?>" rel="noopener noreferrer">Open payment page</a><?php endif; ?>
      <?php if (in_array($status, ['failed', 'expired'], true)): ?><a class="btn btn-primary btn-sm" href="p.php?id=<?= (int) $product['id'] ?>">Back to the product</a><?php endif; ?>
    </div>
  </section>

  <?php if ($status === 'paid' && !$isRights): ?>
    <section class="card mk-delivery-card" aria-labelledby="dl-title" data-delivery>
      <div class="card-header"><h2 id="dl-title">Your product</h2></div>
      <?php if ($source['delivery_type'] === 'file' && $source['delivery_path'] !== null): ?>
        <p><a class="btn btn-primary" href="<?= h(Orders::downloadUrl($order)) ?>">Download <?= h((string) ($source['delivery_name'] ?? 'file')) ?></a></p>
      <?php endif; ?>
      <?php if (!empty($source['delivery_text'])): ?><?= yolotask_mkt_delivery_text((string) $source['delivery_text']) ?><?php endif; ?>
    </section>
  <?php elseif ($status === 'paid' && $isRights && $order['buyer_user_id'] !== null): ?>
    <?php $buyerRole = (string) $pdo->query('SELECT role FROM users WHERE id = ' . (int) $order['buyer_user_id'])->fetchColumn(); ?>
    <section class="card"><a class="btn btn-primary" href="<?= h(yolotask_portal_url($buyerRole === 'advertiser' ? 'advertiser' : 'earner')) ?>/my-products.php#rights">Go to My Products</a></section>
  <?php endif; ?>
</div>
<?php if ($waiting): ?><script>setTimeout(function () { location.reload(); }, 10000);</script><?php endif; ?>
<?php
$mktBody = (string) ob_get_clean();
$mktTitle = 'Your order';
$mktNoIndex = true;
require YOLOTASK_APP_ROOT . '/shared/views/layouts/market.php';
exit;
