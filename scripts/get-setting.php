<?php
declare(strict_types=1);

/**
 * Print admin settings from the command line (read-only).
 *
 * Usage: php scripts/get-setting.php --key=a.b [--key=c.d ...] [--config-dir=...]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opts = getopt('', ['config-dir:', 'key:']);
if (isset($opts['config-dir'])) putenv('YOLOTASK_CONFIG_DIR=' . $opts['config-dir']);
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

use YoloTask\Core\Settings;

$keys = (array) ($opts['key'] ?? []);
if ($keys === []) { fwrite(STDERR, "get-setting: give --key.\n"); exit(1); }
$pdo = \YoloTask\Core\Database::connection();
foreach ($keys as $k) echo 'get-setting: ' . $k . ' = ' . var_export(Settings::get($pdo, (string) $k), true) . "\n";
