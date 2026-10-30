<?php
declare(strict_types=1);

/**
 * Hourly: release task rewards whose hold window has passed (only used when
 * the admin sets a reward hold above 0 hours) and run the campaign escrow /
 * revenue reconciliation, logging any mismatch (exit code 2). cPanel cron:
 *   5 * * * * /opt/cpanel/ea-php84/root/usr/bin/php $HOME/yolotask-v2-live/current/cron/hourly.php >> $HOME/yolotask-v2-live/runtime/logs/cron-hourly.log 2>&1
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

try {
    $pdo = \YoloTask\Core\Database::connection();
    $t = gmdate('Y-m-d H:i:s') . 'Z';
    fwrite(STDOUT, "{$t} tasks: rewards released: " . \YoloTask\Tasks\Approvals::releaseDue($pdo) . "\n");
    $problems = \YoloTask\Money\Ledger::reconcileCampaigns($pdo);
    if ($problems === []) {
        fwrite(STDOUT, "{$t} campaigns: escrow reconciliation OK\n");
    } else {
        foreach ($problems as $p) {
            fwrite(STDOUT, "{$t} campaigns: MISMATCH: {$p}\n");
            \YoloTask\Core\Logger::error('cron.campaign_reconciliation_mismatch', ['problem' => $p]);
        }
        exit(2);
    }
} catch (Throwable $e) {
    \YoloTask\Core\Logger::error('cron.hourly_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    fwrite(STDERR, "hourly failed: " . get_class($e) . "\n");
    exit(1);
}
