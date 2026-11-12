<?php
declare(strict_types=1);

use YoloTask\Account\Activation;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Money\Money;
use YoloTask\Security\Audit;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/tasks-helpers.php';

/*
 * Admin: account activation (migration 0019). The ON/OFF switch (default
 * OFF) and the one-time fee; activation payments (AfrStar, YTA...) with a
 * manual verify path; activated earners. While ON, an earner who has not
 * activated cannot see or start any task; work already started can be
 * finished. Earners who activated stay activated if it is switched off.
 */
if (yolotask_role() !== 'admin') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $admin = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('admin.activation_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$adminId = (int) $admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'activation.php' . (is_string($_POST['back'] ?? null) && preg_match('/^[a-z0-9_=&%#.+\-]*$/i', $_POST['back']) ? ($_POST['back'] !== '' ? '?' . ltrim($_POST['back'], '?') : '') : '');
    yolotask_admin_csrf_or_back($back);
    $action = yolotask_post_str('action');
    try {
        if ($action === 'settings') {
            $fee = yolotask_parse_naira(yolotask_post_str('fee'));
            if ($fee === null) throw new RuntimeException('Enter the activation fee in naira, for example 500.');
            if ($fee < 10000 || $fee > 10000000) throw new RuntimeException('The activation fee must be between ' . Money::format(10000) . ' and ' . Money::format(10000000) . '.');
            $on = !empty($_POST['required']);
            Settings::save($pdo, $adminId, ['activation.fee_kobo' => (string) $fee, 'activation.required' => $on ? '1' : '0']);
            Audit::record($pdo, $adminId, 'admin.activation_settings_updated', 'settings', null);
            yolotask_flash('Activation settings saved. Account activation is ' . ($on ? 'ON: earners who have not activated cannot see or start tasks.' : 'OFF: every earner can see and do every task.'));
        } elseif ($action === 'verify') {
            Activation::adminVerify($pdo, $adminId, (int) ($_POST['payment_id'] ?? 0), yolotask_post_str('reference'), yolotask_post_str('note'));
            yolotask_flash('Payment verified. The earner\'s account is activated.');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.activation_action_failed', ['action' => $action, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$str = static fn (string $k, int $max = 100): string => trim(is_string($_GET[$k] ?? null) ? mb_substr($_GET[$k], 0, $max) : '');
$payStatus = $str('pay');
$payQ = $str('pq');
$payPage = max(1, (int) ($_GET['pp'] ?? 1));
$actQ = $str('aq');
$actPage = max(1, (int) ($_GET['ap'] ?? 1));
try {
    $required = Activation::required($pdo);
    $fee = Activation::fee($pdo);
    $payments = Activation::listPayments($pdo, $payStatus, $payQ, $payPage);
    $activated = Activation::listActivated($pdo, $actQ, $actPage);
    $revenue = (int) $pdo->query("SELECT COALESCE((SELECT balance_kobo FROM ledger_accounts WHERE code = '" . Activation::REVENUE_ACCOUNT . "'), 0)")->fetchColumn();
    $waiting = (int) $pdo->query("SELECT COUNT(*) FROM activation_payments WHERE status IN ('pending', 'review')")->fetchColumn();
} catch (Throwable $e) {
    Logger::error('admin.activation_page_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
$csrf = Csrf::token();
$keep = static fn (array $over = []): string => http_build_query(array_filter(array_merge(['pay' => $payStatus, 'pq' => $payQ, 'pp' => $payPage > 1 ? $payPage : null, 'aq' => $actQ, 'ap' => $actPage > 1 ? $actPage : null], $over), static fn ($v): bool => $v !== null && $v !== '' && $v !== 0));
$back = $keep();
$opt = static fn (string $value, string $label, string $current): string => '<option value="' . h($value) . '"' . ($value === $current ? ' selected' : '') . '>' . h($label) . '</option>';
$hidden = '<input type="hidden" name="_csrf" value="' . h($csrf) . '"><input type="hidden" name="back" value="' . h($back) . '">';
ob_start();
?>
<?= yolotask_admin_market_tabs($pdo, 'activation') ?>
<div class="wallet-grid">
  <section class="grid grid-4 dash-stats money-stats" aria-label="Account activation overview">
    <article class="stat-card <?= $required ? 'stat-warning' : '' ?>"><span class="stat-label">Account activation</span><span class="stat-value" data-stat="required"><?= $required ? 'ON' : 'OFF' ?></span><span class="text-xs text-faint">Fee <?= h(yolotask_money($fee)) ?></span></article>
    <article class="stat-card stat-success"><span class="stat-label">Activated earners</span><span class="stat-value" data-stat="activated"><?= number_format($activated['total']) ?></span><span class="text-xs text-faint">Stay activated</span></article>
    <article class="stat-card"><span class="stat-label">Activation revenue</span><span class="stat-value" data-stat="revenue"><?= h(yolotask_money($revenue)) ?></span><span class="text-xs text-faint">system:activation_revenue</span></article>
    <article class="stat-card"><span class="stat-label">Waiting</span><span class="stat-value" data-stat="waiting"><?= number_format($waiting) ?></span><span class="text-xs text-faint">Payments pending or in review</span></article>
  </section>

  <section class="card" id="settings" aria-labelledby="as-title">
    <div class="card-header"><h2 id="as-title">Activation settings</h2><span class="badge <?= $required ? 'badge-warning' : 'badge-success' ?>" data-activation-switch="<?= $required ? 'on' : 'off' ?>"><?= $required ? 'On' : 'Off' ?></span></div>
    <p class="text-sm text-faint">When ON, an earner who has not activated cannot see or start any task (advertisers' or YOLOTASK's). Their feed and dashboard show “Activate your account with <?= h(yolotask_money($fee)) ?> to access tasks.” with a payment through the active pay-in gateway (reference YTA…). Work they had already started can still be submitted. The fee is platform revenue and never reaches the wallet. When OFF, every earner sees every task and nothing mentions activation. Earners who activated stay activated.</p>
    <form method="post" action="activation.php" class="stack">
      <?= $hidden ?><input type="hidden" name="action" value="settings">
      <div class="field"><label class="label" for="fee">Activation fee (₦)</label>
        <input class="input" id="fee" name="fee" inputmode="decimal" required value="<?= h(rtrim(rtrim(number_format($fee / 100, 2, '.', ''), '0'), '.')) ?>">
        <p class="input-hint">₦100 to ₦100,000. A change applies to new payments; a waiting payment keeps its amount.</p></div>
      <label class="toggle-inline"><input type="checkbox" name="required" value="1"<?= $required ? ' checked' : '' ?>> Require account activation before earners can access tasks</label>
      <button class="btn btn-primary" type="submit">Save activation settings</button>
    </form>
  </section>

  <section class="card" id="payments" aria-labelledby="pp-title">
    <div class="card-header"><h2 id="pp-title">Activation payments</h2><span class="badge badge-info"><?= number_format($payments['total']) ?></span></div>
    <p class="text-sm text-faint">Confirmed automatically by the gateway: the AfrStar callback, or for Korapay its webhook plus a status check (also run on return and every five minutes). A different amount goes to the <a href="payments.php#review">payment review queue</a>. AfrStar has no status lookup, so when an earner paid and no confirmation came, find the transfer in the AfrStar portal and verify it here.</p>
    <form method="get" action="activation.php#payments" class="filter-bar">
      <input class="input" type="search" name="pq" value="<?= h($payQ) ?>" placeholder="Reference or email" aria-label="Search activation payments">
      <select class="input" name="pay" aria-label="Status"><?= $opt('', 'Any status', $payStatus) . $opt('pending', 'Waiting', $payStatus) . $opt('paid', 'Paid', $payStatus) . $opt('review', 'In review', $payStatus) . $opt('expired', 'Expired', $payStatus) . $opt('failed', 'Failed', $payStatus) . $opt('rejected', 'Rejected', $payStatus) ?></select>
      <span></span>
      <button class="btn btn-primary" type="submit">Filter</button>
    </form>
    <?php if ($payments['rows'] === []): ?>
      <div class="empty-state"><p class="text-faint">No activation payments yet.</p></div>
    <?php else: ?>
      <ul class="tx-list">
        <?php foreach ($payments['rows'] as $o): ?>
          <li class="tx-row" data-activation-payment="<?= h((string) $o['order_sn']) ?>">
            <div class="tx-meta">
              <strong class="mono"><?= h((string) $o['order_sn']) ?></strong>
              <span class="text-xs text-faint text-break"><?= h((string) $o['display_name']) ?> · <?= h((string) $o['email']) ?></span>
              <span class="text-xs text-faint"><?= h(yolotask_wat((string) $o['created_at'])) ?><?= $o['platform_osn'] ? ' · AfrStar ' . h((string) $o['platform_osn']) : '' ?><?= $o['verified_by_user_id'] ? ' · verified by an admin' : '' ?><?= $o['provider_message'] ? ' · ' . h((string) $o['provider_message']) : '' ?></span>
              <?php if (in_array((string) $o['status'], ['pending', 'expired', 'failed'], true)): ?>
                <details><summary class="text-xs">Verify payment manually</summary>
                  <form method="post" action="activation.php" class="stack" style="margin-top:6px">
                    <?= $hidden ?><input type="hidden" name="action" value="verify"><input type="hidden" name="payment_id" value="<?= (int) $o['id'] ?>">
                    <input class="input" name="reference" maxlength="120" required placeholder="AfrStar reference (order, session id or UTR)" aria-label="AfrStar reference">
                    <input class="input" name="note" maxlength="255" minlength="3" required placeholder="How you verified it (required)" aria-label="Verification note">
                    <button class="btn btn-primary btn-sm" type="submit" data-confirm="Mark <?= h((string) $o['order_sn']) ?> as paid (<?= h(yolotask_money($o['amount_kobo'])) ?>) and activate this earner's account?">Verify and activate</button>
                  </form>
                </details>
              <?php endif; ?>
            </div>
            <div class="tx-amount"><span><?= h(yolotask_money($o['amount_kobo'])) ?></span><?php if ($o['amount_paid_kobo'] !== null && (int) $o['amount_paid_kobo'] !== (int) $o['amount_kobo']): ?><span class="text-xs text-warning">paid <?= h(yolotask_money($o['amount_paid_kobo'])) ?></span><?php endif; ?><?= yolotask_money_badge((string) $o['status']) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?= yolotask_pager($payments, static fn (int $p): string => 'activation.php?' . $keep(['pp' => $p]) . '#payments') ?>
    <?php endif; ?>
  </section>

  <section class="card" id="activated" aria-labelledby="pa-title">
    <div class="card-header"><h2 id="pa-title">Activated earners</h2><span class="badge badge-info"><?= number_format($activated['total']) ?></span></div>
    <form method="get" action="activation.php#activated" class="inline-form" style="margin-bottom:.75rem">
      <input class="input" type="search" name="aq" value="<?= h($actQ) ?>" placeholder="Name or email" aria-label="Search activated earners">
      <button class="btn btn-secondary btn-sm" type="submit">Search</button>
    </form>
    <?php if ($activated['rows'] === []): ?>
      <div class="empty-state"><p class="text-faint">No earner has activated yet.</p></div>
    <?php else: ?>
      <ul class="tx-list">
        <?php foreach ($activated['rows'] as $a): ?>
          <li class="tx-row" data-activated="<?= (int) $a['user_id'] ?>">
            <div class="tx-meta"><strong><?= h((string) $a['display_name']) ?></strong>
              <span class="text-xs text-faint text-break"><?= h((string) $a['email']) ?> · <?= h(yolotask_wat((string) $a['activated_at'])) ?><?= $a['order_sn'] ? ' · ' . h((string) $a['order_sn']) : '' ?><?= (string) $a['source'] !== 'payment' ? ' · ' . ((string) $a['source'] === 'review' ? 'after review' : 'verified by an admin') : '' ?></span></div>
            <div class="tx-amount"><span><?= h(yolotask_money($a['amount_kobo'])) ?></span><span class="text-xs text-faint"><?= number_format((int) $a['tasks_approved']) ?> tasks approved</span></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?= yolotask_pager($activated, static fn (int $p): string => 'activation.php?' . $keep(['ap' => $p]) . '#activated') ?>
    <?php endif; ?>
  </section>
</div>
<?php
yolotask_render_app($admin, 'Account activation', (string) ob_get_clean(), 'market');
