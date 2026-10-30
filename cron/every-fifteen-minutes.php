<?php
declare(strict_types=1);

/**
 * Every fifteen minutes: expire task reservations, auto-approve pending
 * submissions whose advertiser did not decide within the auto-approve
 * window, close campaigns whose end date passed (refunding unused escrow),
 * and report the stale-submission count. Safe to run twice: every money
 * movement is idempotent and re-checks state under row locks. cPanel cron:
 *   0,15,30,45 * * * * /opt/cpanel/ea-php84/root/usr/bin/php $HOME/yolotask-v2-live/current/cron/every-fifteen-minutes.php >> $HOME/yolotask-v2-live/runtime/logs/cron-15min.log 2>&1
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

$lock = fopen(sys_get_temp_dir() . '/yolotask-cron-15min-' . md5(YOLOTASK_APP_ROOT) . '.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDOUT, "every-fifteen-minutes: previous run still active\n"); exit(0); }
try {
    $pdo = \YoloTask\Core\Database::connection();
    $t = gmdate('Y-m-d H:i:s') . 'Z';
    fwrite(STDOUT, "{$t} tasks: reservations expired: " . \YoloTask\Tasks\Tasks::expireReservations($pdo) . "\n");
    fwrite(STDOUT, "{$t} tasks: submissions auto-approved: " . \YoloTask\Tasks\Approvals::autoApproveDue($pdo) . "\n");
    fwrite(STDOUT, "{$t} campaigns: ended (end date reached): " . \YoloTask\Campaigns\Campaigns::endExpired($pdo) . "\n");
    fwrite(STDOUT, "{$t} tasks: stale pending submissions: " . \YoloTask\Tasks\StaleQueue::count($pdo) . "\n");
} catch (Throwable $e) {
    \YoloTask\Core\Logger::error('cron.fifteen_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    fwrite(STDERR, "every-fifteen-minutes failed: " . get_class($e) . "\n");
    exit(1);
}
