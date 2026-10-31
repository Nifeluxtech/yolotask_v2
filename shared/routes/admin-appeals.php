<?php
declare(strict_types=1);

use YoloTask\Campaigns\Instructions;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Money\AdvertiserDebts;
use YoloTask\Security\Csrf;
use YoloTask\Tasks\AdvertiserQuality;
use YoloTask\Tasks\Appeals;
use YoloTask\Tasks\Proofs;

require_once __DIR__ . '/tasks-helpers.php';
require_once __DIR__ . '/review-helpers.php';

/*
 * Admin: every appeal (decide open ones, override reviewer decisions once —
 * final and audited), flagged advertisers and advertiser debts.
 * appeals.php[?status=&q=&advertiser=] · appeals.php?id= · appeals.php?view=flags · appeals.php?view=debts
 */
if (yolotask_role() !== 'admin') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $admin = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('admin.appeals_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$adminId = (int) $admin['id'];
$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'appeals';
$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $backParam = yolotask_post_str('back');
    $back = 'appeals.php' . (str_starts_with($backParam, '?') ? $backParam : '');
    yolotask_admin_csrf_or_back($back);
    $action = yolotask_post_str('action');
    try {
        switch ($action) {
            case 'uphold':
            case 'overturn':
                $aid = (int) ($_POST['appeal_id'] ?? 0);
                $a = Appeals::find($pdo, $aid);
                if ($a === null) throw new RuntimeException('Appeal not found.');
                if ((string) $a['status'] === 'open') Appeals::decide($pdo, $admin, $aid, $action, yolotask_post_str('note'));
                else Appeals::override($pdo, $admin, $aid, $action, yolotask_post_str('note'));
                yolotask_flash($action === 'overturn' ? 'Overturned. The earner has been paid. This decision is final.' : 'Rejection upheld. This decision is final.');
                break;
            case 'debt_collect':
                AdvertiserDebts::collect($pdo, $adminId, (int) ($_POST['debt_id'] ?? 0));
                yolotask_flash('Debt collected from the advertiser\'s wallet.');
                break;
            case 'debt_writeoff':
                AdvertiserDebts::writeOff($pdo, $adminId, (int) ($_POST['debt_id'] ?? 0), yolotask_post_str('note'));
                yolotask_flash('Debt written off.');
                break;
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.appeal_action_failed', ['action' => $action, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$csrf = Csrf::token();
$debts = AdvertiserDebts::openSummary($pdo);
$flags = AdvertiserQuality::flagged($pdo);
$subTabs = static function (string $active) use ($debts, $flags): string {
    $tabs = ['appeals' => ['All appeals', 'appeals.php', 0], 'flags' => ['Flagged advertisers', 'appeals.php?view=flags', count($flags)], 'debts' => ['Advertiser debts', 'appeals.php?view=debts', $debts['count']]];
    $html = '<nav class="money-tabs" aria-label="Appeal sections" style="padding:0;margin-bottom:.75rem">';
    foreach ($tabs as $k => [$label, $url, $n]) $html .= '<a href="' . h($url) . '"' . ($k === $active ? ' class="active"' : '') . '>' . h($label) . ($n > 0 ? ' <span class="badge badge-warning">' . (int) $n . '</span>' : '') . '</a>';
    return $html . '</nav>';
};

if ($id > 0) {
    $a = Appeals::find($pdo, $id);
    if ($a === null) yolotask_error_page(404);
    $proofs = Proofs::forSubmission($pdo, (int) $a['submission_id']);
    $version = Instructions::version($pdo, (int) $a['campaign_id'], (int) $a['instructions_version']);
    $hidden = '<input type="hidden" name="_csrf" value="' . h($csrf) . '"><input type="hidden" name="appeal_id" value="' . (int) $a['id'] . '"><input type="hidden" name="back" value="?id=' . (int) $a['id'] . '">';
    $final = (int) $a['admin_override'] === 1;
    ob_start();
    ?>
<?= yolotask_admin_market_tabs($pdo, 'appeals') ?>
<div class="wallet-grid" data-appeal="<?= (int) $a['id'] ?>">
  <section class="card card-glow dash-hero" aria-labelledby="ap-title">
    <p class="text-xs fw-bold text-primary"><?= h(strtoupper((string) $a['platform_name'] . ' · ' . $a['task_type_name'])) ?> · APPEAL #<?= (int) $a['id'] ?> · SUBMISSION #<?= (int) $a['submission_id'] ?></p>
    <h2 id="ap-title" class="text-break"><a href="campaign.php?id=<?= (int) $a['campaign_id'] ?>"><?= h((string) $a['campaign_title']) ?></a></h2>
    <div class="wallet-sub"><?= yolotask_appeal_badge($a) ?><span>Earner <strong><?= h((string) $a['earner_name']) ?></strong> <span class="text-break"><?= h((string) $a['earner_email']) ?></span> · advertiser <span class="text-break"><?= h((string) $a['advertiser_email']) ?></span></span></div>
    <p class="text-sm">Price <?= h(yolotask_money($a['unit_price_kobo'])) ?> · earner reward <?= h(yolotask_money($a['unit_reward_kobo'])) ?> · campaign <?= yolotask_campaign_badge((string) $a['campaign_status']) ?></p>
    <?php if ($a['status'] === 'overturned'): ?><p class="text-sm" data-funding><?= h(yolotask_appeal_funding($a)) ?></p><?php endif; ?>
  </section>
  <section class="card" aria-labelledby="in-title">
    <div class="card-header"><h2 id="in-title">Instructions used</h2><span class="badge badge-info">Version <?= (int) $a['instructions_version'] ?></span></div>
    <?php if ($version !== null): ?><div class="instructions text-break"><?= nl2br(h((string) $version['instructions'])) ?></div><p class="text-sm text-faint text-break">Proof required: <?= h((string) $version['proof_requirements']) ?></p><?php endif; ?>
  </section>
  <section class="card" aria-labelledby="pf-title">
    <div class="card-header"><h2 id="pf-title">Proof</h2></div>
    <div class="proof-grid"><?php foreach ($proofs as $p): ?><figure><?= yolotask_proof_thumb((int) $p['id']) ?><figcaption class="text-xs text-faint"><?= (int) $p['is_appeal'] === 1 ? 'Added with the appeal' : 'Attempt ' . (int) $p['attempt'] ?></figcaption></figure><?php endforeach; ?></div>
    <?php if ($a['earner_note']): ?><p class="text-sm text-break">Earner's note: “<?= h((string) $a['earner_note']) ?>”</p><?php endif; ?>
  </section>
  <section class="card" aria-labelledby="hs-title">
    <div class="card-header"><h2 id="hs-title">History</h2></div>
    <p class="text-sm text-break"><strong>Rejected by <?= h(yolotask_rejection_source((string) $a['rejection_source'])) ?><?= $a['rejecter_name'] ? ' (' . h((string) $a['rejecter_name']) . ')' : '' ?></strong> <?= h(yolotask_wat($a['rejected_at'] ? (string) $a['rejected_at'] : null)) ?>: <?= h((string) $a['rejection_reason']) ?></p>
    <p class="text-sm text-break"><strong>Appeal</strong> <?= h(yolotask_wat((string) $a['created_at'])) ?>: “<?= h((string) $a['message']) ?>”</p>
    <?php if ($a['status'] !== 'open' || $final): ?>
      <p class="text-sm text-break"><strong><?= $a['decided_by_role'] === 'admin' ? 'Admin decision' : 'Reviewer decision' ?></strong> by <?= h((string) $a['resolver_name']) ?> <?= h(yolotask_wat($a['resolved_at'] ? (string) $a['resolved_at'] : null)) ?>: <?= h((string) $a['resolution_note']) ?></p>
      <?php if ($final && $a['override_from_status'] !== 'open'): ?><p class="text-sm text-break"><strong>Admin override</strong> (<?= h((string) $a['override_from_status']) ?> → <?= h((string) $a['status']) ?>) by <?= h((string) $a['overrider_name']) ?> <?= h(yolotask_wat((string) $a['overridden_at'])) ?>: <?= h((string) $a['override_note']) ?></p><?php endif; ?>
    <?php elseif ($a['claim_user_id'] !== null): ?>
      <p class="text-sm">Claimed by <?= h((string) $a['claim_reviewer_name']) ?> until <?= h(yolotask_wat((string) $a['claim_expires_at'], 'g:i A')) ?>.</p>
    <?php endif; ?>
  </section>
  <?php if (!$final): ?>
  <section class="card" id="decide" aria-labelledby="dc-title">
    <div class="card-header"><h2 id="dc-title"><?= $a['status'] === 'open' ? 'Decide' : 'Override the reviewer' ?></h2><span class="badge badge-warning">Final</span></div>
    <p class="text-sm text-faint"><?= $a['status'] === 'open' ? 'An admin decision is final and cannot be overridden.' : ($a['status'] === 'overturned' ? 'Upholding reverses the payment: the reward comes back from the earner and any wallet or platform top-up is returned. Refused if the earner no longer has the reward.' : 'Overturning pays the earner through the normal approve path (escrow, then advertiser wallet, then platform).') ?> Both sides are emailed.</p>
    <form method="post" action="appeals.php?id=<?= (int) $a['id'] ?>" class="stack"><?= $hidden ?>
      <div class="field"><label class="label" for="note">Note (the earner sees this)</label><textarea class="input" id="note" name="note" rows="3" minlength="5" maxlength="500" required></textarea></div>
      <div class="review-actions">
        <?php if ($a['status'] !== 'overturned'): ?><button class="btn btn-primary btn-sm" type="submit" name="action" value="overturn">Overturn and pay</button><?php endif; ?>
        <?php if ($a['status'] !== 'upheld'): ?><button class="btn btn-danger-outline btn-sm" type="submit" name="action" value="uphold"><?= $a['status'] === 'overturned' ? 'Uphold rejection and reverse payment' : 'Uphold rejection' ?></button><?php endif; ?>
      </div>
    </form>
  </section>
  <?php endif; ?>
  <p class="text-center text-sm"><a href="appeals.php">All appeals</a></p>
</div>
    <?php
    yolotask_render_app($admin, 'Appeal #' . (int) $a['id'], (string) ob_get_clean(), 'market');
}

if ($view === 'flags') {
    $rules = AdvertiserQuality::rules($pdo);
    $owed = AdvertiserDebts::openTotals($pdo, array_map(static fn ($f) => (int) $f['advertiser_user_id'], $flags));
    ob_start();
    ?>
<?= yolotask_admin_market_tabs($pdo, 'appeals') ?>
<section class="card" aria-labelledby="fl-title">
  <?= $subTabs('flags') ?>
  <div class="card-header"><h2 id="fl-title">Flagged advertisers</h2><span class="badge badge-info"><?= count($flags) ?></span></div>
  <p class="text-sm text-faint">Advertisers with at least <?= $rules['min'] ?> rejections overturned on appeal and an overturn rate of <?= $rules['rate'] ?>% or more (of their appealed rejections decided) in the last <?= $rules['days'] ?> days. For your attention only; nothing happens automatically. Thresholds are in Task settings.</p>
  <?php if ($flags === []): ?>
    <div class="empty-state"><p class="text-faint">No advertiser is flagged.</p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($flags as $f): $aid = (int) $f['advertiser_user_id']; ?>
        <li class="user-row" data-flagged="<?= $aid ?>">
          <div class="user-meta"><strong><?= h((string) $f['display_name']) ?></strong><span class="text-xs text-faint text-break"><?= h((string) $f['email']) ?></span>
            <span class="user-tags"><span class="badge badge-danger">Quality flag</span><span class="text-xs"><?= (int) $f['overturned'] ?> overturned of <?= (int) $f['decided'] ?> decided appeals (<?= h(number_format((float) $f['rate'], 1)) ?>%)</span>
              <?php if (($owed[$aid] ?? 0) > 0): ?><span class="badge badge-warning">Owes <?= h(yolotask_money($owed[$aid])) ?></span><?php endif; ?>
              <a class="text-xs" href="appeals.php?status=all&advertiser=<?= $aid ?>">Their appeals</a></span></div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
    <?php
    yolotask_render_app($admin, 'Flagged advertisers', (string) ob_get_clean(), 'market');
}

if ($view === 'debts') {
    $dstatus = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'open';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $list = AdvertiserDebts::listForAdmin($pdo, $dstatus, $page);
    ob_start();
    ?>
<?= yolotask_admin_market_tabs($pdo, 'appeals') ?>
<section class="card" aria-labelledby="db-title">
  <?= $subTabs('debts') ?>
  <div class="card-header"><h2 id="db-title">Advertiser debts</h2><span class="badge badge-info"><?= h(yolotask_money($debts['total'])) ?> open</span></div>
  <p class="text-sm text-faint">When an appeal is overturned after a campaign's budget was used and the advertiser's wallet was short, YOLOTASK paid the earner and the advertiser owes the difference. Collect it once their wallet has funds, or write it off.</p>
  <nav class="money-tabs" aria-label="Debt status" style="padding:0">
    <?php foreach (['open' => 'Open', 'collected' => 'Collected', 'written_off' => 'Written off', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $label): ?><a href="appeals.php?view=debts&status=<?= h($k) ?>"<?= $k === $dstatus ? ' class="active"' : '' ?>><?= h($label) ?></a><?php endforeach; ?>
  </nav>
  <?php if ($list['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint">No debts here.</p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($list['rows'] as $d): ?>
        <li class="user-row" data-debt="<?= (int) $d['id'] ?>">
          <div class="user-meta"><strong>#<?= (int) $d['id'] ?> · <?= h(yolotask_money($d['amount_kobo'])) ?> · <?= h((string) $d['advertiser_name']) ?></strong>
            <span class="text-xs text-faint text-break"><?= h((string) $d['advertiser_email']) ?> · <a href="appeals.php?id=<?= (int) $d['appeal_id'] ?>">appeal #<?= (int) $d['appeal_id'] ?></a> · <?= h((string) $d['campaign_title']) ?> · <?= h(yolotask_wat((string) $d['created_at'])) ?> · wallet <?= h(yolotask_money($d['wallet_kobo'])) ?></span>
            <span class="user-tags"><span class="badge <?= $d['status'] === 'open' ? 'badge-warning' : 'badge-info' ?>"><?= h(ucfirst(str_replace('_', ' ', (string) $d['status']))) ?></span><?= $d['note'] ? '<span class="text-xs text-faint">' . h((string) $d['note']) . '</span>' : '' ?></span>
            <?php if ($d['status'] === 'open'): ?>
              <div class="review-actions">
                <form method="post" action="appeals.php?view=debts"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="back" value="?view=debts"><input type="hidden" name="debt_id" value="<?= (int) $d['id'] ?>"><button class="btn btn-primary btn-sm" type="submit" name="action" value="debt_collect">Collect from wallet</button></form>
                <form method="post" action="appeals.php?view=debts" class="inline-form"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="back" value="?view=debts"><input type="hidden" name="debt_id" value="<?= (int) $d['id'] ?>"><input class="input" name="note" minlength="5" maxlength="255" required placeholder="Reason" aria-label="Write-off reason"><button class="btn btn-danger-outline btn-sm" type="submit" name="action" value="debt_writeoff">Write off</button></form>
              </div>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?= yolotask_pager($list, static fn (int $p): string => 'appeals.php?' . http_build_query(['view' => 'debts', 'status' => $dstatus, 'page' => $p])) ?>
  <?php endif; ?>
</section>
    <?php
    yolotask_render_app($admin, 'Advertiser debts', (string) ob_get_clean(), 'market');
}

$status = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'open';
$q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$advFilter = (int) ($_GET['advertiser'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$list = Appeals::listForAdmin($pdo, $status, mb_substr($q, 0, 100), $page, $advFilter > 0 ? $advFilter : null);
$query = static fn (array $over): string => '?' . http_build_query(array_filter(array_merge(['status' => $status, 'q' => $q, 'advertiser' => $advFilter ?: '', 'page' => $page], $over), static fn ($v) => $v !== '' && $v !== null));
$filters = ['open' => 'Open', 'upheld' => 'Upheld', 'overturned' => 'Overturned', 'override' => 'Admin decisions', 'over_budget' => 'Over budget', 'all' => 'All'];
ob_start();
?>
<?= yolotask_admin_market_tabs($pdo, 'appeals') ?>
<section class="card" aria-labelledby="al-title">
  <?= $subTabs('appeals') ?>
  <div class="card-header"><h2 id="al-title">Appeals</h2><span class="badge badge-info"><?= number_format($list['total']) ?></span></div>
  <form method="get" action="appeals.php" class="inline-form" style="margin-bottom:.75rem">
    <input type="hidden" name="status" value="<?= h($status) ?>">
    <input class="input" type="search" name="q" value="<?= h($q) ?>" placeholder="Earner or advertiser email, campaign title" aria-label="Search appeals">
    <button class="btn btn-secondary btn-sm" type="submit">Search</button>
  </form>
  <nav class="money-tabs" aria-label="Status filter" style="padding:0">
    <?php foreach ($filters as $k => $label): ?><a href="appeals.php<?= h($query(['status' => $k, 'page' => null])) ?>"<?= $k === $status || ($k === 'all' && !isset($filters[$status])) ? ' class="active"' : '' ?>><?= h($label) ?></a><?php endforeach; ?>
  </nav>
  <?php if ($list['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint">No appeals here.</p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($list['rows'] as $a): ?>
        <li class="user-row submission-row" data-appeal="<?= (int) $a['id'] ?>">
          <?= yolotask_proof_thumb($a['latest_proof_id'] !== null ? (int) $a['latest_proof_id'] : null) ?>
          <div class="user-meta">
            <strong><a href="appeals.php?id=<?= (int) $a['id'] ?>">#<?= (int) $a['id'] ?> · <?= h((string) $a['campaign_title']) ?></a></strong>
            <span class="text-xs text-faint text-break"><?= h((string) $a['earner_email']) ?> → <?= h((string) $a['advertiser_email']) ?> · filed <?= h(yolotask_wat((string) $a['created_at'])) ?> · rejected by <?= h(yolotask_rejection_source((string) $a['rejection_source'])) ?></span>
            <span class="user-tags"><?= yolotask_appeal_badge($a) ?><?php if ($a['status'] === 'open' && $a['claim_user_id'] !== null): ?><span class="badge badge-info">Claimed by <?= h((string) $a['claim_reviewer_name']) ?></span><?php endif; ?><?php if ((int) $a['over_budget'] === 1): ?><span class="badge badge-warning">Over budget</span><?php endif; ?></span>
            <?php if ($a['status'] === 'overturned'): ?><span class="text-xs text-faint"><?= h(yolotask_appeal_funding($a)) ?></span><?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?= yolotask_pager($list, static fn (int $p): string => 'appeals.php' . $query(['page' => $p])) ?>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($admin, 'Appeals', (string) ob_get_clean(), 'market');
