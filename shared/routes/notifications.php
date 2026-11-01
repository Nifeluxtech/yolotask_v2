<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Messaging\Notifications;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/money-helpers.php';

$role = yolotask_role();
if (!in_array($role, ['earner', 'advertiser'], true)) yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('notifications.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$userId = (int) $user['id'];
$unreadOnly = ($_GET['show'] ?? '') === 'unread';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'notifications.php' . ($unreadOnly ? '?show=unread' : '');
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        yolotask_flash('Your form expired. Please try again.', 'error');
        yolotask_redirect($back);
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'open' || $action === 'read') {
            $url = Notifications::markRead($pdo, $userId, (int) ($_POST['id'] ?? 0));
            if ($url === null) {
                yolotask_flash('That notification was not found.', 'error');
                yolotask_redirect($back);
            }
            $safe = Notifications::safeUrl($url);
            yolotask_redirect($action === 'open' && $safe !== null ? $safe : $back);
        } elseif ($action === 'read_all') {
            $n = Notifications::markAllRead($pdo, $userId);
            yolotask_flash($n > 0 ? 'All notifications marked as read.' : 'You are all caught up.');
        } elseif ($action === 'settings') {
            $on = is_array($_POST['email'] ?? null) ? array_values(array_filter($_POST['email'], 'is_string')) : [];
            Notifications::savePreferences($pdo, $userId, $role, $on);
            yolotask_flash('Email settings saved.');
            $back = 'notifications.php#settings';
        }
    } catch (Throwable $e) {
        Logger::error('notifications.action_failed', ['action' => $action, 'exception' => get_class($e)]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$list = Notifications::list($pdo, $userId, $unreadOnly, $page);
$unread = Notifications::unreadCount($pdo, $userId);
$prefs = Notifications::preferences($pdo, $userId, $role);
$categories = Notifications::categoriesFor($role);
$csrf = Csrf::token();
$icon = static fn (string $cat): string => match ($cat) {
    'tasks' => 'tasks', 'appeals' => 'shield', 'payments' => 'wallet', 'campaigns' => 'megaphone', 'rewards' => 'sparkle', 'announcements' => 'megaphone', default => 'bell',
};
ob_start();
?>
<section class="card" aria-labelledby="inbox-title">
  <div class="card-header">
    <h2 id="inbox-title">Inbox</h2>
    <span class="badge <?= $unread > 0 ? 'badge-warning' : 'badge-info' ?>"><?= $unread > 0 ? number_format($unread) . ' unread' : 'All read' ?></span>
  </div>
  <div class="inline-form notif-toolbar">
    <nav class="money-tabs notif-tabs" aria-label="Filter">
      <a href="notifications.php"<?= !$unreadOnly ? ' class="active" aria-current="page"' : '' ?>>All</a>
      <a href="notifications.php?show=unread"<?= $unreadOnly ? ' class="active" aria-current="page"' : '' ?>>Unread<?= $unread > 0 ? ' <span class="badge badge-warning">' . (int) $unread . '</span>' : '' ?></a>
    </nav>
    <?php if ($unread > 0): ?>
      <form method="post" action="notifications.php<?= $unreadOnly ? '?show=unread' : '' ?>"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="read_all"><button class="btn btn-secondary btn-sm" type="submit">Mark all as read</button></form>
    <?php endif; ?>
  </div>
  <?php if ($list['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint"><?= $unreadOnly ? 'No unread notifications. You are all caught up.' : 'Nothing here yet. Approvals, payments, referral earnings and news from YOLOTASK will appear here.' ?></p></div>
  <?php else: ?>
    <ul class="notif-list">
      <?php foreach ($list['rows'] as $n): $isUnread = $n['read_at'] === null; $link = Notifications::safeUrl($n['url'] ?? null); ?>
        <li class="notif-row<?= $isUnread ? ' unread' : '' ?>">
          <span class="tx-icon"><?= yolotask_nav_icon($icon((string) $n['category'])) ?></span>
          <div class="notif-meta">
            <strong><?= h((string) $n['title']) ?></strong>
            <p class="text-sm text-faint"><?= h((string) $n['body']) ?></p>
            <span class="text-xs text-faint"><?= h(yolotask_wat((string) $n['created_at'])) ?> WAT · <?= h(Notifications::CATEGORIES[(string) $n['category']][0] ?? 'Update') ?></span>
          </div>
          <div class="notif-actions">
            <?php if ($link !== null && $link !== 'notifications.php'): ?>
              <form method="post" action="notifications.php"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="open"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Open</button></form>
            <?php endif; ?>
            <?php if ($isUnread): ?>
              <form method="post" action="notifications.php<?= $unreadOnly ? '?show=unread' : '' ?>"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Mark read</button></form>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?= yolotask_pager($list, static fn (int $p): string => 'notifications.php?' . http_build_query(array_filter(['show' => $unreadOnly ? 'unread' : null, 'page' => $p]))) ?>
  <?php endif; ?>
</section>

<section class="card" id="settings" aria-labelledby="settings-title">
  <div class="card-header"><h2 id="settings-title">Email settings</h2></div>
  <p class="text-sm text-faint">Everything always appears in your inbox here. Choose which kinds of update are also emailed to <?= h((string) $user['email']) ?>. Security emails (password changes, new sign-ins, bank details) are always sent.</p>
  <form method="post" action="notifications.php" class="stack">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="settings">
    <?php foreach ($categories as $key => [$label, $desc, , $emailable]): ?>
      <label class="toggle-row">
        <input type="checkbox" name="email[]" value="<?= h($key) ?>"<?= !empty($prefs[$key]) ? ' checked' : '' ?><?= $emailable ? '' : ' disabled' ?>>
        <span><strong><?= h($label) ?></strong><span class="text-xs text-faint"><?= h($desc) ?></span></span>
      </label>
    <?php endforeach; ?>
    <label class="toggle-row"><input type="checkbox" checked disabled><span><strong>Security</strong><span class="text-xs text-faint">Always on, to keep your account safe.</span></span></label>
    <button class="btn btn-primary" type="submit">Save email settings</button>
  </form>
</section>
<?php
yolotask_render_app($user, 'Notifications', (string) ob_get_clean(), 'notifications');
