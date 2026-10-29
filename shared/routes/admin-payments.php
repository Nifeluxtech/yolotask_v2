<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Payments\PaymentOrders;
use YoloTask\Payments\PaymentReview;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/money-helpers.php';

if (yolotask_role() !== 'admin') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $admin = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('admin.payments_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$adminId = (int) $admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    yolotask_admin_csrf_or_back('payments.php');
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['review_id'] ?? 0);
    try {
        if ($action === 'credit') {
            $amount = PaymentReview::credit($pdo, $adminId, $id, (string) ($_POST['note'] ?? ''));
            yolotask_flash('Credited ' . yolotask_money($amount) . ' to the user\'s wallet.');
        } elseif ($action === 'reject') {
            PaymentReview::reject($pdo, $adminId, $id, (string) ($_POST['note'] ?? ''));
            yolotask_flash('Review item closed without crediting.');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.payments_action_failed', ['action' => $action, 'exception' => get_class($e)]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect('payments.php');
}

$reviews = PaymentReview::list($pdo, 'open');
$resolved = array_merge(PaymentReview::list($pdo, 'credited', 5), PaymentReview::list($pdo, 'rejected', 5), PaymentReview::list($pdo, 'dismissed', 5));
$status = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$q = trim(is_string($_GET['q'] ?? null) ? mb_substr($_GET['q'], 0, 100) : '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$orders = PaymentOrders::list($pdo, $status, $q, $page);
$callbacks = $pdo->query('SELECT id, kind, order_sn, platform_osn, provider_status, signature_valid, outcome, http_status, remote_ip, raw_body, received_at FROM payment_callbacks ORDER BY id DESC LIMIT 20')->fetchAll() ?: [];
$csrf = Csrf::token();
$kindLabel = ['amount_mismatch' => 'Amount mismatch', 'unmatched_callback' => 'Unknown order', 'payout_conflict' => 'Payout conflict'];
$opt = static fn (string $value, string $label, string $current): string => '<option value="' . h($value) . '"' . ($value === $current ? ' selected' : '') . '>' . h($label) . '</option>';
ob_start();
?>
<?= yolotask_admin_money_tabs($pdo, 'payments') ?>
<section class="card" id="review" aria-labelledby="review-title">
  <div class="card-header"><h2 id="review-title">Payment review queue</h2><span class="badge <?= $reviews ? 'badge-warning' : 'badge-success' ?>"><?= count($reviews) ?> open</span></div>
  <p class="text-sm text-faint">Nothing here is ever credited automatically. For an amount mismatch you can credit what the customer actually paid, or reject it and settle the payment with AfrStar outside the app.</p>
  <?php if ($reviews === []): ?>
    <div class="empty-state"><p class="text-faint">The queue is clear.</p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($reviews as $r): ?>
        <li class="user-row" data-review="<?= (int) $r['id'] ?>">
          <div class="user-meta">
            <strong><?= h($kindLabel[(string) $r['kind']] ?? (string) $r['kind']) ?> · <?= h((string) ($r['order_sn'] ?? '—')) ?></strong>
            <span class="text-xs text-faint text-break"><?= h((string) $r['detail']) ?></span>
            <span class="user-tags">
              <?php if ($r['expected_kobo'] !== null): ?><span class="badge badge-info">Order <?= h(yolotask_money($r['expected_kobo'])) ?></span><?php endif; ?>
              <?php if ($r['paid_kobo'] !== null): ?><span class="badge badge-warning">Paid <?= h(yolotask_money($r['paid_kobo'])) ?></span><?php endif; ?>
              <?php if (!empty($r['email'])): ?><span class="text-xs text-faint"><?= h((string) $r['display_name']) ?> · <?= h((string) $r['email']) ?></span><?php endif; ?>
              <span class="text-xs text-faint"><?= h(yolotask_wat((string) $r['created_at'])) ?><?= $r['platform_osn'] ? ' · AfrStar ' . h((string) $r['platform_osn']) : '' ?></span>
            </span>
            <form method="post" action="payments.php" class="stack" style="margin-top:8px">
              <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="review_id" value="<?= (int) $r['id'] ?>">
              <input class="input" name="note" maxlength="255" minlength="3" required placeholder="Decision note (required)" aria-label="Decision note">
              <div class="review-actions">
                <?php if ((string) $r['kind'] === 'amount_mismatch' && (int) ($r['paid_kobo'] ?? 0) > 0): ?>
                  <button class="btn btn-primary btn-sm" type="submit" name="action" value="credit" data-confirm="Credit <?= h(yolotask_money($r['paid_kobo'])) ?> to this wallet?">Credit <?= h(yolotask_money($r['paid_kobo'])) ?></button>
                <?php endif; ?>
                <button class="btn btn-danger-outline btn-sm" type="submit" name="action" value="reject"><?= (string) $r['kind'] === 'amount_mismatch' ? 'Reject' : 'Dismiss' ?></button>
              </div>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if ($resolved !== []): ?>
    <details style="margin-top:1rem"><summary class="text-sm fw-semibold">Recently resolved</summary>
      <div class="table-wrap"><table class="table"><thead><tr><th>Item</th><th>Outcome</th><th>Note</th></tr></thead><tbody>
        <?php foreach ($resolved as $r): ?><tr><td><?= h($kindLabel[(string) $r['kind']] ?? '') ?> <?= h((string) ($r['order_sn'] ?? '')) ?></td><td><?= yolotask_money_badge((string) $r['status']) ?><?= $r['credited_kobo'] ? ' ' . h(yolotask_money($r['credited_kobo'])) : '' ?></td><td class="text-xs"><?= h((string) $r['resolution_note']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </details>
  <?php endif; ?>
</section>

<section class="card" id="orders" aria-labelledby="orders-title">
  <div class="card-header"><h2 id="orders-title">Top-up orders</h2><span class="badge badge-info"><?= number_format($orders['total']) ?></span></div>
  <form method="get" action="payments.php#orders" class="filter-bar">
    <input class="input" type="search" name="q" value="<?= h($q) ?>" placeholder="Order, AfrStar ref or email" aria-label="Search orders">
    <select class="input" name="status" aria-label="Status"><?= $opt('', 'Any status', $status) . $opt('pending', 'Waiting', $status) . $opt('paid', 'Paid', $status) . $opt('review', 'In review', $status) . $opt('expired', 'Expired', $status) . $opt('failed', 'Failed', $status) . $opt('rejected', 'Rejected', $status) ?></select>
    <span></span>
    <button class="btn btn-primary" type="submit">Filter</button>
  </form>
  <?php if ($orders['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint">No orders match.</p></div>
  <?php else: ?>
    <ul class="tx-list">
      <?php foreach ($orders['rows'] as $o): ?>
        <li class="tx-row">
          <div class="tx-meta">
            <strong class="mono"><?= h((string) $o['order_sn']) ?></strong>
            <span class="text-xs text-faint text-break"><?= h((string) $o['display_name']) ?> · <?= h((string) $o['email']) ?></span>
            <span class="text-xs text-faint"><?= h(yolotask_wat((string) $o['created_at'])) ?><?= $o['platform_osn'] ? ' · AfrStar ' . h((string) $o['platform_osn']) : '' ?></span>
          </div>
          <div class="tx-amount"><span><?= h(yolotask_money($o['amount_kobo'])) ?></span><?php if ($o['amount_paid_kobo'] !== null && (int) $o['amount_paid_kobo'] !== (int) $o['amount_kobo']): ?><span class="text-xs text-warning">paid <?= h(yolotask_money($o['amount_paid_kobo'])) ?></span><?php endif; ?><?= yolotask_money_badge((string) $o['status']) ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?= yolotask_pager($orders, static fn (int $p): string => 'payments.php?' . http_build_query(['status' => $status, 'q' => $q, 'page' => $p]) . '#orders') ?>
  <?php endif; ?>
</section>

<section class="card" id="callbacks" aria-labelledby="cb-title">
  <div class="card-header"><h2 id="cb-title">Latest AfrStar callbacks</h2><span class="badge badge-info">Raw log</span></div>
  <?php if ($callbacks === []): ?>
    <div class="empty-state"><p class="text-faint">No callbacks received yet.</p></div>
  <?php else: ?>
    <ul class="tx-list">
      <?php foreach ($callbacks as $c): ?>
        <li class="tx-row"><div class="tx-meta">
          <strong><?= h(ucfirst((string) $c['kind'])) ?> · <span class="mono"><?= h((string) ($c['order_sn'] ?? '—')) ?></span></strong>
          <span class="text-xs text-faint"><?= h(yolotask_wat((string) $c['received_at'])) ?> · status <?= h((string) ($c['provider_status'] ?? '—')) ?> · <?= h((string) $c['outcome']) ?> · HTTP <?= h((string) ($c['http_status'] ?? '—')) ?><?= $c['signature_valid'] === null ? '' : ((int) $c['signature_valid'] ? ' · signature ok' : ' · bad signature') ?> · <?= h((string) ($c['remote_ip'] ?? '')) ?></span>
          <details><summary class="text-xs">Raw body</summary><pre class="raw-body"><?= h(mb_substr((string) $c['raw_body'], 0, 4000)) ?></pre></details>
        </div></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($admin, 'Payments', (string) ob_get_clean(), 'money');
