<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Growth\Levels;

require_once __DIR__ . '/levels-helpers.php';

/* Earner levels page (Phase 7): every active level, the earner's current one and what is missing for the next. */
if (yolotask_role() !== 'earner') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
    $info = Levels::forEarner($pdo, (int) $user['id']);
    $levels = Levels::enabled($pdo) ? Levels::all($pdo, true) : [];
} catch (Throwable $e) {
    Logger::error('levels.load_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
$rate = rtrim(rtrim(number_format($info['rate'], 1), '0'), '.');
$currentId = $info['level']['id'] ?? null;
$next = $info['next'];
$missing = [];
if ($next !== null) {
    $needTasks = max(0, (int) $next['min_approved'] - $info['approved']);
    if ($needTasks > 0) $missing[] = number_format($needTasks) . ' more approved task' . ($needTasks === 1 ? '' : 's');
    if ($info['rate'] < (int) $next['min_approval_rate']) $missing[] = 'an approval rate of at least ' . (int) $next['min_approval_rate'] . '% (now ' . $rate . '%)';
}
ob_start();
?>
<section class="card card-glow dash-hero" aria-labelledby="lv-title">
  <p class="text-xs fw-bold text-primary">EARNER LEVELS</p>
  <?php if (!$info['enabled']): ?>
    <h2 id="lv-title">Levels are paused</h2>
    <p class="text-sm text-faint">Right now every earner can hold up to <?= (int) $info['max_active'] ?> tasks at once and premium tasks are open to everyone with matching interests.</p>
  <?php else: ?>
    <h2 id="lv-title"><?= $currentId !== null ? 'You are ' . h((string) $info['level']['name']) : 'Your first level is close' ?></h2>
    <p class="text-sm text-faint" data-level-stats><?= number_format($info['approved']) ?> approved · <?= number_format($info['rejected']) ?> rejected · <?= h($rate) ?>% approval rate · hold up to <?= (int) $info['max_active'] ?> tasks<?= $info['premium'] ? ' · premium tasks unlocked' : ' · no premium tasks' ?></p>
    <?php if ($next !== null): ?>
      <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $info['progress'] ?>" aria-label="Progress to <?= h((string) $next['name']) ?>"><span class="progress-bar" style="width:<?= (int) $info['progress'] ?>%"></span></div>
      <p class="text-sm" data-level-missing><?= $missing !== [] ? 'You need ' . h(implode(' and ', $missing)) : 'You meet the requirements; your level updates with your next decided task' ?> to reach <strong><?= h((string) $next['name']) ?></strong>.</p>
    <?php else: ?>
      <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="100" aria-label="Top level reached"><span class="progress-bar" style="width:100%"></span></div>
      <p class="text-sm" data-level-missing>Top level reached. Keep your approval rate up to stay here.</p>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php if ($info['enabled'] && $levels !== []): ?>
<section class="card" aria-labelledby="lv-all-title">
  <div class="card-header"><h2 id="lv-all-title">All levels</h2><span class="badge badge-info"><?= count($levels) ?></span></div>
  <?= yolotask_levels_list($levels, $currentId, ['base_fee' => \YoloTask\Money\Fees::withdrawalBaseBps($pdo), 'checkin_on' => \YoloTask\Growth\Checkin::enabled($pdo)]) ?>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="lv-how-title">
  <div class="card-header"><h2 id="lv-how-title">How levels work</h2></div>
  <ul class="stack dash-tips">
    <li><strong>Approved tasks</strong><p class="text-sm text-faint">Every approved task counts toward your level, for life.</p></li>
    <li><strong>Approval rate</strong><p class="text-sm text-faint">Approved ÷ (approved + rejected). Careful, honest proofs keep it high; a rejection lowers it and can move you down a level.</p></li>
    <li><strong>Premium tasks</strong><p class="text-sm text-faint">Premium tasks pay more. You see them when your level allows it and they match <a href="interests.php">your interests</a>.</p></li>
    <li><strong>See where you rank</strong><p class="text-sm text-faint"><a href="leaderboard.php">Leaderboard</a> · <a href="tasks.php">Find tasks</a></p></li>
  </ul>
</section>
<?php
yolotask_render_app($user, 'Levels', (string) ob_get_clean(), 'home');
