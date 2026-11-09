<?php
declare(strict_types=1);

use YoloTask\Campaigns\Pricing;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Account\Activation;
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
    $activationOffer = Activation::offer($pdo, $uid); // account activation required and not done yet: the activation card (migration 0019)
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

  <?php if ($activationOffer !== null): ?>
    <?= yolotask_activation_card($activationOffer) ?>
  <?php endif; ?>

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

  <?php if ($activationOffer === null): ?>
  <section class="card" aria-labelledby="feed-title">
    <div class="card-header"><h2 id="feed-title">Available tasks</h2><span class="badge badge-info" data-feed-count="<?= (int) $feed['count'] ?>"><?= number_format($feed['count']) ?></span></div>
    <nav class="money-tabs" aria-label="Platform filter" style="padding:0">
      <a href="tasks.php"<?= $platform === 0 ? ' class="active"' : '' ?>>All</a>
      <?php foreach ($platforms as $p): ?><a href="tasks.php?platform=<?= (int) $p['id'] ?>"<?= $platform === (int) $p['id'] ? ' class="active"' : '' ?>><?= h((string) $p['name']) ?></a><?php endforeach; ?>
    </nav>
    <?php if ($feed['rows'] === []): ?>
      <div class="empty-state"><p class="text-faint">No tasks available right now. New campaigns go live every day, so check back soon.</p></div>
    <?php else: ?>
      <ul class="tx-list" data-feed>
        <?php foreach ($feed['units'] as $unit): ?>
          <?php if ($unit['bundle'] !== null && count($unit['tasks']) > 1): $b = $unit['bundle']; $n = count($unit['tasks']); ?>
            <li class="task-bundle" data-bundle="<?= (int) $b['id'] ?>">
              <details>
                <summary class="tx-row task-row">
                  <span class="tx-icon platform-icon" data-platform="<?= h($b['platform_slug']) ?>"><?= h(mb_substr($b['platform_name'], 0, 1)) ?></span>
                  <span class="tx-meta"><strong><?= h($b['account_name']) ?> · <?= h($b['platform_name']) ?> · <?= $n ?> tasks</strong>
                    <span class="text-xs text-faint"><?= h(implode(' · ', array_values(array_unique(array_map(static fn (array $t): string => (string) $t['task_type_name'], $unit['tasks']))))) ?></span></span>
                  <span class="tx-amount"><span class="tx-in">+<?= h(yolotask_money($b['reward_total'])) ?></span><span class="btn btn-secondary btn-sm bundle-toggle" aria-hidden="true">Tasks</span></span>
                </summary>
                <ul class="tx-list bundle-tasks">
                  <?php foreach ($unit['tasks'] as $c): ?><?= yolotask_feed_task_row($c) ?><?php endforeach; ?>
                </ul>
              </details>
            </li>
          <?php else: ?>
            <?php foreach ($unit['tasks'] as $c): ?><?= yolotask_feed_task_row($c) ?><?php endforeach; ?>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>
      <?= yolotask_pager($feed, static fn (int $p): string => 'tasks.php?' . http_build_query(array_filter(['platform' => $platform ?: null, 'page' => $p]))) ?>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php
yolotask_render_app($user, 'Tasks', (string) ob_get_clean(), 'tasks');
