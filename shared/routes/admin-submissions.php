<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Csrf;
use YoloTask\Tasks\Approvals;
use YoloTask\Tasks\Proofs;
use YoloTask\Tasks\StaleQueue;
use YoloTask\Tasks\Submissions;

require_once __DIR__ . '/tasks-helpers.php';

/* Admin: every submission, with the stale queue, proofs and admin decisions. */
if (yolotask_role() !== 'admin') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $admin = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('admin.submissions_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$adminId = (int) $admin['id'];
$status = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'pending';
$q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$query = static fn (array $over): string => '?' . http_build_query(array_filter(array_merge(['status' => $status, 'q' => $q, 'page' => $page], $over), static fn ($v) => $v !== '' && $v !== null));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $backParam = yolotask_post_str('back');
    $back = 'submissions.php' . (str_starts_with($backParam, '?') ? $backParam : '');
    yolotask_admin_csrf_or_back($back);
    $sid = (int) ($_POST['submission_id'] ?? 0);
    try {
        if (yolotask_post_str('action') === 'approve') {
            Approvals::approve($pdo, $sid, 'admin', $adminId);
            yolotask_flash('Submission approved and the earner paid.');
        } elseif (yolotask_post_str('action') === 'reject') {
            Approvals::reject($pdo, $sid, 'admin', $adminId, yolotask_post_str('reason'));
            yolotask_flash('Submission rejected.');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.submission_action_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$list = Submissions::listForAdmin($pdo, $status, $q, $page);
$staleHours = StaleQueue::hours($pdo);
$csrf = Csrf::token();
$filters = ['pending' => 'Pending', 'stale' => 'Stale (' . $staleHours . ' h+)', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'];
ob_start();
?>
<?= yolotask_admin_market_tabs($pdo, 'submissions') ?>
<section class="card" aria-labelledby="as-title">
  <div class="card-header"><h2 id="as-title">Submissions</h2><span class="badge badge-info"><?= number_format($list['total']) ?></span></div>
  <form method="get" action="submissions.php" class="inline-form" style="margin-bottom:.75rem">
    <input type="hidden" name="status" value="<?= h($status) ?>">
    <input class="input" type="search" name="q" value="<?= h($q) ?>" placeholder="Earner email or campaign title" aria-label="Search submissions">
    <button class="btn btn-secondary btn-sm" type="submit">Search</button>
  </form>
  <nav class="money-tabs" aria-label="Status filter" style="padding:0">
    <?php foreach ($filters as $k => $label): ?><a href="submissions.php?status=<?= h($k) ?>"<?= $k === $status || ($k === 'all' && !isset($filters[$status])) ? ' class="active"' : '' ?>><?= h($label) ?></a><?php endforeach; ?>
  </nav>
  <?php if ($list['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint">No submissions here.</p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($list['rows'] as $s): ?>
        <li class="user-row submission-row" data-submission="<?= (int) $s['id'] ?>">
          <?= yolotask_proof_thumb($s['latest_proof_id'] !== null ? (int) $s['latest_proof_id'] : null) ?>
          <div class="user-meta">
            <strong>#<?= (int) $s['id'] ?> · <a href="campaign.php?id=<?= (int) $s['campaign_id'] ?>"><?= h((string) $s['campaign_title']) ?></a></strong>
            <span class="text-xs text-faint text-break"><?= h((string) $s['earner_name']) ?> · <?= h((string) $s['earner_email']) ?> · <?= h((string) $s['platform_name']) ?> <?= h((string) $s['task_type_name']) ?></span>
            <span class="user-tags"><?= yolotask_submission_badge($s) ?><span class="text-xs text-faint">Submitted <?= h(yolotask_wat((string) $s['submitted_at'])) ?><?= $s['status'] === 'pending' ? ' · auto-approves ' . h(yolotask_wat((string) $s['auto_approve_at'])) : '' ?> · <?= h(yolotask_money($s['unit_price_kobo'])) ?> / earner <?= h(yolotask_money($s['unit_reward_kobo'])) ?></span></span>
            <?php if ($s['comment']): ?><span class="text-sm text-break">“<?= h((string) $s['comment']) ?>”</span><?php endif; ?>
            <?php if ($s['reject_reason']): ?><span class="text-xs text-faint">Reason: <?= h((string) $s['reject_reason']) ?></span><?php endif; ?>
            <?php if ($s['status'] === 'pending'): ?>
              <div class="review-actions">
                <form method="post" action="submissions.php"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="back" value="<?= h($query([])) ?>"><input type="hidden" name="submission_id" value="<?= (int) $s['id'] ?>">
                  <button class="btn btn-primary btn-sm btn-block" type="submit" name="action" value="approve">Approve</button></form>
                <form method="post" action="submissions.php" class="inline-form"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="back" value="<?= h($query([])) ?>"><input type="hidden" name="submission_id" value="<?= (int) $s['id'] ?>">
                  <input class="input" name="reason" minlength="3" maxlength="255" required placeholder="Reason" aria-label="Reject reason"><button class="btn btn-danger-outline btn-sm" type="submit" name="action" value="reject">Reject</button></form>
              </div>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?= yolotask_pager($list, static fn (int $p): string => 'submissions.php' . $query(['page' => $p])) ?>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($admin, 'Submissions', (string) ob_get_clean(), 'market');
