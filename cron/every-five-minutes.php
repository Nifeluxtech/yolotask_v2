<?php
declare(strict_types=1);

/**
 * Every five minutes: payments housekeeping.
 *  - re-runs AfrStar callbacks that were logged but never finished processing
 *    (same signature checks; idempotent),
 *  - expires unpaid top-up orders past money.payin_expiry_hours (no money moves),
 *  - flags payouts with no callback after money.payout_stuck_hours into the
 *    payment review queue.
 * AfrStar has no status-query API, so callbacks stay the source of truth.
 * cPanel cron:
 *   0-59/5 * * * * /opt/cpanel/ea-php84/root/usr/bin/php $HOME/yolotask-v2-live/current/cron/every-five-minutes.php >> $HOME/yolotask-v2-live/runtime/logs/cron-5min.log 2>&1
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

$lock = fopen(sys_get_temp_dir() . '/yolotask-cron-5min-' . md5(YOLOTASK_APP_ROOT) . '.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDOUT, "every-five-minutes: previous run still active\n"); exit(0); }
try {
    $pdo = \YoloTask\Core\Database::connection();
    $t = gmdate('Y-m-d H:i:s') . 'Z';
    $r = \YoloTask\Payments\CallbackProcessor::retryUnfinished($pdo);
    fwrite(STDOUT, "{$t} payments: unfinished callbacks retried: {$r['retried']}, failed again: {$r['failed']}\n");
    fwrite(STDOUT, "{$t} payments: stale top-up orders expired: " . \YoloTask\Payments\PaymentOrders::expireStale($pdo) . "\n");
    fwrite(STDOUT, "{$t} payments: stuck payouts flagged for review: " . \YoloTask\Payments\CallbackProcessor::flagStuckPayouts($pdo) . "\n");
} catch (Throwable $e) {
    \YoloTask\Core\Logger::error('cron.five_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    fwrite(STDERR, "every-five-minutes failed: " . get_class($e) . "\n");
    exit(1);
}
