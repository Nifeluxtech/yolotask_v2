<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Money\Wallet;
use YoloTask\Payments\AfrstarClient;
use YoloTask\Payments\PaymentOrders;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/money-helpers.php';

if (yolotask_role() !== 'advertiser') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('topup.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$configured = AfrstarClient::isConfigured();
$verified = !empty($user['email_verified_at']);
$limits = PaymentOrders::limits($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        yolotask_flash('Your form expired. Please try again.', 'error');
        yolotask_redirect('topup.php');
    }
    $amount = yolotask_parse_naira(is_string($_POST['amount'] ?? null) ? $_POST['amount'] : '');
    try {
        if ($amount === null) throw new RuntimeException('Enter an amount in naira, for example 5000.');
        $order = PaymentOrders::create($pdo, $user, $amount, is_string($_POST['phone'] ?? null) ? $_POST['phone'] : '', yolotask_portal_url('advertiser'));
        yolotask_redirect($order['pay_url']);
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('topup.create_failed', ['exception' => get_class($e)]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect('topup.php');
}

$wallet = Wallet::forUser($pdo, (int) $user['id']);
$csrf = Csrf::token();
$phone = (string) ($user['phone'] ?? '');
if (str_starts_with($phone, '+234')) $phone = '0' . substr($phone, 4);
ob_start();
?>
<div class="wallet-grid">
  <?php if (!$configured): ?>
    <?= yolotask_payments_unavailable_card('Wallet top-ups') ?>
  <?php elseif (!$verified): ?>
    <section class="card verify-banner" role="status">
      <span class="verify-icon"><?= yolotask_nav_icon('bell') ?></span>
      <div><strong>Confirm your email first</strong><p class="text-sm text-faint">Funding is unlocked once your email address is confirmed.</p></div>
      <a class="btn btn-primary btn-sm" href="verify.php">Confirm now</a>
    </section>
  <?php endif; ?>

  <section class="card" aria-labelledby="topup-title">
    <div class="card-header"><h2 id="topup-title">Top up your wallet</h2><span class="badge badge-info">Balance <?= h(yolotask_money($wallet['available_kobo'])) ?></span></div>
    <p class="text-sm text-faint">You will pay on AfrStar's secure page by bank transfer. Your wallet is credited automatically as soon as AfrStar confirms the payment. No fees.</p>
    <form method="post" action="topup.php" class="stack">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <div class="field">
        <label class="label" for="amount">Amount (₦)</label>
        <input class="input" id="amount" name="amount" inputmode="decimal" autocomplete="off" required placeholder="5000" pattern="[0-9,]+(\.[0-9]{1,2})?">
        <p class="input-hint">Minimum <?= h(yolotask_money($limits['min'])) ?>, maximum <?= h(yolotask_money($limits['max'])) ?> per top-up.</p>
      </div>
      <div class="field">
        <label class="label" for="phone">Phone number</label>
        <input class="input" id="phone" name="phone" type="tel" inputmode="tel" maxlength="20" autocomplete="tel" required placeholder="0803 123 4567" value="<?= h($phone) ?>">
        <p class="input-hint">AfrStar asks for it on the payment page.</p>
      </div>
      <button class="btn btn-primary btn-block" type="submit"<?= $configured && $verified ? '' : ' disabled' ?>>Continue to payment</button>
    </form>
  </section>
  <p class="text-center text-sm"><a href="wallet.php">Back to wallet</a></p>
</div>
<?php
yolotask_render_app($user, 'Top up', (string) ob_get_clean(), 'wallet');
