<?php
declare(strict_types=1);

use YoloTask\Core\Clock;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Core\Maintenance;
use YoloTask\Core\Settings;
use YoloTask\Security\Audit;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/tasks-helpers.php';

/*
 * Admin: maintenance mode. ON: the main site and the earner, advertiser and
 * reviewer portals answer every request with the 503 maintenance page (the
 * message and optional "back by" time below). The admin portal, payment
 * callbacks and cron keep running. Default OFF; no migration (settings rows).
 */
if (yolotask_role() !== 'admin') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $admin = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('admin.maintenance_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$adminId = (int) $admin['id'];
$lagos = new DateTimeZone('Africa/Lagos');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    yolotask_admin_csrf_or_back('maintenance.php');
    $action = yolotask_post_str('action');
    if ($action === 'tester_create' || $action === 'tester_revoke') {
        // Tester link: only the sha256 of the token and its expiry are stored; the link is shown once.
        try {
            if ($action === 'tester_create') {
                $token = Maintenance::newTesterToken();
                $expires = Clock::now()->modify('+' . Maintenance::TESTER_DEFAULT_DAYS . ' days')->getTimestamp();
                Settings::save($pdo, $adminId, ['maintenance.preview_hash' => Maintenance::testerHash($token), 'maintenance.preview_expires_at' => (string) $expires]);
                Audit::record($pdo, $adminId, 'admin.maintenance_tester_created', 'settings', null);
                $_SESSION['_maintenance_tester_link'] = $token;
                yolotask_flash('Tester link created. Copy it now: it is shown only once. Any earlier tester link no longer works.', 'success');
            } else {
                Settings::save($pdo, $adminId, ['maintenance.preview_hash' => '', 'maintenance.preview_expires_at' => '0']);
                Audit::record($pdo, $adminId, 'admin.maintenance_tester_revoked', 'settings', null);
                yolotask_flash('Tester link revoked. Anyone using it now sees the maintenance page.', 'success');
            }
        } catch (Throwable $e) {
            Logger::error('admin.maintenance_tester_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
            yolotask_flash('Something went wrong. Please try again.', 'error');
        }
        yolotask_redirect('maintenance.php#tester');
    }
    try {
        $on = !empty($_POST['enabled']);
        $message = trim(str_replace("\r\n", "\n", yolotask_post_str('message')));
        if ($message === '') $message = Maintenance::DEFAULT_MESSAGE;
        if (mb_strlen($message) > Maintenance::MESSAGE_MAX) throw new RuntimeException('Keep the message to ' . Maintenance::MESSAGE_MAX . ' characters or fewer.');
        $untilRaw = yolotask_post_str('until');
        $until = '';
        if ($untilRaw !== '') {
            $dt = Maintenance::parseUntil($untilRaw);
            if ($dt === null) throw new RuntimeException('Enter the "back by" time as a date and time, or leave it empty.');
            $now = Clock::now()->setTimezone($lagos);
            if ($dt <= $now) throw new RuntimeException('The "back by" time must be in the future (Lagos time). Leave it empty to show no time.');
            if ($dt > $now->modify('+30 days')) throw new RuntimeException('The "back by" time must be within the next 30 days.');
            $until = $dt->format(Maintenance::UNTIL_FORMAT);
        }
        $was = Maintenance::state($pdo)['enabled'];
        Settings::save($pdo, $adminId, ['maintenance.enabled' => $on ? '1' : '0', 'maintenance.message' => $message, 'maintenance.until' => $until]);
        Audit::record($pdo, $adminId, $on ? ($was ? 'admin.maintenance_updated' : 'admin.maintenance_enabled') : ($was ? 'admin.maintenance_disabled' : 'admin.maintenance_updated'), 'settings', null);
        yolotask_flash($on
            ? 'Maintenance mode is ON. The main site, earner, advertiser and reviewer portals now show the maintenance page. The admin portal stays open.'
            : 'Maintenance mode is OFF. The site is open to everyone.', $on ? 'warning' : 'success');
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.maintenance_save_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect('maintenance.php');
}

try {
    $state = Maintenance::state($pdo);
    $row = $pdo->query("SELECT s.updated_at, u.display_name FROM settings s LEFT JOIN users u ON u.id = s.updated_by_user_id WHERE s.setting_key = 'maintenance.enabled'")->fetch() ?: null;
} catch (Throwable $e) {
    Logger::error('admin.maintenance_page_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
$on = $state['enabled'];
$untilValue = $state['until'] instanceof DateTimeImmutable ? $state['until']->setTimezone($lagos)->format('Y-m-d\TH:i') : '';
$untilPast = $state['until'] instanceof DateTimeImmutable && $state['until'] <= Clock::now();
$minValue = Clock::now()->setTimezone($lagos)->format('Y-m-d\TH:i');
$csrf = Csrf::token();
$testerToken = isset($_SESSION['_maintenance_tester_link']) && is_string($_SESSION['_maintenance_tester_link']) ? $_SESSION['_maintenance_tester_link'] : null;
unset($_SESSION['_maintenance_tester_link']);
$testerActive = $state['preview_hash'] !== '' && $state['preview_expires_at'] > time();
$testerExpired = $state['preview_hash'] !== '' && !$testerActive;
$testerExpires = $state['preview_expires_at'] > 0 ? (new DateTimeImmutable('@' . $state['preview_expires_at']))->setTimezone($lagos) : null;
$portals = [yolotask_portal_url('main'), yolotask_portal_url('earner'), yolotask_portal_url('advertiser'), yolotask_portal_url('reviewer')];
ob_start();
?>
<?= yolotask_admin_market_tabs($pdo, 'maintenance') ?>
<div class="wallet-grid">
  <section class="grid grid-4 dash-stats money-stats" aria-label="Maintenance overview">
    <article class="stat-card <?= $on ? 'stat-warning' : 'stat-success' ?>"><span class="stat-label">Maintenance mode</span><span class="stat-value" data-stat="maintenance"><?= $on ? 'ON' : 'OFF' ?></span><span class="text-xs text-faint"><?= $on ? 'Users see the maintenance page' : 'Site open to everyone' ?></span></article>
    <article class="stat-card"><span class="stat-label">Back by</span><span class="stat-value"><?= $state['until'] instanceof DateTimeImmutable ? h($state['until']->setTimezone($lagos)->format('g:i A')) : '—' ?></span><span class="text-xs text-faint"><?= $state['until'] instanceof DateTimeImmutable ? h($state['until']->setTimezone($lagos)->format('D j M')) . ' · Lagos' . ($untilPast ? ' · passed' : '') : 'No time shown' ?></span></article>
    <article class="stat-card"><span class="stat-label">Last change</span><span class="stat-value text-sm"><?= $row ? h(yolotask_wat((string) $row['updated_at'], 'j M, g:i A')) : '—' ?></span><span class="text-xs text-faint text-break"><?= $row && $row['display_name'] ? 'by ' . h((string) $row['display_name']) : 'Never switched' ?></span></article>
    <article class="stat-card"><span class="stat-label">Still running</span><span class="stat-value text-sm">Admin, payments, cron</span><span class="text-xs text-faint">Callbacks and cron are never paused</span></article>
  </section>

  <section class="card" id="settings" aria-labelledby="mt-title">
    <div class="card-header"><h2 id="mt-title">Maintenance mode</h2><span class="badge <?= $on ? 'badge-warning' : 'badge-success' ?>" data-maintenance-switch="<?= $on ? 'on' : 'off' ?>"><?= $on ? 'On' : 'Off' ?></span></div>
    <p class="text-sm text-faint">When ON, every page of the main site and the earner, advertiser and reviewer portals (including sign-in, registration and form submissions) shows the maintenance page below, with HTTP 503 so search engines and apps know it is temporary. Signed-in users stay signed in and see it too. The admin portal stays open so you can switch it off. Payment callbacks (AfrStar, Korapay) and cron jobs keep running, so money already in flight still settles. Task reservations keep counting down while the site is closed.</p>
    <?php if ($untilPast && $on): ?><p class="text-sm text-warning">The "back by" time has passed. Users now see “This is taking a little longer than planned.” Set a new time or switch maintenance off.</p><?php endif; ?>
    <form method="post" action="maintenance.php" class="stack">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <label class="toggle-inline"><input type="checkbox" name="enabled" value="1"<?= $on ? ' checked' : '' ?>> Maintenance mode ON (close the main site, earner, advertiser and reviewer portals)</label>
      <div class="field"><label class="label" for="message">Message shown to users</label>
        <textarea class="input" id="message" name="message" rows="3" maxlength="<?= Maintenance::MESSAGE_MAX ?>"><?= h($state['message']) ?></textarea>
        <p class="input-hint">Up to <?= Maintenance::MESSAGE_MAX ?> characters. Empty uses “<?= h(Maintenance::DEFAULT_MESSAGE) ?>”</p></div>
      <div class="field"><label class="label" for="until">Back by (optional, Lagos time)</label>
        <input class="input" type="datetime-local" id="until" name="until" value="<?= h($untilValue) ?>" min="<?= h($minValue) ?>">
        <p class="input-hint">Shown as “We expect to be back by …” and sent to browsers as Retry-After. Leave empty to show no time. Within the next 30 days.</p></div>
      <button class="btn <?= $on ? 'btn-primary' : 'btn-secondary' ?>" type="submit" data-confirm="Save maintenance settings? If the switch is ON, users will see the maintenance page right away.">Save maintenance settings</button>
    </form>
  </section>

  <section class="card" id="tester" aria-labelledby="tt-title">
    <div class="card-header"><h2 id="tt-title">Tester link</h2><span class="badge <?= $testerActive ? 'badge-warning' : 'badge-info' ?>" data-tester-link="<?= $testerActive ? 'active' : 'none' ?>"><?= $testerActive ? 'Active' : ($testerExpired ? 'Expired' : 'None') ?></span></div>
    <p class="text-sm text-faint">A secret link that lets you (or anyone you give it to) use the live site while maintenance mode is ON; everyone else still sees the maintenance page. Opening the link once sets a cookie that works on the main site and the earner, advertiser and reviewer portals until the link expires (<?= Maintenance::TESTER_DEFAULT_DAYS ?> days). Sign in normally after opening it. When maintenance is OFF the link does nothing. Creating a new link replaces the old one.</p>
    <?php if ($testerToken !== null): $testerUrl = yolotask_portal_url('main') . '/?' . Maintenance::TESTER_PARAM . '=' . $testerToken; ?>
      <div class="field"><label class="label" for="tester-url">Your tester link (shown only once — copy it now)</label>
        <input class="input" id="tester-url" type="text" readonly value="<?= h($testerUrl) ?>" onclick="this.select()" data-tester-url>
        <p class="input-hint">The same <code>?<?= h(Maintenance::TESTER_PARAM) ?>=…</code> works on any portal, e.g. <?= h(yolotask_portal_url('earner')) ?>/?<?= h(Maintenance::TESTER_PARAM) ?>=…</p></div>
    <?php endif; ?>
    <?php if ($testerActive && $testerExpires): ?><p class="text-sm">Active until <?= h($testerExpires->format('g:i A, D j M')) ?> (Lagos time).<?= $on ? '' : ' Maintenance is OFF, so the link has no effect right now.' ?></p>
    <?php elseif ($testerExpired): ?><p class="text-sm text-faint">The last tester link has expired.</p><?php endif; ?>
    <div class="flex flex-wrap" style="gap:.5rem">
      <form method="post" action="maintenance.php">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="tester_create">
        <button class="btn btn-secondary" type="submit"<?= $testerActive ? ' data-confirm="Create a new tester link? The current one stops working."' : '' ?>>Create tester link</button>
      </form>
      <?php if ($state['preview_hash'] !== ''): ?>
      <form method="post" action="maintenance.php">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="tester_revoke">
        <button class="btn btn-ghost" type="submit" data-confirm="Revoke the tester link? Anyone using it will see the maintenance page.">Revoke tester link</button>
      </form>
      <?php endif; ?>
    </div>
  </section>

  <section class="card" aria-labelledby="mp-title">
    <div class="card-header"><h2 id="mp-title">What users see</h2></div>
    <div class="announcement-banner" role="presentation">
      <span class="announcement-icon" aria-hidden="true"><?= yolotask_nav_icon('sparkle') ?></span>
      <div class="announcement-body"><strong>We'll be right back</strong><p><?= h($state['message']) ?></p>
        <?php if ($state['until'] instanceof DateTimeImmutable && !$untilPast): ?><p class="text-xs text-faint">We expect to be back by <?= h($state['until']->setTimezone($lagos)->format('g:i A, D j M')) ?> (Lagos time).</p><?php endif; ?></div>
    </div>
    <p class="text-xs text-faint text-break">Applies to: <?= h(implode(' · ', $portals)) ?></p>
  </section>
</div>
<?php
yolotask_render_app($admin, 'Maintenance', (string) ob_get_clean(), 'market');
