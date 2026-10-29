<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Payments\PaymentOrders;

require_once __DIR__ . '/money-helpers.php';

if (yolotask_role() !== 'advertiser') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
    $orderSn = is_string($_GET['o'] ?? null) ? substr($_GET['o'], 0, 64) : '';
    $order = $orderSn !== '' ? PaymentOrders::findForUser($pdo, (int) $user['id'], $orderSn) : null;
} catch (Throwable $e) {
    Logger::error('topup.return_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
if ($order === null) yolotask_error_page(404);
$status = (string) $order['status'];
$waiting = in_array($status, ['created', 'pending'], true);
ob_start();
?>
<div class="wallet-grid">
  <section class="card card-glow dash-hero" aria-labelledby="ret-title">
    <p class="text-xs fw-bold text-primary">TOP-UP <?= h((string) $order['order_sn']) ?> <?= yolotask_money_badge($status) ?></p>
    <h2 id="ret-title"><?= h(match ($status) {
        'paid' => 'Your wallet has been credited',
        'review' => 'We are checking your payment',
        'rejected' => 'This payment needs support',
        'expired' => 'This top-up expired',
        'failed' => 'This top-up could not start',
        default => 'We\'ll credit you once AfrStar confirms',
    }) ?></h2>
    <div class="wallet-balance"><span class="amount"><?= h(yolotask_money($status === 'paid' ? ($order['credited_kobo'] ?? $order['amount_kobo']) : $order['amount_kobo'])) ?></span></div>
    <p class="text-faint"><?= h(match ($status) {
        'paid' => 'Thank you. The money is in your wallet and ready to use.',
        'review' => 'The amount AfrStar reported is different from your order, so a person is checking it. You will get an email when it is resolved.',
        'rejected' => 'Our team could not match this payment. Contact support with the reference above.',
        'expired' => 'We did not receive confirmation within the time limit. If you paid, contact support with the reference above.',
        'failed' => 'Nothing was charged. Please start a new top-up.',
        default => 'If you have completed the transfer, confirmation usually takes a minute or two. This page refreshes on its own.',
    }) ?></p>
    <div class="hero-actions">
      <?php if ($waiting): ?><a class="btn btn-secondary btn-sm" href="topup-return.php?o=<?= h(rawurlencode((string) $order['order_sn'])) ?>">Refresh status</a><?php endif; ?>
      <?php if ($waiting && !empty($order['pay_url'])): ?><a class="btn btn-ghost btn-sm" href="<?= h((string) $order['pay_url']) ?>" rel="noopener noreferrer">Open payment page</a><?php endif; ?>
      <a class="btn btn-primary btn-sm" href="wallet.php">Go to wallet</a>
    </div>
  </section>
</div>
<?php if ($waiting): ?><script>setTimeout(function () { location.reload(); }, 10000);</script><?php endif; ?>
<?php
yolotask_render_app($user, 'Top-up status', (string) ob_get_clean(), 'wallet');
