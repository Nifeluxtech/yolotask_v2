<?php
declare(strict_types=1);

/**
 * Set the YOLOTASK (platform) task reward percent and re-price every live
 * platform task (PlatformTasks::saveRewardPercent + syncRewards), the same as
 * the admin Platform tasks page. --halve sets it to half the current value
 * (rounded down, at least 1). Recorded in the audit log as the first active admin.
 *
 * Usage: php scripts/platform-reward-percent.php (--percent=N | --halve) [--config-dir=...]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opts = getopt('', ['config-dir:', 'percent:', 'halve']);
if (isset($opts['config-dir'])) putenv('YOLOTASK_CONFIG_DIR=' . $opts['config-dir']);
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

use YoloTask\PlatformTasks\PlatformTasks;

$pdo = \YoloTask\Core\Database::connection();
$before = PlatformTasks::rewardPercent($pdo);
if (isset($opts['halve'])) $new = max(1, intdiv($before, 2));
elseif (isset($opts['percent'])) $new = (int) $opts['percent'];
else { fwrite(STDERR, "platform-reward-percent: give --percent=N or --halve.\n"); exit(1); }
$adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
try {
    $n = PlatformTasks::saveRewardPercent($pdo, $adminId, $new);
    echo "platform-reward-percent: $before% -> $new%, $n platform tasks re-priced\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'platform-reward-percent: ' . $e->getMessage() . "\n");
    exit(1);
}
