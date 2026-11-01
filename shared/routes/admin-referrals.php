<?php
declare(strict_types=1);

use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Growth\DepositCashback;
use YoloTask\Growth\Referrals;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/growth-helpers.php';

/* Admin: referral programme settings, milestones, flagged referrals and payouts, and advertiser deposit cashback. */
[$pdo, $admin] = yolotask_admin_growth_boot('admin.referrals_load_failed');
$adminId = (int) $admin['id'];
$filter = in_array($_GET['f'] ?? '', ['flagged', 'milestone', 'commission', 'reversed'], true) ? (string) $_GET['f'] : 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'referrals.php' . ($filter !== 'all' ? '?f=' . $filter : '');
    yolotask_admin_csrf_or_back($back);
    $action = yolotask_post_str('action');
    try {
        if ($action === 'settings') {
            $pct = trim(yolotask_post_str('commission_percent'));
            if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $pct) || (float) $pct > Referrals::MAX_BPS / 100) throw new RuntimeException('Commission must be a percentage from 0 to 50 (up to two decimals).');
            $oldAllowance = Referrals::ipAllowance($pdo);
            $allow = array_key_exists('ip_allowance', $_POST) ? trim(yolotask_post_str('ip_allowance')) : (string) $oldAllowance; // older forms without the field keep the current value
            if (!preg_match('/^\d{1,3}$/', $allow) || (int) $allow < 1 || (int) $allow > Referrals::MAX_IP_ALLOWANCE) throw new RuntimeException('Sign-ups per network or device must be a whole number from 1 to ' . Referrals::MAX_IP_ALLOWANCE . '.');
            Settings::save($pdo, $adminId, ['referrals.enabled' => yolotask_post_str('enabled') === '1' ? '1' : '0', 'referrals.commission_bps' => (string) (int) round((float) $pct * 100), 'referrals.ip_allowance' => (string) (int) $allow]);
            if ((int) $allow !== $oldAllowance) \YoloTask\Security\Audit::record($pdo, $adminId, 'admin.referral_ip_allowance_changed', 'settings', (int) $allow);
            yolotask_flash('Referral settings saved.');
            $back = 'referrals.php#settings';
        } elseif ($action === 'cashback_settings') {
            $minInput = trim(yolotask_post_str('cashback_min'));
            DepositCashback::saveSettings($pdo, $adminId, yolotask_post_str('cashback_percent'), $minInput === '' ? 0 : yolotask_parse_naira($minInput));
            yolotask_flash('Cashback settings saved.');
            $back = 'referrals.php#cashback';
        } elseif ($action === 'cashback_reverse') {
            DepositCashback::reverse($pdo, $adminId, (int) yolotask_post_str('id'), yolotask_post_str('reason'));
            yolotask_flash('Cashback reversed in the ledger.');
            $back = 'referrals.php#cashback';
        } elseif ($action === 'milestone_save') {
            $id = (int) yolotask_post_str('id');
            $count = yolotask_post_str('task_count');
            if (!preg_match('/^\d{1,6}$/', $count)) throw new RuntimeException('Enter the number of approved tasks.');
            $reward = yolotask_parse_naira(yolotask_post_str('reward'));
            if ($reward === null) throw new RuntimeException('Enter the reward in naira.');
            Referrals::saveMilestone($pdo, $adminId, $id > 0 ? $id : null, (int) $count, $reward, yolotask_post_str('active') === '1');
            yolotask_flash($id > 0 ? 'Milestone updated.' : 'Milestone added. Referrers whose referees already qualify are paid on the next sweep.');
            $back = 'referrals.php#milestones';
        } elseif ($action === 'milestone_delete') {
            Referrals::deleteMilestone($pdo, $adminId, (int) yolotask_post_str('id'));
            yolotask_flash('Milestone deleted.');
            $back = 'referrals.php#milestones';
        } elseif ($action === 'referral_review') {
            $decision = yolotask_post_str('decision');
            Referrals::review($pdo, $adminId, (int) yolotask_post_str('id'), $decision, yolotask_post_str('note'));
            yolotask_flash($decision === 'approve' ? 'Referral approved. Anything already earned has been paid.' : 'Referral rejected. It will never earn rewards.');
            $back = 'referrals.php#flagged';
        } elseif ($action === 'payout_reverse') {
            Referrals::reversePayout($pdo, $adminId, (int) yolotask_post_str('id'), yolotask_post_str('reason'));
            yolotask_flash('Payout reversed in the ledger.');
        } elseif ($action === 'sweep') {
            $r = Referrals::sweep($pdo);
            yolotask_flash('Catch-up finished: ' . $r['milestones'] . ' milestone and ' . $r['commissions'] . ' commission payout(s).');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.referrals_action_failed', ['action' => $action, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$stats = Referrals::adminStats($pdo);
$enabled = Referrals::enabled($pdo);
$bps = Referrals::commissionBps($pdo);
$ipAllowance = Referrals::ipAllowance($pdo);
$milestones = Referrals::milestones($pdo);
$flagged = Referrals::flagged($pdo);
$page = max(1, (int) ($_GET['page'] ?? 1));
$payouts = Referrals::payouts($pdo, $filter, $page);
$cbBps = DepositCashback::rateBps($pdo);
$cbMin = DepositCashback::minDepositKobo($pdo);
$cbStats = DepositCashback::stats($pdo);
$cbPage = max(1, (int) ($_GET['cpage'] ?? 1));
$cashbacks = DepositCashback::recent($pdo, $cbPage, 10);
$csrf = Csrf::token();
$naira = static fn (int $kobo): string => rtrim(rtrim(number_format($kobo / 100, 2, '.', ''), '0'), '.');
ob_start();
?>
<?= yolotask_admin_growth_tabs($pdo, 'referrals') ?>
<section class="grid grid-4 dash-stats money-stats" aria-label="Referral totals">
  <article class="stat-card"><span class="stat-icon"><?= yolotask_nav_icon('user-plus') ?></span><span class="stat-label">Referrals</span><span class="stat-value"><?= number_format($stats['referrals']) ?></span><span class="text-xs text-faint"><?= number_format($stats['earners']) ?> earners · <?= number_format($stats['advertisers']) ?> advertisers</span></article>
  <article class="stat-card <?= $stats['flagged'] + $stats['flagged_payouts'] > 0 ? 'stat-warning' : '' ?>"><span class="stat-icon"><?= yolotask_nav_icon('shield') ?></span><span class="stat-label">Needs review</span><span class="stat-value"><?= number_format($stats['flagged'] + $stats['flagged_payouts']) ?></span><span class="text-xs text-faint"><?= number_format($stats['flagged']) ?> sign-ups · <?= number_format($stats['flagged_payouts']) ?> payouts</span></article>
  <article class="stat-card"><span class="stat-icon"><?= yolotask_nav_icon('sparkle') ?></span><span class="stat-label">Milestone bonuses</span><span class="stat-value"><?= h(yolotask_money($stats['bonus'])) ?></span><span class="text-xs text-faint">system:referral_bonus</span></article>
  <article class="stat-card"><span class="stat-icon"><?= yolotask_nav_icon('wallet') ?></span><span class="stat-label">Deposit commission</span><span class="stat-value"><?= h(yolotask_money($stats['commission'])) ?></span><span class="text-xs text-faint">system:referral_commission</span></article>
</section>

<div class="grid grid-2 dash-panels">
  <section class="card" id="settings" aria-labelledby="ref-settings-title">
    <div class="card-header"><h2 id="ref-settings-title">Programme settings</h2><span class="badge <?= $enabled ? 'badge-success' : 'badge-warning' ?>"><?= $enabled ? 'On' : 'Paused' ?></span></div>
    <form method="post" action="referrals.php" class="stack">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="settings">
      <label class="toggle-row"><input type="checkbox" name="enabled" value="1"<?= $enabled ? ' checked' : '' ?>><span><strong>Referral rewards on</strong><span class="text-xs text-faint">Off = no new referrals are recorded and nothing is paid. When switched back on, the catch-up pays what was earned meanwhile.</span></span></label>
      <div class="field"><label class="label" for="commission_percent">Advertiser deposit commission (%)</label>
        <input class="input" id="commission_percent" name="commission_percent" inputmode="decimal" value="<?= h(rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.')) ?>" required>
        <span class="text-xs text-faint">0 to 50. Paid on every confirmed AfrStar top-up by a referred advertiser, for life. Admin wallet credits never earn commission.</span></div>
      <div class="field"><label class="label" for="ip_allowance">Sign-ups allowed per network or device</label>
        <input class="input" id="ip_allowance" name="ip_allowance" type="number" inputmode="numeric" min="1" max="<?= Referrals::MAX_IP_ALLOWANCE ?>" value="<?= $ipAllowance ?>" required>
        <span class="text-xs text-faint">Per referrer. This many referred sign-ups from one IP address (the referrer's own network included) or one device are accepted and paid as usual; the next one is flagged for review. A sign-up on the referrer's own device is always flagged.</span></div>
      <button class="btn btn-primary" type="submit">Save settings</button>
    </form>
    <form method="post" action="referrals.php" style="margin-top:12px"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="sweep"><button class="btn btn-ghost btn-sm" type="submit">Run payout catch-up now</button><span class="text-xs text-faint"> Also runs hourly.</span></form>
  </section>

  <section class="card" id="milestones" aria-labelledby="ms-title">
    <div class="card-header"><h2 id="ms-title">Earner milestones</h2><span class="badge badge-info"><?= count($milestones) ?></span></div>
    <p class="text-sm text-faint">When an invited earner reaches each count of approved tasks, their referrer is paid that reward. Milestones add up; each is paid once per referee.</p>
    <ul class="user-list">
      <?php foreach ($milestones as $m): ?>
        <li class="user-row">
          <form method="post" action="referrals.php" class="inline-form milestone-form">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="milestone_save"><input type="hidden" name="id" value="<?= $m['id'] ?>">
            <label class="mini-field"><span>Approved tasks</span><input class="input" name="task_count" inputmode="numeric" value="<?= $m['task_count'] ?>" required></label>
            <label class="mini-field"><span>Reward ₦</span><input class="input" name="reward" inputmode="decimal" value="<?= h($naira($m['reward_kobo'])) ?>" required></label>
            <label class="toggle-inline"><input type="checkbox" name="active" value="1"<?= $m['is_active'] ? ' checked' : '' ?>> Active</label>
            <button class="btn btn-secondary btn-sm" type="submit">Save</button>
          </form>
          <span class="text-xs text-faint"><?= number_format($m['task_count']) ?> tasks → <?= h(yolotask_money($m['reward_kobo'])) ?> · paid <?= number_format($m['payouts']) ?> time<?= $m['payouts'] === 1 ? '' : 's' ?><?= $m['is_active'] ? '' : ' · disabled' ?></span>
          <?php if ($m['payouts'] === 0): ?>
            <form method="post" action="referrals.php"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="milestone_delete"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit" data-confirm="Delete this milestone?">Delete</button></form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <form method="post" action="referrals.php" class="inline-form milestone-form" style="margin-top:12px">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="milestone_save"><input type="hidden" name="id" value="0"><input type="hidden" name="active" value="1">
      <input class="input" name="task_count" inputmode="numeric" placeholder="Approved tasks" aria-label="Approved tasks for the new milestone" required>
      <input class="input" name="reward" inputmode="decimal" placeholder="Reward ₦" aria-label="Reward in naira for the new milestone" required>
      <button class="btn btn-primary btn-sm" type="submit">Add milestone</button>
    </form>
  </section>
</div>

<section class="card" id="cashback" aria-labelledby="cashback-title">
  <div class="card-header"><h2 id="cashback-title">Advertiser deposit cashback</h2><span class="badge <?= $cbBps > 0 ? 'badge-success' : 'badge-warning' ?>"><?= $cbBps > 0 ? h(Referrals::percent($cbBps)) . ' on' : 'Off' ?></span></div>
  <p class="text-sm text-faint">Every confirmed AfrStar top-up pays the advertiser this percentage of the amount credited, rounded down to the kobo, into their wallet balance (Dr system:deposit_cashback). Admin wallet credits, adjustments and bonuses never earn cashback, and cashback never earns referral commission. Paid so far: <?= number_format($cbStats['paid_count']) ?> top-up<?= $cbStats['paid_count'] === 1 ? '' : 's' ?>, <?= h(yolotask_money($cbStats['paid_kobo'])) ?>.</p>
  <form method="post" action="referrals.php" class="stack">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="cashback_settings">
    <div class="field"><label class="label" for="cashback_percent">Cashback on deposits (%)</label>
      <input class="input" id="cashback_percent" name="cashback_percent" inputmode="decimal" value="<?= h(rtrim(rtrim(number_format($cbBps / 100, 2, '.', ''), '0'), '.')) ?>" required>
      <span class="text-xs text-faint">0 to 50 (up to two decimals). 0 switches cashback off and hides the offer on the top-up page. Applies to deposits confirmed after you save.</span></div>
    <div class="field"><label class="label" for="cashback_min">Minimum deposit for cashback (₦)</label>
      <input class="input" id="cashback_min" name="cashback_min" inputmode="decimal" value="<?= h($naira($cbMin)) ?>" required>
      <span class="text-xs text-faint">Top-ups below this get no cashback. 0 = no minimum.</span></div>
    <button class="btn btn-primary" type="submit">Save cashback</button>
  </form>
  <?php if ($cashbacks['rows'] !== []): ?>
    <ul class="user-list" style="margin-top:12px">
      <?php foreach ($cashbacks['rows'] as $c): $cbPaid = (string) $c['status'] === 'paid'; ?>
        <li class="user-row">
          <div class="user-meta">
            <strong>#<?= (int) $c['id'] ?> · <?= h(yolotask_money($c['amount_kobo'])) ?> to <?= h((string) $c['display_name']) ?></strong>
            <span class="text-xs text-faint text-break">Cashback <?= h(Referrals::percent((int) $c['rate_bps'])) ?> of <?= h(yolotask_money($c['base_kobo'])) ?> top-up <?= h((string) $c['order_sn']) ?> · <?= h((string) $c['email']) ?> · <?= h(yolotask_wat((string) $c['created_at'])) ?></span>
            <span class="user-tags"><span class="badge <?= $cbPaid ? 'badge-success' : 'badge-danger' ?>"><?= h(ucfirst((string) $c['status'])) ?></span></span>
            <?php if (!$cbPaid && $c['reverse_reason']): ?><span class="text-xs text-faint">Reversed: <?= h((string) $c['reverse_reason']) ?></span><?php endif; ?>
            <?php if ($cbPaid): ?>
              <details class="danger-details" style="margin-top:8px">
                <summary class="btn btn-ghost btn-sm">Reverse cashback</summary>
                <form method="post" action="referrals.php" class="stack">
                  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="cashback_reverse"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <input class="input" name="reason" minlength="5" maxlength="255" required placeholder="Reason (required, kept in the ledger)" aria-label="Reason">
                  <button class="btn btn-secondary btn-sm" type="submit" data-confirm="Take this cashback back from the advertiser's wallet?">Reverse in ledger</button>
                </form>
              </details>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?= yolotask_pager($cashbacks, static fn (int $pg): string => 'referrals.php?cpage=' . $pg . '#cashback') ?>
  <?php endif; ?>
</section>

<section class="card" id="flagged" aria-labelledby="flagged-title">
  <div class="card-header"><h2 id="flagged-title">Flagged sign-ups</h2><span class="badge <?= $flagged !== [] ? 'badge-warning' : 'badge-info' ?>"><?= count($flagged) ?></span></div>
  <p class="text-sm text-faint">Sign-ups past the allowance of <?= $ipAllowance ?> per network or device, and sign-ups on the referrer's own device. Nothing is paid until you approve.</p>
  <?php if ($flagged === []): ?>
    <div class="empty-state"><p class="text-faint">No referrals waiting for review.</p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($flagged as $r): ?>
        <li class="user-row">
          <div class="user-meta">
            <strong><?= h((string) $r['referee_name']) ?> <span class="text-faint">invited by</span> <?= h((string) $r['referrer_name']) ?></strong>
            <span class="text-xs text-faint text-break"><?= h((string) $r['referee_email']) ?> ← <?= h((string) $r['referrer_email']) ?> · <?= h(ucfirst((string) $r['referee_role'])) ?> · <?= h(yolotask_wat((string) $r['created_at'])) ?></span>
            <span class="badge badge-warning"><?= h((string) $r['flag_reason']) ?></span>
            <form method="post" action="referrals.php" class="inline-form" style="margin-top:8px">
              <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="referral_review"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input class="input" name="note" minlength="3" maxlength="255" required placeholder="Note (required)" aria-label="Review note">
              <button class="btn btn-secondary btn-sm" type="submit" name="decision" value="approve">Approve</button>
              <button class="btn btn-ghost btn-sm" type="submit" name="decision" value="reject" data-confirm="Reject this referral for good?">Reject</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="card" id="payouts" aria-labelledby="payouts-title">
  <div class="card-header"><h2 id="payouts-title">Referral payouts</h2><span class="badge badge-info"><?= number_format($payouts['total']) ?></span></div>
  <nav class="money-tabs" aria-label="Payout filter">
    <?php foreach (['all' => 'All', 'flagged' => 'Flagged', 'milestone' => 'Milestones', 'commission' => 'Commission', 'reversed' => 'Reversed'] as $k => $label): ?>
      <a href="referrals.php<?= $k === 'all' ? '' : '?f=' . $k ?>#payouts"<?= $filter === $k ? ' class="active" aria-current="page"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($payouts['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint">No payouts here.</p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($payouts['rows'] as $p): $isPaid = (string) $p['status'] === 'paid'; ?>
        <li class="user-row">
          <div class="user-meta">
            <strong>#<?= (int) $p['id'] ?> · <?= h(yolotask_money($p['amount_kobo'])) ?> to <?= h((string) $p['referrer_name']) ?></strong>
            <span class="text-xs text-faint text-break"><?= (string) $p['kind'] === 'milestone'
                ? 'Milestone: ' . h((string) $p['referee_name']) . ' reached ' . number_format((int) $p['milestone_task_count']) . ' approved tasks'
                : 'Commission ' . h(Referrals::percent((int) $p['rate_bps'])) . ' of ' . h(yolotask_money($p['base_kobo'])) . ' top-up ' . h((string) ($p['order_sn'] ?? '')) . ' by ' . h((string) $p['referee_name']) ?> · <?= h(yolotask_wat((string) $p['created_at'])) ?></span>
            <span class="user-tags">
              <span class="badge <?= $isPaid ? 'badge-success' : 'badge-danger' ?>"><?= h(ucfirst((string) $p['status'])) ?></span>
              <?php if ($p['flagged_at'] !== null && $isPaid): ?><span class="badge badge-warning">Flagged</span><?php endif; ?>
            </span>
            <?php if ($p['flag_note']): ?><span class="text-xs text-warning"><?= h((string) $p['flag_note']) ?></span><?php endif; ?>
            <?php if (!$isPaid && $p['reverse_reason']): ?><span class="text-xs text-faint">Reversed: <?= h((string) $p['reverse_reason']) ?></span><?php endif; ?>
            <?php if ($isPaid): ?>
              <details class="danger-details" style="margin-top:8px">
                <summary class="btn btn-ghost btn-sm">Reverse payout</summary>
                <form method="post" action="referrals.php<?= $filter !== 'all' ? '?f=' . $filter : '' ?>" class="stack">
                  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="payout_reverse"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                  <input class="input" name="reason" minlength="5" maxlength="255" required placeholder="Reason (required, kept in the ledger)" aria-label="Reason">
                  <button class="btn btn-secondary btn-sm" type="submit" data-confirm="Take this payout back from the referrer's wallet?">Reverse in ledger</button>
                </form>
              </details>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?= yolotask_pager($payouts, static fn (int $pg): string => 'referrals.php?' . http_build_query(array_filter(['f' => $filter !== 'all' ? $filter : null, 'page' => $pg])) . '#payouts') ?>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($admin, 'Growth', (string) ob_get_clean(), 'money');
