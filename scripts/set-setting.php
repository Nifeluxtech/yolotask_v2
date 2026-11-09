<?php
declare(strict_types=1);

/**
 * Set one admin setting from the command line (for a scheduled one-shot cron,
 * e.g. switching account activation on at a set time). Recorded in the audit
 * log as the first active admin.
 *
 * Usage: php scripts/set-setting.php --key=activation.required --value=1 [--config-dir=...]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opts = getopt('', ['config-dir:', 'key:', 'value:']);
if (isset($opts['config-dir'])) putenv('YOLOTASK_CONFIG_DIR=' . $opts['config-dir']);
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

use YoloTask\Core\Settings;

$key = (string) ($opts['key'] ?? '');
if ($key === '' || !isset($opts['value'])) { fwrite(STDERR, "set-setting: give --key and --value.\n"); exit(1); }
$pdo = \YoloTask\Core\Database::connection();
$adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
try {
    $before = Settings::get($pdo, $key);
    Settings::save($pdo, $adminId, [$key => (string) $opts['value']]);
    echo "set-setting: $key " . var_export($before, true) . ' -> ' . var_export(Settings::get($pdo, $key), true) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'set-setting: ' . $e->getMessage() . "\n");
    exit(1);
}
