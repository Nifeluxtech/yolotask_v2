<?php
declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

if (!defined('YOLOTASK_APP_ROOT')) {
    throw new RuntimeException('YOLOTASK_APP_ROOT must be defined by the app front controller.');
}
$physicalRoot = realpath(YOLOTASK_APP_ROOT) ?: YOLOTASK_APP_ROOT;
$deploymentBase = dirname($physicalRoot, 2);
$privateConfigDir = getenv('YOLOTASK_CONFIG_DIR') ?: $deploymentBase . '/config-private';
$config = [];
$appConfigFile = rtrim($privateConfigDir, '/') . '/app.local.php';
if (is_file($appConfigFile)) {
    $loaded = require $appConfigFile;
    if (is_array($loaded)) {
        $config = $loaded;
    }
}
$mailConfigFile = rtrim($privateConfigDir, '/') . '/mail.local.php';
if (is_file($mailConfigFile)) {
    $mailLoaded = require $mailConfigFile;
    if (is_array($mailLoaded)) {
        $config['mail'] = array_merge(is_array($config['mail'] ?? null) ? $config['mail'] : [], $mailLoaded);
    }
}

// Payment provider settings: payments.local.php (which reads afrstar.key),
// or afrstar.key on its own. See config/payments.example.php.
$config['_config_dir'] = rtrim($privateConfigDir, '/');
$paymentsConfigFile = rtrim($privateConfigDir, '/') . '/payments.local.php';
if (is_file($paymentsConfigFile)) {
    $paymentsLoaded = require $paymentsConfigFile;
    if (is_array($paymentsLoaded)) {
        $config['payments'] = $paymentsLoaded;
    }
}

// Provider callbacks (hooks/) and cron define YOLOTASK_NO_SESSION: no cookie, no session.
if (!defined('YOLOTASK_NO_SESSION') && session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    $role = defined('YOLOTASK_APP_ROLE') ? (string) YOLOTASK_APP_ROLE : 'main';
    session_name('YT_' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $role)) . '_SESSION');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    $session = is_array($config['session'] ?? null) ? $config['session'] : [];
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (bool) ($session['secure'] ?? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')),
        'httponly' => (bool) ($session['httponly'] ?? true),
        'samesite' => (string) ($session['samesite'] ?? 'Lax'),
    ]);
    session_start();
}

function yolotask_config(): array { global $config; return $config; }
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/**
 * Absolute base URL for a portal. Uses config base_urls when set to a real
 * host; otherwise derives it from the current host so the same release runs
 * unchanged on yolotask.com.ng and on the staging subdomains.
 */
function yolotask_portal_url(string $portal): string
{
    $portal = in_array($portal, ['main', 'earner', 'advertiser', 'reviewer', 'admin'], true) ? $portal : 'main';
    $configured = yolotask_config()['base_urls'][$portal] ?? null;
    if (is_string($configured) && $configured !== '' && !str_contains($configured, 'example.')) {
        return rtrim($configured, '/');
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'yolotask.com.ng'));
    $host = (string) preg_replace('/:\d+$/', '', $host);
    $host = (string) preg_replace('/^www\./', '', $host);
    $isStaging = (bool) preg_match('/(^|-)staging\./', $host);
    $root = (string) preg_replace('/^(?:(?:earner|advertiser|reviewer|admin)(?:-staging)?|staging)\./', '', $host);
    if ($root === '' || !preg_match('/^[a-z0-9.-]+$/', $root)) {
        $root = 'yolotask.com.ng';
    }
    if ($portal === 'main') {
        $prefix = $isStaging ? 'staging.' : '';
    } else {
        $prefix = $portal . ($isStaging ? '-staging.' : '.');
    }
    return 'https://' . $prefix . $root;
}

/** Public brand name: always "YOLOTASK", whatever internal version label config carries. */
function yolotask_brand_name(?string $name = null): string
{
    $name = trim((string) ($name ?? (yolotask_config()['app_name'] ?? 'YOLOTASK')));
    $name = trim((string) preg_replace('/\s*v\d+$/i', '', $name));
    return $name !== '' ? $name : 'YOLOTASK';
}

/** Portal asset URL with a file-modified stamp so phones never keep a stale stylesheet. */
function yolotask_asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/' . $path;
    $stamp = is_file($file) ? (string) filemtime($file) : '1';
    return $path . '?v=' . $stamp;
}

/** Inline SVG icons shared by headers and the bottom dock. */
function yolotask_nav_icon(string $id): string
{
    static $paths = [
        'home'      => '<path d="m3 10.5 9-7.5 9 7.5V20a1.5 1.5 0 0 1-1.5 1.5h-4.5v-6h-6v6H4.5A1.5 1.5 0 0 1 3 20z"/>',
        'tasks'     => '<path d="M4 7l2 2 4-4M4 17l2 2 4-4M13 7h7M13 17h7"/>',
        'wallet'    => '<path d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM16 12h3M3 9h18"/>',
        'user'      => '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM5 20.5a7.5 7.5 0 0 1 14 0"/>',
        'user-plus' => '<path d="M10 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM3 20.5a7.5 7.5 0 0 1 13 0M19 8v6M16 11h6"/>',
        'chart'     => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'megaphone' => '<path d="m3 11 14-6v14L3 13zM7 12v6a2 2 0 0 0 4 0M19 9a3 3 0 0 1 0 6"/>',
        'bell'      => '<path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6M10 20a2 2 0 0 0 4 0"/>',
        'inbox'     => '<path d="M3 13h5l1.5 2.5h5L16 13h5M4 6h16a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1z"/>',
        'shield'    => '<path d="M12 3 4.5 6v6c0 4.5 3 7.5 7.5 9 4.5-1.5 7.5-4.5 7.5-9V6zM9 12l2 2 4-4"/>',
        'sparkle'   => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z"/>',
        'doc'       => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8zM14 3v5h5M9 13h6M9 17h6"/>',
        'refund'    => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5M12 7v10M9.5 9.5c0-1 1.1-1.5 2.5-1.5s2.5.6 2.5 1.7c0 2.6-5 1.4-5 4 0 1.1 1.1 1.8 2.5 1.8s2.5-.5 2.5-1.5"/>',
        'login'     => '<path d="M15 3h3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H3"/>',
        'logout'    => '<path d="M9 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3M16 17l5-5-5-5M21 12H9"/>',
        'rocket'    => '<path d="M5 15c-1.5 1.3-2 5-2 5s3.7-.5 5-2M14 4c3.5-1 6-.5 6-.5s.5 2.5-.5 6L13 16l-5-5zM9 11H5l3-4h4M13 15v4l4-3v-4"/>',
    ];

    $path = $paths[$id] ?? $paths['home'];

    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}

/** The portal this request belongs to (earner, advertiser, reviewer, admin or main). */
function yolotask_role(): string
{
    return defined('YOLOTASK_APP_ROLE') ? (string) YOLOTASK_APP_ROLE : 'main';
}

function yolotask_role_title(?string $role = null): string
{
    return ['earner' => 'Earner', 'advertiser' => 'Advertiser', 'reviewer' => 'Reviewer', 'admin' => 'Admin'][$role ?? yolotask_role()] ?? 'YOLOTASK';
}

/** Read and clear a one-time flash message: [message, type]. */
function yolotask_flash_take(): array
{
    $msg = is_string($_SESSION['_flash'] ?? null) ? $_SESSION['_flash'] : null;
    $type = in_array($_SESSION['_flash_type'] ?? null, ['success', 'error', 'warning'], true) ? $_SESSION['_flash_type'] : 'success';
    unset($_SESSION['_flash'], $_SESSION['_flash_type']);
    return [$msg, $type];
}

function yolotask_flash(string $message, string $type = 'success'): void
{
    $_SESSION['_flash'] = $message;
    $_SESSION['_flash_type'] = $type;
}

function yolotask_redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function yolotask_error_page(int $code): never
{
    http_response_code($code);
    require YOLOTASK_APP_ROOT . '/shared/views/errors/' . (in_array($code, [403, 404, 500], true) ? $code : 500) . '.php';
    exit;
}

/** Private, uncacheable response headers for signed-in pages. */
function yolotask_private_headers(): void
{
    header('Cache-Control: no-store, private');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('X-Content-Type-Options: nosniff');
}

/** Signed-in user for this portal, or redirect to sign in / 403. */
function yolotask_require_user(PDO $pdo): array
{
    $user = \YoloTask\Security\Auth::currentUser($pdo);
    if (!is_array($user)) {
        yolotask_flash('Please sign in to continue.', 'warning');
        yolotask_redirect('login.php');
    }
    if ((string) $user['role'] !== yolotask_role()) yolotask_error_page(403);
    return $user;
}

/** Bottom-dock / header navigation for signed-in portals. */
function yolotask_portal_nav(string $active): array
{
    $role = yolotask_role();
    $items = [['key' => 'home', 'label' => 'Home', 'url' => 'dashboard.php', 'icon' => 'home']];
    if ($role === 'admin') $items[] = ['key' => 'users', 'label' => 'Users', 'url' => 'users.php', 'icon' => 'user-plus'];
    if ($role === 'admin') $items[] = ['key' => 'money', 'label' => 'Money', 'url' => 'wallets.php', 'icon' => 'wallet'];
    if (in_array($role, ['earner', 'advertiser'], true)) $items[] = ['key' => 'wallet', 'label' => 'Wallet', 'url' => 'wallet.php', 'icon' => 'wallet'];
    if ($role === 'reviewer') $items[] = ['key' => 'activity', 'label' => 'Activity', 'url' => 'dashboard.php#activity', 'icon' => 'inbox'];
    if (in_array($role, ['earner', 'advertiser'], true)) $items[] = ['key' => 'activity', 'label' => 'Activity', 'url' => 'dashboard.php#activity', 'icon' => 'chart'];
    $items[] = ['key' => 'account', 'label' => 'Account', 'url' => 'account.php', 'icon' => 'user'];
    foreach ($items as &$item) $item['active'] = $item['key'] === $active;
    return $items;
}

/** Render a signed-in page through the authenticated layout. */
function yolotask_render_app(array $user, string $pageTitle, string $contentHtml, string $activeNav): never
{
    [$flash, $flashType] = yolotask_flash_take();
    $appName = yolotask_brand_name();
    $roleTitle = yolotask_role_title();
    $userDisplayName = (string) ($user['display_name'] ?? '');
    $logoutCsrfToken = \YoloTask\Security\Csrf::token();
    $navItems = yolotask_portal_nav($activeNav);
    require YOLOTASK_APP_ROOT . '/shared/views/layouts/authenticated.php';
    exit;
}

/** Render the shared auth card. */
function yolotask_render_auth(string $authMode, array $vars = []): never
{
    [$flash] = yolotask_flash_take();
    $roleTitle = yolotask_role_title();
    $csrfToken = \YoloTask\Security\Csrf::token();
    extract($vars, EXTR_SKIP);
    require YOLOTASK_APP_ROOT . '/shared/views/layouts/auth.php';
    exit;
}

/** Format a UTC database time in Lagos time. */
function yolotask_wat(?string $utc, string $pattern = 'j M Y, g:i A'): string
{
    if ($utc === null || $utc === '') return '—';
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Lagos'))->format($pattern);
    } catch (Throwable) {
        return '—';
    }
}
