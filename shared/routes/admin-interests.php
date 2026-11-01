<?php
declare(strict_types=1);

use YoloTask\Campaigns\Interests;
use YoloTask\Core\Logger;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/growth-helpers.php';

/* Admin: the interest list used for earner profiles and premium-task targeting. */
[$pdo, $admin] = yolotask_admin_growth_boot('admin.interests_load_failed');
$adminId = (int) $admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    yolotask_admin_csrf_or_back('interests.php');
    $action = yolotask_post_str('action');
    $id = (int) yolotask_post_str('id');
    try {
        if ($action === 'add') {
            Interests::add($pdo, $adminId, yolotask_post_str('name'));
            yolotask_flash('Interest added. It is now offered to earners and advertisers.');
        } elseif ($action === 'save') {
            Interests::update($pdo, $adminId, $id, yolotask_post_str('name'), yolotask_post_str('active') === '1');
            yolotask_flash('Interest saved.');
        } elseif ($action === 'move') {
            Interests::move($pdo, $adminId, $id, yolotask_post_str('dir'));
            yolotask_flash('Order updated.');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.interests_action_failed', ['action' => $action, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect('interests.php' . ($id > 0 ? '#interest-' . $id : ''));
}

$rows = Interests::all($pdo);
$activeCount = count(array_filter($rows, static fn (array $r): bool => $r['is_active'] === 1));
$noInterest = (int) $pdo->query("SELECT COUNT(*) FROM users u WHERE u.role = 'earner' AND u.status = 'active'
    AND NOT EXISTS (SELECT 1 FROM user_interests ui JOIN interests i ON i.id = ui.interest_id AND i.is_active = 1 WHERE ui.user_id = u.id)")->fetchColumn();
$csrf = Csrf::token();
$hidden = '<input type="hidden" name="_csrf" value="' . h($csrf) . '">';
ob_start();
?>
<?= yolotask_admin_growth_tabs($pdo, 'interests') ?>
<section class="card" aria-labelledby="in-title">
  <div class="card-header"><h2 id="in-title">Interests</h2><span class="badge badge-info"><?= $activeCount ?> active</span></div>
  <p class="text-sm text-faint">Earners pick <?= Interests::MIN ?> to <?= Interests::MAX ?> interests; every new campaign picks <?= Interests::MIN ?> to <?= Interests::MAX ?>. Premium tasks are shown only to earners who share at least one active interest with the campaign (and whose level allows premium). Campaigns with no active interest match every earner who has chosen interests. Earners without interests see no premium tasks: <strong><?= number_format($noInterest) ?></strong> active earner<?= $noInterest === 1 ? '' : 's' ?> have none yet.</p>
  <p class="text-xs text-faint">Disabling hides an interest from pickers and ignores it for matching; existing selections are kept and come back if you enable it again. Interests are never deleted.</p>
  <form method="post" action="interests.php" class="inline-form" style="margin-top:12px"><?= $hidden ?><input type="hidden" name="action" value="add">
    <input class="input" name="name" minlength="2" maxlength="40" required placeholder="New interest, e.g. Health & Fitness" aria-label="New interest name">
    <button class="btn btn-primary btn-sm" type="submit">Add interest</button>
  </form>
</section>
<section class="card" aria-label="Interest list">
  <ul class="user-list">
    <?php foreach ($rows as $i => $r): ?>
      <li class="user-row" id="interest-<?= $r['id'] ?>" data-interest="<?= $r['id'] ?>">
        <div class="user-meta">
          <form method="post" action="interests.php" class="inline-form"><?= $hidden ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $r['id'] ?>">
            <input class="input" name="name" minlength="2" maxlength="40" required value="<?= h($r['name']) ?>" aria-label="Interest name">
            <label class="toggle-inline"><input type="checkbox" name="active" value="1"<?= $r['is_active'] ? ' checked' : '' ?>> Active</label>
            <button class="btn btn-secondary btn-sm" type="submit">Save</button>
          </form>
          <span class="user-tags">
            <?php if (!$r['is_active']): ?><span class="badge badge-warning">Disabled</span><?php endif; ?>
            <span class="text-xs text-faint"><?= number_format($r['earners']) ?> earner<?= $r['earners'] === 1 ? '' : 's' ?> · <?= number_format($r['campaigns']) ?> campaign<?= $r['campaigns'] === 1 ? '' : 's' ?></span>
            <?php if ($r['is_active']): ?>
              <form method="post" action="interests.php"><?= $hidden ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit" name="dir" value="up" aria-label="Move <?= h($r['name']) ?> up"<?= $i === 0 ? ' disabled' : '' ?>>↑</button><button class="btn btn-ghost btn-sm" type="submit" name="dir" value="down" aria-label="Move <?= h($r['name']) ?> down"<?= $i === $activeCount - 1 ? ' disabled' : '' ?>>↓</button></form>
            <?php endif; ?>
          </span>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php
yolotask_render_app($admin, 'Growth', (string) ob_get_clean(), 'market');
