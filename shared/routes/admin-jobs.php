<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Jobs\Attempts;
use YoloTask\Jobs\JobAppeals;
use YoloTask\Jobs\Jobs;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/jobs-helpers.php';

/*
 * Admin → Tasks → Jobs (migration 0023).
 *   ?tab=jobs       list and filter jobs (default)
 *   ?id=<job>       one job: attempts, escrow, pause / unpause, remove with refund of unused escrow
 *   ?attempt=<id>   every step's proof of one attempt
 *   ?tab=appeals    job appeals; ?appeal=<id> to decide one (admin decision, no claim needed)
 *   ?tab=settings   jobs.* settings
 */
if (yolotask_role() !== 'admin') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $admin = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('admin.jobs_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$adminId = (int) $admin['id'];
$tab = in_array($_GET['tab'] ?? '', ['jobs', 'appeals', 'settings'], true) ? (string) $_GET['tab'] : 'jobs';
$jobId = (int) ($_GET['id'] ?? 0);
$attemptId = (int) ($_GET['attempt'] ?? 0);
$appealId = (int) ($_GET['appeal'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $back = (string) ($_SERVER['REQUEST_URI'] ?? 'jobs.php');
    $back = preg_match('#^/?jobs\.php(\?[A-Za-z0-9=&%_\-]*)?$#', ltrim($back, '/')) ? ltrim($back, '/') : 'jobs.php';
    yolotask_csrf_or_back($back);
    $action = yolotask_post_str('action');
    $pid = (int) yolotask_post_str('job_id');
    try {
        switch ($action) {
            case 'pause':
            case 'unpause':
                Jobs::adminPause($pdo, $adminId, $pid, $action === 'pause', yolotask_post_str('reason'));
                yolotask_flash($action === 'pause' ? 'Job paused. The advertiser cannot resume it until you unpause it.' : 'Job is live again.');
                break;
            case 'remove':
                $refund = Jobs::adminRemove($pdo, $adminId, $pid, yolotask_post_str('reason'));
                yolotask_flash('Job removed. ' . yolotask_money($refund) . ' of unused escrow went back to the advertiser; submitted work stays until it is decided.');
                break;
            case 'uphold':
            case 'overturn':
                JobAppeals::decide($pdo, $admin, (int) yolotask_post_str('appeal_id'), $action, yolotask_post_str('note'));
                yolotask_flash($action === 'overturn' ? 'Overturned: the earner was paid.' : 'Rejection upheld.');
                $back = 'jobs.php?tab=appeals';
                break;
            case 'settings':
                $num = static function (string $key, int $min, int $max, string $label): int {
                    $v = trim(yolotask_post_str($key));
                    if (!preg_match('/^\d{1,9}$/', $v) || (int) $v < $min || (int) $v > $max) throw new RuntimeException("{$label}: enter a whole number from {$min} to {$max}.");
                    return (int) $v;
                };
                $minPay = $num('min_pay', 1, 1000000, 'Minimum pay');
                $tmin = $num('time_min', 1, 8760, 'Shortest time limit');
                $tmax = $num('time_max', 1, 8760, 'Longest time limit');
                if ($tmin > $tmax) throw new RuntimeException('The shortest time limit cannot be longer than the longest.');
                Settings::save($pdo, $adminId, [
                    'jobs.enabled' => yolotask_post_str('enabled') === '1' ? '1' : '0',
                    'jobs.min_pay_kobo' => (string) ($minPay * 100),
                    'jobs.earner_percent' => (string) $num('earner_percent', 50, 100, 'Earner share'),
                    'jobs.max_steps' => (string) $num('max_steps', 2, 50, 'Maximum steps'),
                    'jobs.time_limit_min_hours' => (string) $tmin,
                    'jobs.time_limit_max_hours' => (string) $tmax,
                    'jobs.send_back_max_rounds' => (string) $num('send_back_rounds', 0, 5, 'Send-back rounds'),
                    'jobs.send_back_fix_hours' => (string) $num('fix_hours', 1, 336, 'Fix deadline'),
                    'jobs.max_active_per_earner' => (string) $num('max_active', 1, 10, 'Jobs in progress per earner'),
                ]);
                yolotask_flash('Jobs settings saved. Pay split and limits apply to jobs published from now; existing jobs keep theirs.');
                break;
            default:
                throw new RuntimeException('Choose an action.');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.jobs_action_failed', ['action' => $action, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$csrf = Csrf::token();
$hidden = '<input type="hidden" name="_csrf" value="' . h($csrf) . '">';
try {
    $stats = Jobs::adminStats($pdo);
} catch (Throwable $e) {
    Logger::error('admin.jobs_stats_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
$notFound = static function (): never { ob_end_clean(); yolotask_error_page(404); };
ob_start();
?>
<?= yolotask_admin_market_tabs($pdo, 'jobs') ?>
<section class="grid grid-4 dash-stats money-stats" aria-label="Jobs overview">
  <article class="stat-card"><span class="stat-label">Live jobs</span><span class="stat-value"><?= number_format($stats['live']) ?></span><span class="text-xs text-faint"><?= number_format($stats['jobs']) ?> in total</span></article>
  <article class="stat-card stat-warning"><span class="stat-label">Waiting for advertisers</span><span class="stat-value"><?= number_format($stats['to_review']) ?></span><span class="text-xs text-faint"><?= number_format($stats['appeals']) ?> open appeal<?= $stats['appeals'] === 1 ? '' : 's' ?></span></article>
  <article class="stat-card"><span class="stat-label">Jobs escrow</span><span class="stat-value"><?= h(yolotask_money($stats['escrow'])) ?></span></article>
  <article class="stat-card stat-success"><span class="stat-label">Platform share</span><span class="stat-value"><?= h(yolotask_money($stats['revenue'])) ?></span><?php if ($stats['platform_appeals'] > 0): ?><span class="text-xs text-faint"><?= h(yolotask_money($stats['platform_appeals'])) ?> appeal payouts covered</span><?php endif; ?></article>
</section>
<nav class="card money-tabs" aria-label="Jobs admin">
  <?php foreach (['jobs' => 'Jobs', 'appeals' => 'Appeals', 'settings' => 'Settings'] as $k => $label): ?>
    <a href="jobs.php?tab=<?= h($k) ?>"<?= $k === $tab && $jobId === 0 && $attemptId === 0 && $appealId === 0 ? ' class="active" aria-current="page"' : '' ?>><?= h($label) ?><?= $k === 'appeals' && $stats['appeals'] > 0 ? ' <span class="badge badge-warning">' . $stats['appeals'] . '</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>
<?php
if ($attemptId > 0 || $appealId > 0):
    $ap = $appealId > 0 ? JobAppeals::find($pdo, $appealId) : null;
    if ($appealId > 0 && $ap === null) $notFound();
    $a = Attempts::find($pdo, $ap !== null ? (int) $ap['attempt_id'] : $attemptId);
    if ($a === null) $notFound();
    $ap ??= $a['appeal_id'] !== null ? JobAppeals::forAttempt($pdo, (int) $a['id']) : null;
    $steps = Jobs::steps($pdo, (int) $a['job_id']);
    $proofs = Attempts::stepProofs($pdo, (int) $a['id']);
    $backs = Attempts::sendBacks($pdo, (int) $a['id']);
?>
  <section class="card card-glow dash-hero" data-admin-attempt="<?= (int) $a['id'] ?>">
    <p class="text-xs fw-bold text-primary">JOB #<?= (int) $a['job_id'] ?> · ATTEMPT #<?= (int) $a['id'] ?></p>
    <h2 class="text-break"><a href="jobs.php?id=<?= (int) $a['job_id'] ?>"><?= h((string) $a['job_title']) ?></a></h2>
    <div class="wallet-sub"><?= yolotask_attempt_badge($a) ?><span>Earner <?= h((string) $a['earner_name']) ?> (<?= h((string) $a['earner_email']) ?>) · advertiser <?= h((string) $a['advertiser_name']) ?> · pay <?= h(yolotask_money($a['pay_kobo'])) ?> (earner <?= h(yolotask_money($a['earner_kobo'])) ?>)</span></div>
    <?php if ($a['reject_reason']): ?><p class="text-sm text-break">Rejected: <?= h((string) $a['reject_reason']) ?></p><?php endif; ?>
    <?php if ($a['ended_reason']): ?><p class="text-sm text-faint"><?= h((string) $a['ended_reason']) ?></p><?php endif; ?>
  </section>
  <?= yolotask_job_review_steps($steps, $proofs, $a) ?>
  <?php if ($backs !== []): ?><section class="card"><div class="card-header"><h2>Sent back</h2></div><?= yolotask_job_send_backs($backs) ?></section><?php endif; ?>
  <?php if ($ap !== null): ?>
    <section class="card" id="appeal"><div class="card-header"><h2>Appeal #<?= (int) $ap['id'] ?></h2><?= yolotask_job_appeal_badge($ap) ?></div>
      <p class="text-sm text-break"><strong>Earner's appeal:</strong> “<?= h((string) $ap['message']) ?>”</p>
      <?php if ($ap['status'] !== 'open'): ?>
        <p class="text-sm text-break"><strong>Decision</strong> (<?= h((string) $ap['decided_by_role']) ?>) by <?= h((string) $ap['resolver_name']) ?> <?= h(yolotask_wat((string) $ap['resolved_at'])) ?>: <?= h((string) $ap['resolution_note']) ?></p>
        <?php if ($ap['status'] === 'overturned'): ?><p class="text-xs text-faint">Paid <?= (int) $ap['escrow_kobo'] > 0 ? 'from a free slot of the job' : 'with a top-up: ' . h(yolotask_money($ap['wallet_kobo'])) . ' from the advertiser wallet, ' . h(yolotask_money($ap['platform_kobo'])) . ' by YOLOTASK' ?>.</p><?php endif; ?>
      <?php else: ?>
        <form method="post" action="jobs.php?appeal=<?= (int) $ap['id'] ?>" class="stack"><?= $hidden ?><input type="hidden" name="appeal_id" value="<?= (int) $ap['id'] ?>">
          <div class="field"><label class="label" for="note">Decision note (the earner sees this; your decision is final)</label><textarea class="input" id="note" name="note" rows="3" minlength="5" maxlength="500" required></textarea></div>
          <div class="review-actions"><button class="btn btn-primary btn-sm" type="submit" name="action" value="overturn" data-confirm="Overturn and pay the earner?">Overturn and pay</button>
            <button class="btn btn-danger-outline btn-sm" type="submit" name="action" value="uphold">Uphold rejection</button></div></form>
      <?php endif; ?>
    </section>
  <?php endif; ?>

<?php elseif ($jobId > 0):
    $job = Jobs::find($pdo, $jobId);
    if ($job === null) $notFound();
    $st = in_array($_GET['status'] ?? '', ['review', 'active', 'approved', 'rejected', 'ended'], true) ? (string) $_GET['status'] : 'all';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $list = Attempts::listForJob($pdo, $jobId, $st, $page);
    $steps = Jobs::steps($pdo, $jobId);
    $events = Jobs::fundingEvents($pdo, $jobId);
    $escrow = (int) $job['funded_kobo'] - (int) $job['spent_kobo'] - (int) $job['refunded_kobo'];
?>
  <section class="card card-glow dash-hero" data-admin-job="<?= $jobId ?>">
    <p class="text-xs fw-bold text-primary">JOB #<?= $jobId ?> · <?= (int) $job['step_count'] ?> STEPS</p>
    <h2 class="text-break"><?= h((string) $job['title']) ?></h2>
    <div class="wallet-sub"><?= yolotask_job_badge($job) ?> <?= yolotask_job_mode($job) ?><span>Advertiser <?= h((string) $job['advertiser_name']) ?> (<?= h((string) $job['advertiser_email']) ?>)</span></div>
    <p class="text-sm">Pay <?= h(yolotask_money($job['pay_kobo'])) ?> (earner <?= h(yolotask_money($job['earner_kobo'])) ?>, <?= (int) $job['earner_percent'] ?>%) · slots <?= number_format((int) $job['slots']) ?> · approved <?= number_format((int) $job['approved_count']) ?> · working <?= number_format((int) $job['active_count']) ?> · rejected <?= number_format((int) $job['rejected_count']) ?> · expired <?= number_format((int) $job['expired_count']) ?> · escrow <?= h(yolotask_money($escrow)) ?></p>
    <?php if ($job['status_reason']): ?><p class="text-sm text-faint">Note: <?= h((string) $job['status_reason']) ?></p><?php endif; ?>
    <?php if (in_array((string) $job['status'], Jobs::OPEN, true)): ?>
      <form method="post" action="jobs.php?id=<?= $jobId ?>" class="stack" style="margin-top:.75rem"><?= $hidden ?><input type="hidden" name="job_id" value="<?= $jobId ?>">
        <input class="input" name="reason" maxlength="255" placeholder="Reason shown to the advertiser (needed to pause or remove)" aria-label="Reason">
        <div class="hero-actions">
          <?php if ((int) $job['admin_paused'] === 1): ?><button class="btn btn-secondary btn-sm" type="submit" name="action" value="unpause">Unpause</button>
          <?php else: ?><button class="btn btn-secondary btn-sm" type="submit" name="action" value="pause">Pause</button><?php endif; ?>
          <button class="btn btn-danger-outline btn-sm" type="submit" name="action" value="remove" data-confirm="Remove this job? In-progress attempts are cancelled and unused escrow goes back to the advertiser.">Remove and refund</button>
        </div></form>
    <?php endif; ?>
  </section>
  <section class="card"><div class="card-header"><h2>Instructions and steps</h2></div>
    <div class="instructions text-break"><?= nl2br(h((string) $job['instructions'])) ?></div>
    <?php if ($job['link_url']): ?><p class="text-sm text-break"><?= yolotask_task_link((string) $job['link_url'], 'Open job link') ?></p><?php endif; ?>
    <ol class="steps-list"><?php foreach ($steps as $s): ?><li><strong><?= h((string) $s['title']) ?></strong><div class="text-sm text-break"><?= nl2br(h((string) $s['instructions'])) ?></div><?= yolotask_job_kinds($s) ?></li><?php endforeach; ?></ol>
  </section>
  <section class="card"><div class="card-header"><h2>Attempts</h2><span class="badge badge-info"><?= number_format($list['total']) ?></span></div>
    <nav class="money-tabs"><?php foreach (['all' => 'All', 'review' => 'Waiting for review', 'active' => 'Working', 'approved' => 'Approved', 'rejected' => 'Rejected', 'ended' => 'Ended'] as $k => $label): ?><a href="jobs.php?id=<?= $jobId ?>&status=<?= h($k) ?>"<?= $k === $st ? ' class="active"' : '' ?>><?= h($label) ?></a><?php endforeach; ?></nav>
    <?php if ($list['rows'] === []): ?><div class="empty-state"><p class="text-faint">None.</p></div><?php else: ?>
      <ul class="tx-list"><?php foreach ($list['rows'] as $a): ?>
        <li class="tx-row"><div class="tx-meta"><strong><a href="jobs.php?attempt=<?= (int) $a['id'] ?>">#<?= (int) $a['id'] ?> · <?= h((string) $a['earner_name']) ?></a></strong><span class="text-xs text-faint"><?= h((string) $a['earner_email']) ?> · started <?= h(yolotask_wat((string) $a['started_at'])) ?></span><span><?= yolotask_attempt_badge($a) ?></span></div>
          <div class="tx-amount"><a class="btn btn-secondary btn-sm" href="jobs.php?attempt=<?= (int) $a['id'] ?>">Proofs</a></div></li>
      <?php endforeach; ?></ul>
      <?= yolotask_pager($list, static fn (int $p): string => 'jobs.php?id=' . $jobId . '&status=' . $st . '&page=' . $p) ?>
    <?php endif; ?>
  </section>
  <?php if ($events !== []): ?><section class="card"><div class="card-header"><h2>Escrow events</h2></div><ul class="tx-list"><?php foreach ($events as $e): ?>
    <li class="tx-row"><div class="tx-meta"><strong><?= h((string) $e['event']) ?> (<?= (int) $e['slots'] ?> slot<?= abs((int) $e['slots']) === 1 ? '' : 's' ?>)</strong><span class="text-xs text-faint"><?= h((string) $e['note']) ?> · <?= h(yolotask_wat((string) $e['created_at'])) ?> · ledger tx #<?= (int) $e['ledger_transaction_id'] ?></span></div><div class="tx-amount"><?= h(yolotask_money($e['amount_kobo'])) ?></div></li>
  <?php endforeach; ?></ul></section><?php endif; ?>

<?php elseif ($tab === 'appeals'):
    $st = in_array($_GET['status'] ?? '', ['upheld', 'overturned', 'all'], true) ? (string) $_GET['status'] : 'open';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $list = JobAppeals::listForAdmin($pdo, $st, $page);
?>
  <section class="card"><div class="card-header"><h2>Job appeals</h2><span class="badge badge-info"><?= number_format($list['total']) ?></span></div>
    <p class="text-sm text-faint">Reviewers work these in their Job appeals queue. You can decide any open appeal here; an admin decision is final.</p>
    <nav class="money-tabs"><?php foreach (['open' => 'Open', 'upheld' => 'Upheld', 'overturned' => 'Overturned', 'all' => 'All'] as $k => $label): ?><a href="jobs.php?tab=appeals&status=<?= h($k) ?>"<?= $k === $st ? ' class="active"' : '' ?>><?= h($label) ?></a><?php endforeach; ?></nav>
    <?php if ($list['rows'] === []): ?><div class="empty-state"><p class="text-faint">None.</p></div><?php else: ?>
      <ul class="tx-list"><?php foreach ($list['rows'] as $ap): ?>
        <li class="tx-row" data-admin-job-appeal="<?= (int) $ap['id'] ?>"><div class="tx-meta"><strong><a href="jobs.php?appeal=<?= (int) $ap['id'] ?>">#<?= (int) $ap['id'] ?> · <?= h((string) $ap['job_title']) ?></a></strong>
          <span class="text-xs text-faint">Earner <?= h((string) $ap['earner_name']) ?> · advertiser <?= h((string) $ap['advertiser_name']) ?> · filed <?= h(yolotask_wat((string) $ap['created_at'])) ?></span>
          <span class="text-sm text-break">Rejection: <?= h((string) $ap['rejection_reason']) ?></span><span><?= yolotask_job_appeal_badge($ap) ?><?= $ap['claim_user_id'] !== null ? ' <span class="badge badge-warning">Claimed by ' . h((string) $ap['claim_reviewer_name']) . '</span>' : '' ?></span></div>
          <div class="tx-amount"><a class="btn btn-secondary btn-sm" href="jobs.php?appeal=<?= (int) $ap['id'] ?>">Open</a></div></li>
      <?php endforeach; ?></ul>
      <?= yolotask_pager($list, static fn (int $p): string => 'jobs.php?tab=appeals&status=' . $st . '&page=' . $p) ?>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'settings'):
    $tl = Jobs::timeLimitBounds($pdo);
?>
  <section class="card"><div class="card-header"><h2>Jobs settings</h2></div>
    <form method="post" action="jobs.php?tab=settings" class="stack"><?= $hidden ?><input type="hidden" name="action" value="settings">
      <div class="field"><label class="label" for="enabled">Jobs</label>
        <select class="input" id="enabled" name="enabled"><option value="1"<?= Jobs::enabled($pdo) ? ' selected' : '' ?>>On: advertisers can publish, earners see the Jobs tab</option><option value="0"<?= Jobs::enabled($pdo) ? '' : ' selected' ?>>Off: no new jobs or starts (work already started still finishes)</option></select></div>
      <div class="grid grid-2 form-pair">
        <div class="field"><label class="label" for="min_pay">Minimum pay per earner (₦)</label><input class="input" id="min_pay" name="min_pay" inputmode="numeric" required value="<?= intdiv(Jobs::minPay($pdo), 100) ?>"><p class="input-hint">Default ₦200.</p></div>
        <div class="field"><label class="label" for="earner_percent">Earner share (%)</label><input class="input" id="earner_percent" name="earner_percent" inputmode="numeric" required value="<?= Jobs::earnerPercent($pdo) ?>"><p class="input-hint">50 to 100, rounded down to the kobo; the rest is platform revenue. Default 90.</p></div>
      </div>
      <div class="grid grid-2 form-pair">
        <div class="field"><label class="label" for="max_steps">Maximum steps per job</label><input class="input" id="max_steps" name="max_steps" inputmode="numeric" required value="<?= Jobs::maxSteps($pdo) ?>"><p class="input-hint">2 to 50. Default 20.</p></div>
        <div class="field"><label class="label" for="max_active">Jobs in progress per earner</label><input class="input" id="max_active" name="max_active" inputmode="numeric" required value="<?= Jobs::maxActivePerEarner($pdo) ?>"><p class="input-hint">1 to 10 (in progress or sent back). Default 1.</p></div>
      </div>
      <div class="grid grid-2 form-pair">
        <div class="field"><label class="label" for="time_min">Shortest time limit (hours)</label><input class="input" id="time_min" name="time_min" inputmode="numeric" required value="<?= $tl['min'] ?>"><p class="input-hint">Default 1 hour.</p></div>
        <div class="field"><label class="label" for="time_max">Longest time limit (hours)</label><input class="input" id="time_max" name="time_max" inputmode="numeric" required value="<?= $tl['max'] ?>"><p class="input-hint">Default 336 (14 days).</p></div>
      </div>
      <div class="grid grid-2 form-pair">
        <div class="field"><label class="label" for="send_back_rounds">Send-back rounds per submission</label><input class="input" id="send_back_rounds" name="send_back_rounds" inputmode="numeric" required value="<?= Jobs::sendBackMaxRounds($pdo) ?>"><p class="input-hint">0 to 5. Default 2.</p></div>
        <div class="field"><label class="label" for="fix_hours">Time to fix a sent-back step (hours)</label><input class="input" id="fix_hours" name="fix_hours" inputmode="numeric" required value="<?= Jobs::fixHours($pdo) ?>"><p class="input-hint">1 to 336. Default 48.</p></div>
      </div>
      <p class="text-sm text-faint">The advertiser's review window uses the task auto-approve limits (Task settings). Proof screenshots use the task proof size limit.</p>
      <button class="btn btn-primary" type="submit">Save</button></form>
  </section>

<?php else:
    $st = is_string($_GET['status'] ?? null) ? (string) $_GET['status'] : '';
    $q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 80) : '';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $list = Jobs::listForAdmin($pdo, $st, $q, $page);
?>
  <section class="card">
    <form method="get" action="jobs.php" class="copy-field">
      <select class="input" name="status" aria-label="Status"><?php foreach (['' => 'All', 'live' => 'Live', 'paused' => 'Paused', 'closed' => 'Closed', 'completed' => 'Completed', 'removed' => 'Removed'] as $k => $label): ?><option value="<?= h($k) ?>"<?= $st === $k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select>
      <input class="input" name="q" value="<?= h($q) ?>" placeholder="Title, advertiser email or job id"><button class="btn btn-secondary btn-sm" type="submit">Filter</button></form>
  </section>
  <section class="card">
    <?php if ($list['rows'] === []): ?><div class="empty-state"><p class="text-faint">No jobs.</p></div><?php else: ?>
      <ul class="tx-list"><?php foreach ($list['rows'] as $j): ?>
        <li class="tx-row" data-admin-job="<?= (int) $j['id'] ?>"><div class="tx-meta"><strong><a href="jobs.php?id=<?= (int) $j['id'] ?>">#<?= (int) $j['id'] ?> · <?= h((string) $j['title']) ?></a></strong>
          <span class="text-xs text-faint"><?= h((string) $j['advertiser_name']) ?> (<?= h((string) $j['advertiser_email']) ?>) · <?= (int) $j['step_count'] ?> steps · <?= number_format((int) $j['approved_count']) ?>/<?= number_format((int) $j['slots']) ?> approved · <?= h(yolotask_money($j['pay_kobo'])) ?> each · <?= h(yolotask_wat((string) $j['created_at'])) ?></span>
          <span><?= yolotask_job_badge($j) ?> <?= yolotask_job_mode($j) ?><?= (int) $j['to_review'] > 0 ? ' <span class="badge badge-warning">' . (int) $j['to_review'] . ' waiting</span>' : '' ?></span></div>
          <div class="tx-amount"><a class="btn btn-secondary btn-sm" href="jobs.php?id=<?= (int) $j['id'] ?>">View</a></div></li>
      <?php endforeach; ?></ul>
      <?= yolotask_pager($list, static fn (int $p): string => 'jobs.php?status=' . rawurlencode($st) . '&q=' . rawurlencode($q) . '&page=' . $p) ?>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php
yolotask_render_app($admin, 'Jobs', (string) ob_get_clean(), 'market');
