<?php
declare(strict_types=1);

use YoloTask\Campaigns\Campaigns;
use YoloTask\Campaigns\Pricing;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Tasks\Tasks;

require_once __DIR__ . '/tasks-helpers.php';

if (yolotask_role() !== 'earner') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
    $uid = (int) $user['id'];
    $platform = max(0, (int) ($_GET['platform'] ?? 0));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $feed = Tasks::feed($pdo, $uid, $page, $platform ?: null);
    $working = Tasks::inProgress($pdo, $uid);
    $platforms = Pricing::platforms($pdo);
    $stats = Tasks::earnerStats($pdo, $uid);
} catch (Throwable $e) {
    Logger::error('tasks.feed_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
$verified = !empty($user['email_verified_at']);
ob_start();
?>
<div class="wallet-grid">
  <?php if (!$verified): ?>
    <section class="card verify-banner" role="status">
      <span class="verify-icon"><?= yolotask_nav_icon('bell') ?></span>
      <div><strong>Confirm your email to start earning</strong><p class="text-sm text-faint">You can browse tasks now. Starting one needs a confirmed email.</p></div>
      <a class="btn btn-primary btn-sm" href="verify.php">Confirm now</a>
    </section>
  <?php endif; ?>

  <section class="grid grid-4 dash-stats money-stats" aria-label="Your earnings">
    <article class="stat-card stat-success"><span class="stat-label">Earned</span><span class="stat-value"><?= h(yolotask_money($stats['earned'] ?? 0)) ?></span><span class="text-xs text-faint"><?= (int) ($stats['approved'] ?? 0) ?> approved</span></article>
    <article class="stat-card stat-warning"><span class="stat-label">Waiting</span><span class="stat-value"><?= (int) ($stats['pending'] ?? 0) ?></span><span class="text-xs text-faint">Proofs in review</span></article>
  </section>

  <?php if ($working !== []): ?>
    <section class="card card-glow" aria-labelledby="working-title">
      <div class="card-header"><h2 id="working-title">In progress</h2><span class="badge badge-warning"><?= count($working) ?></span></div>
      <ul class="tx-list">
        <?php foreach ($working as $w): ?>
          <li class="tx-row">
            <span class="tx-icon"><?= yolotask_nav_icon('tasks') ?></span>
            <div class="tx-meta"><strong><a href="task.php?id=<?= (int) $w['campaign_id'] ?>"><?= h((string) $w['title']) ?></a></strong>
              <span class="text-xs text-faint"><?= h((string) $w['platform_name']) ?> · <?= h((string) $w['task_type_name']) ?> · submit within <?= h(yolotask_until((string) $w['expires_at'])) ?></span></div>
            <div class="tx-amount"><span class="tx-in">+<?= h(yolotask_money($w['unit_reward_kobo'])) ?></span><a class="btn btn-primary btn-sm" href="task.php?id=<?= (int) $w['campaign_id'] ?>">Continue</a></div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <section class="card" aria-labelledby="feed-title">
    <div class="card-header"><h2 id="feed-title">Available tasks</h2><span class="badge badge-info"><?= number_format($feed['total']) ?></span></div>
    <nav class="money-tabs" aria-label="Platform filter" style="padding:0">
      <a href="tasks.php"<?= $platform === 0 ? ' class="active"' : '' ?>>All</a>
      <?php foreach ($platforms as $p): ?><a href="tasks.php?platform=<?= (int) $p['id'] ?>"<?= $platform === (int) $p['id'] ? ' class="active"' : '' ?>><?= h((string) $p['name']) ?></a><?php endforeach; ?>
    </nav>
    <?php if ($feed['rows'] === []): ?>
      <div class="empty-state"><p class="text-faint">No tasks available right now. New campaigns go live every day, so check back soon.</p></div>
    <?php else: ?>
      <ul class="tx-list" data-feed>
        <?php foreach ($feed['rows'] as $c): $left = Campaigns::slotsLeft($c); ?>
          <li class="tx-row task-row">
            <span class="tx-icon platform-icon" data-platform="<?= h((string) $c['platform_slug']) ?>"><?= h(mb_substr((string) $c['platform_name'], 0, 1)) ?></span>
            <div class="tx-meta"><strong><a href="task.php?id=<?= (int) $c['id'] ?>"><?= h((string) $c['title']) ?></a></strong>
              <span class="text-xs text-faint"><?= h((string) $c['platform_name']) ?> · <?= h((string) $c['task_type_name']) ?> · <?= number_format($left) ?> slot<?= $left === 1 ? '' : 's' ?> left</span>
              <?php if (($c['tier'] ?? '') === 'targeted'): ?><span class="user-tags"><?= yolotask_tier_badge('targeted') ?></span><?php endif; ?></div>
            <div class="tx-amount"><span class="tx-in">+<?= h(yolotask_money($c['unit_reward_kobo'])) ?></span><a class="btn btn-secondary btn-sm" href="task.php?id=<?= (int) $c['id'] ?>">View</a></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?= yolotask_pager($feed, static fn (int $p): string => 'tasks.php?' . http_build_query(array_filter(['platform' => $platform ?: null, 'page' => $p]))) ?>
    <?php endif; ?>
  </section>
</div>
<?php
yolotask_render_app($user, 'Tasks', (string) ob_get_clean(), 'tasks');
