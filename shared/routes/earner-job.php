<?php
declare(strict_types=1);

use YoloTask\Account\Activation;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Jobs\Attempts;
use YoloTask\Jobs\JobAppeals;
use YoloTask\Jobs\JobProofs;
use YoloTask\Jobs\Jobs;
use YoloTask\Security\Csrf;
use YoloTask\Tasks\Appeals;

require_once __DIR__ . '/jobs-helpers.php';

/*
 * Earner: one job (migration 0023). Before starting: every step is visible,
 * Start takes a slot and starts the timer. While working: step N's proof form
 * opens once steps 1..N-1 have proof; earlier steps stay editable until the
 * final submit. Sent back: only that step can change, then resubmit.
 * Rejected: appeal to a YOLOTASK reviewer within appeals.window_hours.
 */
if (yolotask_role() !== 'earner') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('earner_job.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$uid = (int) $user['id'];
$id = (int) ($_GET['id'] ?? $_POST['job_id'] ?? 0);
$back = 'job.php?id=' . $id;
$job = $id > 0 ? Jobs::find($pdo, $id) : null;
$attempt = $job !== null ? Attempts::forJobEarner($pdo, $id, $uid) : null;
// Earners see live/paused jobs, and any job they took.
if ($job === null || ($attempt === null && !in_array((string) $job['status'], Jobs::OPEN, true)) || (int) $job['advertiser_user_id'] === $uid) yolotask_error_page(404);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A file larger than post_max_size empties $_POST entirely, CSRF token included.
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        yolotask_flash('The upload is too large. Use a smaller file.', 'error');
        yolotask_redirect($back);
    }
    yolotask_csrf_or_back($back);
    $action = yolotask_post_str('action');
    try {
        switch ($action) {
            case 'start':
                Attempts::start($pdo, $user, $id);
                yolotask_flash('Job started. Your slot is held; finish every step and submit before your time runs out.');
                break;
            case 'save_step':
                if ($attempt === null) throw new RuntimeException('Start the job first.');
                Attempts::saveStep($pdo, $user, (int) $attempt['id'], (int) yolotask_post_str('step_id'), ['text' => yolotask_post_str('text'), 'link' => yolotask_post_str('link')], $_FILES);
                yolotask_flash('Step saved.');
                break;
            case 'submit':
                if ($attempt === null) throw new RuntimeException('Start the job first.');
                Attempts::submit($pdo, $user, (int) $attempt['id']);
                yolotask_flash('Submitted for review. You are paid as soon as the advertiser approves (or automatically if they do not review in time).');
                break;
            case 'resubmit':
                if ($attempt === null) throw new RuntimeException('Start the job first.');
                Attempts::resubmit($pdo, $user, (int) $attempt['id']);
                yolotask_flash('Resubmitted for review.');
                break;
            case 'abandon':
                if ($attempt === null) throw new RuntimeException('Start the job first.');
                Attempts::abandon($pdo, $user, (int) $attempt['id']);
                yolotask_flash('You gave up this job. The slot is open for someone else.');
                yolotask_redirect('jobs.php');
            case 'appeal':
                if ($attempt === null) throw new RuntimeException('Start the job first.');
                JobAppeals::file($pdo, $user, (int) $attempt['id'], yolotask_post_str('reason'));
                yolotask_flash('Appeal sent. A YOLOTASK reviewer will look at your work.');
                break;
            default:
                throw new RuntimeException('Choose an action.');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('earner_job.action_failed', ['action' => $action, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

// Account activation: while required, a non-activated earner sees no job, except one they already took.
if ($attempt === null && !Activation::canWork($pdo, $uid)) {
    ob_start();
    echo yolotask_activation_card(['fee' => Activation::fee($pdo)]);
    echo '<p class="text-center text-sm"><a href="jobs.php">Back to jobs</a></p>';
    yolotask_render_app($user, 'Job', (string) ob_get_clean(), 'jobs');
}

$steps = Jobs::steps($pdo, $id);
$proofs = $attempt !== null ? Attempts::stepProofs($pdo, (int) $attempt['id']) : [];
$done = Attempts::doneCount($steps, $proofs);
$status = $attempt !== null ? (string) $attempt['status'] : null;
$working = $status === 'in_progress';
$fixing = $status === 'sent_back';
$backs = $attempt !== null ? Attempts::sendBacks($pdo, (int) $attempt['id']) : [];
$appeal = $attempt !== null && $attempt['appeal_id'] !== null ? JobAppeals::forAttempt($pdo, (int) $attempt['id']) : null;
$canAppeal = $attempt !== null && $status === 'rejected' && JobAppeals::eligibility($pdo, $attempt) === null;
$fixed = false;
if ($fixing && $backs !== []) $fixed = end($backs)['fixed_at'] !== null;
$free = Jobs::freeSlots($job);
$canStart = $attempt === null && (string) $job['status'] === 'live' && $free > 0 && Jobs::enabled($pdo) && !empty($user['email_verified_at']);
$maxMb = round(Settings::int($pdo, 'tasks.proof_max_bytes') / 1048576, 1);
$fileMb = JobProofs::FILE_MAX_BYTES / 1048576;
$csrf = Csrf::token();
$hidden = '<input type="hidden" name="_csrf" value="' . h($csrf) . '"><input type="hidden" name="job_id" value="' . $id . '">';
$editable = static function (array $s) use ($working, $fixing, $done, $attempt): bool {
    if ($working) return (int) $s['position'] <= $done + 1;
    if ($fixing) return (int) $attempt['sent_back_step_id'] === (int) $s['id'];
    return false;
};
ob_start();
?>
<div class="wallet-grid">
  <section class="card card-glow dash-hero" aria-labelledby="job-title">
    <p class="text-xs fw-bold text-primary">JOB · <?= (int) $job['step_count'] ?> STEPS</p>
    <h2 id="job-title" class="text-break"><?= h((string) $job['title']) ?></h2>
    <div class="wallet-balance"><span class="amount">+<?= h(yolotask_money($attempt !== null ? $attempt['earner_kobo'] : $job['earner_kobo'])) ?></span></div>
    <div class="wallet-sub"><?= yolotask_job_mode($job) ?><span><?= h(Jobs::hoursLabel((int) $job['time_limit_hours'])) ?> to finish once started</span><?php if ($attempt === null): ?><span>Slots left <strong><?= number_format($free) ?></strong></span><?php endif; ?></div>
    <?php if ($attempt !== null): ?><div class="wallet-sub"><?= yolotask_attempt_badge($attempt) ?>
      <?php if ($working): ?><span class="badge badge-warning" data-deadline>Submit within <?= h(yolotask_until((string) $attempt['deadline_at'])) ?></span><?php endif; ?>
      <?php if ($fixing): ?><span class="badge badge-warning" data-fix-deadline>Fix within <?= h(yolotask_until((string) $attempt['fix_deadline_at'])) ?></span><?php endif; ?>
    </div><?php endif; ?>
  </section>

  <?php if ($fixing): ?>
    <section class="card notice-card" role="alert"><div><strong>Step <?= (int) $attempt['sent_back_position'] ?> was sent back</strong><p class="text-sm text-break">Advertiser's note: “<?= h((string) $attempt['send_back_note']) ?>”</p>
      <p class="text-xs text-faint">Fix that step below, then resubmit. If you do not resubmit by <?= h(yolotask_wat((string) $attempt['fix_deadline_at'])) ?>, the job ends without pay.</p></div></section>
  <?php elseif ($status === 'submitted'): ?>
    <section class="card notice-card" role="status"><div><strong>Waiting for review</strong><p class="text-sm text-faint">Submitted <?= h(yolotask_wat((string) $attempt['submitted_at'])) ?>. If the advertiser does not decide by <?= h(yolotask_wat((string) $attempt['review_due_at'])) ?>, it is approved automatically.</p></div></section>
  <?php elseif ($status === 'approved'): ?>
    <section class="card notice-card" role="status"><div><strong>Approved</strong><p class="text-sm text-faint"><?= $attempt['reward_status'] === 'held' ? 'Your reward is on a short hold and reaches your wallet ' . h(yolotask_wat((string) $attempt['reward_release_at'])) . '.' : h(yolotask_money($attempt['earner_kobo'])) . ' was added to your wallet.' ?></p></div></section>
  <?php elseif ($status === 'rejected'): ?>
    <section class="card notice-card" role="status"><div><strong>Not accepted</strong><p class="text-sm text-break">Reason: <?= h((string) $attempt['reject_reason']) ?></p>
      <?php if ($appeal !== null): ?><p class="text-sm"><?= yolotask_job_appeal_badge($appeal) ?><?= $appeal['resolution_note'] ? ' ' . h((string) $appeal['resolution_note']) : ' A YOLOTASK reviewer will decide.' ?></p><?php endif; ?></div></section>
    <?php if ($canAppeal): ?>
      <section class="card" aria-labelledby="ap-title"><div class="card-header"><h2 id="ap-title">Appeal this decision</h2></div>
        <p class="text-sm text-faint">If you followed every step and believe this is wrong, a YOLOTASK reviewer can check your proof. You can appeal once, within <?= Appeals::windowHours($pdo) ?> hours of the rejection.</p>
        <form method="post" action="<?= h($back) ?>" class="stack"><?= $hidden ?><input type="hidden" name="action" value="appeal">
          <div class="field"><label class="label" for="reason">Why is the rejection wrong?</label><textarea class="input" id="reason" name="reason" rows="3" minlength="10" maxlength="1000" required></textarea></div>
          <button class="btn btn-primary" type="submit">Send appeal</button></form></section>
    <?php endif; ?>
  <?php elseif (in_array($status, ['expired', 'abandoned', 'cancelled'], true)): ?>
    <section class="card notice-card" role="status"><div><strong>This job ended</strong><p class="text-sm text-faint"><?= h((string) ($attempt['ended_reason'] ?? '')) ?>. Nothing was paid. Each job can be done once.</p></div></section>
  <?php endif; ?>

  <section class="card" aria-labelledby="ins-title">
    <div class="card-header"><h2 id="ins-title">About this job</h2></div>
    <div class="instructions text-break"><?= nl2br(h((string) $job['instructions'])) ?></div>
    <?php if ($attempt !== null && $job['link_url'] && yolotask_safe_task_url((string) $job['link_url']) !== null): ?>
      <p><a class="btn btn-secondary btn-block" href="<?= h((string) $job['link_url']) ?>" target="_blank" rel="noopener noreferrer nofollow">Open the job link</a></p>
    <?php elseif ($job['link_url']): ?><p class="text-xs text-faint">The job link opens once you start.</p><?php endif; ?>
  </section>

  <?php foreach ($steps as $s): $sid = (int) $s['id']; $proof = $proofs[$sid] ?? null; $kinds = Jobs::kinds((string) $s['proof_kinds']); $can = $editable($s); $isDone = Attempts::stepDone($s, $proof); ?>
    <section class="card" data-step="<?= (int) $s['position'] ?>" aria-labelledby="st-<?= $sid ?>">
      <div class="card-header"><h2 id="st-<?= $sid ?>" class="text-break">Step <?= (int) $s['position'] ?> · <?= h((string) $s['title']) ?></h2>
        <?php if ($attempt !== null): ?><span class="badge <?= $isDone ? 'badge-success' : ($can ? 'badge-warning' : 'badge-info') ?>"><?= $isDone ? 'Done' : ($can ? 'To do' : 'Locked') ?></span><?php endif; ?></div>
      <div class="instructions text-sm text-break"><?= nl2br(h((string) $s['instructions'])) ?></div>
      <?= yolotask_job_kinds($s) ?>
      <?php if ($proof !== null): ?><?= yolotask_job_step_proof($s, $proof) ?><?php endif; ?>
      <?php if ($can): ?>
        <?php $open = !$isDone || ($fixing); ?>
        <details<?= $open ? ' open' : '' ?>><summary class="text-sm fw-bold"><?= $isDone ? 'Change this step' : 'Add your proof' ?></summary>
          <form method="post" action="<?= h($back) ?>" enctype="multipart/form-data" class="stack" data-proof-form><?= $hidden ?><input type="hidden" name="action" value="save_step"><input type="hidden" name="step_id" value="<?= $sid ?>">
            <?php if (in_array('image', $kinds, true)): ?><div class="field"><label class="label" for="im<?= $sid ?>">Screenshot / image</label><input class="input" id="im<?= $sid ?>" name="image" type="file" accept="image/jpeg,image/png,image/webp"<?= $proof === null || $proof['image_file_id'] === null ? ' required' : '' ?>><p class="input-hint">JPEG, PNG or WebP, up to <?= h((string) $maxMb) ?> MB.<?= $proof !== null && $proof['image_file_id'] !== null ? ' Leave empty to keep the saved one.' : '' ?></p></div><?php endif; ?>
            <?php if (in_array('text', $kinds, true)): ?><div class="field"><label class="label" for="tx<?= $sid ?>">Your answer</label><textarea class="input" id="tx<?= $sid ?>" name="text" rows="3" maxlength="5000"<?= $proof === null || trim((string) $proof['text_answer']) === '' ? ' required' : '' ?>><?= h((string) ($proof['text_answer'] ?? '')) ?></textarea></div><?php endif; ?>
            <?php if (in_array('link', $kinds, true)): ?><div class="field"><label class="label" for="ln<?= $sid ?>">Link</label><input class="input" id="ln<?= $sid ?>" name="link" type="url" maxlength="500" inputmode="url" value="<?= h((string) ($proof['link_url'] ?? '')) ?>" placeholder="https://"<?= $proof === null || trim((string) $proof['link_url']) === '' ? ' required' : '' ?>></div><?php endif; ?>
            <?php if (in_array('file', $kinds, true)): ?><div class="field"><label class="label" for="fl<?= $sid ?>">File</label><input class="input" id="fl<?= $sid ?>" name="file" type="file" accept=".<?= h(implode(',.', JobProofs::FILE_EXTENSIONS)) ?>"<?= $proof === null || $proof['file_file_id'] === null ? ' required' : '' ?>><p class="input-hint"><?= h(strtoupper(implode(', ', JobProofs::FILE_EXTENSIONS))) ?>, up to <?= $fileMb ?> MB.<?= $proof !== null && $proof['file_file_id'] !== null ? ' Leave empty to keep the saved one.' : '' ?></p></div><?php endif; ?>
            <button class="btn btn-primary" type="submit"><?= $isDone ? 'Save changes' : 'Save step ' . (int) $s['position'] ?></button>
            <p class="input-hint">Only you, the advertiser and YOLOTASK staff can see your proof.</p>
          </form></details>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <?php if ($backs !== []): ?><section class="card"><div class="card-header"><h2>Sent back</h2></div><?= yolotask_job_send_backs($backs) ?></section><?php endif; ?>

  <?php if ($working): ?>
    <section class="card" aria-labelledby="sb-title"><div class="card-header"><h2 id="sb-title">Submit for review</h2><span class="badge badge-info"><?= $done ?> of <?= count($steps) ?> steps done</span></div>
      <form method="post" action="<?= h($back) ?>"><?= $hidden ?><button class="btn btn-primary btn-block" type="submit" name="action" value="submit"<?= $done < count($steps) ? ' disabled' : '' ?> data-confirm="Submit every step for review? You cannot change them after this unless the advertiser sends a step back.">Submit for review</button></form>
      <form method="post" action="<?= h($back) ?>" style="margin-top:.75rem"><?= $hidden ?><button class="btn btn-ghost btn-sm btn-block" type="submit" name="action" value="abandon" data-confirm="Give up this job? The slot goes to someone else and you cannot take this job again.">Give up this job</button></form>
    </section>
  <?php elseif ($fixing): ?>
    <section class="card"><form method="post" action="<?= h($back) ?>"><?= $hidden ?><button class="btn btn-primary btn-block" type="submit" name="action" value="resubmit"<?= $fixed ? '' : ' disabled' ?>>Resubmit for review</button></form>
      <?php if (!$fixed): ?><p class="input-hint text-center">Update step <?= (int) $attempt['sent_back_position'] ?> first.</p><?php endif; ?>
      <form method="post" action="<?= h($back) ?>" style="margin-top:.75rem"><?= $hidden ?><button class="btn btn-ghost btn-sm btn-block" type="submit" name="action" value="abandon" data-confirm="Give up this job? You will not be paid.">Give up this job</button></form></section>
  <?php elseif ($attempt === null): ?>
    <section class="card" aria-labelledby="start-title"><div class="card-header"><h2 id="start-title">Start this job</h2></div>
      <?php if ((string) $job['status'] !== 'live'): ?><p class="text-faint">This job is paused. Check back later.</p>
      <?php elseif ($free <= 0): ?><p class="text-faint">All slots are taken right now. Slots free up when other earners give up or run out of time.</p>
      <?php elseif (empty($user['email_verified_at'])): ?><p class="text-faint">Confirm your email to start. <a href="verify.php">Confirm now</a></p>
      <?php else: ?><p class="text-sm text-faint">Starting holds a slot for you and gives you <?= h(Jobs::hoursLabel((int) $job['time_limit_hours'])) ?> to finish every step and submit. You can take each job once<?= Jobs::maxActivePerEarner($pdo) === 1 ? ', and work on one job at a time' : '' ?>.</p><?php endif; ?>
      <form method="post" action="<?= h($back) ?>"><?= $hidden ?><button class="btn btn-primary btn-block" type="submit" name="action" value="start"<?= $canStart ? '' : ' disabled' ?>>Start job</button></form>
    </section>
  <?php endif; ?>
  <p class="text-center text-sm"><a href="jobs.php">Back to jobs</a></p>
</div>
<?php
yolotask_render_app($user, 'Job', (string) ob_get_clean(), 'jobs');
