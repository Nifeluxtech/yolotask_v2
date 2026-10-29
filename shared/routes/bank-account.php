<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Payments\BankAccounts;
use YoloTask\Payments\BankCodes;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/money-helpers.php';

if (yolotask_role() !== 'earner') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('bank_account.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        yolotask_flash('Your form expired. Please try again.', 'error');
        yolotask_redirect('bank-account.php');
    }
    $str = static fn (string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    try {
        BankAccounts::save($pdo, $user, $str('bank_code'), $str('account_number'), $str('account_name'), $str('password'));
        yolotask_flash('Bank account saved. We emailed you a security notice.');
        yolotask_redirect('withdraw.php');
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('bank_account.save_failed', ['exception' => get_class($e)]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect('bank-account.php');
}

$bank = BankAccounts::get($pdo, $userId);
$csrf = Csrf::token();
$current = (string) ($bank['bank_code'] ?? '');
ob_start();
?>
<div class="wallet-grid">
  <?php if ($bank !== null): ?>
    <section class="card notice-card" aria-label="Current bank account">
      <span class="verify-icon"><?= yolotask_nav_icon('shield') ?></span>
      <div><strong><?= h((string) $bank['bank_name']) ?> ••••<?= h(substr((string) $bank['account_number'], -4)) ?></strong>
        <p class="text-sm text-faint"><?= h((string) $bank['account_name']) ?> · Updated <?= h(yolotask_wat((string) $bank['updated_at'], 'j M Y')) ?></p></div>
    </section>
  <?php endif; ?>
  <section class="card" aria-labelledby="bank-title">
    <div class="card-header"><h2 id="bank-title"><?= $bank ? 'Change bank account' : 'Add your bank account' ?></h2><span class="badge badge-info">One account</span></div>
    <p class="text-sm text-faint">Withdrawals are paid only into this account. For your safety we ask for your password and email you whenever it changes.</p>
    <form method="post" action="bank-account.php" class="stack" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <div class="field">
        <label class="label" for="bank_code">Bank</label>
        <select class="input" id="bank_code" name="bank_code" required>
          <option value="">Choose your bank</option>
          <?php foreach (BankCodes::options() as $code => $name): ?>
            <option value="<?= h((string) $code) ?>"<?= (string) $code === $current ? ' selected' : '' ?>><?= h($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="label" for="account_number">Account number</label>
        <input class="input" id="account_number" name="account_number" inputmode="numeric" pattern="[0-9 ]{10,13}" maxlength="13" required placeholder="0123456789">
        <p class="input-hint">Your 10-digit NUBAN.</p>
      </div>
      <div class="field">
        <label class="label" for="account_name">Account name</label>
        <input class="input" id="account_name" name="account_name" maxlength="100" required placeholder="As shown by your bank">
      </div>
      <div class="field">
        <label class="label" for="password">Your YOLOTASK password</label>
        <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
      </div>
      <button class="btn btn-primary btn-block" type="submit">Save bank account</button>
    </form>
  </section>
  <p class="text-center text-sm"><a href="wallet.php">Back to wallet</a></p>
</div>
<?php
yolotask_render_app($user, 'Bank account', (string) ob_get_clean(), 'wallet');
