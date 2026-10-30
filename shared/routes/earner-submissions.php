<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Security\Csrf;
use YoloTask\Tasks\Proofs;
use YoloTask\Tasks\Submissions;
use YoloTask\Tasks\Tasks;

require_once __DIR__ . '/tasks-helpers.php';

/* Earner: my submissions (list) and one submission (?id=, with resubmit). */
if (yolotask_role() !== 'earner') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('submissions.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$uid = (int) $user['id'];
$detail = defined('YOLOTASK_SUBMISSION_DETAIL');

if ($detail) {
    $id = (int) ($_GET['id'] ?? $_POST['submission_id'] ?? 0);
    $back = 'submission.php?id=' . $id;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            yolotask_flash('The screenshot is too large. Crop it or save it smaller.', 'error');
            yolotask_redirect($back);
        }
        yolotask_csrf_or_back($back);
        try {
            Submissions::resubmit($pdo, $user, $id, $_FILES['proof'] ?? null, yolotask_post_str('comment'));
            yolotask_flash('Proof resubmitted. The advertiser will review it again.');
        } catch (RuntimeException $e) {
            yolotask_flash($e->getMessage(), 'error');
        } catch (Throwable $e) {
            Logger::error('submissions.resubmit_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
            yolotask_flash('Something went wrong. Please try again.', 'error');
        }
        yolotask_redirect($back);
    }
    $s = $id > 0 ? Submissions::findForEarner($pdo, $id, $uid) : null;
    if ($s === null) yolotask_error_page(404);
    $proofs = Proofs::forSubmission($pdo, $id);
    $canResubmit = Submissions::canResubmit($pdo, $s);
    $csrf = Csrf::token();
    ob_start();
    ?>
<div class="wallet-grid">
  <section class="card card-glow dash-hero" aria-labelledby="sub-title">
    <p class="text-xs fw-bold text-primary"><?= h(strtoupper((string) $s['platform_name'] . ' · ' . $s['task_type_name'])) ?></p>
    <h2 id="sub-title" class="text-break"><?= h((string) $s['campaign_title']) ?></h2>
    <div class="wallet-balance"><span class="amount"><?= h(yolotask_money($s['unit_reward_kobo'])) ?></span></div>
    <div class="wallet-sub"><?= yolotask_submission_badge($s) ?><span>Submitted <strong><?= h(yolotask_wat((string) $s['submitted_at'])) ?></strong></span></div>
  </section>
  <section class="card" aria-labelledby="st-title">
    <div class="card-header"><h2 id="st-title">Status</h2></div>
    <?php if ($s['status'] === 'pending'): ?>
      <p class="text-sm">Waiting for the advertiser. If they do not decide, it is approved automatically on <strong><?= h(yolotask_wat((string) $s['auto_approve_at'])) ?></strong>.</p>
    <?php elseif ($s['status'] === 'approved'): ?>
      <p class="text-sm">Approved <?= h(yolotask_wat((string) $s['decided_at'])) ?>. <?php if ($s['reward_status'] === 'held'): ?>Your reward is on a short hold and reaches your wallet on <strong><?= h(yolotask_wat((string) $s['reward_release_at'])) ?></strong>.<?php else: ?>Your reward is in your wallet.<?php endif; ?></p>
    <?php else: ?>
      <p class="text-sm">Not accepted <?= h(yolotask_wat((string) $s['decided_at'])) ?>.</p>
      <p class="text-sm"><strong>Reason:</strong> <?= h((string) $s['reject_reason']) ?></p>
    <?php endif; ?>
    <?php if ($s['comment']): ?><p class="text-sm text-faint text-break">Your note: <?= h((string) $s['comment']) ?></p><?php endif; ?>
  </section>
  <section class="card" aria-labelledby="pf-title">
    <div class="card-header"><h2 id="pf-title">Your proof</h2><span class="badge badge-info"><?= count($proofs) ?> file<?= count($proofs) === 1 ? '' : 's' ?></span></div>
    <div class="proof-grid">
      <?php foreach ($proofs as $p): ?><figure><?= yolotask_proof_thumb((int) $p['id']) ?><figcaption class="text-xs text-faint">Attempt <?= (int) $p['attempt'] ?></figcaption></figure><?php endforeach; ?>
    </div>
  </section>
  <?php if ($canResubmit): ?>
    <section class="card" aria-labelledby="rs-title">
      <div class="card-header"><h2 id="rs-title">Fix and resubmit</h2></div>
      <form method="post" action="submission.php?id=<?= (int) $s['id'] ?>" enctype="multipart/form-data" class="stack">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="submission_id" value="<?= (int) $s['id'] ?>">
        <div class="field"><label class="label" for="proof">New proof screenshot</label><input class="input" id="proof" name="proof" type="file" accept="image/jpeg,image/png,image/webp" required></div>
        <div class="field"><label class="label" for="comment">Note (optional)</label><textarea class="input" id="comment" name="comment" rows="2" maxlength="500"></textarea></div>
        <button class="btn btn-primary btn-block" type="submit">Resubmit proof</button>
      </form>
    </section>
  <?php endif; ?>
  <p class="text-center text-sm"><a href="submissions.php">All my submissions</a> · <a href="<?= h($s['campaign_status'] === 'live' || $s['campaign_status'] === 'paused' ? 'task.php?id=' . (int) $s['campaign_id'] : 'tasks.php') ?>">Task</a></p>
</div>
    <?php
    yolotask_render_app($user, 'Submission', (string) ob_get_clean(), 'submissions');
}

$status = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'all';
$page = max(1, (int) ($_GET['page'] ?? 1));
$list = Submissions::listForEarner($pdo, $uid, $status, $page);
$stats = Tasks::earnerStats($pdo, $uid);
$filters = ['all' => 'All', 'pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected'];
ob_start();
?>
<div class="wallet-grid">
  <section class="grid grid-4 dash-stats money-stats" aria-label="Summary">
    <article class="stat-card stat-success"><span class="stat-label">Earned</span><span class="stat-value"><?= h(yolotask_money($stats['earned'] ?? 0)) ?></span><span class="text-xs text-faint"><?= ($stats['on_hold'] ?? 0) > 0 ? h(yolotask_money($stats['on_hold'])) . ' on hold' : 'Paid to your wallet' ?></span></article>
    <article class="stat-card"><span class="stat-label">Approved</span><span class="stat-value"><?= (int) ($stats['approved'] ?? 0) ?></span><span class="text-xs text-faint"><?= (int) ($stats['rejected'] ?? 0) ?> rejected</span></article>
  </section>
  <section class="card" aria-labelledby="list-title">
    <div class="card-header"><h2 id="list-title">My submissions</h2><span class="badge badge-info"><?= number_format($list['total']) ?></span></div>
    <nav class="money-tabs" aria-label="Status filter" style="padding:0">
      <?php foreach ($filters as $k => $label): ?><a href="submissions.php?status=<?= h($k) ?>"<?= $k === $status || ($k === 'all' && !isset($filters[$status])) ? ' class="active"' : '' ?>><?= h($label) ?></a><?php endforeach; ?>
    </nav>
    <?php if ($list['rows'] === []): ?>
      <div class="empty-state"><p class="text-faint">Nothing here yet.</p><a class="btn btn-primary" href="tasks.php">Find tasks</a></div>
    <?php else: ?>
      <ul class="tx-list">
        <?php foreach ($list['rows'] as $s): ?>
          <li class="tx-row">
            <span class="tx-icon"><?= yolotask_nav_icon('tasks') ?></span>
            <div class="tx-meta"><strong><a href="submission.php?id=<?= (int) $s['id'] ?>"><?= h((string) $s['campaign_title']) ?></a></strong>
              <span class="text-xs text-faint"><?= h((string) $s['platform_name']) ?> · <?= h((string) $s['task_type_name']) ?> · <?= h(yolotask_wat((string) $s['submitted_at'])) ?></span></div>
            <div class="tx-amount"><span class="<?= $s['status'] === 'approved' ? 'tx-in' : 'tx-out' ?>"><?= h(yolotask_money($s['unit_reward_kobo'])) ?></span><?= yolotask_submission_badge($s) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?= yolotask_pager($list, static fn (int $p): string => 'submissions.php?' . http_build_query(['status' => $status, 'page' => $p])) ?>
    <?php endif; ?>
  </section>
</div>
<?php
yolotask_render_app($user, 'My work', (string) ob_get_clean(), 'submissions');
