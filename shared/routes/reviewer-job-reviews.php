<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Jobs\Attempts;
use YoloTask\Jobs\Jobs;
use YoloTask\Jobs\SystemReviews;
use YoloTask\Security\Csrf;
use YoloTask\Tasks\ReviewClaims;

require_once __DIR__ . '/jobs-helpers.php';
require_once __DIR__ . '/review-helpers.php';

/*
 * Reviewer: submissions on YOLOTASK jobs (migration 0024). There is no
 * advertiser, so reviewers decide them like the advertiser would: claim, then
 * approve (YOLOTASK pays the earner), send one step back, or reject.
 *   job-reviews.php         the queue, oldest review deadline first
 *   job-review.php?id=      one submission with every step's proof
 */
if (yolotask_role() !== 'reviewer') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('reviewer.job_reviews_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$rid = (int) $user['id'];
$detail = defined('YOLOTASK_JOB_REVIEW_DETAIL');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aid = (int) ($_POST['attempt_id'] ?? 0);
    $back = $detail ? 'job-review.php?id=' . $aid : 'job-reviews.php';
    yolotask_csrf_or_back($back);
    $action = yolotask_post_str('action');
    try {
        switch ($action) {
            case 'claim':
                $exp = SystemReviews::claim($pdo, $user, $aid);
                yolotask_flash('Claimed until ' . yolotask_wat($exp, 'g:i A') . '.');
                $back = 'job-review.php?id=' . $aid;
                break;
            case 'release':
                SystemReviews::release($pdo, $user, $aid);
                yolotask_flash('Released back to the queue.');
                break;
            case 'approve':
                SystemReviews::decide($pdo, $user, $aid, 'approve');
                yolotask_flash('Approved. YOLOTASK paid the earner.');
                $back = 'job-reviews.php';
                break;
            case 'send_back':
                SystemReviews::decide($pdo, $user, $aid, 'send_back', ['step_id' => (int) yolotask_post_str('step_id'), 'note' => yolotask_post_str('note')]);
                yolotask_flash('Step sent back. The earner has ' . Jobs::hoursLabel(Jobs::fixHours($pdo)) . ' to fix it and resubmit.');
                $back = 'job-reviews.php';
                break;
            case 'reject':
                SystemReviews::decide($pdo, $user, $aid, 'reject', ['reason' => yolotask_post_str('reason')]);
                yolotask_flash('Rejected. The earner was told the reason and may appeal.');
                $back = 'job-reviews.php';
                break;
            default:
                throw new RuntimeException('Choose an action.');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('reviewer.job_review_action_failed', ['action' => $action, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$csrf = Csrf::token();
$hidden = static fn (int $aid): string => '<input type="hidden" name="_csrf" value="' . h($csrf) . '"><input type="hidden" name="attempt_id" value="' . $aid . '">';

if ($detail) {
    $id = (int) ($_GET['id'] ?? 0);
    $a = $id > 0 ? SystemReviews::find($pdo, $id) : null;
    if ($a === null) yolotask_error_page(404);
    $steps = Jobs::steps($pdo, (int) $a['job_id']);
    $proofs = Attempts::stepProofs($pdo, $id);
    $backs = Attempts::sendBacks($pdo, $id);
    $maxRounds = Jobs::sendBackMaxRounds($pdo);
    $mine = $a['claim_user_id'] !== null && (int) $a['claim_user_id'] === $rid;
    $taken = $a['claim_user_id'] !== null && !$mine;
    $f = $hidden($id);
    ob_start();
    ?>
<?= yolotask_reviewer_tabs($pdo, 'job_reviews') ?>
<div class="wallet-grid" data-system-job-review="<?= $id ?>">
  <section class="card card-glow dash-hero" aria-labelledby="sr-title">
    <p class="text-xs fw-bold text-primary">YOLOTASK JOB #<?= (int) $a['job_id'] ?> · SUBMISSION #<?= $id ?> · <?= count($steps) ?> STEPS</p>
    <h2 id="sr-title" class="text-break"><?= h((string) $a['job_title']) ?></h2>
    <div class="wallet-sub"><?= yolotask_attempt_badge($a) ?> <?= yolotask_job_mode($a) ?><span>Earner <?= h((string) $a['earner_name']) ?> · pay <?= h(yolotask_money($a['earner_kobo'])) ?><?= $a['submitted_at'] ? ' · submitted ' . h(yolotask_wat((string) $a['submitted_at'])) : '' ?></span></div>
    <?php if ($a['status'] === 'submitted'): ?><p class="text-sm">Decide within <strong><?= h(yolotask_until((string) $a['review_due_at'])) ?></strong>, or it is approved automatically.</p><?php endif; ?>
    <?php if ($a['link_url'] && yolotask_safe_task_url((string) $a['link_url']) !== null): ?><p class="text-sm text-break"><a href="<?= h((string) $a['link_url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= h((string) $a['link_url']) ?></a></p><?php endif; ?>
  </section>
  <section class="card"><div class="card-header"><h2>Job instructions</h2></div><div class="instructions text-break"><?= nl2br(h((string) $a['job_instructions'])) ?></div></section>
  <?= yolotask_job_review_steps($steps, $proofs, $a) ?>
  <?php if ($backs !== []): ?><section class="card"><div class="card-header"><h2>Sent back</h2><span class="badge badge-info"><?= count($backs) ?> of <?= $maxRounds ?></span></div><?= yolotask_job_send_backs($backs) ?></section><?php endif; ?>
  <?php if ($a['status'] === 'submitted'): ?>
  <section class="card" id="decide" aria-labelledby="dc-title">
    <div class="card-header"><h2 id="dc-title">Decide</h2>
      <?php if ($mine): ?><span class="badge badge-success">Yours until <?= h(yolotask_wat((string) $a['claim_expires_at'], 'g:i A')) ?></span>
      <?php elseif ($taken): ?><span class="badge badge-warning"><?= h((string) $a['claim_reviewer_name']) ?> until <?= h(yolotask_wat((string) $a['claim_expires_at'], 'g:i A')) ?></span><?php endif; ?></div>
    <?php if ($mine): ?>
      <?= yolotask_job_decision_forms('job-review.php?id=' . $id, $f, $a, $steps, $maxRounds, Jobs::fixHours($pdo)) ?>
      <form method="post" action="job-review.php?id=<?= $id ?>" style="margin-top:1rem"><?= $f ?><button class="btn btn-ghost btn-sm" type="submit" name="action" value="release">Release to queue</button></form>
    <?php elseif ($taken): ?>
      <p class="text-sm">Another reviewer is working on this submission.</p>
    <?php else: ?>
      <form method="post" action="job-review.php?id=<?= $id ?>"><?= $f ?><button class="btn btn-primary btn-block" type="submit" name="action" value="claim">Claim this submission</button></form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <p class="text-center text-sm"><a href="job-reviews.php">Back to YOLOTASK jobs</a></p>
</div>
    <?php
    yolotask_render_app($user, 'YOLOTASK job submission #' . $id, (string) ob_get_clean(), 'stale');
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$list = SystemReviews::queue($pdo, $page);
ob_start();
?>
<?= yolotask_reviewer_tabs($pdo, 'job_reviews') ?>
<section class="card" aria-labelledby="q-title">
  <div class="card-header"><h2 id="q-title">YOLOTASK job submissions</h2><span class="badge badge-info"><?= number_format($list['total']) ?></span></div>
  <p class="text-sm text-faint">Jobs YOLOTASK posts itself have no advertiser, so you decide them: approve (YOLOTASK pays the earner), send one step back, or reject. Claim one to lock it for <?= ReviewClaims::minutes($pdo) ?> minutes. Anything not decided in time is approved automatically.</p>
  <?php if ($list['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint">No YOLOTASK job submissions waiting.</p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($list['rows'] as $a): $mine = $a['claim_user_id'] !== null && (int) $a['claim_user_id'] === $rid; ?>
        <li class="user-row submission-row" data-system-job-attempt="<?= (int) $a['id'] ?>">
          <div class="user-meta">
            <strong><a href="job-review.php?id=<?= (int) $a['id'] ?>">#<?= (int) $a['id'] ?> · <?= h((string) $a['job_title']) ?></a> <span class="badge badge-info">YOLOTASK</span></strong>
            <span class="text-xs text-faint">Earner <?= h((string) $a['earner_name']) ?> · submitted <?= h(yolotask_wat((string) $a['submitted_at'])) ?> · auto-approves in <?= h(yolotask_until((string) $a['review_due_at'])) ?> · pay <?= h(yolotask_money($a['earner_kobo'])) ?><?= (int) $a['send_back_count'] > 0 ? ' · resubmitted after a fix' : '' ?></span>
            <span class="user-tags"><?= yolotask_job_mode($a) ?>
              <?php if ($mine): ?><span class="badge badge-success">Claimed by you</span>
              <?php elseif ($a['claim_user_id'] !== null): ?><span class="badge badge-warning">Claimed by <?= h((string) $a['claim_reviewer_name']) ?></span>
              <?php else: ?><span class="badge badge-info">Available</span><?php endif; ?></span>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?= yolotask_pager($list, static fn (int $p): string => 'job-reviews.php?page=' . $p) ?>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($user, 'YOLOTASK jobs', (string) ob_get_clean(), 'stale');
