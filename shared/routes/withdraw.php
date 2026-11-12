<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Money\Fees;
use YoloTask\Money\Wallet;
use YoloTask\Payments\Gateway;
use YoloTask\Payments\BankAccounts;
use YoloTask\Payments\WithdrawalFeeChanged;
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

// Two steps: "Preview" (server-computed fee and net, nothing is created), then
// "Confirm" with the fee the earner saw; Withdrawals::request recomputes it
// under the wallet lock and refuses (WithdrawalFeeChanged) when it changed.
$preview = null;
$previewError = null;
$postedAmount = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        yolotask_flash('Your form expired. Please try again.', 'error');
        yolotask_redirect('withdraw.php');
    }
    $postedAmount = is_string($_POST['amount'] ?? null) ? mb_substr(trim($_POST['amount']), 0, 20) : '';
    $amount = yolotask_parse_naira($postedAmount);
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'preview';
    $quoted = is_string($_POST['quoted_fee'] ?? null) && preg_match('/^\d{1,12}$/', $_POST['quoted_fee']) ? (int) $_POST['quoted_fee'] : null;
    try {
        if ($amount === null) throw new RuntimeException('Enter an amount in naira, for example 2000.');
        if ($action === 'confirm' && $quoted !== null) {
            Withdrawals::request($pdo, $user, $amount, is_string($_POST['idem'] ?? null) ? $_POST['idem'] : '', $quoted);
            yolotask_flash('Withdrawal requested. We will review it and send it to your bank.');
            yolotask_redirect('wallet.php');
        }
        // Preview (also any submit without a quoted fee): nothing is created.
        $preview = Withdrawals::preview($pdo, $user, $amount);
    } catch (WithdrawalFeeChanged $e) {
        $preview = $e->quote;
        $previewError = $e->getMessage();
    } catch (\PDOException $e) {
        Logger::error('withdraw.request_failed', ['exception' => get_class($e)]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
        yolotask_redirect('withdraw.php');
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
        yolotask_redirect('withdraw.php');
    } catch (Throwable $e) {
        Logger::error('withdraw.request_failed', ['exception' => get_class($e)]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
        yolotask_redirect('withdraw.php');
    }
}

$wallet = Wallet::forUser($pdo, $userId);
$bank = BankAccounts::get($pdo, $userId);
$open = Withdrawals::openForUser($pdo, $userId);
$limits = Withdrawals::limits($pdo);
$configured = Gateway::payoutReady($pdo);
$verified = !empty($user['email_verified_at']);
$cooldown = BankAccounts::cooldownUntil($pdo, $bank);
$canRequest = $configured && $verified && $bank !== null && $open === null && $cooldown === null && $wallet['available_kobo'] >= $limits['min'];
$rate = Fees::withdrawalRateFor($pdo, $userId);
$csrf = Csrf::token();
$idem = is_string($_POST['idem'] ?? null) && preg_match('/^[a-f0-9]{32}$/', $_POST['idem']) ? $_POST['idem'] : bin2hex(random_bytes(16));
$feeLabel = $rate['bps'] > 0 ? Fees::percent($rate['bps']) . ' withdrawal fee' . ($rate['from_level'] ? ' (' . $rate['level_name'] . ' level rate; standard ' . Fees::percent($rate['base_bps']) . ')' : '') : 'No withdrawal fee';
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

  <?php if ($cooldown !== null && $open === null): ?>
    <section class="card notice-card notice-warning" role="status" data-bank-cooldown>
      <span class="verify-icon"><?= yolotask_nav_icon('shield') ?></span>
      <div><strong>Withdrawals paused until <?= h(yolotask_wat($cooldown)) ?> WAT</strong>
        <p class="text-sm text-faint">Your bank account was changed recently. This short wait protects your earnings if someone else got into your account.</p></div>
    </section>
  <?php endif; ?>

  <?php if ($open !== null): ?>
    <section class="card notice-card" role="status">
      <span class="verify-icon"><?= yolotask_nav_icon('refund') ?></span>
      <div><strong>Withdrawal of <?= h(yolotask_money($open['amount_kobo'])) ?> in progress</strong><?php if ((int) $open['fee_kobo'] > 0): ?> <span class="text-sm text-faint" data-open-fee>(fee <?= h(yolotask_money($open['fee_kobo'])) ?>, you receive <?= h(yolotask_money((int) $open['amount_kobo'] - (int) $open['fee_kobo'])) ?>)</span><?php endif; ?>
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
      <?php if ($preview !== null): ?>
        <div class="stack wd-preview" data-withdraw-preview aria-live="polite">
          <?php if ($previewError !== null): ?><div class="notice-card notice-warning" role="alert" data-fee-changed><span class="verify-icon"><?= yolotask_nav_icon('bell') ?></span><div><strong>The fee changed</strong><p class="text-sm text-faint"><?= h($previewError) ?></p></div></div><?php endif; ?>
          <dl class="receipt-list">
            <div><dt>Withdrawal amount</dt><dd data-preview-amount><?= h(yolotask_money($preview['amount'])) ?></dd></div>
            <div><dt>Fee (<?= h(Fees::percent((int) $preview['bps'])) ?><?= $preview['from_level'] ? ', ' . h((string) $preview['level_name']) . ' level' : '' ?>)</dt><dd data-preview-fee>− <?= h(yolotask_money($preview['fee'])) ?></dd></div>
            <div><dt><strong>You will receive</strong></dt><dd><strong data-preview-net><?= h(yolotask_money($preview['net'])) ?></strong></dd></div>
          </dl>
          <p class="input-hint"><?= h(yolotask_money($preview['amount'])) ?> leaves your balance now and is held until the withdrawal is reviewed. If it is declined or the bank transfer fails, the full <?= h(yolotask_money($preview['amount'])) ?> (fee included) comes back to your wallet. Fees are rounded down to the kobo.</p>
          <form method="post" action="withdraw.php" class="stack">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="idem" value="<?= h($idem) ?>">
            <input type="hidden" name="action" value="confirm"><input type="hidden" name="amount" value="<?= h(\YoloTask\Money\Money::decimal((int) $preview['amount'])) ?>"><input type="hidden" name="quoted_fee" value="<?= (int) $preview['fee'] ?>">
            <button class="btn btn-primary btn-block" type="submit"<?= $canRequest ? '' : ' disabled' ?>>Confirm withdrawal of <?= h(yolotask_money($preview['amount'])) ?></button>
          </form>
          <a class="btn btn-ghost btn-block" href="withdraw.php">Change amount</a>
        </div>
      <?php else: ?>
      <form method="post" action="withdraw.php" class="stack">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="idem" value="<?= h($idem) ?>"><input type="hidden" name="action" value="preview">
        <div class="field">
          <label class="label" for="amount">Amount (₦)</label>
          <input class="input" id="amount" name="amount" inputmode="decimal" autocomplete="off" required placeholder="2000" pattern="[0-9,]+(\.[0-9]{1,2})?" value="<?= h($postedAmount) ?>">
          <p class="input-hint">Minimum <?= h(yolotask_money($limits['min'])) ?>, up to <?= h(yolotask_money($limits['daily_max'])) ?> and <?= (int) $limits['daily_count'] ?> withdrawals in 24 hours. <span data-fee-rate="<?= (int) $rate['bps'] ?>"><?= h($feeLabel) ?></span>, taken from the amount. You will see the fee and what you receive before you confirm. Every withdrawal is reviewed before it is sent.</p>
        </div>
        <button class="btn btn-primary btn-block" type="submit"<?= $canRequest ? '' : ' disabled' ?>>Preview withdrawal</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </section>
  <p class="text-center text-sm"><a href="wallet.php">Back to wallet</a></p>
</div>
<?php
yolotask_render_app($user, 'Withdraw', (string) ob_get_clean(), 'wallet');
