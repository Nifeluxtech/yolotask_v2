<?php
declare(strict_types=1);

use YoloTask\Campaigns\Campaigns;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Security\Csrf;
use YoloTask\Tasks\Submissions;
use YoloTask\Tasks\Tasks;

require_once __DIR__ . '/tasks-helpers.php';

if (yolotask_role() !== 'earner') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('task.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$uid = (int) $user['id'];
$id = (int) ($_GET['id'] ?? $_POST['campaign_id'] ?? 0);
$back = 'task.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A file larger than post_max_size empties $_POST entirely, CSRF token included.
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        yolotask_flash('The screenshot is too large. Crop it or save it smaller.', 'error');
        yolotask_redirect($back);
    }
    yolotask_csrf_or_back($back);
    $action = yolotask_post_str('action');
    try {
        if ($action === 'start') {
            Tasks::start($pdo, $user, $id);
            yolotask_flash('Task started. Your slot is held for ' . Settings::int($pdo, 'tasks.reservation_minutes') . ' minutes.');
        } elseif ($action === 'release') {
            Tasks::release($pdo, $user, $id);
            yolotask_flash('Task released. The slot is open for someone else.');
            yolotask_redirect('tasks.php');
        } elseif ($action === 'submit') {
            $sid = Submissions::submit($pdo, $user, $id, $_FILES['proof'] ?? null, yolotask_post_str('comment'));
            yolotask_flash('Proof submitted. You will be paid as soon as it is approved.');
            yolotask_redirect('submission.php?id=' . $sid);
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('task.action_failed', ['action' => $action, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$c = $id > 0 ? Campaigns::find($pdo, $id) : null;
$submission = $c !== null ? Submissions::forEarnerCampaign($pdo, $id, $uid) : null;
// Earners only see campaigns that are (or were, for their own work) open to them.
if ($c === null || ($submission === null && !in_array((string) $c['status'], ['live', 'paused'], true)) || (int) $c['advertiser_user_id'] === $uid) yolotask_error_page(404);
$res = Tasks::reservation($pdo, $id, $uid);
$working = $res !== null && (string) $res['status'] === 'active' && (int) $res['still_valid'] === 1 && in_array((string) $c['status'], ['live', 'paused'], true);
$left = Campaigns::slotsLeft($c);
$canStart = !$working && $submission === null && (string) $c['status'] === 'live' && $left > 0 && !empty($user['email_verified_at']);
$maxMb = round(Settings::int($pdo, 'tasks.proof_max_bytes') / 1048576, 1);
$csrf = Csrf::token();
ob_start();
?>
<div class="wallet-grid">
  <section class="card card-glow dash-hero" aria-labelledby="task-title">
    <p class="text-xs fw-bold text-primary"><?= h(strtoupper((string) $c['platform_name'] . ' · ' . $c['task_type_name'])) ?></p>
    <h2 id="task-title" class="text-break"><?= h((string) $c['title']) ?></h2>
    <div class="wallet-balance"><span class="amount">+<?= h(yolotask_money($c['unit_reward_kobo'])) ?></span></div>
    <div class="wallet-sub">
      <span>Slots left <strong><?= number_format($left) ?></strong></span>
      <?php if ($c['ends_at']): ?><span>Ends <strong><?= h(yolotask_wat((string) $c['ends_at'], 'j M Y')) ?></strong></span><?php endif; ?>
      <?= yolotask_tier_badge((string) $c['tier']) ?>
    </div>
  </section>

  <?php if ($submission !== null): ?>
    <section class="card notice-card" role="status">
      <span class="verify-icon"><?= yolotask_nav_icon('inbox') ?></span>
      <div><strong>You submitted this task</strong><p class="text-sm text-faint">Submitted <?= h(yolotask_wat((string) $submission['submitted_at'])) ?>.</p></div>
      <?= yolotask_submission_badge($submission) ?>
    </section>
    <p class="text-center"><a class="btn btn-primary" href="submission.php?id=<?= (int) $submission['id'] ?>">View my submission</a></p>
  <?php endif; ?>

  <section class="card" aria-labelledby="ins-title">
    <div class="card-header"><h2 id="ins-title">What to do</h2><span class="badge badge-info">v<?= (int) $c['instructions_version'] ?></span></div>
    <div class="instructions text-break"><?= nl2br(h((string) $c['instructions'])) ?></div>
    <h3 class="text-sm fw-bold" style="margin-top:1rem">Proof needed</h3>
    <p class="text-sm text-break"><?= h((string) $c['proof_requirements']) ?></p>
  </section>

  <?php if ($working): ?>
    <section class="card" aria-labelledby="do-title">
      <div class="card-header"><h2 id="do-title">Your slot</h2><span class="badge badge-warning">Submit within <?= h(yolotask_until((string) $res['expires_at'])) ?></span></div>
      <ol class="steps-list text-sm">
        <li>Open the link and do the task with your own account.</li>
        <li>Take a screenshot that shows the proof described above.</li>
        <li>Upload it here before your slot expires.</li>
      </ol>
      <p><a class="btn btn-secondary btn-block" href="<?= h((string) $c['link_url']) ?>" target="_blank" rel="noopener noreferrer nofollow">Open <?= h((string) $c['platform_name']) ?> link</a></p>
      <form method="post" action="task.php?id=<?= (int) $id ?>" enctype="multipart/form-data" class="stack" data-proof-form>
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="campaign_id" value="<?= (int) $id ?>"><input type="hidden" name="action" value="submit">
        <div class="field">
          <label class="label" for="proof">Proof screenshot</label>
          <input class="input" id="proof" name="proof" type="file" accept="image/jpeg,image/png,image/webp" required>
          <p class="input-hint">JPEG, PNG or WebP, up to <?= h((string) $maxMb) ?> MB. Only you, the advertiser and YOLOTASK staff can see it.</p>
        </div>
        <div class="field">
          <label class="label" for="comment">Note for the advertiser (optional)</label>
          <textarea class="input" id="comment" name="comment" rows="2" maxlength="500" placeholder="For example, the username you used"></textarea>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Submit proof</button>
      </form>
      <form method="post" action="task.php?id=<?= (int) $id ?>" style="margin-top:.75rem">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="campaign_id" value="<?= (int) $id ?>">
        <button class="btn btn-ghost btn-sm btn-block" type="submit" name="action" value="release" data-confirm="Give up this task? Someone else can take the slot.">Release this task</button>
      </form>
    </section>
  <?php elseif ($submission === null): ?>
    <section class="card" aria-labelledby="start-title">
      <div class="card-header"><h2 id="start-title">Start this task</h2></div>
      <?php if ((string) $c['status'] !== 'live'): ?>
        <p class="text-faint">This campaign is paused. Check back later.</p>
      <?php elseif ($left <= 0): ?>
        <p class="text-faint">All slots are taken right now. Slots free up when other earners release or run out of time.</p>
      <?php elseif (empty($user['email_verified_at'])): ?>
        <p class="text-faint">Confirm your email to start earning. <a href="verify.php">Confirm now</a></p>
      <?php else: ?>
        <p class="text-sm text-faint">Starting holds a slot for you for <?= Settings::int($pdo, 'tasks.reservation_minutes') ?> minutes. The link opens once you start.</p>
      <?php endif; ?>
      <form method="post" action="task.php?id=<?= (int) $id ?>">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="campaign_id" value="<?= (int) $id ?>">
        <button class="btn btn-primary btn-block" type="submit" name="action" value="start"<?= $canStart ? '' : ' disabled' ?>>Start task</button>
      </form>
    </section>
  <?php endif; ?>
  <p class="text-center text-sm"><a href="tasks.php">Back to tasks</a></p>
</div>
<?php
yolotask_render_app($user, 'Task', (string) ob_get_clean(), 'tasks');
