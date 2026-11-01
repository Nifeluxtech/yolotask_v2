<?php
declare(strict_types=1);

use YoloTask\Core\Logger;
use YoloTask\Growth\Leaderboard;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/growth-helpers.php';
require_once __DIR__ . '/leaderboard-helpers.php';

/* Admin leaderboard (Phase 7): full names + ids, leadership rewards, reward history. */
[$pdo, $admin] = yolotask_admin_growth_boot('admin.leaders_load_failed');
$adminId = (int) $admin['id'];
$board = Leaderboard::board(is_string($_GET['board'] ?? null) ? $_GET['board'] : null);
$period = Leaderboard::period(is_string($_GET['period'] ?? null) ? $_GET['period'] : null);
$self = 'leaders.php?' . http_build_query(['board' => $board, 'period' => $period]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    yolotask_admin_csrf_or_back($self);
    $back = $self;
    try {
        if (yolotask_post_str('action') !== 'reward') throw new RuntimeException('Unknown action.');
        $amount = yolotask_parse_naira(trim(yolotask_post_str('amount')));
        if ($amount === null) throw new RuntimeException('Enter the reward in naira (₦1 to ₦100,000).');
        $note = yolotask_post_str('note');
        if (mb_strlen(trim($note)) > Leaderboard::NOTE_MAX) throw new RuntimeException('Keep the note to ' . Leaderboard::NOTE_MAX . ' characters.');
        $rank = (int) yolotask_post_str('rank');
        Leaderboard::reward($pdo, $adminId, (int) yolotask_post_str('user_id'), $amount, $note, yolotask_post_str('period_label'),
            yolotask_post_str('board'), yolotask_post_str('period'), $rank > 0 ? $rank : null, yolotask_post_str('idem'));
        yolotask_flash('Leadership bonus of ' . yolotask_money($amount) . ' paid and posted to the ledger.');
        $back .= '#history';
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.leaders_action_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

try {
    $lb = Leaderboard::get($pdo, $board, $period);
    $history = Leaderboard::history($pdo, 50);
} catch (Throwable $e) {
    Logger::error('admin.leaders_load_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
$csrf = Csrf::token();
ob_start();
?>
<?= yolotask_admin_growth_tabs($pdo, 'leaders') ?>
<section class="card" aria-labelledby="leaders-title">
  <div class="card-header"><h2 id="leaders-title">Leaderboard · <?= $board === 'referrers' ? 'Top Referrers' : 'Top Workers' ?></h2><span class="badge badge-info"><?= h($lb['label']) ?></span></div>
  <p class="text-sm text-faint"><?= $period === 'all' ? 'All time.' : 'Since ' . h($lb['since']) . ', 12:00 AM WAT.' ?> <?= $board === 'referrers' ? 'People each user referred who joined in the period (flagged and rejected referrals excluded; imported v1 referrals included), with referral bonuses and commissions paid in the period.' : 'Tasks approved in the period and the rewards they paid.' ?> Staff and suspended accounts are left out. Earners see first name + last initial only.</p>
</section>
<?= yolotask_leaderboard_toggles('leaders.php', $board, $period) ?>

<section class="card" aria-labelledby="leaders-list-title">
  <div class="card-header"><h2 id="leaders-list-title">Top <?= Leaderboard::LIMIT ?></h2><span class="badge badge-info"><?= count($lb['rows']) ?></span></div>
  <?php if ($lb['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint">Nobody on this board yet.</p></div>
  <?php else: ?>
    <ol class="lb-list">
      <?php foreach ($lb['rows'] as $r): ?>
        <li class="lb-row lb-admin<?= $r['rank'] <= 3 ? ' lb-top' : '' ?>">
          <?= yolotask_leaderboard_rank($r['rank']) ?>
          <span class="lb-avatar" aria-hidden="true"><?= h(Leaderboard::initials($r['name'])) ?></span>
          <span class="lb-meta">
            <strong><?= h($r['name']) ?></strong>
            <span class="text-xs text-faint">User #<?= (int) $r['user_id'] ?> · <?= h(yolotask_leaderboard_count($board, $r['count'])) ?> · <?= h(yolotask_money($r['total_kobo'])) ?></span>
            <details class="danger-details lb-reward">
              <summary class="btn btn-secondary btn-sm">Reward</summary>
              <form method="post" action="<?= h($self) ?>" class="stack">
                <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="reward">
                <input type="hidden" name="user_id" value="<?= (int) $r['user_id'] ?>"><input type="hidden" name="rank" value="<?= (int) $r['rank'] ?>">
                <input type="hidden" name="board" value="<?= h($board) ?>"><input type="hidden" name="period" value="<?= h($period) ?>">
                <input type="hidden" name="idem" value="<?= h(bin2hex(random_bytes(16))) ?>">
                <label class="mini-field"><span>Amount ₦ (1 – 100,000)</span><input class="input" name="amount" inputmode="decimal" required placeholder="e.g. 1000"></label>
                <label class="mini-field"><span>Note (up to <?= Leaderboard::NOTE_MAX ?>)</span><input class="input" name="note" value="Leadership bonus" maxlength="<?= Leaderboard::NOTE_MAX ?>"></label>
                <label class="mini-field"><span>Period</span><input class="input" name="period_label" value="<?= h($lb['label']) ?>" maxlength="<?= Leaderboard::LABEL_MAX ?>" required></label>
                <button class="btn btn-primary btn-sm" type="submit" data-confirm="Pay this leadership bonus to <?= h($r['name']) ?>?">Pay reward</button>
              </form>
            </details>
          </span>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>

<section class="card" id="history" aria-labelledby="history-title">
  <div class="card-header"><h2 id="history-title">Reward history</h2><span class="badge badge-info"><?= count($history) ?></span></div>
  <p class="text-sm text-faint">The last 50 leadership bonuses. Each is an admin bonus in the ledger (system:bonuses).</p>
  <?php if ($history === []): ?>
    <div class="empty-state"><p class="text-faint">No leadership rewards yet.</p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($history as $hrow): ?>
        <li class="user-row">
          <div class="user-meta">
            <strong><?= h(yolotask_money((int) $hrow['amount_kobo'])) ?> to <?= h((string) $hrow['user_name']) ?> <span class="text-faint">#<?= (int) $hrow['user_id'] ?></span></strong>
            <span class="text-xs text-faint text-break"><?= h((string) $hrow['period_label']) ?> · <?= (string) $hrow['board'] === 'referrers' ? 'Top Referrers' : 'Top Workers' ?><?= $hrow['rank_at_reward'] !== null ? ' · rank ' . (int) $hrow['rank_at_reward'] : '' ?> · <?= h((string) $hrow['note']) ?></span>
            <span class="text-xs text-faint">By <?= h((string) ($hrow['admin_name'] ?? 'admin')) ?> · <?= h(yolotask_wat((string) $hrow['created_at'])) ?></span>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($admin, 'Leaderboard', (string) ob_get_clean(), 'money');
