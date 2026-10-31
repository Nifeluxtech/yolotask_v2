<?php
declare(strict_types=1);

use YoloTask\Campaigns\CampaignMembers;
use YoloTask\Campaigns\Campaigns;
use YoloTask\Campaigns\Instructions;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Csrf;
use YoloTask\Tasks\Approvals;
use YoloTask\Tasks\Submissions;

require_once __DIR__ . '/tasks-helpers.php';

/* Admin: campaign approval queue and all campaigns (list), and one campaign (campaign.php?id=). */
if (yolotask_role() !== 'admin') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $admin = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('admin.campaigns_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$adminId = (int) $admin['id'];
$detail = defined('YOLOTASK_CAMPAIGN_DETAIL');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['campaign_id'] ?? 0);
    $backParam = yolotask_post_str('back');
    $back = str_starts_with($backParam, 'campaign.php?') || str_starts_with($backParam, 'campaigns.php') ? $backParam : 'campaigns.php';
    yolotask_admin_csrf_or_back($back);
    $action = yolotask_post_str('action');
    try {
        switch ($action) {
            case 'approve':
                Campaigns::adminApprove($pdo, $adminId, $id);
                yolotask_flash('Campaign approved and live.');
                break;
            case 'reject':
                $r = Campaigns::adminReject($pdo, $adminId, $id, yolotask_post_str('reason'));
                yolotask_flash('Campaign rejected; ' . yolotask_money($r) . ' refunded to the advertiser.');
                break;
            case 'cancel':
                $r = Campaigns::adminCancel($pdo, $adminId, $id, yolotask_post_str('reason'));
                yolotask_flash('Campaign cancelled; ' . yolotask_money($r) . ' refunded to the advertiser.');
                break;
            case 'approve_submission':
                Approvals::approve($pdo, (int) ($_POST['submission_id'] ?? 0), 'admin', $adminId);
                yolotask_flash('Submission approved and the earner paid.');
                break;
            case 'reject_submission':
                Approvals::reject($pdo, (int) ($_POST['submission_id'] ?? 0), 'admin', $adminId, yolotask_post_str('reason'));
                yolotask_flash('Submission rejected.');
                break;
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.campaign_action_failed', ['action' => $action, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$csrf = Csrf::token();
$reviewForms = static function (array $c, string $back) use ($csrf): string {
    $hidden = '<input type="hidden" name="_csrf" value="' . h($csrf) . '"><input type="hidden" name="campaign_id" value="' . (int) $c['id'] . '"><input type="hidden" name="back" value="' . h($back) . '">';
    if ($c['status'] === 'pending_approval') {
        return '<div class="review-actions"><form method="post" action="campaigns.php">' . $hidden . '<button class="btn btn-primary btn-sm btn-block" type="submit" name="action" value="approve">Approve and go live</button></form>'
            . '<form method="post" action="campaigns.php" class="inline-form">' . $hidden . '<input class="input" name="reason" minlength="3" maxlength="255" required placeholder="Reason shown to the advertiser" aria-label="Reject reason">'
            . '<button class="btn btn-danger-outline btn-sm" type="submit" name="action" value="reject">Reject and refund</button></form></div>';
    }
    if (in_array((string) $c['status'], ['live', 'paused'], true)) {
        return '<details class="danger-details"><summary class="btn btn-ghost btn-sm">Stop this campaign</summary><form method="post" action="campaigns.php" class="inline-form">' . $hidden
            . '<input class="input" name="reason" minlength="3" maxlength="255" required placeholder="Reason" aria-label="Cancel reason"><button class="btn btn-danger-outline btn-sm" type="submit" name="action" value="cancel">Cancel and refund</button></form></details>';
    }
    return '';
};

if ($detail) {
    $id = (int) ($_GET['id'] ?? 0);
    $c = $id > 0 ? Campaigns::find($pdo, $id) : null;
    if ($c === null) yolotask_error_page(404);
    $tab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : 'all';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $subs = Submissions::listForCampaign($pdo, $id, $tab, $page);
    $members = CampaignMembers::summary($pdo, $id);
    $events = Campaigns::fundingEvents($pdo, $id);
    $back = 'campaign.php?id=' . $id;
    $escrow = (int) $c['funded_kobo'] - (int) $c['spent_kobo'] - (int) $c['refunded_kobo'];
    ob_start();
    ?>
<?= yolotask_admin_market_tabs($pdo, 'campaigns') ?>
<div class="wallet-grid">
  <section class="card card-glow dash-hero" aria-labelledby="c-title">
    <p class="text-xs fw-bold text-primary"><?= h(strtoupper((string) $c['platform_name'] . ' · ' . $c['task_type_name'])) ?> <?= yolotask_tier_badge((string) $c['tier']) ?></p>
    <h2 id="c-title" class="text-break"><?= h((string) $c['title']) ?></h2>
    <?php $quality = \YoloTask\Tasks\AdvertiserQuality::stats($pdo, (int) $c['advertiser_user_id']); ?>
    <div class="wallet-sub"><?= yolotask_campaign_badge((string) $c['status']) ?><span>By <strong><?= h((string) $c['advertiser_name']) ?></strong> <span class="text-break"><?= h((string) $c['advertiser_email']) ?></span></span><?php if ($quality['flagged']): ?><a class="badge badge-danger" href="appeals.php?view=flags">Quality flag</a><?php endif; ?></div>
    <p class="text-xs text-faint">Appeals against this advertiser (<?= \YoloTask\Tasks\AdvertiserQuality::rules($pdo)['days'] ?> days): <?= (int) $quality['overturned'] ?> overturned of <?= (int) $quality['decided'] ?> decided · <a href="appeals.php?status=all&amp;advertiser=<?= (int) $c['advertiser_user_id'] ?>">view appeals</a><?= (int) $c['extra_approved_count'] > 0 ? ' · ' . (int) $c['extra_approved_count'] . ' paid over budget on appeal' : '' ?></p>
    <?= yolotask_progress((int) $c['approved_count'], (int) $c['pending_count'], (int) $c['quantity']) ?>
    <p class="text-sm text-break"><a href="<?= h((string) $c['link_url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= h((string) $c['link_url']) ?></a></p>
    <?php if ($c['status_reason']): ?><p class="text-sm text-faint">Status note: <?= h((string) $c['status_reason']) ?></p><?php endif; ?>
    <?= $reviewForms($c, $back) ?>
  </section>
  <section class="grid grid-4 dash-stats money-stats" aria-label="Numbers">
    <article class="stat-card"><span class="stat-label">Budget</span><span class="stat-value"><?= h(yolotask_money($c['budget_kobo'])) ?></span><span class="text-xs text-faint"><?= number_format((int) $c['quantity']) ?> × <?= h(yolotask_money($c['unit_price_kobo'])) ?> (earner <?= h(yolotask_money($c['unit_reward_kobo'])) ?>)</span></article>
    <article class="stat-card"><span class="stat-label">Escrow</span><span class="stat-value"><?= h(yolotask_money($escrow)) ?></span><span class="text-xs text-faint">Spent <?= h(yolotask_money($c['spent_kobo'])) ?> · refunded <?= h(yolotask_money($c['refunded_kobo'])) ?></span></article>
    <article class="stat-card stat-success"><span class="stat-label">Approved</span><span class="stat-value"><?= (int) $members['approved'] ?></span><span class="text-xs text-faint"><?= (int) $members['auto_approved'] ?> automatically</span></article>
    <article class="stat-card stat-warning"><span class="stat-label">Pending</span><span class="stat-value"><?= (int) $members['pending'] ?></span><span class="text-xs text-faint"><?= (int) $members['working'] ?> working · <?= (int) $members['rejected'] ?> rejected</span></article>
  </section>
  <section class="card" aria-labelledby="i-title">
    <div class="card-header"><h2 id="i-title">Instructions</h2><span class="badge badge-info">v<?= (int) $c['instructions_version'] ?></span></div>
    <div class="instructions text-break"><?= nl2br(h((string) $c['instructions'])) ?></div>
    <p class="text-sm text-faint text-break">Proof: <?= h((string) $c['proof_requirements']) ?></p>
    <p class="text-xs text-faint">Auto-approve after <?= (int) $c['auto_approve_hours'] ?> h<?= $c['ends_at'] ? ' · ends ' . h(yolotask_wat((string) $c['ends_at'])) : '' ?> · created <?= h(yolotask_wat((string) $c['created_at'])) ?></p>
  </section>
  <section class="card" aria-labelledby="s-title">
    <div class="card-header"><h2 id="s-title">Submissions</h2><span class="badge badge-info"><?= number_format($subs['total']) ?></span></div>
    <?php if ($subs['rows'] === []): ?><div class="empty-state"><p class="text-faint">No submissions.</p></div><?php else: ?>
      <ul class="user-list">
        <?php foreach ($subs['rows'] as $s): ?>
          <li class="user-row submission-row"><?= yolotask_proof_thumb($s['latest_proof_id'] !== null ? (int) $s['latest_proof_id'] : null) ?>
            <div class="user-meta"><strong>#<?= (int) $s['id'] ?> · <?= h((string) $s['earner_name']) ?></strong><span class="text-xs text-faint text-break"><?= h((string) $s['earner_email']) ?> · <?= h(yolotask_wat((string) $s['submitted_at'])) ?></span>
              <span class="user-tags"><?= yolotask_submission_badge($s) ?></span>
              <?php if ($s['comment']): ?><span class="text-sm text-break">“<?= h((string) $s['comment']) ?>”</span><?php endif; ?>
              <?php if ($s['reject_reason']): ?><span class="text-xs text-faint">Reason: <?= h((string) $s['reject_reason']) ?></span><?php endif; ?>
            </div></li>
        <?php endforeach; ?>
      </ul>
      <?= yolotask_pager($subs, static fn (int $p): string => 'campaign.php?' . http_build_query(['id' => $id, 'tab' => $tab, 'page' => $p])) ?>
    <?php endif; ?>
  </section>
  <?php if ($events !== []): ?>
  <section class="card" aria-labelledby="f-title"><div class="card-header"><h2 id="f-title">Funding</h2></div>
    <ul class="tx-list"><?php foreach ($events as $e): ?><li class="tx-row"><div class="tx-meta"><strong><?= $e['event'] === 'refund' ? 'Refund' : 'Escrow lock' ?></strong><span class="text-xs text-faint"><?= h(yolotask_wat((string) $e['created_at'])) ?> · <?= h((string) $e['note']) ?></span></div><div class="tx-amount"><span><?= h(yolotask_money($e['amount_kobo'])) ?></span></div></li><?php endforeach; ?></ul></section>
  <?php endif; ?>
  <p class="text-center text-sm"><a href="campaigns.php">All campaigns</a></p>
</div>
    <?php
    yolotask_render_app($admin, 'Campaign #' . $id, (string) ob_get_clean(), 'market');
}

$status = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'pending_approval';
$q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$list = Campaigns::listForAdmin($pdo, $status, $q, $page);
$counts = Campaigns::adminCounts($pdo);
$query = static fn (array $over): string => '?' . http_build_query(array_filter(array_merge(['status' => $status, 'q' => $q, 'page' => $page], $over), static fn ($v) => $v !== '' && $v !== null));
$filters = ['pending_approval' => 'Approval queue', 'live' => 'Live', 'paused' => 'Paused', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'ended' => 'Ended', 'rejected' => 'Rejected', 'draft' => 'Drafts', 'all' => 'All'];
ob_start();
?>
<?= yolotask_admin_market_tabs($pdo, 'campaigns') ?>
<section class="card" aria-labelledby="ac-title">
  <div class="card-header"><h2 id="ac-title">Campaigns</h2><span class="badge <?= $counts['pending_approval'] > 0 ? 'badge-warning' : 'badge-info' ?>"><?= (int) $counts['pending_approval'] ?> awaiting approval</span></div>
  <form method="get" action="campaigns.php" class="inline-form" style="margin-bottom:.75rem">
    <input type="hidden" name="status" value="<?= h($status) ?>">
    <input class="input" type="search" name="q" value="<?= h($q) ?>" placeholder="Title, advertiser email or link" aria-label="Search campaigns">
    <button class="btn btn-secondary btn-sm" type="submit">Search</button>
  </form>
  <nav class="money-tabs" aria-label="Status filter" style="padding:0">
    <?php foreach ($filters as $k => $label): ?><a href="campaigns.php?status=<?= h($k) ?>"<?= $k === $status || ($k === 'all' && !isset($filters[$status])) ? ' class="active"' : '' ?>><?= h($label) ?><?= isset($counts[$k]) && $k !== 'all' && $counts[$k] > 0 ? ' <span class="badge badge-info">' . (int) $counts[$k] . '</span>' : '' ?></a><?php endforeach; ?>
  </nav>
  <?php if ($list['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint"><?= $status === 'pending_approval' ? 'No campaigns waiting for approval.' : 'No campaigns here.' ?></p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($list['rows'] as $c): ?>
        <li class="user-row" data-campaign="<?= (int) $c['id'] ?>">
          <div class="user-meta">
            <strong><a href="campaign.php?id=<?= (int) $c['id'] ?>">#<?= (int) $c['id'] ?> · <?= h((string) $c['title']) ?></a></strong>
            <span class="text-xs text-faint text-break"><?= h((string) $c['platform_name']) ?> · <?= h((string) $c['task_type_name']) ?> · <?= number_format((int) $c['quantity']) ?> × <?= h(yolotask_money($c['unit_price_kobo'])) ?> = <?= h(yolotask_money($c['budget_kobo'])) ?> · <?= h((string) $c['advertiser_email']) ?></span>
            <span class="text-xs text-faint text-break"><?= h((string) $c['link_url']) ?></span>
            <span class="user-tags"><?= yolotask_campaign_badge((string) $c['status']) ?><?= yolotask_tier_badge((string) $c['tier']) ?><span class="text-xs text-faint"><?= (int) $c['approved_count'] ?>/<?= (int) $c['quantity'] ?> done<?= $c['submitted_at'] ? ' · submitted ' . h(yolotask_wat((string) $c['submitted_at'])) : '' ?></span></span>
            <?= $reviewForms($c, 'campaigns.php' . $query([])) ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?= yolotask_pager($list, static fn (int $p): string => 'campaigns.php' . $query(['page' => $p])) ?>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($admin, 'Campaigns', (string) ob_get_clean(), 'market');
