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
