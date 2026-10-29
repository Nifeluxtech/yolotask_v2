<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Money\Wallet;
use YoloTask\Payments\AfrstarClient;
use YoloTask\Payments\BankAccounts;
use YoloTask\Payments\Withdrawals;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/money-helpers.php';

if (yolotask_role() !== 'earner') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('withdraw.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        yolotask_flash('Your form expired. Please try again.', 'error');
        yolotask_redirect('withdraw.php');
    }
    $amount = yolotask_parse_naira(is_string($_POST['amount'] ?? null) ? $_POST['amount'] : '');
    try {
        if ($amount === null) throw new RuntimeException('Enter an amount in naira, for example 2000.');
        Withdrawals::request($pdo, $user, $amount, is_string($_POST['idem'] ?? null) ? $_POST['idem'] : '');
        yolotask_flash('Withdrawal requested. We will review it and send it to your bank.');
        yolotask_redirect('wallet.php');
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('withdraw.request_failed', ['exception' => get_class($e)]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect('withdraw.php');
}

$wallet = Wallet::forUser($pdo, $userId);
$bank = BankAccounts::get($pdo, $userId);
$open = Withdrawals::openForUser($pdo, $userId);
$limits = Withdrawals::limits($pdo);
$configured = AfrstarClient::isConfigured();
$verified = !empty($user['email_verified_at']);
$canRequest = $configured && $verified && $bank !== null && $open === null && $wallet['available_kobo'] >= $limits['min'];
$csrf = Csrf::token();
$idem = bin2hex(random_bytes(16));
ob_start();
?>
<div class="wallet-grid">
  <?php if (!$configured): ?>
    <?= yolotask_payments_unavailable_card('Withdrawals') ?>
  <?php elseif (!$verified): ?>
    <section class="card verify-banner" role="status">
      <span class="verify-icon"><?= yolotask_nav_icon('bell') ?></span>
      <div><strong>Confirm your email first</strong><p class="text-sm text-faint">Withdrawals are unlocked once your email address is confirmed.</p></div>
      <a class="btn btn-primary btn-sm" href="verify.php">Confirm now</a>
    </section>
  <?php endif; ?>

  <?php if ($open !== null): ?>
    <section class="card notice-card" role="status">
      <span class="verify-icon"><?= yolotask_nav_icon('refund') ?></span>
      <div><strong>Withdrawal of <?= h(yolotask_money($open['amount_kobo'])) ?> in progress</strong>
        <p class="text-sm text-faint"><?= $open['status'] === 'processing' ? 'Approved and on its way to your bank.' : 'Waiting for review. The amount is on hold in your wallet.' ?> You can request another once it finishes.</p></div>
      <?= yolotask_money_badge((string) $open['status']) ?>
    </section>
  <?php endif; ?>

  <section class="card" aria-labelledby="wd-title">
    <div class="card-header"><h2 id="wd-title">Withdraw to your bank</h2><span class="badge badge-info">Available <?= h(yolotask_money($wallet['available_kobo'])) ?></span></div>
    <?php if ($bank === null): ?>
      <div class="empty-state"><p class="text-faint">Add the bank account you want to be paid into first.</p><a class="btn btn-primary" href="bank-account.php">Add bank account</a></div>
    <?php else: ?>
      <p class="text-sm">Paying into <strong><?= h((string) $bank['bank_name']) ?></strong> ••••<?= h(substr((string) $bank['account_number'], -4)) ?> · <?= h((string) $bank['account_name']) ?> <a class="text-sm" href="bank-account.php">Change</a></p>
      <form method="post" action="withdraw.php" class="stack">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="idem" value="<?= h($idem) ?>">
        <div class="field">
          <label class="label" for="amount">Amount (₦)</label>
          <input class="input" id="amount" name="amount" inputmode="decimal" autocomplete="off" required placeholder="2000" pattern="[0-9,]+(\.[0-9]{1,2})?">
          <p class="input-hint">Minimum <?= h(yolotask_money($limits['min'])) ?>. No fees. Every withdrawal is reviewed before it is sent.</p>
        </div>
        <button class="btn btn-primary btn-block" type="submit"<?= $canRequest ? '' : ' disabled' ?>>Request withdrawal</button>
      </form>
    <?php endif; ?>
  </section>
  <p class="text-center text-sm"><a href="wallet.php">Back to wallet</a></p>
</div>
<?php
yolotask_render_app($user, 'Withdraw', (string) ob_get_clean(), 'wallet');
