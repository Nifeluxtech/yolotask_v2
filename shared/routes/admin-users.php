<?php
declare(strict_types=1);

use YoloTask\Admin\Users;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Csrf;

if (yolotask_role() !== 'admin') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $admin = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('admin.users_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$adminId = (int) $admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'users.php' . (is_string($_POST['back'] ?? null) && str_starts_with($_POST['back'], '?') ? $_POST['back'] : '');
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        yolotask_flash('Your form expired. Please try again.', 'error');
        yolotask_redirect($back);
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'suspend' || $action === 'restore') {
            Users::setStatus($pdo, $adminId, (int) ($_POST['user_id'] ?? 0), $action === 'suspend' ? 'suspended' : 'active');
            yolotask_flash($action === 'suspend' ? 'Account suspended and signed out everywhere.' : 'Account restored.');
        } elseif ($action === 'invite') {
            Users::inviteStaff($pdo, $adminId, (string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['role'] ?? ''));
            yolotask_flash('Invitation sent. The link expires in 48 hours.');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.users_action_failed', ['action' => $action, 'exception' => get_class($e)]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$fRole = is_string($_GET['role'] ?? null) ? $_GET['role'] : '';
$fStatus = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = Users::search($pdo, mb_substr($q, 0, 100), $fRole, $fStatus, $page);
$counts = Users::counts($pdo);
$csrf = Csrf::token();
$query = static fn (array $over): string => '?' . http_build_query(array_filter(array_merge(['q' => $q, 'role' => $fRole, 'status' => $fStatus, 'page' => $page], $over), static fn ($v) => $v !== '' && $v !== null));
$statusBadge = ['active' => 'badge-success', 'suspended' => 'badge-warning', 'closed' => 'badge-danger'];
$opt = static fn (string $value, string $label, string $current): string => '<option value="' . h($value) . '"' . ($value === $current ? ' selected' : '') . '>' . h($label) . '</option>';
ob_start();
?>
<section class="grid grid-4 dash-stats" aria-label="User totals">
  <article class="stat-card"><span class="stat-icon"><?= yolotask_nav_icon('tasks') ?></span><span class="stat-label">Earners</span><span class="stat-value"><?= number_format($counts['earner']) ?></span><span class="text-xs text-faint"><?= number_format($counts['new_7d']) ?> new accounts this week</span></article>
  <article class="stat-card"><span class="stat-icon"><?= yolotask_nav_icon('megaphone') ?></span><span class="stat-label">Advertisers</span><span class="stat-value"><?= number_format($counts['advertiser']) ?></span><span class="text-xs text-faint">Active and suspended</span></article>
  <article class="stat-card stat-warning"><span class="stat-icon"><?= yolotask_nav_icon('bell') ?></span><span class="stat-label">Unverified</span><span class="stat-value"><?= number_format($counts['unverified']) ?></span><span class="text-xs text-faint">Email not confirmed</span></article>
  <article class="stat-card"><span class="stat-icon"><?= yolotask_nav_icon('shield') ?></span><span class="stat-label">Staff</span><span class="stat-value"><?= number_format($counts['reviewer'] + $counts['admin']) ?></span><span class="text-xs text-faint"><?= $counts['reviewer'] ?> reviewer<?= $counts['reviewer'] === 1 ? '' : 's' ?> · <?= $counts['admin'] ?> admin<?= $counts['admin'] === 1 ? '' : 's' ?></span></article>
</section>

<section class="card" aria-labelledby="users-title">
  <div class="card-header"><h2 id="users-title">Directory</h2><span class="badge badge-info"><?= number_format($result['total']) ?> found</span></div>
  <form method="get" action="users.php" class="filter-bar">
    <input class="input" type="search" name="q" value="<?= h($q) ?>" placeholder="Search name, email or phone" aria-label="Search users">
    <select class="input" name="role" aria-label="Role"><?= $opt('', 'All roles', $fRole) . $opt('earner', 'Earners', $fRole) . $opt('advertiser', 'Advertisers', $fRole) . $opt('reviewer', 'Reviewers', $fRole) . $opt('admin', 'Admins', $fRole) ?></select>
    <select class="input" name="status" aria-label="Status"><?= $opt('', 'Any status', $fStatus) . $opt('active', 'Active', $fStatus) . $opt('suspended', 'Suspended', $fStatus) . $opt('closed', 'Closed', $fStatus) ?></select>
    <button class="btn btn-primary" type="submit">Filter</button>
  </form>

  <?php if ($result['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint">No users match those filters.</p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($result['rows'] as $u): ?>
        <li class="user-row">
          <div class="user-meta">
            <strong><?= h((string) $u['display_name']) ?></strong>
            <span class="text-xs text-faint text-break"><?= h((string) $u['email']) ?><?= $u['phone'] ? ' · ' . h((string) $u['phone']) : '' ?></span>
            <span class="user-tags">
              <span class="badge badge-info"><?= h(ucfirst((string) $u['role'])) ?></span>
              <span class="badge <?= $statusBadge[(string) $u['status']] ?? 'badge-info' ?>"><?= h(ucfirst((string) $u['status'])) ?></span>
              <?php if (empty($u['email_verified_at'])): ?><span class="badge badge-warning">Unverified</span><?php endif; ?>
              <span class="text-xs text-faint">Joined <?= h(yolotask_wat((string) $u['created_at'], 'j M Y')) ?> · Last sign-in <?= h(yolotask_wat($u['last_login_at'] ? (string) $u['last_login_at'] : null, 'j M, g:i A')) ?></span>
            </span>
          </div>
          <?php if ((int) $u['id'] !== $adminId && (string) $u['status'] !== 'closed'): ?>
            <form method="post" action="users.php" class="user-action">
              <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="back" value="<?= h($query([])) ?>"><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
              <?php if ((string) $u['status'] === 'active'): ?>
                <button class="btn btn-danger-outline btn-sm" type="submit" name="action" value="suspend" data-confirm="Suspend this account and sign it out everywhere?">Suspend</button>
              <?php else: ?>
                <button class="btn btn-secondary btn-sm" type="submit" name="action" value="restore">Restore</button>
              <?php endif; ?>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($result['pages'] > 1): ?>
      <nav class="flex-between pager" aria-label="Pages">
        <?php if ($result['page'] > 1): ?><a class="btn btn-ghost btn-sm" href="users.php<?= h($query(['page' => $result['page'] - 1])) ?>">Previous</a><?php else: ?><span></span><?php endif; ?>
        <span class="text-xs text-faint">Page <?= $result['page'] ?> of <?= $result['pages'] ?></span>
        <?php if ($result['page'] < $result['pages']): ?><a class="btn btn-ghost btn-sm" href="users.php<?= h($query(['page' => $result['page'] + 1])) ?>">Next</a><?php else: ?><span></span><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section class="card" id="invite" aria-labelledby="invite-title">
  <div class="card-header"><h2 id="invite-title">Invite staff</h2><span class="badge badge-info">Invite only</span></div>
  <p class="text-sm text-faint">Staff get an email link to set their own password. You never see or choose it.</p>
  <form method="post" action="users.php" class="stack">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="invite">
    <div class="field"><label class="label" for="inv-name">Full name</label><input class="input" id="inv-name" name="name" maxlength="80" required></div>
    <div class="field"><label class="label" for="inv-email">Work email</label><input class="input" id="inv-email" name="email" type="email" maxlength="254" required></div>
    <div class="field"><label class="label" for="inv-role">Role</label><select class="input" id="inv-role" name="role"><option value="reviewer">Reviewer</option><option value="admin">Admin</option></select></div>
    <button class="btn btn-primary" type="submit">Send invitation</button>
  </form>
</section>
<?php
yolotask_render_app($admin, 'Users', (string) ob_get_clean(), 'users');
