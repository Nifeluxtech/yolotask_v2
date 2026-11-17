<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Jobs\Attempts;
use YoloTask\Jobs\JobAppeals;
use YoloTask\Jobs\Jobs;
use YoloTask\Security\Csrf;
use YoloTask\Tasks\ReviewClaims;

require_once __DIR__ . '/jobs-helpers.php';
require_once __DIR__ . '/review-helpers.php';

/* Reviewer: job appeal queue (job-appeals.php) and one appeal (job-appeal.php?id=) with claim / uphold / overturn (migration 0023). */
if (yolotask_role() !== 'reviewer') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('reviewer.job_appeals_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$rid = (int) $user['id'];
$detail = defined('YOLOTASK_JOB_APPEAL_DETAIL');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aid = (int) ($_POST['appeal_id'] ?? 0);
    $back = $detail ? 'job-appeal.php?id=' . $aid : 'job-appeals.php';
    yolotask_csrf_or_back($back);
    $action = yolotask_post_str('action');
    try {
        switch ($action) {
            case 'claim':
                $exp = JobAppeals::claim($pdo, $user, $aid);
                yolotask_flash('Claimed until ' . yolotask_wat($exp, 'g:i A') . '.');
                $back = 'job-appeal.php?id=' . $aid;
                break;
            case 'release':
                JobAppeals::release($pdo, $user, $aid);
                yolotask_flash('Released back to the queue.');
                break;
            case 'uphold':
            case 'overturn':
                JobAppeals::decide($pdo, $user, $aid, $action, yolotask_post_str('note'));
                yolotask_flash($action === 'overturn' ? 'Overturned. The earner has been paid and both sides were told.' : 'Rejection upheld. The earner was told.');
                $back = 'job-appeals.php';
                break;
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('reviewer.job_appeal_action_failed', ['action' => $action, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$csrf = Csrf::token();
$hidden = static fn (int $aid): string => '<input type="hidden" name="_csrf" value="' . h($csrf) . '"><input type="hidden" name="appeal_id" value="' . $aid . '">';

if ($detail) {
    $id = (int) ($_GET['id'] ?? 0);
    $ap = $id > 0 ? JobAppeals::find($pdo, $id) : null;
    if ($ap === null) yolotask_error_page(404);
    $attempt = Attempts::find($pdo, (int) $ap['attempt_id']);
    $steps = Jobs::steps($pdo, (int) $ap['job_id']);
    $proofs = Attempts::stepProofs($pdo, (int) $ap['attempt_id']);
    $backs = Attempts::sendBacks($pdo, (int) $ap['attempt_id']);
    $mine = $ap['claim_user_id'] !== null && (int) $ap['claim_user_id'] === $rid;
    $taken = $ap['claim_user_id'] !== null && !$mine;
    $own = (int) ($ap['rejected_by_user_id'] ?? 0) === $rid;
    ob_start();
    ?>
<?= yolotask_reviewer_tabs($pdo, 'job_appeals') ?>
<div class="wallet-grid" data-job-appeal="<?= (int) $ap['id'] ?>">
  <section class="card card-glow dash-hero" aria-labelledby="ja-title">
    <p class="text-xs fw-bold text-primary">JOB APPEAL #<?= (int) $ap['id'] ?> · <?= count($steps) ?> STEPS</p>
    <h2 id="ja-title" class="text-break"><?= h((string) $ap['job_title']) ?></h2>
    <div class="wallet-sub"><?= yolotask_job_appeal_badge($ap) ?> <?= yolotask_job_mode($ap) ?><span>Filed <strong><?= h(yolotask_wat((string) $ap['created_at'])) ?></strong> · earner <?= h((string) $ap['earner_name']) ?> · pay <?= h(yolotask_money($ap['earner_kobo'])) ?></span></div>
    <?php if ($ap['link_url'] && yolotask_safe_task_url((string) $ap['link_url']) !== null): ?><p class="text-sm text-break"><a href="<?= h((string) $ap['link_url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= h((string) $ap['link_url']) ?></a></p><?php endif; ?>
  </section>
  <section class="card"><div class="card-header"><h2>Job instructions</h2></div><div class="instructions text-break"><?= nl2br(h((string) $ap['job_instructions'])) ?></div></section>
  <?= yolotask_job_review_steps($steps, $proofs, $attempt) ?>
  <?php if ($backs !== []): ?><section class="card"><div class="card-header"><h2>Sent back by the advertiser</h2></div><?= yolotask_job_send_backs($backs) ?></section><?php endif; ?>
  <section class="card" aria-labelledby="rj-title">
    <div class="card-header"><h2 id="rj-title">Rejection and appeal</h2></div>
    <p class="text-sm text-break"><strong>Rejected</strong> <?= h(yolotask_wat($ap['rejected_at'] ? (string) $ap['rejected_at'] : null)) ?>: <?= h((string) $ap['rejection_reason']) ?></p>
    <p class="text-sm text-break"><strong>Earner's appeal:</strong> “<?= h((string) $ap['message']) ?>”</p>
    <?php if ($ap['status'] !== 'open'): ?><p class="text-sm text-break"><strong>Decision</strong> by <?= h((string) $ap['resolver_name']) ?> <?= h(yolotask_wat((string) $ap['resolved_at'])) ?>: <?= h((string) $ap['resolution_note']) ?></p><?php endif; ?>
  </section>
  <?php if ($ap['status'] === 'open'): ?>
  <section class="card" id="decide" aria-labelledby="dc-title">
    <div class="card-header"><h2 id="dc-title">Decide</h2>
      <?php if ($mine): ?><span class="badge badge-success">Yours until <?= h(yolotask_wat((string) $ap['claim_expires_at'], 'g:i A')) ?></span>
      <?php elseif ($taken): ?><span class="badge badge-warning"><?= h((string) $ap['claim_reviewer_name']) ?> until <?= h(yolotask_wat((string) $ap['claim_expires_at'], 'g:i A')) ?></span><?php endif; ?></div>
    <?php if ($own): ?>
      <p class="text-sm">You made the original decision, so another reviewer must handle this appeal.</p>
    <?php elseif ($mine): ?>
      <form method="post" action="job-appeal.php?id=<?= (int) $ap['id'] ?>" class="stack"><?= $hidden((int) $ap['id']) ?>
        <div class="field"><label class="label" for="note">Decision note (the earner sees this)</label><textarea class="input" id="note" name="note" rows="3" minlength="5" maxlength="500" required></textarea></div>
        <div class="review-actions">
          <button class="btn btn-primary btn-sm" type="submit" name="action" value="overturn" data-confirm="Overturn and pay the earner <?= h(yolotask_money($ap['earner_kobo'])) ?>?">Overturn and pay</button>
          <button class="btn btn-danger-outline btn-sm" type="submit" name="action" value="uphold">Uphold rejection</button>
        </div></form>
      <form method="post" action="job-appeal.php?id=<?= (int) $ap['id'] ?>"><?= $hidden((int) $ap['id']) ?><button class="btn btn-ghost btn-sm" type="submit" name="action" value="release">Release to queue</button></form>
    <?php elseif ($taken): ?>
      <p class="text-sm">Another reviewer is working on this appeal.</p>
    <?php else: ?>
      <form method="post" action="job-appeal.php?id=<?= (int) $ap['id'] ?>"><?= $hidden((int) $ap['id']) ?><button class="btn btn-primary btn-block" type="submit" name="action" value="claim">Claim this appeal</button></form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <p class="text-center text-sm"><a href="job-appeals.php">Back to job appeals</a></p>
</div>
    <?php
    yolotask_render_app($user, 'Job appeal #' . (int) $ap['id'], (string) ob_get_clean(), 'appeals');
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$list = JobAppeals::queue($pdo, $page);
ob_start();
?>
<?= yolotask_reviewer_tabs($pdo, 'job_appeals') ?>
<section class="card" aria-labelledby="q-title">
  <div class="card-header"><h2 id="q-title">Open job appeals</h2><span class="badge badge-info"><?= number_format($list['total']) ?></span></div>
  <p class="text-sm text-faint">Oldest first. Claim an appeal to lock it for <?= ReviewClaims::minutes($pdo) ?> minutes while you compare every step's proof with the instructions.</p>
  <?php if ($list['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint">No open job appeals.</p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($list['rows'] as $ap): $mine = $ap['claim_user_id'] !== null && (int) $ap['claim_user_id'] === $rid; ?>
        <li class="user-row submission-row" data-job-appeal="<?= (int) $ap['id'] ?>">
          <div class="user-meta">
            <strong><a href="job-appeal.php?id=<?= (int) $ap['id'] ?>">#<?= (int) $ap['id'] ?> · <?= h((string) $ap['job_title']) ?></a></strong>
            <span class="text-xs text-faint">Filed <?= h(yolotask_wat((string) $ap['created_at'])) ?> · earner <?= h((string) $ap['earner_name']) ?></span>
            <span class="text-sm text-break">Reason: <?= h((string) $ap['rejection_reason']) ?></span>
            <span class="user-tags"><?= yolotask_job_mode($ap) ?>
              <?php if ($mine): ?><span class="badge badge-success">Claimed by you</span>
              <?php elseif ($ap['claim_user_id'] !== null): ?><span class="badge badge-warning">Claimed by <?= h((string) $ap['claim_reviewer_name']) ?></span>
              <?php else: ?><span class="badge badge-info">Available</span><?php endif; ?></span>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?= yolotask_pager($list, static fn (int $p): string => 'job-appeals.php?page=' . $p) ?>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($user, 'Job appeals', (string) ob_get_clean(), 'appeals');
