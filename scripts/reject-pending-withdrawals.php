<?php
declare(strict_types=1);

/**
 * Reject every withdrawal waiting for review (status pending_review), one by
 * one through Withdrawals::reject: the held amount returns to the earner's
 * available balance, and the earner is notified with the reason. Recorded in
 * the audit log as the first active admin. Withdrawals already processing
 * are left alone.
 *
 * Usage: php scripts/reject-pending-withdrawals.php --reason="..." [--dry-run] [--config-dir=...]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opts = getopt('', ['config-dir:', 'reason:', 'dry-run']);
if (isset($opts['config-dir'])) putenv('YOLOTASK_CONFIG_DIR=' . $opts['config-dir']);
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

use YoloTask\Payments\Withdrawals;

$reason = trim((string) ($opts['reason'] ?? ''));
if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) { fwrite(STDERR, "reject-pending-withdrawals: give --reason (3 to 255 characters).\n"); exit(1); }
$dry = isset($opts['dry-run']);
$pdo = \YoloTask\Core\Database::connection();
$adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
if ($adminId <= 0) { fwrite(STDERR, "reject-pending-withdrawals: no active admin found.\n"); exit(1); }

$rows = $pdo->query("SELECT id, amount_kobo FROM withdrawals WHERE status = 'pending_review' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$done = 0; $failed = 0; $total = 0;
foreach ($rows as $r) {
    $id = (int) $r['id'];
    if ($dry) { echo "would reject #$id (" . (int) $r['amount_kobo'] . " kobo)\n"; $total += (int) $r['amount_kobo']; continue; }
    try {
        Withdrawals::reject($pdo, $adminId, $id, $reason);
        $done++; $total += (int) $r['amount_kobo'];
        echo "rejected #$id\n";
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "failed #$id: " . $e->getMessage() . "\n");
    }
}
echo 'reject-pending-withdrawals: ' . ($dry ? count($rows) . ' pending (dry run)' : "$done rejected, $failed failed") . ', ' . number_format($total / 100, 2) . " NGN returned to wallets\n";
exit($failed > 0 ? 2 : 0);
