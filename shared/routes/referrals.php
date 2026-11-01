<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Growth\Referrals;

require_once __DIR__ . '/money-helpers.php';
require_once __DIR__ . '/tasks-helpers.php';

$role = yolotask_role();
if (!in_array($role, ['earner', 'advertiser'], true)) yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
    $userId = (int) $user['id'];
    $links = Referrals::links($pdo, $userId);
    $data = Referrals::forReferrer($pdo, $userId);
    $enabled = Referrals::enabled($pdo);
    $bps = Referrals::commissionBps($pdo);
} catch (Throwable $e) {
    Logger::error('referrals.load_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
$milestones = $data['milestones'];
$total = $data['milestone_total'] + $data['commission_total'];
$statusBadge = static fn (string $s): string => match ($s) {
    'active' => '<span class="badge badge-success">Active</span>',
    'flagged' => '<span class="badge badge-warning">Under review</span>',
    default => '<span class="badge badge-danger">Not eligible</span>',
};
ob_start();
?>
<?php if (!$enabled): ?>
  <section class="card" role="status"><p class="text-sm text-faint">Referral rewards are paused right now. Your link still works, and anything your referrals earn will be paid when rewards resume.</p></section>
<?php endif; ?>

<section class="card card-glow dash-hero" aria-labelledby="ref-title">
  <p class="text-xs fw-bold text-primary" id="ref-title">INVITE AND EARN</p>
  <div class="wallet-balance"><span class="amount"><?= h(yolotask_money($total)) ?></span></div>
  <div class="wallet-sub">
    <span>Referrals <strong><?= number_format(count($data['referees'])) ?></strong></span>
    <span>Milestones <strong><?= h(yolotask_money($data['milestone_total'])) ?></strong></span>
    <span>Commission <strong><?= h(yolotask_money($data['commission_total'])) ?></strong></span>
  </div>
  <p class="text-sm text-faint">Your code is <strong class="ref-code"><?= h($links['code']) ?></strong>. Rewards go straight to your wallet balance.</p>
</section>

<div class="grid grid-2 dash-panels">
  <section class="card" aria-labelledby="links-title">
    <div class="card-header"><h2 id="links-title">Your invite links</h2></div>
    <div class="stack">
      <div class="field">
        <label class="label" for="link-earner">Invite earners</label>
        <div class="copy-field"><input class="input" id="link-earner" type="text" readonly value="<?= h($links['earner']) ?>"><button type="button" class="btn btn-secondary btn-sm" data-copy="#link-earner">Copy</button></div>
      </div>
      <div class="field">
        <label class="label" for="link-advertiser">Invite advertisers</label>
        <div class="copy-field"><input class="input" id="link-advertiser" type="text" readonly value="<?= h($links['advertiser']) ?>"><button type="button" class="btn btn-secondary btn-sm" data-copy="#link-advertiser">Copy</button></div>
      </div>
    </div>
  </section>

  <section class="card" aria-labelledby="how-title">
    <div class="card-header"><h2 id="how-title">How you earn</h2></div>
    <ul class="stack dash-tips">
      <li><strong>Earners you invite</strong>
        <?php if ($milestones === []): ?><p class="text-sm text-faint">No milestones are running right now.</p>
        <?php else: ?><p class="text-sm text-faint">You earn a bonus each time they reach these approved-task milestones (all of them add up):</p>
          <ul class="milestone-chips"><?php foreach ($milestones as $m): ?><li><?= number_format($m['task_count']) ?> tasks · <strong><?= h(yolotask_money($m['reward_kobo'])) ?></strong></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </li>
      <li><strong>Advertisers you invite</strong><p class="text-sm text-faint">You earn <?= h(Referrals::percent($bps)) ?> of every wallet top-up they make, for as long as they advertise.</p></li>
      <li><strong>Fair play</strong><p class="text-sm text-faint">Inviting yourself or making extra accounts is not allowed. Sign-ups from the same device or network are checked by our team before anything is paid.</p></li>
    </ul>
  </section>
</div>

<section class="card" aria-labelledby="referees-title">
  <div class="card-header"><h2 id="referees-title">People you invited</h2><span class="badge badge-info"><?= number_format(count($data['referees'])) ?></span></div>
  <?php if ($data['referees'] === []): ?>
    <div class="empty-state"><p class="text-faint">No one has joined with your link yet. Share it with friends who want to earn or advertise.</p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($data['referees'] as $r): ?>
        <li class="user-row">
          <div class="user-meta">
            <strong><?= h($r['name']) ?></strong>
            <span class="user-tags"><span class="badge badge-info"><?= h(ucfirst($r['role'])) ?></span><?= $statusBadge($r['status']) ?><span class="badge badge-success">Earned <?= h(yolotask_money($r['earned'])) ?></span></span>
            <span class="text-xs text-faint">Joined <?= h(yolotask_wat($r['joined'], 'j M Y')) ?></span>
            <?php if ($r['role'] === 'earner'): ?>
              <?php if ($r['next'] !== null): ?>
                <?= yolotask_progress(min($r['approved'], $r['next']['task_count']), 0, $r['next']['task_count']) ?>
                <span class="text-xs text-faint"><?= number_format($r['approved']) ?> of <?= number_format($r['next']['task_count']) ?> approved tasks to your next <?= h(yolotask_money($r['next']['reward_kobo'])) ?> bonus</span>
              <?php else: ?>
                <span class="text-xs text-faint"><?= number_format($r['approved']) ?> approved tasks · every milestone reached</span>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-xs text-faint"><?= number_format($r['deposits']) ?> confirmed top-up<?= $r['deposits'] === 1 ? '' : 's' ?></span>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="card" aria-labelledby="payouts-title">
  <div class="card-header"><h2 id="payouts-title">Referral earnings</h2><span class="badge badge-info">Latest 20</span></div>
  <?php if ($data['payouts'] === []): ?>
    <div class="empty-state"><p class="text-faint">Your referral bonuses and commissions will be listed here.</p></div>
  <?php else: ?>
    <ul class="tx-list">
      <?php foreach ($data['payouts'] as $p): $rev = (string) $p['status'] === 'reversed'; ?>
        <li class="tx-row">
          <span class="tx-icon"><?= yolotask_nav_icon('sparkle') ?></span>
          <div class="tx-meta">
            <strong><?= (string) $p['kind'] === 'milestone' ? h((string) $p['display_name']) . ' reached ' . number_format((int) $p['milestone_task_count']) . ' tasks' : h(Referrals::percent((int) $p['rate_bps'])) . ' of ' . h((string) $p['display_name']) . '’s ' . h(yolotask_money($p['base_kobo'])) . ' top-up' ?></strong>
            <span class="text-xs text-faint"><?= h(yolotask_wat((string) $p['created_at'])) ?><?= $rev ? ' · reversed' : '' ?></span>
          </div>
          <div class="tx-amount"><span class="<?= $rev ? 'tx-out' : 'tx-in' ?>"><?= $rev ? '' : '+' ?><?= h(yolotask_money($p['amount_kobo'])) ?></span></div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($user, 'Referrals', (string) ob_get_clean(), 'referrals');
