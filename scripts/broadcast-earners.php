<?php
declare(strict_types=1);

/**
 * One-off email broadcast to earners, plus optional in-app announcement.
 *
 * The message comes from a JSON file:
 *   {"subject": "...", "heading": "...", "lines": ["paragraph", ...],
 *    "button": {"label": "...", "url": "https://..."} (optional),
 *    "announcement": {"title": "...", "body": "...", "ends_local": "Y-m-dTH:i"} (optional)}
 * Recipients: every earner whose account is active and whose email is verified
 * (--include-unverified adds unverified ones). Resumable: each sent user id is
 * appended to --state (default runtime/logs/broadcast-<name>.sent), so a second
 * run never emails anyone twice; --limit caps one run (host mail limits).
 * --announce posts the announcement once (marked in the state file).
 *
 * Usage: php scripts/broadcast-earners.php --file=msg.json [--limit=150] [--sleep-ms=400]
 *        [--announce] [--include-unverified] [--dry-run] [--state=path] [--config-dir=...]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opts = getopt('', ['config-dir:', 'file:', 'limit:', 'sleep-ms:', 'announce', 'include-unverified', 'dry-run', 'state:']);
if (isset($opts['config-dir'])) putenv('YOLOTASK_CONFIG_DIR=' . $opts['config-dir']);
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

use YoloTask\Messaging\Announcements;
use YoloTask\Messaging\Mailer;
use YoloTask\Security\Audit;

$file = (string) ($opts['file'] ?? '');
$msg = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
if (!is_array($msg) || trim((string) ($msg['subject'] ?? '')) === '' || !is_array($msg['lines'] ?? null) || $msg['lines'] === []) {
    fwrite(STDERR, "broadcast-earners: give --file=<json> with subject, heading and lines.\n");
    exit(1);
}
$dry = isset($opts['dry-run']);
$limit = max(1, (int) ($opts['limit'] ?? 150));
$sleepUs = max(0, (int) ($opts['sleep-ms'] ?? 400)) * 1000;
$name = preg_replace('/[^a-z0-9_-]+/i', '-', pathinfo($file, PATHINFO_FILENAME));
$state = (string) ($opts['state'] ?? (dirname(YOLOTASK_APP_ROOT, 2) . '/runtime/logs/broadcast-' . $name . '.sent'));
if (!is_dir(dirname($state))) $state = sys_get_temp_dir() . '/broadcast-' . $name . '.sent';

$sent = [];
if (is_file($state)) foreach (file($state, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) $sent[trim($l)] = true;

$pdo = \YoloTask\Core\Database::connection();
$adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();

// In-app announcement (once).
if (isset($opts['announce']) && is_array($msg['announcement'] ?? null) && !isset($sent['announcement'])) {
    $a = $msg['announcement'];
    if ($dry) {
        echo "broadcast-earners: would post announcement \"" . $a['title'] . "\"\n";
    } else {
        $id = Announcements::create($pdo, $adminId, (string) $a['title'], (string) $a['body'], 'earner', '', (string) ($a['ends_local'] ?? ''));
        file_put_contents($state, "announcement\n", FILE_APPEND | LOCK_EX);
        echo "broadcast-earners: announcement #$id posted\n";
    }
}

$sql = "SELECT id, email FROM users WHERE role = 'earner' AND status = 'active'" . (isset($opts['include-unverified']) ? '' : ' AND email_verified_at IS NOT NULL') . ' ORDER BY id';
$all = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$todo = array_values(array_filter($all, static fn ($u) => !isset($sent[(string) $u['id']])));
echo 'broadcast-earners: earners matched: ' . count($all) . ', already sent: ' . (count($all) - count($todo)) . ', to send now: ' . min($limit, count($todo)) . ($dry ? ' (dry run)' : '') . "\n";
if ($dry) exit(0);

$ok = 0; $fail = 0;
$button = is_array($msg['button'] ?? null) ? $msg['button'] : null;
foreach (array_slice($todo, 0, $limit) as $u) {
    $good = Mailer::send((string) $u['email'], (string) $msg['subject'], (string) ($msg['heading'] ?? $msg['subject']), $msg['lines'], $button);
    if ($good) { $ok++; file_put_contents($state, $u['id'] . "\n", FILE_APPEND | LOCK_EX); } else { $fail++; }
    if ($sleepUs) usleep($sleepUs);
}
if ($ok > 0 && $adminId > 0) Audit::record($pdo, $adminId, 'admin.broadcast_email', 'broadcast', $ok);
$left = count($todo) - $ok;
echo "broadcast-earners: sent $ok, failed $fail, remaining $left\n";
exit($fail > 0 && $ok === 0 ? 2 : 0);
