<?php
declare(strict_types=1);

use YoloTask\Campaigns\CampaignMembers;
use YoloTask\Campaigns\Campaigns;
use YoloTask\Campaigns\Instructions;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Money\Wallet;
use YoloTask\Security\Csrf;
use YoloTask\Tasks\Appeals;
use YoloTask\Tasks\Approvals;
use YoloTask\Tasks\Submissions;

require_once __DIR__ . '/tasks-helpers.php';

/* Advertiser: one campaign — fund, pause/resume/cancel, instructions, review submissions. */
if (yolotask_role() !== 'advertiser') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('campaign.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$uid = (int) $user['id'];
$id = (int) ($_GET['id'] ?? $_POST['campaign_id'] ?? 0);
try {
    $c = Campaigns::ownedBy($pdo, $id, $uid);
} catch (RuntimeException) {
    yolotask_error_page(404);
}
$tab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : 'pending';
$back = 'campaign.php?id=' . $id . ($tab !== 'pending' ? '&tab=' . rawurlencode($tab) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    yolotask_csrf_or_back($back);
    $action = yolotask_post_str('action');
    $sid = (int) ($_POST['submission_id'] ?? 0);
    try {
        switch ($action) {
            case 'submit':
                Campaigns::submit($pdo, $user, $id);
                yolotask_flash('Campaign funded and sent for approval. It goes live as soon as YOLOTASK approves it.');
                break;
            case 'delete':
                Campaigns::deleteDraft($pdo, $user, $id);
                yolotask_flash('Draft deleted.');
                yolotask_redirect('campaigns.php');
            case 'pause':
                Campaigns::pause($pdo, $user, $id);
                yolotask_flash('Campaign paused. No new earners can start it until you resume.');
                break;
            case 'resume':
                Campaigns::resume($pdo, $user, $id);
                yolotask_flash('Campaign is live again.');
                break;
            case 'cancel':
                $refund = Campaigns::cancel($pdo, $user, $id);
                yolotask_flash('Campaign cancelled. ' . yolotask_money($refund) . ' returned to your wallet' . ((int) Campaigns::find($pdo, $id)['pending_count'] > 0 ? '; proofs still waiting for review stay reserved until you decide them.' : '.'));
                break;
            case 'instructions':
                Campaigns::updateInstructions($pdo, $user, $id, yolotask_post_str('instructions'), yolotask_post_str('proof_requirements'));
                yolotask_flash('Instructions updated. Earners who start from now see the new version.');
                break;
            case 'approve':
                Approvals::approve($pdo, $sid, 'advertiser', $uid, $uid);
                yolotask_flash('Approved. The earner has been paid.');
                break;
            case 'reject':
                Approvals::reject($pdo, $sid, 'advertiser', $uid, yolotask_post_str('reason'), $uid);
                yolotask_flash('Rejected. The slot is open again.');
                break;
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('campaign.action_failed', ['action' => $action, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$members = CampaignMembers::summary($pdo, $id);
$page = max(1, (int) ($_GET['page'] ?? 1));
$subs = in_array((string) $c['status'], ['draft'], true) ? ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1] : Submissions::listForCampaign($pdo, $id, $tab, $page);
$wallet = Wallet::forUser($pdo, $uid);
$events = Campaigns::fundingEvents($pdo, $id);
$history = Instructions::history($pdo, $id);
$appeals = Appeals::forCampaign($pdo, $id);
$escrow = (int) $c['funded_kobo'] - (int) $c['spent_kobo'] - (int) $c['refunded_kobo'];
$open = in_array((string) $c['status'], Campaigns::OPEN, true);
$csrf = Csrf::token();
$hidden = '<input type="hidden" name="_csrf" value="' . h($csrf) . '"><input type="hidden" name="campaign_id" value="' . $id . '">';
$tabs = ['pending' => 'To review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'];
ob_start();
?>
<div class="wallet-grid">
  <section class="card card-glow dash-hero" aria-labelledby="c-title">
    <p class="text-xs fw-bold text-primary"><?= h(strtoupper((string) $c['platform_name'] . ' · ' . $c['task_type_name'])) ?> <?= yolotask_tier_badge((string) $c['tier']) ?></p>
    <h2 id="c-title" class="text-break"><?= h((string) $c['title']) ?></h2>
    <div class="wallet-sub"><?= yolotask_campaign_badge((string) $c['status']) ?><span><strong><?= number_format((int) $c['approved_count']) ?></strong> of <?= number_format((int) $c['quantity']) ?> done</span></div>
    <?= yolotask_progress((int) $c['approved_count'], (int) $c['pending_count'], (int) $c['quantity']) ?>
    <?php if ($c['status_reason'] && in_array((string) $c['status'], ['rejected', 'cancelled', 'ended'], true)): ?><p class="text-sm text-faint">Reason: <?= h((string) $c['status_reason']) ?></p><?php endif; ?>
    <p class="text-sm text-break"><a href="<?= h((string) $c['link_url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= h((string) $c['link_url']) ?></a></p>
    <div class="hero-actions">
      <?php if ($c['status'] === 'draft'): ?>
        <form method="post" action="<?= h($back) ?>"><?= $hidden ?><button class="btn btn-primary btn-sm" type="submit" name="action" value="submit"<?= $wallet['available_kobo'] < (int) $c['budget_kobo'] ? ' disabled' : '' ?> data-confirm="Pay <?= h(yolotask_money($c['budget_kobo'])) ?> from your wallet into escrow and send for approval?">Pay <?= h(yolotask_money($c['budget_kobo'])) ?> and submit</button></form>
        <a class="btn btn-secondary btn-sm" href="campaign-new.php?id=<?= $id ?>">Edit</a>
        <form method="post" action="<?= h($back) ?>"><?= $hidden ?><button class="btn btn-ghost btn-sm" type="submit" name="action" value="delete" data-confirm="Delete this draft?">Delete</button></form>
      <?php elseif ($c['status'] === 'live'): ?>
        <form method="post" action="<?= h($back) ?>"><?= $hidden ?><button class="btn btn-secondary btn-sm" type="submit" name="action" value="pause">Pause</button></form>
      <?php elseif ($c['status'] === 'paused'): ?>
        <form method="post" action="<?= h($back) ?>"><?= $hidden ?><button class="btn btn-primary btn-sm" type="submit" name="action" value="resume">Resume</button></form>
      <?php endif; ?>
      <?php if (in_array((string) $c['status'], ['live', 'paused'], true)): ?>
        <form method="post" action="<?= h($back) ?>"><?= $hidden ?><button class="btn btn-danger-outline btn-sm" type="submit" name="action" value="cancel" data-confirm="Cancel this campaign? Unused money returns to your wallet now.">Cancel campaign</button></form>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($c['status'] === 'draft' && $wallet['available_kobo'] < (int) $c['budget_kobo']): ?>
    <section class="card notice-card notice-warning" role="status"><span class="verify-icon"><?= yolotask_nav_icon('wallet') ?></span>
      <div><strong>Top up <?= h(yolotask_money((int) $c['budget_kobo'] - $wallet['available_kobo'])) ?> to fund this campaign</strong><p class="text-sm text-faint">Your wallet has <?= h(yolotask_money($wallet['available_kobo'])) ?>.</p></div>
      <a class="btn btn-primary btn-sm" href="topup.php">Top up</a></section>
  <?php elseif ($c['status'] === 'pending_approval'): ?>
    <section class="card notice-card" role="status"><span class="verify-icon"><?= yolotask_nav_icon('shield') ?></span>
      <div><strong>Waiting for approval</strong><p class="text-sm text-faint">The budget is safely in escrow. If the campaign is not approved, it all comes back to your wallet.</p></div></section>
  <?php endif; ?>

  <section class="grid grid-4 dash-stats money-stats" aria-label="Campaign numbers">
    <article class="stat-card"><span class="stat-label">Budget</span><span class="stat-value"><?= h(yolotask_money($c['budget_kobo'])) ?></span><span class="text-xs text-faint"><?= h(yolotask_money($c['unit_price_kobo'])) ?> per task</span></article>
    <article class="stat-card stat-success"><span class="stat-label">Spent</span><span class="stat-value"><?= h(yolotask_money($c['spent_kobo'])) ?></span><span class="text-xs text-faint"><?= number_format((int) $c['approved_count']) ?> approved</span></article>
    <article class="stat-card"><span class="stat-label">In escrow</span><span class="stat-value"><?= h(yolotask_money($escrow)) ?></span><span class="text-xs text-faint"><?= (int) $c['refunded_kobo'] > 0 ? h(yolotask_money($c['refunded_kobo'])) . ' refunded' : 'Unused money is refunded' ?></span></article>
    <article class="stat-card stat-warning"><span class="stat-label">Working now</span><span class="stat-value"><?= (int) $members['working'] ?></span><span class="text-xs text-faint"><?= (int) $members['started'] ?> started · <?= number_format(Campaigns::slotsLeft($c)) ?> slots free</span></article>
  </section>

  <?php if ($c['status'] !== 'draft'): ?>
  <section class="card" id="submissions" aria-labelledby="s-title">
    <div class="card-header"><h2 id="s-title">Submissions</h2><span class="badge <?= (int) $c['pending_count'] > 0 ? 'badge-warning' : 'badge-info' ?>"><?= (int) $c['pending_count'] ?> to review</span></div>
    <p class="text-xs text-faint">Proofs you do not decide are approved automatically after <?= (int) $c['auto_approve_hours'] ?> hours. <a href="campaign-settings.php">Change</a></p>
    <nav class="money-tabs" aria-label="Submission filter" style="padding:0">
      <?php foreach ($tabs as $k => $label): ?><a href="campaign.php?id=<?= $id ?>&amp;tab=<?= h($k) ?>#submissions"<?= $k === $tab || ($k === 'all' && !isset($tabs[$tab])) ? ' class="active"' : '' ?>><?= h($label) ?></a><?php endforeach; ?>
    </nav>
    <?php if ($subs['rows'] === []): ?>
      <div class="empty-state"><p class="text-faint">No submissions here.</p></div>
    <?php else: ?>
      <ul class="user-list">
        <?php foreach ($subs['rows'] as $s): ?>
          <li class="user-row submission-row" data-submission="<?= (int) $s['id'] ?>">
            <?= yolotask_proof_thumb($s['latest_proof_id'] !== null ? (int) $s['latest_proof_id'] : null, 'Proof from ' . CampaignMembers::publicName((string) $s['earner_name'])) ?>
            <div class="user-meta">
              <strong><?= h(CampaignMembers::publicName((string) $s['earner_name'])) ?> · #<?= (int) $s['id'] ?></strong>
              <span class="text-xs text-faint">Submitted <?= h(yolotask_wat((string) $s['submitted_at'])) ?><?= (int) $s['attempts'] > 1 ? ' · resubmission' : '' ?><?= $s['status'] === 'pending' ? ' · auto-approves in ' . h(yolotask_until((string) $s['auto_approve_at'])) : '' ?></span>
              <span class="user-tags"><?= yolotask_submission_badge($s) ?></span>
              <?php if ($s['comment']): ?><span class="text-sm text-break">“<?= h((string) $s['comment']) ?>”</span><?php endif; ?>
              <?php if ($s['status'] === 'rejected' && $s['reject_reason']): ?><span class="text-xs text-faint">Reason: <?= h((string) $s['reject_reason']) ?></span><?php endif; ?>
              <?php if ($s['status'] === 'pending'): ?>
                <div class="review-actions">
                  <form method="post" action="<?= h($back) ?>"><?= $hidden ?><input type="hidden" name="submission_id" value="<?= (int) $s['id'] ?>">
                    <button class="btn btn-primary btn-sm btn-block" type="submit" name="action" value="approve">Approve</button></form>
                  <form method="post" action="<?= h($back) ?>" class="inline-form"><?= $hidden ?><input type="hidden" name="submission_id" value="<?= (int) $s['id'] ?>">
                    <input class="input" name="reason" maxlength="255" minlength="3" required placeholder="Why it does not match" aria-label="Reject reason">
                    <button class="btn btn-danger-outline btn-sm" type="submit" name="action" value="reject">Reject</button></form>
                </div>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?= yolotask_pager($subs, static fn (int $p): string => 'campaign.php?' . http_build_query(['id' => $id, 'tab' => $tab, 'page' => $p]) . '#submissions') ?>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="card" aria-labelledby="i-title">
    <div class="card-header"><h2 id="i-title">Instructions</h2><span class="badge badge-info">Version <?= (int) $c['instructions_version'] ?></span></div>
    <?php if ($open): ?>
      <form method="post" action="<?= h($back) ?>" class="stack"><?= $hidden ?>
        <div class="field"><label class="label" for="instructions">What earners should do</label><textarea class="input" id="instructions" name="instructions" rows="4" required minlength="10" maxlength="2000"><?= h((string) $c['instructions']) ?></textarea></div>
        <div class="field"><label class="label" for="proof_requirements">Proof you need</label><input class="input" id="proof_requirements" name="proof_requirements" required minlength="5" maxlength="500" value="<?= h((string) $c['proof_requirements']) ?>"></div>
        <button class="btn btn-secondary" type="submit" name="action" value="instructions">Save new version</button>
      </form>
    <?php else: ?>
      <div class="instructions text-break"><?= nl2br(h((string) $c['instructions'])) ?></div>
      <p class="text-sm text-faint text-break">Proof: <?= h((string) $c['proof_requirements']) ?></p>
    <?php endif; ?>
    <?php if (count($history) > 1): ?><details class="danger-details"><summary class="text-sm">Earlier versions (<?= count($history) - 1 ?>)</summary>
      <?php foreach (array_slice($history, 1) as $v): ?><p class="text-xs text-faint text-break">v<?= (int) $v['version'] ?> · <?= h(yolotask_wat((string) $v['created_at'])) ?>: <?= h(mb_strimwidth((string) $v['instructions'], 0, 200, '…')) ?></p><?php endforeach; ?></details><?php endif; ?>
  </section>

  <?php if ($appeals !== []): ?>
  <section class="card" id="appeals" aria-labelledby="ap-title">
    <div class="card-header"><h2 id="ap-title">Appeals</h2><span class="badge badge-info"><?= count($appeals) ?></span></div>
    <p class="text-sm text-faint">Earners can ask a YOLOTASK reviewer to check a rejected proof. Overturned rejections are paid from this campaign's escrow, or from your wallet when the budget is already used.</p>
    <ul class="tx-list">
      <?php foreach ($appeals as $ap): [$apLabel, $apClass] = Appeals::statusLabel($ap); ?>
        <li class="tx-row" data-appeal="<?= (int) $ap['id'] ?>" data-appeal-status="<?= h((string) $ap['status']) ?>"><span class="tx-icon"><?= yolotask_nav_icon('shield') ?></span>
          <div class="tx-meta"><strong><?= h(CampaignMembers::publicName((string) $ap['earner_name'])) ?> · submission #<?= (int) $ap['submission_id'] ?></strong>
            <span class="text-xs text-faint text-break">Your reason: <?= h((string) $ap['rejection_reason']) ?> · appealed <?= h(yolotask_wat((string) $ap['created_at'])) ?></span>
            <?php if ($ap['status'] !== 'open'): ?><span class="text-xs text-break">Reviewer: <?= h((string) ((int) $ap['admin_override'] === 1 && $ap['override_note'] ? $ap['override_note'] : $ap['resolution_note'])) ?></span><?php endif; ?>
            <?php if ($ap['status'] === 'overturned' && ((int) $ap['wallet_kobo'] > 0 || (int) $ap['platform_kobo'] > 0)): ?><span class="text-xs text-faint">Budget already used: <?= (int) $ap['wallet_kobo'] > 0 ? h(yolotask_money($ap['wallet_kobo'])) . ' charged from your wallet' : '' ?><?= (int) $ap['wallet_kobo'] > 0 && (int) $ap['platform_kobo'] > 0 ? '; ' : '' ?><?= (int) $ap['platform_kobo'] > 0 ? h(yolotask_money($ap['platform_kobo'])) . ' paid by YOLOTASK and owed by you' : '' ?></span><?php endif; ?></div>
          <div class="tx-amount"><span class="badge <?= h($apClass) ?>"><?= h($apLabel) ?></span></div></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php if ($events !== []): ?>
  <section class="card" aria-labelledby="f-title">
    <div class="card-header"><h2 id="f-title">Funding</h2></div>
    <ul class="tx-list">
      <?php foreach ($events as $e): $in = $e['event'] === 'refund'; ?>
        <li class="tx-row"><span class="tx-icon"><?= yolotask_nav_icon($in ? 'refund' : 'wallet') ?></span>
          <div class="tx-meta"><strong><?= $in ? 'Refunded' : ($e['event'] === 'appeal_topup' ? 'Added for an appeal (over budget)' : 'Locked in escrow') ?></strong><span class="text-xs text-faint"><?= h(yolotask_wat((string) $e['created_at'])) ?><?= $e['note'] ? ' · ' . h((string) $e['note']) : '' ?></span></div>
          <div class="tx-amount"><span class="<?= $in ? 'tx-in' : 'tx-out' ?>"><?= $in ? '+' : '−' ?><?= h(yolotask_money($e['amount_kobo'])) ?></span></div></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
  <p class="text-center text-sm"><a href="campaigns.php">All campaigns</a></p>
</div>
<?php
yolotask_render_app($user, 'Campaign', (string) ob_get_clean(), 'campaigns');
