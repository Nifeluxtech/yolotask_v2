<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Jobs\Jobs;

require_once __DIR__ . '/jobs-helpers.php';

/* Advertiser: my jobs (migration 0023) — list, totals, post a job. */
if (yolotask_role() !== 'advertiser') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
    $uid = (int) $user['id'];
    $status = in_array($_GET['status'] ?? '', ['open', 'ended', 'all'], true) ? (string) $_GET['status'] : 'open';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $list = Jobs::listForAdvertiser($pdo, $uid, $status, $page);
    $stats = Jobs::advertiserStats($pdo, $uid);
    $enabled = Jobs::enabled($pdo);
} catch (Throwable $e) {
    Logger::error('jobs.advertiser_list_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
ob_start();
?>
<section class="grid grid-4 dash-stats money-stats" aria-label="Jobs overview">
  <article class="stat-card"><span class="stat-label">Open jobs</span><span class="stat-value"><?= number_format($stats['open']) ?></span></article>
  <article class="stat-card stat-warning"><span class="stat-label">To review</span><span class="stat-value"><?= number_format($stats['to_review']) ?></span></article>
  <article class="stat-card"><span class="stat-label">In escrow</span><span class="stat-value"><?= h(yolotask_money($stats['escrow'])) ?></span></article>
  <article class="stat-card stat-success"><span class="stat-label">Paid out</span><span class="stat-value"><?= h(yolotask_money($stats['spent'])) ?></span></article>
</section>
<section class="card" aria-labelledby="jobs-title">
  <div class="card-header"><h2 id="jobs-title">My jobs</h2>
    <?php if ($enabled): ?><a class="btn btn-primary btn-sm" href="job-new.php">Post a job</a><?php else: ?><span class="badge badge-info">Posting jobs is paused</span><?php endif; ?></div>
  <p class="text-sm text-faint">Jobs are longer tasks with several steps. You write every step and the proof you need; the pay for every slot is locked from your wallet when you publish, and anything unused comes back when you close the job.</p>
  <nav class="money-tabs" aria-label="Filter jobs">
    <?php foreach (['open' => 'Open', 'ended' => 'Ended', 'all' => 'All'] as $k => $label): ?><a href="jobs.php?status=<?= h($k) ?>"<?= $k === $status ? ' class="active" aria-current="page"' : '' ?>><?= h($label) ?></a><?php endforeach; ?>
  </nav>
  <?php if ($list['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint">No jobs here yet.</p></div>
  <?php else: ?>
    <ul class="tx-list">
      <?php foreach ($list['rows'] as $j): ?>
        <li class="tx-row" data-job="<?= (int) $j['id'] ?>">
          <div class="tx-meta"><strong><a href="job.php?id=<?= (int) $j['id'] ?>"><?= h((string) $j['title']) ?></a></strong>
            <span class="text-xs text-faint"><?= (int) $j['step_count'] ?> steps · <?= number_format((int) $j['approved_count']) ?> of <?= number_format((int) $j['slots']) ?> approved · <?= number_format((int) $j['active_count']) ?> working · <?= h(yolotask_money($j['pay_kobo'])) ?> each</span>
            <span><?= yolotask_job_mode($j) ?> <?= yolotask_job_badge($j) ?><?= (int) $j['to_review'] > 0 ? ' <span class="badge badge-warning">' . (int) $j['to_review'] . ' to review</span>' : '' ?></span></div>
          <div class="tx-amount"><a class="btn btn-secondary btn-sm" href="job.php?id=<?= (int) $j['id'] ?>">Open</a></div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?= yolotask_pager($list, static fn (int $p): string => 'jobs.php?status=' . $status . '&page=' . $p) ?>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($user, 'Jobs', (string) ob_get_clean(), 'jobs');
