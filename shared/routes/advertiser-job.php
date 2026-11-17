<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Jobs\Attempts;
use YoloTask\Jobs\Jobs;
use YoloTask\Money\Wallet;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/jobs-helpers.php';

/* Advertiser: one job (migration 0023) — pause / resume, add slots, close, steps, attempts to review, escrow events. */
if (yolotask_role() !== 'advertiser') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('job.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$uid = (int) $user['id'];
$id = (int) ($_GET['id'] ?? $_POST['job_id'] ?? 0);
try {
    $job = Jobs::ownedBy($pdo, $id, $uid);
} catch (RuntimeException) {
    yolotask_error_page(404);
}
$tabs = ['review' => 'To review', 'active' => 'Working', 'approved' => 'Approved', 'rejected' => 'Rejected', 'ended' => 'Ended', 'all' => 'All'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? (string) $_GET['tab'] : 'review';
$back = 'job.php?id=' . $id . ($tab !== 'review' ? '&tab=' . $tab : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    yolotask_csrf_or_back($back);
    $action = yolotask_post_str('action');
    try {
        switch ($action) {
            case 'pause':
                Jobs::pause($pdo, $user, $id);
                yolotask_flash('Job paused. No new earners can start it; earners already working can finish.');
                break;
            case 'resume':
                Jobs::resume($pdo, $user, $id);
                yolotask_flash('Job is live again.');
                break;
            case 'add_slots':
                $amount = Jobs::addSlots($pdo, $user, $id, (int) yolotask_post_str('slots'));
                yolotask_flash('Slots added. ' . yolotask_money($amount) . ' was locked from your wallet.');
                break;
            case 'close':
                $refund = Jobs::close($pdo, $user, $id);
                $j = Jobs::find($pdo, $id);
                yolotask_flash('Job closed. ' . yolotask_money($refund) . ' returned to your wallet' . ((int) $j['active_count'] > 0 ? '; work in progress or waiting for your review stays reserved until it is resolved.' : '.'));
                break;
            default:
                throw new RuntimeException('Choose an action.');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('job.action_failed', ['action' => $action, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$steps = Jobs::steps($pdo, $id);
$page = max(1, (int) ($_GET['page'] ?? 1));
$list = Attempts::listForJob($pdo, $id, $tab, $page);
$events = Jobs::fundingEvents($pdo, $id);
$wallet = Wallet::forUser($pdo, $uid);
$escrow = (int) $job['funded_kobo'] - (int) $job['spent_kobo'] - (int) $job['refunded_kobo'];
$open = in_array((string) $job['status'], Jobs::OPEN, true);
$csrf = Csrf::token();
$hidden = '<input type="hidden" name="_csrf" value="' . h($csrf) . '"><input type="hidden" name="job_id" value="' . $id . '">';
ob_start();
?>
<div class="wallet-grid">
  <section class="card card-glow dash-hero" aria-labelledby="j-title">
    <p class="text-xs fw-bold text-primary">JOB #<?= $id ?> · <?= (int) $job['step_count'] ?> STEPS</p>
    <h2 id="j-title" class="text-break"><?= h((string) $job['title']) ?></h2>
    <div class="wallet-sub"><?= yolotask_job_badge($job) ?> <?= yolotask_job_mode($job) ?><span><strong><?= number_format((int) $job['approved_count']) ?></strong> of <?= number_format((int) $job['slots']) ?> approved · <?= number_format((int) $job['active_count']) ?> working · <?= number_format(Jobs::freeSlots($job)) ?> free</span></div>
    <?= yolotask_progress((int) $job['approved_count'], (int) $job['active_count'], max(1, (int) $job['slots'])) ?>
    <p class="text-sm">Pay <?= h(yolotask_money($job['pay_kobo'])) ?> per earner (earner gets <?= h(yolotask_money($job['earner_kobo'])) ?>) · time limit <?= h(Jobs::hoursLabel((int) $job['time_limit_hours'])) ?> · you review within <?= h(Jobs::hoursLabel((int) $job['review_hours'])) ?> · in escrow <?= h(yolotask_money($escrow)) ?></p>
    <?php if ($job['status_reason'] && !in_array((string) $job['status'], ['live'], true)): ?><p class="text-sm text-faint">Note: <?= h((string) $job['status_reason']) ?></p><?php endif; ?>
    <?php if ($job['link_url'] && yolotask_safe_task_url((string) $job['link_url']) !== null): ?><p class="text-sm text-break"><a href="<?= h((string) $job['link_url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= h((string) $job['link_url']) ?></a></p><?php endif; ?>
    <div class="hero-actions">
      <?php if ($job['status'] === 'live'): ?>
        <form method="post" action="<?= h($back) ?>"><?= $hidden ?><button class="btn btn-secondary btn-sm" type="submit" name="action" value="pause">Pause</button></form>
      <?php elseif ($job['status'] === 'paused' && (int) $job['admin_paused'] === 0): ?>
        <form method="post" action="<?= h($back) ?>"><?= $hidden ?><button class="btn btn-primary btn-sm" type="submit" name="action" value="resume">Resume</button></form>
      <?php endif; ?>
      <?php if ($open): ?>
        <form method="post" action="<?= h($back) ?>"><?= $hidden ?><button class="btn btn-danger-outline btn-sm" type="submit" name="action" value="close" data-confirm="Close this job? Unused slots are refunded to your wallet now; work in progress or waiting for review stays reserved until it is resolved.">Close job</button></form>
      <?php endif; ?>
    </div>
    <?php if ($open): ?>
      <form method="post" action="<?= h($back) ?>" class="copy-field" style="margin-top:.75rem"><?= $hidden ?><input type="hidden" name="action" value="add_slots">
        <input class="input" name="slots" type="number" min="1" max="<?= Jobs::MAX_SLOTS ?>" required placeholder="More slots" aria-label="Slots to add">
        <button class="btn btn-secondary btn-sm" type="submit">Add slots (<?= h(yolotask_money($job['pay_kobo'])) ?> each)</button></form>
      <p class="input-hint">Wallet <?= h(yolotask_money($wallet['available_kobo'])) ?>.</p>
    <?php endif; ?>
  </section>

  <section class="card" aria-labelledby="sub-title">
    <div class="card-header"><h2 id="sub-title">Submissions</h2></div>
    <nav class="money-tabs" aria-label="Filter submissions">
      <?php foreach ($tabs as $k => $label): ?><a href="job.php?id=<?= $id ?>&tab=<?= h($k) ?>"<?= $k === $tab ? ' class="active" aria-current="page"' : '' ?>><?= h($label) ?></a><?php endforeach; ?>
    </nav>
    <?php if ($list['rows'] === []): ?>
      <div class="empty-state"><p class="text-faint"><?= $tab === 'review' ? 'Nothing waiting for your review.' : 'Nothing here.' ?></p></div>
    <?php else: ?>
      <ul class="tx-list">
        <?php foreach ($list['rows'] as $a): ?>
          <li class="tx-row" data-attempt="<?= (int) $a['id'] ?>">
            <div class="tx-meta"><strong><a href="job-review.php?id=<?= (int) $a['id'] ?>"><?= h((string) $a['earner_name']) ?></a></strong>
              <span class="text-xs text-faint">Started <?= h(yolotask_wat((string) $a['started_at'])) ?><?= $a['submitted_at'] ? ' · submitted ' . h(yolotask_wat((string) $a['submitted_at'])) : '' ?><?= $a['status'] === 'submitted' ? ' · auto-approves in ' . h(yolotask_until((string) $a['review_due_at'])) : '' ?><?= (int) $a['send_back_count'] > 0 ? ' · sent back ' . (int) $a['send_back_count'] . '×' : '' ?></span>
              <span><?= yolotask_attempt_badge($a) ?></span></div>
            <div class="tx-amount"><a class="btn btn-<?= $a['status'] === 'submitted' ? 'primary' : 'secondary' ?> btn-sm" href="job-review.php?id=<?= (int) $a['id'] ?>"><?= $a['status'] === 'submitted' ? 'Review' : 'View' ?></a></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?= yolotask_pager($list, static fn (int $p): string => 'job.php?id=' . $id . '&tab=' . $tab . '&page=' . $p) ?>
    <?php endif; ?>
  </section>

  <section class="card" aria-labelledby="ins-title">
    <div class="card-header"><h2 id="ins-title">Instructions and steps</h2></div>
    <div class="instructions text-break"><?= nl2br(h((string) $job['instructions'])) ?></div>
    <ol class="steps-list">
      <?php foreach ($steps as $s): ?><li><strong><?= h((string) $s['title']) ?></strong><div class="text-sm text-break"><?= nl2br(h((string) $s['instructions'])) ?></div><?= yolotask_job_kinds($s) ?></li><?php endforeach; ?>
    </ol>
  </section>

  <?php if ($events !== []): ?>
  <section class="card" aria-labelledby="ev-title">
    <div class="card-header"><h2 id="ev-title">Escrow</h2><span class="badge badge-info"><?= h(yolotask_money($escrow)) ?> held</span></div>
    <ul class="tx-list">
      <?php foreach ($events as $e): ?>
        <li class="tx-row"><div class="tx-meta"><strong><?= h(match ((string) $e['event']) { 'lock' => 'Locked from your wallet', 'refund' => 'Refunded to your wallet', default => 'Appeal payout' }) ?></strong><span class="text-xs text-faint"><?= h((string) $e['note']) ?> · <?= h(yolotask_wat((string) $e['created_at'])) ?></span></div>
          <div class="tx-amount"><span class="<?= $e['event'] === 'refund' ? 'tx-in' : 'tx-out' ?>"><?= $e['event'] === 'refund' ? '+' : '−' ?><?= h(yolotask_money($e['amount_kobo'])) ?></span></div></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
  <p class="text-center text-sm"><a href="jobs.php">Back to jobs</a></p>
</div>
<?php
yolotask_render_app($user, 'Job', (string) ob_get_clean(), 'jobs');
