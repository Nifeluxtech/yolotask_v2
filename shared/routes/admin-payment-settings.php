<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Money\Fees;
use YoloTask\Money\Money;
use YoloTask\Payments\AfrstarClient;
use YoloTask\Security\Audit;
use YoloTask\Security\Crypto;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/money-helpers.php';

if (yolotask_role() !== 'admin') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $admin = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('admin.money_settings_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}

$fields = [
    'money.topup_min_kobo' => 'Minimum top-up (₦)',
    'money.topup_max_kobo' => 'Maximum top-up (₦)',
    'money.withdraw_min_kobo' => 'Minimum withdrawal (₦)',
    'money.withdraw_max_kobo' => 'Maximum withdrawal (₦)',
    'money.withdraw_daily_max_kobo' => 'Withdrawals per earner per 24 hours, total (₦)',
];
// key => [label, min, max, hint]
$intFields = [
    'money.withdraw_daily_count' => ['Withdrawals per earner per 24 hours (count)', 1, 50, 'Rejected requests do not count.'],
    'money.bank_change_cooldown_hours' => ['Withdrawal pause after a bank account change (hours)', 0, 168, '0 = no pause. Adding the first account never pauses withdrawals.'],
    'money.payout_stuck_hours' => ['Flag a payout with no AfrStar callback after (hours)', 1, 72, 'Flagged payouts appear in Payments & review.'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    yolotask_admin_csrf_or_back('payment-settings.php');
    try {
        $values = [];
        foreach ($fields as $key => $label) {
            $kobo = yolotask_parse_naira(is_string($_POST[str_replace('.', '_', $key)] ?? null) ? $_POST[str_replace('.', '_', $key)] : '');
            if ($kobo === null || $kobo < 100) throw new RuntimeException($label . ' must be at least ₦1.00.');
            $values[$key] = (string) $kobo;
        }
        $hours = (int) ($_POST['money_payin_expiry_hours'] ?? 0);
        if ($hours < 1 || $hours > 168) throw new RuntimeException('Top-up expiry must be between 1 and 168 hours.');
        $values['money.payin_expiry_hours'] = (string) $hours;
        foreach ($intFields as $key => [$label, $min, $max]) {
            $raw = is_string($_POST[str_replace('.', '_', $key)] ?? null) ? $_POST[str_replace('.', '_', $key)] : '';
            if (!preg_match('/^\d{1,4}$/', $raw) || (int) $raw < $min || (int) $raw > $max) throw new RuntimeException("{$label} must be between {$min} and {$max}.");
            $values[$key] = (string) (int) $raw;
        }
        if ((int) $values['money.withdraw_daily_max_kobo'] < (int) $values['money.withdraw_min_kobo']) throw new RuntimeException('The 24-hour total cannot be below the minimum withdrawal.');
        if ((int) $values['money.topup_min_kobo'] > (int) $values['money.topup_max_kobo']) throw new RuntimeException('The minimum top-up cannot be above the maximum.');
        if ((int) $values['money.withdraw_min_kobo'] > (int) $values['money.withdraw_max_kobo']) throw new RuntimeException('The minimum withdrawal cannot be above the maximum.');
        // Earner withdrawal fee (percentage, 0-50, up to two decimals; stored in basis points).
        // Older forms without the field keep the current rate.
        $oldFee = Fees::withdrawalBaseBps($pdo);
        $feeRaw = is_string($_POST['money_withdraw_fee_percent'] ?? null) ? $_POST['money_withdraw_fee_percent'] : null;
        $feeBps = $feeRaw === null ? $oldFee : Fees::parsePercent($feeRaw);
        if ($feeBps === null) throw new RuntimeException('The withdrawal fee must be a percentage from 0 to 50 (up to two decimals).');
        $values['money.withdraw_fee_bps'] = (string) $feeBps;
        Settings::save($pdo, (int) $admin['id'], $values);
        if ($feeBps !== $oldFee) Audit::record($pdo, (int) $admin['id'], 'admin.withdrawal_fee_changed', 'settings', $feeBps);
        yolotask_flash('Money settings saved.');
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.money_settings_failed', ['exception' => get_class($e)]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect('payment-settings.php');
}

$all = Settings::all($pdo);
$csrf = Csrf::token();
$configured = AfrstarClient::isConfigured();
// Readiness: bank numbers are sealed with OpenSSL AES-256-GCM (enc2:). ext-sodium
// is optional; it is only needed to read legacy enc1: values.
$cryptoOk = Crypto::gcmAvailable();
$sodiumOk = Crypto::sodiumAvailable();
$feeBps = Fees::withdrawalBaseBps($pdo);
$levelFees = array_values(array_filter(\YoloTask\Growth\Levels::all($pdo), static fn (array $l): bool => $l['withdrawal_fee_bps'] !== null));
ob_start();
?>
<?= yolotask_admin_money_tabs($pdo, 'settings') ?>
<div class="wallet-grid">
  <section class="card notice-card<?= $configured ? '' : ' notice-warning' ?>" role="status">
    <span class="verify-icon"><?= yolotask_nav_icon($configured ? 'shield' : 'bell') ?></span>
    <div><strong>AfrStar <?= $configured ? 'connected' : 'not connected' ?></strong>
      <p class="text-sm text-faint"><?= $configured ? 'Merchant credentials are loaded from the private server configuration.' : 'Add the one-line afrstar.key file (MERCHANT_SN,MERCHANT_AK,MERCHANT_SK) to the private config directory to enable top-ups and payouts.' ?></p></div>
  </section>
  <section class="card notice-card<?= $cryptoOk ? '' : ' notice-warning' ?>" role="status" data-check="bank-encryption">
    <span class="verify-icon"><?= yolotask_nav_icon($cryptoOk ? 'shield' : 'bell') ?></span>
    <div><strong>Bank details encryption <?= $cryptoOk ? 'ready' : 'unavailable' ?></strong>
      <p class="text-sm text-faint"><?= $cryptoOk
        ? 'Account numbers are encrypted with OpenSSL AES-256-GCM. The PHP sodium extension is ' . ($sodiumOk ? 'loaded' : 'not loaded — that is fine; it is only needed to read older enc1 values') . '.'
        : 'Enable the PHP openssl extension (aes-256-gcm) on this server. Earners cannot save bank accounts until it is available.' ?></p></div>
  </section>
  <section class="card" aria-labelledby="ms-title">
    <div class="card-header"><h2 id="ms-title">Money rules</h2><span class="badge badge-info" data-fee-badge><?= $feeBps > 0 ? h(Fees::percent($feeBps)) . ' withdrawal fee' : 'No fees' ?></span></div>
    <form method="post" action="payment-settings.php" class="stack">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <?php foreach ($fields as $key => $label): $name = str_replace('.', '_', $key); ?>
        <div class="field"><label class="label" for="<?= h($name) ?>"><?= h($label) ?></label>
          <input class="input" id="<?= h($name) ?>" name="<?= h($name) ?>" inputmode="decimal" required value="<?= h(Money::decimal((int) $all[$key])) ?>"></div>
      <?php endforeach; ?>
      <div class="field"><label class="label" for="money_withdraw_fee_percent">Earner withdrawal fee (%)</label>
        <input class="input" id="money_withdraw_fee_percent" name="money_withdraw_fee_percent" inputmode="decimal" required pattern="[0-9]{1,2}(\.[0-9]{1,2})?" value="<?= h(rtrim(rtrim(number_format($feeBps / 100, 2, '.', ''), '0'), '.')) ?>">
        <p class="input-hint">0–50, up to two decimals; 0 = free. Taken from the amount the earner withdraws and rounded down to the kobo. Earner levels can set a lower rate (Growth → Earner levels)<?= $levelFees !== [] ? ': ' . h(implode(', ', array_map(static fn (array $l): string => $l['name'] . ' ' . Fees::percent(min($feeBps, (int) $l['withdrawal_fee_bps'])), $levelFees))) : '' ?>. Each withdrawal keeps the fee it was requested with; a change here applies to new requests only. The minimum and maximum apply to the amount before the fee.</p></div>
      <div class="field"><label class="label" for="money_payin_expiry_hours">Unpaid top-ups expire after (hours)</label>
        <input class="input" id="money_payin_expiry_hours" name="money_payin_expiry_hours" type="number" min="1" max="168" required value="<?= (int) $all['money.payin_expiry_hours'] ?>">
        <p class="input-hint">Expiring moves no money. A late confirmed payment from AfrStar is still credited.</p></div>
      <?php foreach ($intFields as $key => [$label, $min, $max, $hint]): $name = str_replace('.', '_', $key); ?>
        <div class="field"><label class="label" for="<?= h($name) ?>"><?= h($label) ?></label>
          <input class="input" id="<?= h($name) ?>" name="<?= h($name) ?>" type="number" min="<?= $min ?>" max="<?= $max ?>" required value="<?= (int) $all[$key] ?>">
          <p class="input-hint"><?= h($hint) ?></p></div>
      <?php endforeach; ?>
      <button class="btn btn-primary" type="submit">Save money rules</button>
    </form>
  </section>
</div>
<?php
yolotask_render_app($admin, 'Money settings', (string) ob_get_clean(), 'money');
