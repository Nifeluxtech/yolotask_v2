<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Payments\AfrstarClient;
use YoloTask\Payments\Withdrawals;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/money-helpers.php';

if (yolotask_role() !== 'admin') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $admin = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('admin.withdrawals_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$adminId = (int) $admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'withdrawals.php' . (is_string($_POST['back'] ?? null) && str_starts_with($_POST['back'], '?') ? $_POST['back'] : '');
    yolotask_admin_csrf_or_back($back);
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['withdrawal_id'] ?? 0);
    try {
        if ($action === 'approve') {
            $msg = Withdrawals::approve($pdo, $adminId, $id);
            yolotask_flash($msg, str_starts_with($msg, 'Withdrawal approved') ? 'success' : 'warning');
        } elseif ($action === 'reject') {
            Withdrawals::reject($pdo, $adminId, $id, (string) ($_POST['reason'] ?? ''));
            yolotask_flash('Withdrawal rejected and the hold released to the earner.');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.withdrawals_action_failed', ['action' => $action, 'exception' => get_class($e)]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$status = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'pending_review';
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = Withdrawals::list($pdo, $status, $page);
$counts = Withdrawals::counts($pdo);
$csrf = Csrf::token();
$configured = AfrstarClient::isConfigured();
$query = static fn (array $over): string => '?' . http_build_query(array_filter(array_merge(['status' => $status, 'page' => $page], $over), static fn ($v) => $v !== '' && $v !== null));
$filters = ['pending_review' => 'To review', 'processing' => 'Processing', 'paid' => 'Paid', 'failed' => 'Failed', 'rejected' => 'Rejected', 'all' => 'All'];
ob_start();
?>
<?= yolotask_admin_money_tabs($pdo, 'withdrawals') ?>
<?php if (!$configured): ?><?= yolotask_payments_unavailable_card('Payouts') ?><?php endif; ?>
<section class="card" aria-labelledby="wd-title">
  <div class="card-header"><h2 id="wd-title">Withdrawals</h2><span class="badge <?= $counts['pending_review'] > 0 ? 'badge-warning' : 'badge-info' ?>"><?= $counts['pending_review'] ?> to review</span></div>
  <nav class="money-tabs" aria-label="Status filter" style="padding:0">
    <?php foreach ($filters as $key => $label): ?><a href="withdrawals.php?status=<?= h($key) ?>"<?= $key === $status || ($key === 'all' && !isset($filters[$status])) ? ' class="active"' : '' ?>><?= h($label) ?></a><?php endforeach; ?>
  </nav>
  <?php if ($result['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint">No withdrawals here.</p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($result['rows'] as $w): ?>
        <li class="user-row" data-withdrawal="<?= (int) $w['id'] ?>">
          <div class="user-meta">
            <strong>#<?= (int) $w['id'] ?> · <?= h(yolotask_money($w['amount_kobo'])) ?> · <?= h((string) $w['display_name']) ?></strong>
            <span class="text-xs text-faint text-break"><?= h((string) $w['email']) ?> · <?= h((string) $w['bank_name']) ?> <?= h((string) $w['account_number']) ?> · <?= h((string) $w['account_name']) ?></span>
            <span class="user-tags">
              <?= yolotask_money_badge((string) $w['status']) ?>
              <span class="text-xs text-faint">Requested <?= h(yolotask_wat((string) $w['created_at'])) ?><?= $w['order_sn'] ? ' · ' . h((string) $w['order_sn']) : '' ?><?= $w['platform_osn'] ? ' · AfrStar ' . h((string) $w['platform_osn']) : '' ?></span>
            </span>
            <?php if (!empty($w['provider_message'])): ?><span class="text-xs text-warning"><?= h((string) $w['provider_message']) ?></span><?php endif; ?>
            <?php if (!empty($w['reject_reason'])): ?><span class="text-xs text-faint">Reason: <?= h((string) $w['reject_reason']) ?></span><?php endif; ?>
            <?php if ((string) $w['status'] === 'pending_review'): ?>
              <div class="review-actions">
                <form method="post" action="withdrawals.php">
                  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="back" value="<?= h($query([])) ?>"><input type="hidden" name="withdrawal_id" value="<?= (int) $w['id'] ?>">
                  <button class="btn btn-primary btn-sm btn-block" type="submit" name="action" value="approve"<?= $configured ? '' : ' disabled' ?> data-confirm="Send <?= h(yolotask_money($w['amount_kobo'])) ?> to <?= h((string) $w['account_name']) ?> through AfrStar?">Approve and pay</button>
                </form>
                <form method="post" action="withdrawals.php" class="inline-form">
                  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="back" value="<?= h($query([])) ?>"><input type="hidden" name="withdrawal_id" value="<?= (int) $w['id'] ?>">
                  <input class="input" name="reason" maxlength="255" minlength="3" required placeholder="Reason shown to the earner" aria-label="Reject reason">
                  <button class="btn btn-danger-outline btn-sm" type="submit" name="action" value="reject">Reject</button>
                </form>
              </div>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?= yolotask_pager($result, static fn (int $p): string => 'withdrawals.php' . $query(['page' => $p])) ?>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($admin, 'Withdrawals', (string) ob_get_clean(), 'money');
