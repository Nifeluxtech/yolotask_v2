<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Payments\Gateway;
use YoloTask\Payments\KorapayProcessor;
use YoloTask\Account\Activation;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/money-helpers.php';

/*
 * Earner: activate the account with the one-time activation fee (migration 0019).
 * Only needed while an admin has switched activation.required on; otherwise
 * the page just says no activation is needed (nothing links here).
 * Earners cannot deposit, so the fee is paid on the active pay-in gateway's page
 * (AfrStar or Korapay); it returns here with ?o=<order> and the gateway's
 * callback/webhook activates the account (Korapay is also checked on return).
 */
if (yolotask_role() !== 'earner') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('account.activate_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$uid = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        yolotask_flash('Your form expired. Please try again.', 'error');
        yolotask_redirect('activate.php');
    }
    try {
        $order = Activation::create($pdo, $user, is_string($_POST['phone'] ?? null) ? $_POST['phone'] : '', yolotask_portal_url('earner'));
        yolotask_redirect($order['pay_url']);
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('account.activate_create_failed', ['exception' => get_class($e)]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect('activate.php');
}

try {
    $required = Activation::required($pdo);
    $activation = Activation::activation($pdo, $uid);
    $fee = Activation::fee($pdo);
    $orderSn = is_string($_GET['o'] ?? null) ? substr($_GET['o'], 0, 64) : '';
    if ($orderSn === '' && is_string($_GET['reference'] ?? null)) $orderSn = substr($_GET['reference'], 0, 64);
    $order = $orderSn !== '' ? Activation::findForUser($pdo, $uid, $orderSn) : null;
    if ($order !== null && ($order['gateway'] ?? '') === Gateway::KORAPAY && in_array((string) $order['status'], ['created', 'pending', 'expired'], true)) {
        KorapayProcessor::checkOnReturn($pdo, (string) $order['order_sn']);
        $order = Activation::findForUser($pdo, $uid, (string) $order['order_sn']);
        $activation = Activation::activation($pdo, $uid);
    }
    if ($orderSn !== '' && $order === null) yolotask_error_page(404);
    $recent = Activation::recentForUser($pdo, $uid, 5);
    if ($order === null && $activation === null) {
        foreach ($recent as $r) { if (in_array((string) $r['status'], ['pending', 'review'], true)) { $order = $r; break; } }
    }
} catch (Throwable $e) {
    Logger::error('account.activate_page_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
$payinGateway = Gateway::payin($pdo);
$gatewayLabel = Gateway::label($payinGateway);
$configured = Gateway::isConfigured($payinGateway);
$orderGatewayLabel = Gateway::label((string) ($order['gateway'] ?? $payinGateway));
$verified = !empty($user['email_verified_at']);
$status = $order !== null ? (string) $order['status'] : '';
$waiting = $activation === null && in_array($status, ['created', 'pending'], true);
$csrf = Csrf::token();
$phone = (string) ($user['phone'] ?? '');
if (str_starts_with($phone, '+234')) $phone = '0' . substr($phone, 4);
ob_start();
?>
<div class="wallet-grid">
  <?php if ($activation !== null): ?>
    <section class="card card-glow dash-hero" aria-labelledby="act-title" data-activation="active">
      <p class="text-xs fw-bold text-primary">ACCOUNT <span class="badge badge-success">Activated</span></p>
      <h2 id="act-title">Your account is activated</h2>
      <p class="text-faint">Activated <?= h(yolotask_wat((string) $activation['activated_at'], 'j M Y')) ?><?= $activation['order_sn'] ? ' · ' . h((string) $activation['order_sn']) : '' ?>. Every task in your feed is open to you.</p>
      <div class="hero-actions"><a class="btn btn-primary btn-sm" href="tasks.php">Find tasks</a><a class="btn btn-secondary btn-sm" href="submissions.php">My work</a></div>
    </section>
  <?php elseif (!$required && ($order === null || !in_array($status, ['pending', 'review'], true))): ?>
    <section class="card card-glow dash-hero" aria-labelledby="act-title" data-activation="not-needed">
      <h2 id="act-title">No activation needed</h2>
      <p class="text-faint">Every task is open to you right now. There is nothing to pay.</p>
      <div class="hero-actions"><a class="btn btn-primary btn-sm" href="tasks.php">Find tasks</a></div>
    </section>
  <?php else: ?>
    <?php if ($order !== null && $status !== ''): ?>
      <section class="card card-glow dash-hero" aria-labelledby="ord-title" data-activation="<?= h($status) ?>">
        <p class="text-xs fw-bold text-primary">ACTIVATION <?= h((string) $order['order_sn']) ?> <?= yolotask_money_badge($status) ?></p>
        <h2 id="ord-title"><?= h(match ($status) {
            'paid' => 'Payment received',
            'review' => 'We are checking your payment',
            'rejected' => 'This payment needs support',
            'expired' => 'This payment expired',
            'failed' => 'This payment could not start',
            default => 'We\'ll activate your account once ' . $orderGatewayLabel . ' confirms',
        }) ?></h2>
        <div class="wallet-balance"><span class="amount"><?= h(yolotask_money($order['amount_kobo'])) ?></span></div>
        <p class="text-faint"><?= h(match ($status) {
            'paid' => 'Thank you. Your account is being activated.',
            'review' => 'The amount ' . $orderGatewayLabel . ' reported is different from the activation fee, so a person is checking it. You will get a message when it is resolved.',
            'rejected' => 'Our team could not match this payment. Contact support with the reference above.',
            'expired' => 'We did not receive confirmation within the time limit. If you paid, contact support with the reference above and we will check it.',
            'failed' => 'Nothing was charged. Please start again.',
            default => 'If you have completed the transfer, confirmation usually takes a minute or two. This page refreshes on its own.',
        }) ?></p>
        <div class="hero-actions">
          <?php if ($waiting): ?><a class="btn btn-secondary btn-sm" href="activate.php?o=<?= h(rawurlencode((string) $order['order_sn'])) ?>">Refresh status</a><?php endif; ?>
          <?php if ($waiting && !empty($order['pay_url'])): ?><a class="btn btn-ghost btn-sm" href="<?= h((string) $order['pay_url']) ?>" rel="noopener noreferrer">Open payment page</a><?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if (!$configured): ?>
      <?= yolotask_payments_unavailable_card('Account activation') ?>
    <?php elseif (!$verified): ?>
      <section class="card verify-banner" role="status">
        <span class="verify-icon"><?= yolotask_nav_icon('bell') ?></span>
        <div><strong>Confirm your email first</strong><p class="text-sm text-faint">Activating your account needs a confirmed email address.</p></div>
        <a class="btn btn-primary btn-sm" href="verify.php">Confirm now</a>
      </section>
    <?php endif; ?>

    <?php if ($required): ?>
    <section class="card activation-offer" aria-labelledby="unlock-title" data-activation-offer>
      <div class="card-header"><h2 id="unlock-title">Activate your account</h2><span class="badge badge-info">One-time <?= h(yolotask_money($fee)) ?></span></div>
      <p>Activate your account with <?= h(yolotask_money($fee)) ?> to access tasks.</p>
      <ul class="activation-points text-sm">
        <li>Pay once. Every task in your feed opens as soon as the payment is confirmed, and your account stays activated.</li>
        <li>Each task is paid to your wallet as soon as it is approved.</li>
        <li>The fee is not added to your wallet and cannot be withdrawn.</li>
      </ul>
      <p class="text-sm text-faint">You will pay <?= h(yolotask_money($fee)) ?> on <?= h($gatewayLabel) ?>'s secure page<?= $payinGateway === Gateway::KORAPAY ? ' by bank transfer or card' : ' by bank transfer' ?>. Your account is activated automatically as soon as <?= h($gatewayLabel) ?> confirms the payment.</p>
      <p class="text-sm deposit-notice" role="note"><span class="badge badge-warning">Notice</span> Payments from <strong>OPay</strong> and <strong>PalmPay</strong> are currently unavailable. Please pay from any other bank.</p>
      <?php if ($waiting): ?>
        <p class="text-sm">You already started a payment. Use <strong>Open payment page</strong> above to finish it; a new one is not needed.</p>
      <?php elseif ($status !== 'review'): ?>
        <form method="post" action="activate.php" class="stack">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
          <div class="field">
            <label class="label" for="phone">Phone number</label>
            <input class="input" id="phone" name="phone" type="tel" inputmode="tel" maxlength="20" autocomplete="tel" required placeholder="0803 123 4567" value="<?= h($phone) ?>">
            <p class="input-hint">We pass it to <?= h($gatewayLabel) ?> for the payment page.</p>
          </div>
          <button class="btn btn-primary btn-block" type="submit"<?= $configured && $verified ? '' : ' disabled' ?>>Pay <?= h(yolotask_money($fee)) ?> and activate</button>
        </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($recent !== []): ?>
    <section class="card" aria-labelledby="hist-title">
      <div class="card-header"><h2 id="hist-title">Activation payments</h2></div>
      <ul class="tx-list">
        <?php foreach ($recent as $r): ?>
          <li class="tx-row"><div class="tx-meta"><strong class="mono"><?= h((string) $r['order_sn']) ?></strong><span class="text-xs text-faint"><?= h(yolotask_wat((string) $r['created_at'])) ?></span></div>
            <div class="tx-amount"><span><?= h(yolotask_money($r['amount_kobo'])) ?></span><?= yolotask_money_badge((string) $r['status']) ?></div></li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
  <p class="text-center text-sm"><a href="tasks.php">Back to tasks</a></p>
</div>
<?php if ($waiting): ?><script>setTimeout(function () { location.reload(); }, 10000);</script><?php endif; ?>
<?php
yolotask_render_app($user, 'Account activation', (string) ob_get_clean(), 'tasks');
