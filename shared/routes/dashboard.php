<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Auth;
use YoloTask\Security\Csrf;

$role = defined('YOLOTASK_APP_ROLE') ? (string) YOLOTASK_APP_ROLE : '';
if (!in_array($role, ['earner', 'advertiser'], true)) {
    http_response_code(404);
    require YOLOTASK_APP_ROOT . '/shared/views/errors/404.php';
    exit;
}

header('Cache-Control: no-store, private');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

try {
    $pdo = Database::connection();
    $user = Auth::currentUser($pdo);
} catch (Throwable $e) {
    Logger::error('dashboard.session_lookup_failed', ['exception' => get_class($e)]);
    http_response_code(500);
    require YOLOTASK_APP_ROOT . '/shared/views/errors/500.php';
    exit;
}

if (!is_array($user)) {
    $_SESSION['_flash'] = 'Please sign in to continue.';
    header('Location: login.php', true, 303);
    exit;
}
if ((string) ($user['role'] ?? '') !== $role) {
    http_response_code(403);
    require YOLOTASK_APP_ROOT . '/shared/views/errors/403.php';
    exit;
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
];

$firstName = trim((string) strtok((string) ($user['display_name'] ?? ''), ' '));
$hour = (int) (new DateTimeImmutable('now', $tz))->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$verified = !empty($profile['email_verified_at']);
$isAdvertiser = $role === 'advertiser';

$icons = [
    'shield' => '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6l8-3z"/>',
    'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
    'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
    'device' => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>',
];
$svg = static fn (string $name): string => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($icons[$name] ?? '') . '</svg>';

$stats = [
    ['label' => 'Account status', 'value' => ucfirst((string) ($user['status'] ?? 'active')), 'icon' => 'shield', 'tone' => 'stat-success', 'note' => ucfirst($role) . ' account'],
    ['label' => 'Email', 'value' => $verified ? 'Verified' : 'Unverified', 'icon' => 'mail', 'tone' => $verified ? 'stat-success' : 'stat-warning', 'note' => (string) ($user['email'] ?? '')],
    ['label' => 'Member since', 'value' => $fmt($profile['created_at'] ?? null), 'icon' => 'calendar', 'tone' => '', 'note' => 'Last sign-in ' . $fmt($profile['last_login_at'] ?? null, 'j M, g:i A')],
    ['label' => 'Active sessions', 'value' => (string) $activeSessions, 'icon' => 'device', 'tone' => $activeSessions > 1 ? 'stat-warning' : '', 'note' => $activeSessions > 1 ? 'Signed in on more than one device' : 'This device only'],
];

$tips = $isAdvertiser
    ? [
        ['title' => 'Know your audience', 'body' => 'Campaigns perform best when the task is simple, the instructions are clear and the proof is easy to check.'],
        ['title' => 'Reward fairly', 'body' => 'A fair reward per action attracts careful earners and keeps approval rates high.'],
        ['title' => 'Protect your account', 'body' => 'Use a unique password and sign out on shared devices. YOLOTASK will never ask for your password.'],
    ]
    : [
        ['title' => 'Follow instructions exactly', 'body' => 'Advertisers approve proofs that match their instructions. Careful work keeps your approval rate high.'],
        ['title' => 'One person, one account', 'body' => 'Duplicate accounts, bots and fake proofs lead to suspension and loss of earnings.'],
        ['title' => 'Protect your account', 'body' => 'Use a unique password and sign out on shared devices. YOLOTASK will never ask for your password.'],
    ];

ob_start();
?>
<section class="card card-glow dash-hero" aria-labelledby="dash-greeting">
  <p class="text-xs fw-bold text-primary"><?= h(strtoupper($role)) ?> DASHBOARD</p>
  <h2 id="dash-greeting"><?= h($greeting . ($firstName !== '' ? ', ' . $firstName : '')) ?></h2>
  <p class="text-faint"><?= $isAdvertiser
      ? 'Here is an overview of your advertiser account and its security.'
      : 'Here is an overview of your earner account and its security.' ?></p>
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
    <div class="card-header"><h3 id="activity-title">Recent activity</h3><span class="badge badge-info">Security</span></div>
    <?php if ($activity === []): ?>
      <div class="empty-state"><p class="text-faint">No account activity recorded yet.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th scope="col">Event</th><th scope="col">When (WAT)</th></tr></thead>
          <tbody>
          <?php foreach ($activity as $event): ?>
            <tr><td><?= h($eventLabels[(string) $event['event_key']] ?? 'Account update') ?></td><td><?= h($fmt((string) $event['created_at'], 'j M Y, g:i A')) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card" id="guide" aria-labelledby="guide-title">
    <div class="card-header"><h3 id="guide-title"><?= $isAdvertiser ? 'Advertiser essentials' : 'Earner essentials' ?></h3></div>
    <ul class="stack dash-tips">
      <?php foreach ($tips as $tip): ?>
        <li><strong><?= h($tip['title']) ?></strong><p class="text-sm text-faint"><?= h($tip['body']) ?></p></li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>
<?php
$contentHtml = (string) ob_get_clean();

$flash = is_string($_SESSION['_flash'] ?? null) ? $_SESSION['_flash'] : null;
unset($_SESSION['_flash']);
$flashType = 'success';
$appName = (string) (yolotask_config()['app_name'] ?? 'YOLOTASK');
$roleTitle = $isAdvertiser ? 'Advertiser' : 'Earner';
$pageTitle = 'Dashboard';
$userDisplayName = (string) ($user['display_name'] ?? '');
$logoutCsrfToken = Csrf::token();
$navItems = [
    ['label' => 'Home', 'url' => 'dashboard.php', 'icon' => 'home'],
    ['label' => 'Activity', 'url' => 'dashboard.php#activity', 'icon' => 'shield'],
    ['label' => 'Guide', 'url' => 'dashboard.php#guide', 'icon' => $isAdvertiser ? 'megaphone' : 'tasks'],
];
require YOLOTASK_APP_ROOT . '/shared/views/layouts/authenticated.php';
