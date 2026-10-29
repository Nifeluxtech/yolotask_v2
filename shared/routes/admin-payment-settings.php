<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Money\Money;
use YoloTask\Payments\AfrstarClient;
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
        if ((int) $values['money.topup_min_kobo'] > (int) $values['money.topup_max_kobo']) throw new RuntimeException('The minimum top-up cannot be above the maximum.');
        if ((int) $values['money.withdraw_min_kobo'] > (int) $values['money.withdraw_max_kobo']) throw new RuntimeException('The minimum withdrawal cannot be above the maximum.');
        Settings::save($pdo, (int) $admin['id'], $values);
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
ob_start();
?>
<?= yolotask_admin_money_tabs($pdo, 'settings') ?>
<div class="wallet-grid">
  <section class="card notice-card<?= $configured ? '' : ' notice-warning' ?>" role="status">
    <span class="verify-icon"><?= yolotask_nav_icon($configured ? 'shield' : 'bell') ?></span>
    <div><strong>AfrStar <?= $configured ? 'connected' : 'not connected' ?></strong>
      <p class="text-sm text-faint"><?= $configured ? 'Merchant credentials are loaded from the private server configuration.' : 'Add the one-line afrstar.key file (MERCHANT_SN,MERCHANT_AK,MERCHANT_SK) to the private config directory to enable top-ups and payouts.' ?></p></div>
  </section>
  <section class="card" aria-labelledby="ms-title">
    <div class="card-header"><h2 id="ms-title">Money rules</h2><span class="badge badge-info">No fees</span></div>
    <form method="post" action="payment-settings.php" class="stack">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <?php foreach ($fields as $key => $label): $name = str_replace('.', '_', $key); ?>
        <div class="field"><label class="label" for="<?= h($name) ?>"><?= h($label) ?></label>
          <input class="input" id="<?= h($name) ?>" name="<?= h($name) ?>" inputmode="decimal" required value="<?= h(Money::decimal((int) $all[$key])) ?>"></div>
      <?php endforeach; ?>
      <div class="field"><label class="label" for="money_payin_expiry_hours">Unpaid top-ups expire after (hours)</label>
        <input class="input" id="money_payin_expiry_hours" name="money_payin_expiry_hours" type="number" min="1" max="168" required value="<?= (int) $all['money.payin_expiry_hours'] ?>">
        <p class="input-hint">Expiring moves no money. A late confirmed payment from AfrStar is still credited.</p></div>
      <button class="btn btn-primary" type="submit">Save money rules</button>
    </form>
  </section>
</div>
<?php
yolotask_render_app($admin, 'Money settings', (string) ob_get_clean(), 'money');
