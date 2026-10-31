<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Tasks\ReviewerActions;

require_once __DIR__ . '/tasks-helpers.php';
require_once __DIR__ . '/review-helpers.php';

/* Reviewer: my decisions (recorded for reviewer pay in a later phase). */
if (yolotask_role() !== 'reviewer') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('reviewer.history_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$list = ReviewerActions::forReviewer($pdo, (int) $user['id'], $page);
$counts = ReviewerActions::counts($pdo, (int) $user['id']);
ob_start();
?>
<?= yolotask_reviewer_tabs($pdo, 'history') ?>
<section class="card" aria-labelledby="h-title">
  <div class="card-header"><h2 id="h-title">My decisions</h2><span class="badge badge-info"><?= $counts['today'] ?> today · <?= $counts['last30'] ?> in 30 days</span></div>
  <?php if ($list['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint">No decisions yet.</p></div>
  <?php else: ?>
    <ul class="tx-list">
      <?php foreach ($list['rows'] as $r): ?>
        <li class="tx-row" data-action="<?= h((string) $r['action']) ?>"><span class="tx-icon"><?= yolotask_nav_icon('shield') ?></span>
          <div class="tx-meta"><strong><?= h(ReviewerActions::label((string) $r['action'])) ?></strong>
            <span class="text-xs text-faint text-break"><?= $r['item_type'] === 'appeal' ? 'Appeal #' . (int) $r['item_id'] : 'Submission #' . (int) $r['item_id'] ?> · <?= h((string) $r['campaign_title']) ?> · <?= h(yolotask_wat((string) $r['decided_at'])) ?></span>
            <?php if ($r['note']): ?><span class="text-xs text-break">“<?= h((string) $r['note']) ?>”</span><?php endif; ?></div></li>
      <?php endforeach; ?>
    </ul>
    <?= yolotask_pager($list, static fn (int $p): string => 'history.php?page=' . $p) ?>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($user, 'My decisions', (string) ob_get_clean(), 'home');
