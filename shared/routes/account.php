<?php
declare(strict_types=1);

use YoloTask\Account\Account;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Csrf;
use YoloTask\Security\Devices;

$role = yolotask_role();
if (!in_array($role, ['earner', 'advertiser', 'reviewer', 'admin'], true)) yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('account.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$userId = (int) $user['id'];
$isStaff = in_array($role, ['reviewer', 'admin'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        yolotask_flash('Your form expired. Please try again.', 'error');
        yolotask_redirect('account.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    $str = static fn (string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    try {
        switch ($action) {
            case 'profile':
                Account::updateProfile($pdo, $userId, $str('display_name'), $str('phone'));
                yolotask_flash('Profile saved.');
                yolotask_redirect('account.php#profile');
            case 'password':
                Account::changePassword($pdo, $userId, $str('current_password'), $str('new_password'), $str('new_password_confirmation'));
                yolotask_flash('Password changed. Other devices were signed out.');
                yolotask_redirect('account.php#password');
            case 'revoke':
                $ok = Devices::revoke($pdo, $userId, (int) $str('session_id'));
                yolotask_flash($ok ? 'That device was signed out.' : 'That device was already signed out.', $ok ? 'success' : 'warning');
                yolotask_redirect('account.php#devices');
            case 'revoke_others':
                $n = Devices::revokeOthers($pdo, $userId);
                yolotask_flash($n > 0 ? "Signed out {$n} other device" . ($n === 1 ? '' : 's') . '.' : 'No other devices were signed in.');
                yolotask_redirect('account.php#devices');
            case 'close':
                if ($isStaff) throw new RuntimeException('Staff accounts are closed by an administrator.');
                Account::close($pdo, $userId, $str('close_password'), $str('close_confirm'));
                session_start();
                yolotask_flash('Your account has been closed. We are sorry to see you go.');
                yolotask_redirect('login.php');
            default:
                yolotask_redirect('account.php');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
        yolotask_redirect('account.php' . ($action === 'close' ? '#danger' : ($action === 'password' ? '#password' : '#profile')));
    } catch (Throwable $e) {
        Logger::error('account.action_failed', ['action' => $action, 'exception' => get_class($e)]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
        yolotask_redirect('account.php');
    }
}

$csrf = Csrf::token();
$devices = Devices::list($pdo, $userId);
$verified = !empty($user['email_verified_at']);
$csrfField = '<input type="hidden" name="_csrf" value="' . h($csrf) . '">';
ob_start();
?>
<div class="account-grid">
  <nav class="card account-jump" aria-label="Account sections">
    <a href="#profile"><?= yolotask_nav_icon('user') ?><span>Profile</span></a>
    <a href="#password"><?= yolotask_nav_icon('shield') ?><span>Password</span></a>
    <a href="#devices"><?= yolotask_nav_icon('inbox') ?><span>Devices</span></a>
    <?php if (!$isStaff): ?><a href="#danger"><?= yolotask_nav_icon('logout') ?><span>Close</span></a><?php endif; ?>
  </nav>

  <section class="card" id="profile" aria-labelledby="profile-title">
    <div class="card-header"><h2 id="profile-title">Profile</h2><span class="badge <?= $verified ? 'badge-success' : 'badge-warning' ?>"><?= $verified ? 'Email verified' : 'Email not verified' ?></span></div>
    <form method="post" action="account.php" class="stack">
      <?= $csrfField ?><input type="hidden" name="action" value="profile">
      <div class="field">
        <label class="label" for="display_name"><?= $role === 'advertiser' ? 'Name or business name' : 'Full name' ?></label>
        <input class="input" id="display_name" name="display_name" maxlength="80" autocomplete="name" required value="<?= h((string) $user['display_name']) ?>">
      </div>
      <div class="field">
        <label class="label" for="email">Email address</label>
        <input class="input" id="email" type="email" value="<?= h((string) $user['email']) ?>" disabled>
        <p class="input-hint"><?php if ($verified): ?>Confirmed <?= h(yolotask_wat((string) $user['email_verified_at'], 'j M Y')) ?>. Contact support to change it.<?php else: ?><a href="verify.php">Confirm your email</a> to unlock earning, funding and withdrawals.<?php endif; ?></p>
      </div>
      <div class="field">
        <label class="label" for="phone">Phone number <span class="text-faint">(optional)</span></label>
        <input class="input" id="phone" name="phone" type="tel" inputmode="tel" maxlength="20" autocomplete="tel" placeholder="0803 123 4567" value="<?= h((string) ($user['phone'] ?? '')) ?>">
      </div>
      <button class="btn btn-primary" type="submit">Save profile</button>
    </form>
  </section>

  <section class="card" id="password" aria-labelledby="password-title">
    <div class="card-header"><h2 id="password-title">Password</h2></div>
    <form method="post" action="account.php" class="stack">
      <?= $csrfField ?><input type="hidden" name="action" value="password">
      <input type="email" name="username" value="<?= h((string) $user['email']) ?>" autocomplete="username" hidden>
      <div class="field"><label class="label" for="current_password">Current password</label><input class="input" id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
      <div class="field"><label class="label" for="new_password">New password</label><input class="input" id="new_password" name="new_password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required><p class="input-hint">At least 12 characters. Other devices will be signed out.</p></div>
      <div class="field"><label class="label" for="new_password_confirmation">Confirm new password</label><input class="input" id="new_password_confirmation" name="new_password_confirmation" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></div>
      <button class="btn btn-primary" type="submit">Change password</button>
    </form>
  </section>

  <section class="card" id="devices" aria-labelledby="devices-title">
    <div class="card-header"><h2 id="devices-title">Signed-in devices</h2><span class="badge badge-info"><?= count($devices) ?> active</span></div>
    <ul class="device-list">
      <?php foreach ($devices as $d): ?>
        <li class="device-row">
          <span class="device-icon"><?= yolotask_nav_icon('shield') ?></span>
          <div class="device-meta">
            <strong><?= h((string) ($d['device_label'] ?: 'Unknown device')) ?></strong>
            <span class="text-xs text-faint"><?= $d['is_current'] ? 'This device · ' : '' ?>Signed in <?= h(yolotask_wat((string) $d['created_at'], 'j M, g:i A')) ?> · Active <?= h(yolotask_wat((string) $d['last_seen_at'], 'j M, g:i A')) ?></span>
          </div>
          <?php if ($d['is_current']): ?>
            <span class="badge badge-success">Current</span>
          <?php else: ?>
            <form method="post" action="account.php"><?= $csrfField ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="session_id" value="<?= (int) $d['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Sign out</button></form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if (count($devices) > 1): ?>
      <form method="post" action="account.php"><?= $csrfField ?><input type="hidden" name="action" value="revoke_others"><button class="btn btn-secondary btn-block" type="submit">Sign out all other devices</button></form>
    <?php endif; ?>
  </section>

  <?php if (!$isStaff): ?>
  <section class="card card-danger" id="danger" aria-labelledby="danger-title">
    <div class="card-header"><h2 id="danger-title">Close account</h2></div>
    <p class="text-sm text-faint">Closing your account signs you out everywhere and stops all activity. This cannot be undone from the app.</p>
    <details class="danger-details">
      <summary class="btn btn-danger-outline">I want to close my account</summary>
      <form method="post" action="account.php" class="stack">
        <?= $csrfField ?><input type="hidden" name="action" value="close">
        <div class="field"><label class="label" for="close_password">Password</label><input class="input" id="close_password" name="close_password" type="password" autocomplete="current-password" required></div>
        <div class="field"><label class="label" for="close_confirm">Type DELETE to confirm</label><input class="input" id="close_confirm" name="close_confirm" autocomplete="off" required></div>
        <button class="btn btn-danger" type="submit">Close my account</button>
      </form>
    </details>
  </section>
  <?php endif; ?>
</div>
<?php
yolotask_render_app($user, 'Account', (string) ob_get_clean(), 'account');
