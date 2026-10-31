<?php
declare(strict_types=1);

use YoloTask\Campaigns\Instructions;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Csrf;
use YoloTask\Tasks\ReviewClaims;
use YoloTask\Tasks\StaleQueue;

require_once __DIR__ . '/tasks-helpers.php';
require_once __DIR__ . '/review-helpers.php';

/* Reviewer: stale pending proofs the advertiser has not decided (claim, then approve or reject). */
if (yolotask_role() !== 'reviewer') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('reviewer.stale_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$rid = (int) $user['id'];
$page = max(1, (int) ($_GET['page'] ?? 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'stale.php' . ($page > 1 ? '?page=' . $page : '');
    yolotask_csrf_or_back($back);
    $sid = (int) ($_POST['submission_id'] ?? 0);
    $action = yolotask_post_str('action');
    try {
        switch ($action) {
            case 'claim':
                $exp = StaleQueue::claim($pdo, $user, $sid);
                yolotask_flash('Claimed until ' . yolotask_wat($exp, 'g:i A') . '.');
                break;
            case 'release':
                StaleQueue::release($pdo, $user, $sid);
                yolotask_flash('Released back to the queue.');
                break;
            case 'approve':
                StaleQueue::decide($pdo, $user, $sid, 'approve');
                yolotask_flash('Approved. The earner has been paid.');
                break;
            case 'reject':
                StaleQueue::decide($pdo, $user, $sid, 'reject', yolotask_post_str('reason'));
                yolotask_flash('Rejected. The earner was emailed the reason.');
                break;
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('reviewer.stale_action_failed', ['action' => $action, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$list = StaleQueue::queue($pdo, $page);
$csrf = Csrf::token();
$hidden = static fn (int $sid): string => '<input type="hidden" name="_csrf" value="' . h($csrf) . '"><input type="hidden" name="submission_id" value="' . $sid . '">';
$action = 'stale.php' . ($page > 1 ? '?page=' . $page : '');
ob_start();
?>
<?= yolotask_reviewer_tabs($pdo, 'stale') ?>
<section class="card" aria-labelledby="st-title">
  <div class="card-header"><h2 id="st-title">Stale proofs</h2><span class="badge badge-info"><?= number_format($list['total']) ?></span></div>
  <p class="text-sm text-faint">Proofs waiting more than <?= StaleQueue::hours($pdo) ?> hours for the advertiser. Decide them before they auto-approve. Claim one to lock it for <?= ReviewClaims::minutes($pdo) ?> minutes.</p>
  <?php if ($list['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint">Nothing stale right now.</p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($list['rows'] as $s): $mine = $s['claim_user_id'] !== null && (int) $s['claim_user_id'] === $rid; $v = $mine ? Instructions::version($pdo, (int) $s['campaign_id'], (int) $s['instructions_version']) : null; ?>
        <li class="user-row submission-row" data-stale="<?= (int) $s['id'] ?>">
          <?= yolotask_proof_thumb($s['latest_proof_id'] !== null ? (int) $s['latest_proof_id'] : null) ?>
          <div class="user-meta">
            <strong>#<?= (int) $s['id'] ?> · <?= h((string) $s['campaign_title']) ?></strong>
            <span class="text-xs text-faint"><?= h((string) $s['platform_name']) ?> <?= h((string) $s['task_type_name']) ?> · submitted <?= h(yolotask_wat((string) $s['submitted_at'])) ?> · auto-approves in <?= h(yolotask_until((string) $s['auto_approve_at'])) ?> · reward <?= h(yolotask_money($s['unit_reward_kobo'])) ?></span>
            <?php if ($s['comment']): ?><span class="text-sm text-break">“<?= h((string) $s['comment']) ?>”</span><?php endif; ?>
            <?php if ($mine): ?>
              <?php if ($v !== null): ?><details open><summary class="text-sm">Instructions (version <?= (int) $v['version'] ?>)</summary><div class="instructions text-break text-sm"><?= nl2br(h((string) $v['instructions'])) ?></div><p class="text-xs text-faint text-break">Proof required: <?= h((string) $v['proof_requirements']) ?></p></details><?php endif; ?>
              <span class="user-tags"><span class="badge badge-success" data-claim="mine">Yours until <?= h(yolotask_wat((string) $s['claim_expires_at'], 'g:i A')) ?></span></span>
              <div class="review-actions">
                <form method="post" action="<?= h($action) ?>"><?= $hidden((int) $s['id']) ?><button class="btn btn-primary btn-sm btn-block" type="submit" name="action" value="approve">Approve</button></form>
                <form method="post" action="<?= h($action) ?>" class="inline-form"><?= $hidden((int) $s['id']) ?><input class="input" name="reason" minlength="3" maxlength="255" required placeholder="Reason" aria-label="Reject reason"><button class="btn btn-danger-outline btn-sm" type="submit" name="action" value="reject">Reject</button></form>
                <form method="post" action="<?= h($action) ?>"><?= $hidden((int) $s['id']) ?><button class="btn btn-ghost btn-sm" type="submit" name="action" value="release">Release</button></form>
              </div>
            <?php elseif ($s['claim_user_id'] !== null): ?>
              <span class="user-tags"><span class="badge badge-warning" data-claim="taken">Claimed by <?= h((string) $s['claim_reviewer_name']) ?></span></span>
            <?php else: ?>
              <form method="post" action="<?= h($action) ?>"><?= $hidden((int) $s['id']) ?><button class="btn btn-secondary btn-sm" type="submit" name="action" value="claim">Claim</button></form>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?= yolotask_pager($list, static fn (int $p): string => 'stale.php?page=' . $p) ?>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($user, 'Stale proofs', (string) ob_get_clean(), 'stale');
