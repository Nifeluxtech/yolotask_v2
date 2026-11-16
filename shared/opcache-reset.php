<?php
declare(strict_types=1);

/*
 * Deploy-only OPcache reset. The host keeps compiled PHP in OPcache keyed by
 * the path under the "current" symlink and does not re-check file times, so
 * after a release switch busy pages (home, login, register, dashboard) kept
 * running the previous release. scripts/deploy/cpanel-release.sh writes a
 * one-time token to config-private/opcache-reset.token, calls this endpoint on
 * every portal, then deletes the token. Without a matching token it is a 404.
 * Deliberately standalone: it must never load shared/bootstrap.php.
 */
$fail = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Not found\n";
    exit;
};
$appRoot = realpath(dirname(__DIR__));                    // release root (…/releases/<commit>)
if ($appRoot === false) $fail();
$base = getenv('YOLOTASK_DEPLOY_BASE') ?: dirname($appRoot, 2);
$configDir = getenv('YOLOTASK_CONFIG_DIR') ?: $base . '/config-private';
$tokenFile = rtrim($configDir, '/') . '/opcache-reset.token';
if (!is_file($tokenFile) || (time() - (int) filemtime($tokenFile)) > 600) $fail();
$expected = trim((string) file_get_contents($tokenFile));
$given = (string) ($_SERVER['HTTP_X_YOLOTASK_RESET_TOKEN'] ?? '');
if (strlen($expected) < 32 || !hash_equals($expected, $given)) $fail();

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
if (!function_exists('opcache_reset')) {
    echo "opcache: not loaded\n";
    exit;
}
echo @opcache_reset() ? "opcache: reset\n" : "opcache: reset refused (opcache.restrict_api?)\n";
