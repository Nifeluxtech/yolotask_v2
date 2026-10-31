<?php
declare(strict_types=1);

/**
 * Daily housekeeping. cPanel cron:
 *   15 2 * * * /usr/local/bin/php $HOME/yolotask-v2-live/current/cron/daily.php >/dev/null 2>&1
 * Removes expired sessions, used or expired codes and links, and old throttle rows,
 * expires stale unpaid AfrStar top-up orders (no money moves) and runs the
 * wallet/ledger reconciliation, logging any mismatch. Exit code 2 = mismatch.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

$jobs = [
    'expired sessions' => "DELETE FROM user_sessions WHERE (revoked_at IS NOT NULL AND revoked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)) OR expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)",
    'verification codes' => "DELETE FROM email_verifications WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)",
    'password links' => "DELETE FROM password_reset_tokens WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)",
    'login attempts' => "DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)",
    'throttle rows' => "DELETE FROM rate_limits WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)",
];
try {
    $pdo = \YoloTask\Core\Database::connection();
    foreach ($jobs as $label => $sql) {
        $n = $pdo->exec($sql);
        fwrite(STDOUT, "cleanup: {$label}: " . (int) $n . " removed\n");
    }
} catch (Throwable $e) {
    \YoloTask\Core\Logger::error('cron.daily_failed', ['exception' => get_class($e)]);
    fwrite(STDERR, "cleanup failed\n");
    exit(1);
}

// AfrStar hub log: keep forwarded-callback rows for 180 days (table exists after migration 0007).
try {
    $n = $pdo->exec("DELETE FROM afrstar_callback_forwards WHERE received_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 180 DAY)");
    fwrite(STDOUT, "cleanup: forwarded AfrStar callbacks: " . (int) $n . " removed\n");
} catch (Throwable $e) {
    fwrite(STDOUT, "cleanup: forwarded AfrStar callbacks: skipped (migration 0007 not applied)\n");
}

// Phase 3: money housekeeping and reconciliation.
try {
    $expired = \YoloTask\Payments\PaymentOrders::expireStale($pdo);
    fwrite(STDOUT, "payments: stale top-up orders expired: {$expired}\n");
    $problems = \YoloTask\Money\Ledger::reconcile($pdo);
    if ($problems === []) {
        fwrite(STDOUT, "reconciliation: OK (ledger balanced, wallet caches match entries)\n");
    } else {
        foreach ($problems as $problem) {
            fwrite(STDOUT, "reconciliation: MISMATCH: {$problem}\n");
            \YoloTask\Core\Logger::error('cron.reconciliation_mismatch', ['problem' => $problem]);
        }
        exit(2);
    }
} catch (Throwable $e) {
    \YoloTask\Core\Logger::error('cron.daily_money_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    fwrite(STDERR, "money housekeeping failed: " . get_class($e) . "\n");
    exit(1);
}
