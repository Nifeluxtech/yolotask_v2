<?php
declare(strict_types=1);

use YoloTask\Core\Logger;
use YoloTask\Messaging\Announcements;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/growth-helpers.php';

/* Admin: announcements (banner + inbox) for everyone, earners or advertisers. */
[$pdo, $admin] = yolotask_admin_growth_boot('admin.announcements_load_failed');
$adminId = (int) $admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    yolotask_admin_csrf_or_back('announcements.php');
    $action = yolotask_post_str('action');
    try {
        if ($action === 'create') {
            Announcements::create($pdo, $adminId, yolotask_post_str('title'), yolotask_post_str('body'), yolotask_post_str('audience'), yolotask_post_str('starts_at'), yolotask_post_str('ends_at'));
            yolotask_flash('Announcement saved. It shows as a banner while live and in each inbox once it starts.');
        } elseif ($action === 'withdraw') {
            Announcements::withdraw($pdo, $adminId, (int) yolotask_post_str('id'));
            yolotask_flash('Announcement withdrawn. The banner is gone; inbox copies stay.');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.announcements_action_failed', ['action' => $action, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect('announcements.php');
}

$rows = Announcements::recent($pdo);
$csrf = Csrf::token();
$badge = static fn (string $s): string => match ($s) { 'live' => 'badge-success', 'scheduled' => 'badge-info', default => 'badge-warning' };
ob_start();
?>
<?= yolotask_admin_growth_tabs($pdo, 'announcements') ?>
<section class="card" aria-labelledby="ann-new">
  <div class="card-header"><h2 id="ann-new">New announcement</h2></div>
  <form method="post" action="announcements.php" class="stack">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="create">
    <div class="field"><label class="label" for="ann-title">Title</label><input class="input" id="ann-title" name="title" maxlength="140" required></div>
    <div class="field"><label class="label" for="ann-body">Message</label><textarea class="input" id="ann-body" name="body" rows="3" maxlength="1000" required></textarea></div>
    <div class="grid grid-2 form-pair">
      <div class="field"><label class="label" for="ann-aud">Audience</label><select class="input" id="ann-aud" name="audience"><?php foreach (Announcements::AUDIENCES as $k => $label): ?><option value="<?= h($k) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label class="label" for="ann-start">Starts (Lagos time)</label><input class="input" id="ann-start" name="starts_at" type="datetime-local"><span class="text-xs text-faint">Empty = now.</span></div>
      <div class="field"><label class="label" for="ann-end">Ends (Lagos time)</label><input class="input" id="ann-end" name="ends_at" type="datetime-local"><span class="text-xs text-faint">Empty = until withdrawn.</span></div>
    </div>
    <p class="text-xs text-faint">Shown as a banner on every page of the audience's portal while live, and added once to each person's inbox when it starts. Announcements are not emailed.</p>
    <button class="btn btn-primary" type="submit">Publish announcement</button>
  </form>
</section>
<section class="card" aria-labelledby="ann-list">
  <div class="card-header"><h2 id="ann-list">Announcements</h2><span class="badge badge-info"><?= count($rows) ?></span></div>
  <?php if ($rows === []): ?>
    <div class="empty-state"><p class="text-faint">No announcements yet.</p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($rows as $a): ?>
        <li class="user-row">
          <div class="user-meta">
            <strong><?= h((string) $a['title']) ?></strong>
            <p class="text-sm text-faint"><?= h((string) $a['body']) ?></p>
            <span class="user-tags"><span class="badge <?= $badge((string) $a['state']) ?>"><?= h(ucfirst((string) $a['state'])) ?></span><span class="badge badge-info"><?= h(Announcements::AUDIENCES[(string) $a['audience']] ?? '') ?></span><span class="badge badge-info"><?= number_format((int) $a['inbox_count']) ?> inboxes</span></span>
            <span class="text-xs text-faint"><?= h(yolotask_wat((string) $a['starts_at'])) ?> → <?= $a['ends_at'] ? h(yolotask_wat((string) $a['ends_at'])) : 'until withdrawn' ?> (WAT)</span>
            <?php if (in_array((string) $a['state'], ['live', 'scheduled'], true)): ?>
              <form method="post" action="announcements.php"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="withdraw"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit" data-confirm="Withdraw this announcement?">Withdraw</button></form>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($admin, 'Growth', (string) ob_get_clean(), 'users');
