<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Growth\Leaderboard;

require_once __DIR__ . '/leaderboard-helpers.php';

/* Earner leaderboard (Phase 7): Top Workers / Top Referrers, this week / this month (WAT). */
if (yolotask_role() !== 'earner') yolotask_error_page(404);
yolotask_private_headers();
$board = Leaderboard::board(is_string($_GET['board'] ?? null) ? $_GET['board'] : null);
$period = Leaderboard::period(is_string($_GET['period'] ?? null) ? $_GET['period'] : null);
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
    $lb = Leaderboard::get($pdo, $board, $period, (int) $user['id']);
} catch (Throwable $e) {
    Logger::error('leaderboard.load_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
$what = $board === 'referrers' ? 'people referred' : 'approved tasks';
$renderRow = static function (array $r) use ($board): string {
    return '<li class="lb-row' . ($r['is_viewer'] ? ' lb-you' : '') . ($r['rank'] !== null && $r['rank'] >= 1 && $r['rank'] <= 3 ? ' lb-top' : '') . '"' . ($r['is_viewer'] ? ' data-you' : '') . '>'
        . yolotask_leaderboard_rank($r['rank'])
        . '<span class="lb-avatar" aria-hidden="true">' . h($r['initials']) . '</span>'
        . '<span class="lb-meta"><strong>' . h($r['public_name']) . ($r['is_viewer'] ? ' <span class="badge badge-info">You</span>' : '') . '</strong>'
        . '<span class="text-xs text-faint">' . h(yolotask_leaderboard_count($board, $r['count'])) . '</span></span>'
        . '<span class="lb-amount">' . h(yolotask_money($r['total_kobo'])) . '</span></li>';
};
ob_start();
?>
<section class="card card-glow dash-hero" aria-labelledby="lb-title">
  <p class="text-xs fw-bold text-primary">LEADERBOARD</p>
  <h2 id="lb-title"><?= $board === 'referrers' ? 'Top Referrers' : 'Top Workers' ?> · <?= h(yolotask_leaderboard_period_label($period)) ?></h2>
  <p class="text-sm text-faint">Ranked by <?= h($what) ?><?= $period === 'all' ? ' since launch' : ' since ' . h($lb['since']) . ', 12:00 AM (Lagos time)' ?>.<?= $board === 'referrers' ? ' The amount is referral earnings paid in the same period.' : '' ?> Ties go to whoever got there first. Names show first name and last initial only.</p>
</section>

<?= yolotask_leaderboard_toggles('leaderboard.php', $board, $period) ?>

<section class="card" aria-labelledby="lb-list-title">
  <div class="card-header"><h2 id="lb-list-title">Top <?= Leaderboard::LIMIT ?></h2><span class="badge badge-info"><?= h($lb['label']) ?></span></div>
  <?php if ($lb['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint"><?= $board === 'referrers' ? ($period === 'all' ? 'No referrals yet.' : 'No new referrals yet this ' . $period . '.') . ' Invite friends to get on the board.' : ($period === 'all' ? 'No approved tasks yet.' : 'No approved tasks yet this ' . $period . '.') . ' Be the first on the board.' ?></p></div>
  <?php else: ?>
    <ol class="lb-list">
      <?php foreach ($lb['rows'] as $r) echo $renderRow($r); ?>
    </ol>
  <?php endif; ?>
  <?php if ($lb['viewer'] !== null): $v = $lb['viewer']; ?>
    <div class="lb-viewer" data-viewer-rank>
      <p class="text-xs text-faint"><?= $v['rank'] === null ? 'You are not on the board yet this ' . $period . '.' : 'Your position' ?></p>
      <ol class="lb-list"><?= $renderRow($v) ?></ol>
    </div>
  <?php endif; ?>
</section>

<section class="card" aria-labelledby="lb-how-title">
  <div class="card-header"><h2 id="lb-how-title">How the board works</h2></div>
  <ul class="stack dash-tips">
    <li><strong>Top Workers</strong><p class="text-sm text-faint">Tasks count when they are approved, and the amount is what those tasks paid you.</p></li>
    <li><strong>Top Referrers</strong><p class="text-sm text-faint">Referral milestone bonuses and advertiser deposit commissions credited to you. Reversed payouts do not count.</p></li>
    <li><strong>Leadership bonuses</strong><p class="text-sm text-faint">YOLOTASK may reward top performers. Bonuses land in your wallet and you get a notification. <a href="referrals.php">Invite &amp; earn</a> · <a href="levels.php">Levels</a></p></li>
  </ul>
</section>
<?php
yolotask_render_app($user, 'Leaderboard', (string) ob_get_clean(), 'home');
