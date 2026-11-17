<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Jobs\Attempts;
use YoloTask\Jobs\JobAppeals;
use YoloTask\Jobs\Jobs;
use YoloTask\Jobs\Reviews;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/jobs-helpers.php';

/* Advertiser: review one job submission (migration 0023) — every step's proof on one page; approve, send a step back, or reject. */
if (yolotask_role() !== 'advertiser') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('job_review.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$uid = (int) $user['id'];
$id = (int) ($_GET['id'] ?? $_POST['attempt_id'] ?? 0);
$a = $id > 0 ? Attempts::find($pdo, $id) : null;
if ($a === null || (int) $a['advertiser_user_id'] !== $uid) yolotask_error_page(404);
$back = 'job-review.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    yolotask_csrf_or_back($back);
    $action = yolotask_post_str('action');
    try {
        switch ($action) {
            case 'approve':
                Reviews::approve($pdo, $id, 'advertiser', $uid, $uid);
                yolotask_flash('Approved. The earner has been paid.');
                yolotask_redirect('job.php?id=' . (int) $a['job_id']);
            case 'send_back':
                Reviews::sendBack($pdo, $id, $uid, (int) yolotask_post_str('step_id'), yolotask_post_str('note'), $uid);
                yolotask_flash('Step sent back. The earner has ' . Jobs::hoursLabel(Jobs::fixHours($pdo)) . ' to fix it and resubmit.');
                break;
            case 'reject':
                Reviews::reject($pdo, $id, 'advertiser', $uid, yolotask_post_str('reason'), $uid);
                yolotask_flash('Rejected. The slot is open again (or refunded if the job is closed). The earner may appeal.');
                yolotask_redirect('job.php?id=' . (int) $a['job_id']);
            default:
                throw new RuntimeException('Choose an action.');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('job_review.action_failed', ['action' => $action, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$job = Jobs::find($pdo, (int) $a['job_id']);
$steps = Jobs::steps($pdo, (int) $a['job_id']);
$proofs = Attempts::stepProofs($pdo, $id);
$backs = Attempts::sendBacks($pdo, $id);
$appeal = $a['appeal_id'] !== null ? JobAppeals::forAttempt($pdo, $id) : null;
$maxRounds = Jobs::sendBackMaxRounds($pdo);
$canSendBack = (int) $a['send_back_count'] < $maxRounds;
$csrf = Csrf::token();
$hidden = '<input type="hidden" name="_csrf" value="' . h($csrf) . '"><input type="hidden" name="attempt_id" value="' . $id . '">';
ob_start();
?>
<div class="wallet-grid" data-job-review="<?= $id ?>">
  <section class="card card-glow dash-hero" aria-labelledby="r-title">
    <p class="text-xs fw-bold text-primary">JOB #<?= (int) $a['job_id'] ?> · SUBMISSION #<?= $id ?></p>
    <h2 id="r-title" class="text-break"><?= h((string) $a['job_title']) ?></h2>
    <div class="wallet-sub"><?= yolotask_attempt_badge($a) ?><span>Earner <strong><?= h((string) $a['earner_name']) ?></strong> · started <?= h(yolotask_wat((string) $a['started_at'])) ?><?= $a['submitted_at'] ? ' · submitted ' . h(yolotask_wat((string) $a['submitted_at'])) : '' ?></span></div>
    <?php if ($a['status'] === 'submitted'): ?><p class="text-sm">Decide within <strong><?= h(yolotask_until((string) $a['review_due_at'])) ?></strong>, or it is approved automatically and the earner is paid <?= h(yolotask_money($a['earner_kobo'])) ?>.</p><?php endif; ?>
    <?php if ($a['status'] === 'sent_back'): ?><p class="text-sm">You sent step <?= (int) $a['sent_back_position'] ?> back. The earner has until <?= h(yolotask_wat((string) $a['fix_deadline_at'])) ?> to fix it.</p><?php endif; ?>
    <?php if ($a['status'] === 'rejected'): ?><p class="text-sm text-break">Rejected: <?= h((string) $a['reject_reason']) ?></p><?php endif; ?>
    <?php if ($appeal !== null): ?><p class="text-sm"><?= yolotask_job_appeal_badge($appeal) ?><?= $appeal['resolution_note'] ? ' Reviewer note: ' . h((string) $appeal['resolution_note']) : ' The earner appealed; a YOLOTASK reviewer will decide.' ?></p><?php endif; ?>
  </section>

  <?= yolotask_job_review_steps($steps, $proofs, $a) ?>

  <?php if ($backs !== []): ?>
    <section class="card"><div class="card-header"><h2>Sent back</h2><span class="badge badge-info"><?= count($backs) ?> of <?= $maxRounds ?></span></div><?= yolotask_job_send_backs($backs) ?></section>
  <?php endif; ?>

  <?php if ($a['status'] === 'submitted'): ?>
  <section class="card" id="decide" aria-labelledby="d-title">
    <div class="card-header"><h2 id="d-title">Your decision</h2></div>
    <form method="post" action="<?= h($back) ?>" class="stack"><?= $hidden ?>
      <button class="btn btn-primary btn-block" type="submit" name="action" value="approve" data-confirm="Approve and pay the earner <?= h(yolotask_money($a['earner_kobo'])) ?>?">Approve and pay</button>
    </form>
    <?php if ($canSendBack): ?>
      <details style="margin-top:1rem"><summary class="fw-bold">Send a step back for a fix (<?= (int) $a['send_back_count'] ?> of <?= $maxRounds ?> used)</summary>
        <form method="post" action="<?= h($back) ?>" class="stack"><?= $hidden ?><input type="hidden" name="action" value="send_back">
          <div class="field"><label class="label" for="step_id">Step</label>
            <select class="input" id="step_id" name="step_id" required><?php foreach ($steps as $s): ?><option value="<?= (int) $s['id'] ?>">Step <?= (int) $s['position'] ?> · <?= h((string) $s['title']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label class="label" for="note">What should they fix?</label><textarea class="input" id="note" name="note" rows="3" minlength="3" maxlength="500" required></textarea></div>
          <button class="btn btn-secondary" type="submit">Send back</button>
          <p class="input-hint">The earner gets <?= h(Jobs::hoursLabel(Jobs::fixHours($pdo))) ?> to fix that step and resubmit.</p></form></details>
    <?php else: ?>
      <p class="text-sm text-faint" style="margin-top:1rem">You have used every send-back round (<?= $maxRounds ?>). Approve or reject now.</p>
    <?php endif; ?>
    <details style="margin-top:1rem"><summary class="fw-bold">Reject</summary>
      <form method="post" action="<?= h($back) ?>" class="stack"><?= $hidden ?><input type="hidden" name="action" value="reject">
        <div class="field"><label class="label" for="reason">Reason (the earner sees it)</label><input class="input" id="reason" name="reason" minlength="3" maxlength="255" required></div>
        <button class="btn btn-danger-outline" type="submit" data-confirm="Reject this submission? The earner is not paid and may appeal to a YOLOTASK reviewer.">Reject</button></form></details>
  </section>
  <?php endif; ?>
  <p class="text-center text-sm"><a href="job.php?id=<?= (int) $a['job_id'] ?>">Back to the job</a></p>
</div>
<?php
yolotask_render_app($user, 'Review job', (string) ob_get_clean(), 'jobs');
