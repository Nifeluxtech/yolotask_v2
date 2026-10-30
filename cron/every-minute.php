<?php
declare(strict_types=1);

/**
 * Every minute (optional): marks expired task reservations. Reservations
 * stop counting against slots the moment they expire, so this job is only
 * housekeeping; every-fifteen-minutes.php does the same work if this one is
 * not scheduled. cPanel cron:
 *   * * * * * /opt/cpanel/ea-php84/root/usr/bin/php $HOME/yolotask-v2-live/current/cron/every-minute.php >> $HOME/yolotask-v2-live/runtime/logs/cron-minute.log 2>&1
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

try {
    $pdo = \YoloTask\Core\Database::connection();
    $n = \YoloTask\Tasks\Tasks::expireReservations($pdo);
    if ($n > 0) fwrite(STDOUT, gmdate('Y-m-d H:i:s') . 'Z' . " tasks: reservations expired: {$n}\n");
} catch (Throwable $e) {
    \YoloTask\Core\Logger::error('cron.minute_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    fwrite(STDERR, "every-minute failed: " . get_class($e) . "\n");
    exit(1);
}
