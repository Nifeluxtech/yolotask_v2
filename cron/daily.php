<?php
declare(strict_types=1);

/**
 * Daily housekeeping. cPanel cron:
 *   15 2 * * * /usr/local/bin/php $HOME/yolotask-v2-live/current/cron/daily.php >/dev/null 2>&1
 * Removes expired sessions, used or expired codes and links, and old throttle rows.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__));
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
