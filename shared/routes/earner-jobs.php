<?php
declare(strict_types=1);

use YoloTask\Account\Activation;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Jobs\Attempts;
use YoloTask\Jobs\Jobs;

require_once __DIR__ . '/jobs-helpers.php';

/* Earner: Jobs tab (migration 0023) — open jobs (online / offline with location) and My jobs. */
if (yolotask_role() !== 'earner') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
    $uid = (int) $user['id'];
    $tab = ($_GET['tab'] ?? '') === 'mine' ? 'mine' : 'open';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $enabled = Jobs::enabled($pdo);
    $canWork = Activation::canWork($pdo, $uid);
    $open = $tab === 'open' && $enabled && $canWork ? Jobs::listOpen($pdo, $uid, $page) : ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
    $mine = $tab === 'mine' ? Attempts::listForEarner($pdo, $uid, $page) : null;
    $active = Attempts::activeForEarner($pdo, $uid);
} catch (Throwable $e) {
    Logger::error('jobs.earner_list_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
ob_start();
?>
<?php foreach ($active as $a): ?>
  <section class="card notice-card" role="status" data-active-job="<?= (int) $a['id'] ?>">
    <span class="verify-icon"><?= yolotask_nav_icon('briefcase') ?></span>
    <div><strong><?= $a['status'] === 'sent_back' ? 'Fix needed: ' : 'In progress: ' ?><?= h((string) $a['job_title']) ?></strong>
      <p class="text-sm text-faint"><?= $a['status'] === 'sent_back' ? 'Fix step ' . (int) $a['sent_back_position'] . ' within ' . h(yolotask_until((string) $a['fix_deadline_at'])) : 'Submit within ' . h(yolotask_until((string) $a['deadline_at'])) ?>.</p></div>
    <a class="btn btn-primary btn-sm" href="job.php?id=<?= (int) $a['job_id'] ?>">Continue</a>
  </section>
<?php endforeach; ?>
<nav class="card money-tabs" aria-label="Jobs">
  <a href="jobs.php"<?= $tab === 'open' ? ' class="active" aria-current="page"' : '' ?>>Open jobs</a>
  <a href="jobs.php?tab=mine"<?= $tab === 'mine' ? ' class="active" aria-current="page"' : '' ?>>My jobs</a>
</nav>
<?php if ($tab === 'open'): ?>
  <?php if (!$canWork): ?>
    <?= yolotask_activation_card(['fee' => Activation::fee($pdo)]) ?>
  <?php elseif (!$enabled): ?>
    <section class="card"><div class="empty-state"><p class="text-faint">Jobs are paused right now. Check back soon.</p></div></section>
  <?php else: ?>
    <section class="card" aria-labelledby="oj-title">
      <div class="card-header"><h2 id="oj-title">Open jobs</h2><span class="badge badge-info"><?= number_format($open['total']) ?></span></div>
      <p class="text-sm text-faint">Jobs have several steps and take longer than tasks. Read every step before you start: starting holds a slot for you and starts the timer.</p>
      <?php if ($open['rows'] === []): ?>
        <div class="empty-state"><p class="text-faint">No open jobs right now. New ones appear here as advertisers post them.</p></div>
      <?php else: ?>
        <ul class="tx-list">
          <?php foreach ($open['rows'] as $j): $free = Jobs::freeSlots($j); ?>
            <li class="tx-row task-row" data-job="<?= (int) $j['id'] ?>">
              <span class="tx-icon"><?= yolotask_nav_icon('briefcase') ?></span>
              <div class="tx-meta"><strong><a href="job.php?id=<?= (int) $j['id'] ?>"><?= h((string) $j['title']) ?></a></strong>
                <span><?= yolotask_job_mode($j) ?> <?= yolotask_job_system_badge($j) ?></span>
                <span class="text-xs text-faint"><?= (int) $j['step_count'] ?> steps · <?= h(Jobs::hoursLabel((int) $j['time_limit_hours'])) ?> to finish · <?= number_format($free) ?> slot<?= $free === 1 ? '' : 's' ?> left</span></div>
              <div class="tx-amount"><span class="tx-in">+<?= h(yolotask_money($j['earner_kobo'])) ?></span><a class="btn btn-secondary btn-sm" href="job.php?id=<?= (int) $j['id'] ?>">View</a></div>
            </li>
          <?php endforeach; ?>
        </ul>
        <?= yolotask_pager($open, static fn (int $p): string => 'jobs.php?page=' . $p) ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>
<?php else: ?>
  <section class="card" aria-labelledby="mj-title">
    <div class="card-header"><h2 id="mj-title">My jobs</h2><span class="badge badge-info"><?= number_format($mine['total']) ?></span></div>
    <?php if ($mine['rows'] === []): ?>
      <div class="empty-state"><p class="text-faint">You have not taken a job yet.</p></div>
    <?php else: ?>
      <ul class="tx-list">
        <?php foreach ($mine['rows'] as $a): ?>
          <li class="tx-row" data-attempt="<?= (int) $a['id'] ?>">
            <div class="tx-meta"><strong><a href="job.php?id=<?= (int) $a['job_id'] ?>"><?= h((string) $a['job_title']) ?></a></strong>
              <span class="text-xs text-faint">Started <?= h(yolotask_wat((string) $a['started_at'])) ?><?= $a['submitted_at'] ? ' · submitted ' . h(yolotask_wat((string) $a['submitted_at'])) : '' ?></span>
              <span><?= yolotask_attempt_badge($a) ?></span></div>
            <div class="tx-amount"><span class="<?= $a['status'] === 'approved' ? 'tx-in' : '' ?>"><?= $a['status'] === 'approved' ? '+' : '' ?><?= h(yolotask_money($a['earner_kobo'])) ?></span></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?= yolotask_pager($mine, static fn (int $p): string => 'jobs.php?tab=mine&page=' . $p) ?>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php
yolotask_render_app($user, 'Jobs', (string) ob_get_clean(), 'jobs');
