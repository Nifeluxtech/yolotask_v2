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

if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
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
