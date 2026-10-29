<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;

$role = yolotask_role();
if (!in_array($role, ['earner', 'advertiser', 'reviewer', 'admin'], true)) yolotask_error_page(404);
yolotask_private_headers();

try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('dashboard.session_lookup_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}

$userId = (int) $user['id'];
$profile = ['created_at' => null, 'last_login_at' => null, 'email_verified_at' => null];
$activeSessions = 0;
$activity = [];
try {
    $stmt = $pdo->prepare('SELECT created_at, last_login_at, email_verified_at FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    if (is_array($row)) $profile = $row;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id = :id AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()');
    $stmt->execute(['id' => $userId]);
    $activeSessions = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT event_key, created_at FROM audit_log WHERE actor_user_id = :id ORDER BY id DESC LIMIT 6');
    $stmt->execute(['id' => $userId]);
    $activity = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    Logger::error('dashboard.stats_failed', ['exception' => get_class($e)]);
}

$tz = new DateTimeZone('Africa/Lagos');
$fmt = static function (?string $utc, string $pattern = 'j M Y') use ($tz): string {
    if ($utc === null || $utc === '') return '—';
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format($pattern);
    } catch (Throwable) {
        return '—';
    }
};
$eventLabels = [
    'auth.registered' => 'Account created',
    'auth.login' => 'Signed in',
    'auth.logout' => 'Signed out',
    'auth.email_verified' => 'Email confirmed',
    'auth.password_reset_requested' => 'Password reset requested',
    'auth.password_reset' => 'Password reset',
    'auth.invite_accepted' => 'Account activated',
    'auth.device_signed_out' => 'Device signed out',
    'auth.other_devices_signed_out' => 'Other devices signed out',
    'account.profile_updated' => 'Profile updated',
    'account.password_changed' => 'Password changed',
    'account.closed' => 'Account closed',
    'admin.user_suspended' => 'Suspended a user',
    'admin.user_restored' => 'Restored a user',
    'admin.staff_invited' => 'Invited staff',
    'wallet.topup_started' => 'Top-up started',
    'wallet.withdrawal_requested' => 'Withdrawal requested',
    'wallet.bank_account_added' => 'Bank account added',
    'wallet.bank_account_changed' => 'Bank account changed',
    'admin.withdrawal_approved' => 'Approved a withdrawal',
    'admin.withdrawal_rejected' => 'Rejected a withdrawal',
    'admin.wallet_credited' => 'Credited a wallet',
    'admin.wallet_debited' => 'Debited a wallet',
    'admin.payment_review_credited' => 'Credited a reviewed payment',
    'admin.payment_review_rejected' => 'Closed a payment review',
    'admin.settings_updated' => 'Updated money settings',
];

$firstName = trim((string) strtok((string) ($user['display_name'] ?? ''), ' '));
$hour = (int) (new DateTimeImmutable('now', $tz))->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$verified = !empty($profile['email_verified_at']);
$isAdvertiser = $role === 'advertiser';
$isStaff = in_array($role, ['reviewer', 'admin'], true);

$icons = [
    'shield' => '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6l8-3z"/>',
    'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
    'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
    'device' => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>',
    'wallet' => '<path d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM16 12h3M3 9h18"/>',
];
$svg = static fn (string $name): string => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($icons[$name] ?? '') . '</svg>';

$walletRow = null;
if (in_array($role, ['earner', 'advertiser'], true)) {
    try {
        $walletRow = \YoloTask\Money\Wallet::forUser($pdo, $userId);
    } catch (Throwable $e) {
        Logger::error('dashboard.wallet_failed', ['exception' => get_class($e)]);
    }
}
$stats = [
    ['label' => 'Account status', 'value' => ucfirst((string) ($user['status'] ?? 'active')), 'icon' => 'shield', 'tone' => 'stat-success', 'note' => ucfirst($role) . ' account'],
    ['label' => 'Email', 'value' => $verified ? 'Verified' : 'Unverified', 'icon' => 'mail', 'tone' => $verified ? 'stat-success' : 'stat-warning', 'note' => (string) ($user['email'] ?? '')],
    ['label' => 'Member since', 'value' => $fmt($profile['created_at'] ?? null), 'icon' => 'calendar', 'tone' => '', 'note' => 'Last sign-in ' . $fmt($profile['last_login_at'] ?? null, 'j M, g:i A')],
    ['label' => 'Active sessions', 'value' => (string) $activeSessions, 'icon' => 'device', 'tone' => $activeSessions > 1 ? 'stat-warning' : '', 'note' => $activeSessions > 1 ? 'Signed in on more than one device' : 'This device only'],
];
if ($walletRow !== null) {
    $stats[0] = ['label' => 'Wallet balance', 'value' => \YoloTask\Money\Money::format($walletRow['available_kobo']), 'icon' => 'wallet', 'tone' => 'stat-success',
        'note' => $walletRow['held_kobo'] > 0 ? \YoloTask\Money\Money::format($walletRow['held_kobo']) . ' on hold' : ($role === 'earner' ? 'Available to withdraw' : 'Available for campaigns')];
}

$tips = $role === 'admin'
    ? [
        ['title' => 'Least privilege', 'body' => 'Invite reviewers for proof checks and keep admin access to the few people who run the platform.'],
        ['title' => 'Every action is logged', 'body' => 'Suspensions, restorations and invitations are recorded against your account.'],
        ['title' => 'Protect your account', 'body' => 'Use a unique password and sign out on shared devices. YOLOTASK will never ask for your password.'],
    ]
    : ($role === 'reviewer'
    ? [
        ['title' => 'Judge the proof, not the person', 'body' => 'Approve work that matches the instructions exactly and reject only with a clear reason.'],
        ['title' => 'Stay consistent', 'body' => 'Apply the same standard to every submission so earners and advertisers can trust each decision.'],
        ['title' => 'Protect your account', 'body' => 'Use a unique password and sign out on shared devices. YOLOTASK will never ask for your password.'],
    ]
    : ($isAdvertiser
    ? [
        ['title' => 'Know your audience', 'body' => 'Campaigns perform best when the task is simple, the instructions are clear and the proof is easy to check.'],
        ['title' => 'Reward fairly', 'body' => 'A fair reward per action attracts careful earners and keeps approval rates high.'],
        ['title' => 'Protect your account', 'body' => 'Use a unique password and sign out on shared devices. YOLOTASK will never ask for your password.'],
    ]
    : [
        ['title' => 'Follow instructions exactly', 'body' => 'Advertisers approve proofs that match their instructions. Careful work keeps your approval rate high.'],
        ['title' => 'One person, one account', 'body' => 'Duplicate accounts, bots and fake proofs lead to suspension and loss of earnings.'],
        ['title' => 'Protect your account', 'body' => 'Use a unique password and sign out on shared devices. YOLOTASK will never ask for your password.'],
    ]));

$adminMoney = ['withdrawals' => 0, 'reviews' => 0];
$adminCounts = null;
$platformActivity = [];
if ($role === 'admin') {
    try {
        $adminCounts = \YoloTask\Admin\Users::counts($pdo);
        try {
            $adminMoney['withdrawals'] = \YoloTask\Payments\Withdrawals::counts($pdo)['pending_review'];
            $adminMoney['reviews'] = \YoloTask\Payments\PaymentReview::openCount($pdo);
        } catch (Throwable $e) {
            Logger::error('dashboard.admin_money_failed', ['exception' => get_class($e)]);
        }
        $platformActivity = $pdo->query('SELECT a.event_key, a.created_at, u.display_name FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id ORDER BY a.id DESC LIMIT 8')->fetchAll() ?: [];
        $stats = [
            ['label' => 'Earners', 'value' => number_format($adminCounts['earner']), 'icon' => 'shield', 'tone' => '', 'note' => number_format($adminCounts['new_7d']) . ' new accounts this week'],
            ['label' => 'Advertisers', 'value' => number_format($adminCounts['advertiser']), 'icon' => 'mail', 'tone' => '', 'note' => 'Excluding closed accounts'],
            ['label' => 'Unverified', 'value' => number_format($adminCounts['unverified']), 'icon' => 'mail', 'tone' => 'stat-warning', 'note' => 'Email not confirmed'],
            ['label' => 'Suspended', 'value' => number_format($adminCounts['suspended']), 'icon' => 'device', 'tone' => $adminCounts['suspended'] > 0 ? 'stat-danger' : '', 'note' => 'Blocked from signing in'],
        ];
    } catch (Throwable $e) {
        Logger::error('dashboard.admin_stats_failed', ['exception' => get_class($e)]);
    }
}

ob_start();
?>
<?php if (!$isStaff && !$verified): ?>
<section class="card verify-banner" role="status">
  <span class="verify-icon"><?= $svg('mail') ?></span>
  <div><strong>Confirm your email</strong><p class="text-sm text-faint">Enter the code we sent to <?= h((string) $user['email']) ?> to unlock earning, funding and withdrawals.</p></div>
  <a class="btn btn-primary btn-sm" href="verify.php">Confirm now</a>
</section>
<?php endif; ?>
<section class="card card-glow dash-hero" aria-labelledby="dash-greeting">
  <p class="text-xs fw-bold text-primary"><?= h(strtoupper($role)) ?> DASHBOARD</p>
  <h2 id="dash-greeting"><?= h($greeting . ($firstName !== '' ? ', ' . $firstName : '')) ?></h2>
  <p class="text-faint"><?= h(match ($role) {
      'admin' => 'Here is how the YOLOTASK community looks today.',
      'reviewer' => 'Here is an overview of your reviewer account and its security.',
      'advertiser' => 'Here is an overview of your advertiser account and its security.',
      default => 'Here is an overview of your earner account and its security.',
  }) ?></p>
  <div class="hero-actions">
    <?php if ($role === 'admin'): ?><a class="btn btn-primary btn-sm" href="users.php">Manage users</a><a class="btn btn-secondary btn-sm" href="withdrawals.php">Withdrawals<?= $adminMoney['withdrawals'] > 0 ? ' (' . $adminMoney['withdrawals'] . ')' : '' ?></a><a class="btn btn-secondary btn-sm" href="payments.php">Payment review<?= $adminMoney['reviews'] > 0 ? ' (' . $adminMoney['reviews'] . ')' : '' ?></a>
    <?php elseif ($role === 'earner'): ?><a class="btn btn-primary btn-sm" href="wallet.php">My wallet</a><a class="btn btn-secondary btn-sm" href="withdraw.php">Withdraw</a>
    <?php elseif ($role === 'advertiser'): ?><a class="btn btn-primary btn-sm" href="topup.php">Top up wallet</a><a class="btn btn-secondary btn-sm" href="wallet.php">My wallet</a>
    <?php else: ?><a class="btn btn-secondary btn-sm" href="account.php">Account settings</a><?php endif; ?>
  </div>
</section>

<section class="grid grid-4 dash-stats" aria-label="Account overview">
  <?php foreach ($stats as $stat): ?>
    <article class="stat-card <?= h($stat['tone']) ?>">
      <span class="stat-icon"><?= $svg($stat['icon']) ?></span>
      <span class="stat-label"><?= h($stat['label']) ?></span>
      <span class="stat-value"><?= h($stat['value']) ?></span>
      <span class="text-xs text-faint text-break"><?= h($stat['note']) ?></span>
    </article>
  <?php endforeach; ?>
</section>

<div class="grid grid-2 dash-panels">
  <section class="card" id="activity" aria-labelledby="activity-title">
    <div class="card-header"><h3 id="activity-title"><?= $role === 'admin' ? 'Platform activity' : 'Recent activity' ?></h3><span class="badge badge-info"><?= $role === 'admin' ? 'Audit' : 'Security' ?></span></div>
    <?php if ($role === 'admin'): $activity = $platformActivity; endif; ?>
    <?php if ($activity === []): ?>
      <div class="empty-state"><p class="text-faint">No account activity recorded yet.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th scope="col">Event</th><th scope="col">When (WAT)</th></tr></thead>
          <tbody>
          <?php foreach ($activity as $event): ?>
            <tr><td><?= h($eventLabels[(string) $event['event_key']] ?? 'Account update') ?><?php if ($role === 'admin' && !empty($event['display_name'])): ?><br><span class="text-xs text-faint"><?= h((string) $event['display_name']) ?></span><?php endif; ?></td><td><?= h($fmt((string) $event['created_at'], 'j M Y, g:i A')) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card" id="guide" aria-labelledby="guide-title">
    <div class="card-header"><h3 id="guide-title"><?= h(yolotask_role_title() . ' essentials') ?></h3></div>
    <ul class="stack dash-tips">
      <?php foreach ($tips as $tip): ?>
        <li><strong><?= h($tip['title']) ?></strong><p class="text-sm text-faint"><?= h($tip['body']) ?></p></li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>
<?php
yolotask_render_app($user, 'Dashboard', (string) ob_get_clean(), 'home');
