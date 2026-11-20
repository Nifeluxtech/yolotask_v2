<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Payments\BankAccounts;
use YoloTask\Payments\BankCodes;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/money-helpers.php';

if (!in_array(yolotask_role(), ['earner', 'advertiser'], true)) yolotask_error_page(404); // advertisers: marketplace earnings (0022)
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
    if ($str('action') === 'lookup') {
        // Live account-name check for the form below (JSON). Throttled per user.
        header('Content-Type: application/json; charset=utf-8');
        $acct = preg_replace('/\D/', '', $str('account_number')) ?? '';
        $out = ['ok' => false, 'message' => 'We could not confirm this account. Check the number and bank, or type the name as your bank shows it.'];
        try {
            if (\YoloTask\Security\Throttle::tooMany($pdo, 'bank_lookup', (string) $userId, 20, 600)) {
                $out['message'] = 'Too many checks. Please wait a few minutes.';
            } elseif (BankCodes::isValid($str('bank_code')) && preg_match('/^\d{10}$/', $acct)) {
                \YoloTask\Security\Throttle::hit($pdo, 'bank_lookup', (string) $userId);
                $found = BankAccounts::lookupName($str('bank_code'), $acct);
                if ($found !== null) $out = ['ok' => true, 'name' => $found[0]];
            }
        } catch (Throwable $e) {
            Logger::error('bank_account.lookup_failed', ['exception' => get_class($e)]);
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }
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
        <p class="text-sm text-faint"><?= h((string) $bank['account_name']) ?> · Updated <?= h(yolotask_wat((string) $bank['updated_at'], 'j M Y')) ?></p>
        <?php if (in_array((string) $bank['name_verified_via'], ['paystack', 'korapay'], true)): ?><span class="badge badge-success" data-name-verified>Name confirmed with the bank</span><?php endif; ?></div>
    </section>
  <?php endif; ?>
  <section class="card" aria-labelledby="bank-title">
    <div class="card-header"><h2 id="bank-title"><?= $bank ? 'Change bank account' : 'Add your bank account' ?></h2><span class="badge badge-info">One account</span></div>
    <p class="text-sm text-faint">Withdrawals are paid only into this account. For your safety we ask for your password, email you whenever it changes, and pause withdrawals for <?= (int) \YoloTask\Core\Settings::int($pdo, 'money.bank_change_cooldown_hours') ?> hours after a change.</p>
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
        <input class="input" id="account_name" name="account_name" maxlength="100" placeholder="Fills in automatically">
        <p class="input-hint" id="account_name_hint">Choose your bank and enter your account number, and we'll show the name on the account.</p>
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
<script>
(function () {
  var bank = document.getElementById('bank_code'), num = document.getElementById('account_number'),
      name = document.getElementById('account_name'), hint = document.getElementById('account_name_hint'),
      form = name.form, csrf = form.querySelector('input[name="_csrf"]').value, timer = null, seq = 0, lastKey = '';
  function digits() { return num.value.replace(/\D/g, ''); }
  function reset(msg) { name.readOnly = false; name.value = ''; hint.textContent = msg; }
  function check() {
    var acct = digits(), code = bank.value, key = code + ':' + acct;
    if (!code || acct.length !== 10) { lastKey = ''; if (name.readOnly) reset('Choose your bank and enter your account number, and we\'ll show the name on the account.'); return; }
    if (key === lastKey) return;
    lastKey = key; var mine = ++seq;
    name.readOnly = true; name.value = ''; hint.textContent = 'Checking with your bank\u2026';
    var body = new URLSearchParams({ action: 'lookup', _csrf: csrf, bank_code: code, account_number: acct });
    fetch('bank-account.php', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (mine !== seq) return;
        if (d && d.ok) { name.value = d.name; name.readOnly = true; hint.textContent = 'Name confirmed with the bank.'; }
        else { reset((d && d.message) || 'We could not confirm this account.'); lastKey = ''; }
      })
      .catch(function () { if (mine === seq) { reset('We could not check the name right now. Type it as your bank shows it.'); lastKey = ''; } });
  }
  function soon() { clearTimeout(timer); timer = setTimeout(check, 350); }
  bank.addEventListener('change', soon); num.addEventListener('input', soon);
  form.addEventListener('submit', function (e) {
    if (!name.value.trim()) { e.preventDefault(); hint.textContent = name.readOnly ? 'Still checking your account. One moment.' : 'Enter the account name exactly as your bank shows it.'; name.readOnly || name.focus(); }
  });
})();
</script>
<?php
yolotask_render_app($user, 'Bank account', (string) ob_get_clean(), 'wallet');
