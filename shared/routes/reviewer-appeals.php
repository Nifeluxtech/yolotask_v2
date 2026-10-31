<?php
declare(strict_types=1);

use YoloTask\Campaigns\Instructions;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Csrf;
use YoloTask\Tasks\Appeals;
use YoloTask\Tasks\Proofs;

require_once __DIR__ . '/tasks-helpers.php';
require_once __DIR__ . '/review-helpers.php';

/* Reviewer: appeal queue (appeals.php) and one appeal (appeal.php?id=) with claim / uphold / overturn. */
if (yolotask_role() !== 'reviewer') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('reviewer.appeals_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$rid = (int) $user['id'];
$detail = defined('YOLOTASK_APPEAL_DETAIL');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aid = (int) ($_POST['appeal_id'] ?? 0);
    $back = $detail ? 'appeal.php?id=' . $aid : 'appeals.php';
    yolotask_csrf_or_back($back);
    $action = yolotask_post_str('action');
    try {
        switch ($action) {
            case 'claim':
                $exp = Appeals::claim($pdo, $user, $aid);
                yolotask_flash('Claimed until ' . yolotask_wat($exp, 'g:i A') . '. Other reviewers will not see it as available.');
                $back = 'appeal.php?id=' . $aid;
                break;
            case 'release':
                Appeals::release($pdo, $user, $aid);
                yolotask_flash('Released back to the queue.');
                break;
            case 'uphold':
            case 'overturn':
                Appeals::decide($pdo, $user, $aid, $action, yolotask_post_str('note'));
                yolotask_flash($action === 'overturn' ? 'Overturned. The earner has been paid and both sides were emailed.' : 'Rejection upheld. The earner was emailed.');
                $back = 'appeals.php';
                break;
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('reviewer.appeal_action_failed', ['action' => $action, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$csrf = Csrf::token();
$hidden = static fn (int $aid): string => '<input type="hidden" name="_csrf" value="' . h($csrf) . '"><input type="hidden" name="appeal_id" value="' . $aid . '">';

if ($detail) {
    $id = (int) ($_GET['id'] ?? 0);
    $a = $id > 0 ? Appeals::find($pdo, $id) : null;
    if ($a === null) yolotask_error_page(404);
    $proofs = Proofs::forSubmission($pdo, (int) $a['submission_id']);
    $version = Instructions::version($pdo, (int) $a['campaign_id'], (int) $a['instructions_version']);
    $mine = $a['claim_user_id'] !== null && (int) $a['claim_user_id'] === $rid;
    $taken = $a['claim_user_id'] !== null && !$mine;
    $ownDecision = (int) ($a['rejected_by_user_id'] ?? 0) === $rid;
    [$label, $class] = Appeals::statusLabel($a);
    ob_start();
    ?>
<?= yolotask_reviewer_tabs($pdo, 'appeals') ?>
<div class="wallet-grid" data-appeal="<?= (int) $a['id'] ?>">
  <section class="card card-glow dash-hero" aria-labelledby="ap-title">
    <p class="text-xs fw-bold text-primary"><?= h(strtoupper((string) $a['platform_name'] . ' · ' . $a['task_type_name'])) ?> · APPEAL #<?= (int) $a['id'] ?></p>
    <h2 id="ap-title" class="text-break"><?= h((string) $a['campaign_title']) ?></h2>
    <div class="wallet-sub"><span class="badge <?= h($class) ?>"><?= h($label) ?></span><span>Filed <strong><?= h(yolotask_wat((string) $a['created_at'])) ?></strong> · earner <?= h((string) $a['earner_name']) ?> · reward <?= h(yolotask_money($a['unit_reward_kobo'])) ?></span></div>
    <p class="text-sm text-break"><a href="<?= h((string) $a['link_url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= h((string) $a['link_url']) ?></a></p>
  </section>
  <section class="card" aria-labelledby="in-title">
    <div class="card-header"><h2 id="in-title">Instructions the earner worked from</h2><span class="badge badge-info">Version <?= (int) $a['instructions_version'] ?></span></div>
    <?php if ($version !== null): ?>
      <div class="instructions text-break"><?= nl2br(h((string) $version['instructions'])) ?></div>
      <p class="text-sm text-faint text-break">Proof required: <?= h((string) $version['proof_requirements']) ?></p>
    <?php else: ?><p class="text-sm text-faint">That version is not on record.</p><?php endif; ?>
    <?php if ((int) $a['current_version'] !== (int) $a['instructions_version']): ?><p class="text-xs text-faint">The advertiser has since changed the instructions (now version <?= (int) $a['current_version'] ?>). Judge against the version above.</p><?php endif; ?>
  </section>
  <section class="card" aria-labelledby="pf-title">
    <div class="card-header"><h2 id="pf-title">Proof</h2><span class="badge badge-info"><?= count($proofs) ?> file<?= count($proofs) === 1 ? '' : 's' ?></span></div>
    <div class="proof-grid">
      <?php foreach ($proofs as $p): ?><figure><?= yolotask_proof_thumb((int) $p['id']) ?><figcaption class="text-xs text-faint"><?= (int) $p['is_appeal'] === 1 ? 'Added with the appeal' : 'Attempt ' . (int) $p['attempt'] ?></figcaption></figure><?php endforeach; ?>
    </div>
    <?php if ($a['earner_note']): ?><p class="text-sm text-break">Earner's note with the proof: “<?= h((string) $a['earner_note']) ?>”</p><?php endif; ?>
  </section>
  <section class="card" aria-labelledby="rj-title">
    <div class="card-header"><h2 id="rj-title">Rejection and appeal</h2></div>
    <p class="text-sm text-break"><strong>Rejected by <?= h(yolotask_rejection_source((string) $a['rejection_source'])) ?></strong> <?= h(yolotask_wat($a['rejected_at'] ? (string) $a['rejected_at'] : null)) ?>: <?= h((string) $a['rejection_reason']) ?></p>
    <p class="text-sm text-break"><strong>Earner's appeal:</strong> “<?= h((string) $a['message']) ?>”</p>
    <?php if ($a['status'] !== 'open'): ?>
      <p class="text-sm text-break"><strong>Decision</strong> by <?= h((string) $a['resolver_name']) ?> <?= h(yolotask_wat((string) $a['resolved_at'])) ?>: <?= h((string) $a['resolution_note']) ?></p>
      <?php if ((int) $a['admin_override'] === 1 && $a['override_from_status'] !== 'open'): ?><p class="text-sm text-break"><strong>Admin override</strong> by <?= h((string) $a['overrider_name']) ?>: <?= h((string) $a['override_note']) ?></p><?php endif; ?>
    <?php endif; ?>
  </section>
  <?php if ($a['status'] === 'open'): ?>
  <section class="card" id="decide" aria-labelledby="dc-title">
    <div class="card-header"><h2 id="dc-title">Decide</h2>
      <?php if ($mine): ?><span class="badge badge-success" data-claim="mine">Yours until <?= h(yolotask_wat((string) $a['claim_expires_at'], 'g:i A')) ?></span>
      <?php elseif ($taken): ?><span class="badge badge-warning" data-claim="taken"><?= h((string) $a['claim_reviewer_name']) ?> until <?= h(yolotask_wat((string) $a['claim_expires_at'], 'g:i A')) ?></span><?php endif; ?></div>
    <?php if ($ownDecision): ?>
      <p class="text-sm">You made the original decision on this proof, so another reviewer must handle the appeal.</p>
    <?php elseif ($mine): ?>
      <form method="post" action="appeal.php?id=<?= (int) $a['id'] ?>" class="stack"><?= $hidden((int) $a['id']) ?>
        <div class="field"><label class="label" for="note">Decision note (the earner sees this)</label><textarea class="input" id="note" name="note" rows="3" minlength="5" maxlength="500" required></textarea></div>
        <div class="review-actions">
          <button class="btn btn-primary btn-sm" type="submit" name="action" value="overturn" data-confirm="Overturn and pay the earner <?= h(yolotask_money($a['unit_reward_kobo'])) ?>?">Overturn and pay</button>
          <button class="btn btn-danger-outline btn-sm" type="submit" name="action" value="uphold">Uphold rejection</button>
        </div>
      </form>
      <form method="post" action="appeal.php?id=<?= (int) $a['id'] ?>"><?= $hidden((int) $a['id']) ?><button class="btn btn-ghost btn-sm" type="submit" name="action" value="release">Release to queue</button></form>
    <?php elseif ($taken): ?>
      <p class="text-sm">Another reviewer is working on this appeal. It returns to the queue if they release it or their claim expires.</p>
    <?php else: ?>
      <form method="post" action="appeal.php?id=<?= (int) $a['id'] ?>"><?= $hidden((int) $a['id']) ?><button class="btn btn-primary btn-block" type="submit" name="action" value="claim">Claim this appeal</button></form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <p class="text-center text-sm"><a href="appeals.php">Back to the appeal queue</a></p>
</div>
    <?php
    yolotask_render_app($user, 'Appeal #' . (int) $a['id'], (string) ob_get_clean(), 'appeals');
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$list = Appeals::queue($pdo, $page);
ob_start();
?>
<?= yolotask_reviewer_tabs($pdo, 'appeals') ?>
<section class="card" aria-labelledby="q-title">
  <div class="card-header"><h2 id="q-title">Open appeals</h2><span class="badge badge-info"><?= number_format($list['total']) ?></span></div>
  <p class="text-sm text-faint">Oldest first. Claim an appeal to lock it for <?= \YoloTask\Tasks\ReviewClaims::minutes($pdo) ?> minutes while you decide.</p>
  <?php if ($list['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint">No open appeals. Nice work.</p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($list['rows'] as $a): $mine = $a['claim_user_id'] !== null && (int) $a['claim_user_id'] === $rid; ?>
        <li class="user-row submission-row" data-appeal="<?= (int) $a['id'] ?>">
          <?= yolotask_proof_thumb($a['latest_proof_id'] !== null ? (int) $a['latest_proof_id'] : null) ?>
          <div class="user-meta">
            <strong><a href="appeal.php?id=<?= (int) $a['id'] ?>">#<?= (int) $a['id'] ?> · <?= h((string) $a['campaign_title']) ?></a></strong>
            <span class="text-xs text-faint"><?= h((string) $a['platform_name']) ?> <?= h((string) $a['task_type_name']) ?> · filed <?= h(yolotask_wat((string) $a['created_at'])) ?> · rejected by <?= h(yolotask_rejection_source((string) $a['rejection_source'])) ?></span>
            <span class="text-sm text-break">Reason: <?= h((string) $a['rejection_reason']) ?></span>
            <span class="user-tags">
              <?php if ($mine): ?><span class="badge badge-success">Claimed by you</span>
              <?php elseif ($a['claim_user_id'] !== null): ?><span class="badge badge-warning">Claimed by <?= h((string) $a['claim_reviewer_name']) ?></span>
              <?php else: ?><span class="badge badge-info">Available</span><?php endif; ?>
            </span>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?= yolotask_pager($list, static fn (int $p): string => 'appeals.php?page=' . $p) ?>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($user, 'Appeals', (string) ob_get_clean(), 'appeals');
