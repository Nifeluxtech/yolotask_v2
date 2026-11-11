<?php
declare(strict_types=1);

/**
 * Email announcement to earners through Brevo (never the hosting server's mail).
 *
 * Message JSON (same shape as before):
 *   {"subject": "...", "heading": "...", "lines": ["paragraph", ...],
 *    "button": {"label": "...", "url": "https://..."} (optional),
 *    "announcement": {"title": "...", "body": "...", "ends_local": "Y-m-dTH:i"} (optional)}
 *
 * Config (config-private/brevo.local.php, never committed):
 *   return ['api_key' => '...', 'sender_email' => 'no-reply@yolotask.com.ng', 'sender_name' => 'YOLOTASK'];
 *
 * Recipients: active earners with a verified email (--include-unverified adds
 * the rest). Resumable: each sent user id is appended to the state file, so a
 * rerun never emails anyone twice. Brevo's free plan sends 300 emails a day, so
 * --daily-limit (default 290) caps what one calendar day (Africa/Lagos) sends
 * across runs; run it once a day (cron) until "remaining 0".
 * --announce posts the in-app announcement once.
 *
 * Usage: php scripts/announce-brevo.php --file=msg.json [--daily-limit=290] [--batch=50]
 *        [--announce] [--include-unverified] [--dry-run] [--state=path] [--config-dir=...]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opts = getopt('', ['config-dir:', 'file:', 'daily-limit:', 'batch:', 'announce', 'include-unverified', 'dry-run', 'state:', 'api-base:']);
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
    fwrite(STDERR, "announce-brevo: give --file=<json> with subject, heading and lines.\n");
    exit(1);
}
$cfgFile = rtrim((string) (yolotask_config()['_config_dir'] ?? ''), '/') . '/brevo.local.php';
$brevo = is_file($cfgFile) ? require $cfgFile : [];
$apiKey = (string) ($brevo['api_key'] ?? '');
$sender = ['email' => (string) ($brevo['sender_email'] ?? 'no-reply@yolotask.com.ng'), 'name' => (string) ($brevo['sender_name'] ?? 'YOLOTASK')];
$dry = isset($opts['dry-run']);
if ($apiKey === '' && !$dry) {
    fwrite(STDERR, "announce-brevo: no api_key in config-private/brevo.local.php.\n");
    exit(1);
}
$apiBase = rtrim((string) ($opts['api-base'] ?? 'https://api.brevo.com'), '/');
$dailyLimit = max(1, (int) ($opts['daily-limit'] ?? 290));
$batch = min(100, max(1, (int) ($opts['batch'] ?? 50)));
$name = preg_replace('/[^a-z0-9_-]+/i', '-', pathinfo($file, PATHINFO_FILENAME));
$state = (string) ($opts['state'] ?? (\YoloTask\Support\Storage::dir('logs') ?? sys_get_temp_dir()) . '/announce-' . $name . '.sent');

// State lines: "<user id> <Y-m-d>" per sent email, "announcement" once.
$today = (new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos')))->format('Y-m-d');
$sent = []; $sentToday = 0; $announced = false;
if (is_file($state)) foreach (file($state, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    $p = explode(' ', trim($l));
    if ($p[0] === 'announcement') { $announced = true; continue; }
    $sent[$p[0]] = true;
    if (($p[1] ?? '') === $today) $sentToday++;
}

$pdo = \YoloTask\Core\Database::connection();
$adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();

if (isset($opts['announce']) && is_array($msg['announcement'] ?? null) && !$announced) {
    $a = $msg['announcement'];
    if ($dry) {
        echo "announce-brevo: would post announcement \"" . $a['title'] . "\"\n";
    } else {
        $id = Announcements::create($pdo, $adminId, (string) $a['title'], (string) $a['body'], 'earner', '', (string) ($a['ends_local'] ?? ''));
        file_put_contents($state, "announcement\n", FILE_APPEND | LOCK_EX);
        echo "announce-brevo: announcement #$id posted\n";
    }
}

$sql = "SELECT id, email FROM users WHERE role = 'earner' AND status = 'active'" . (isset($opts['include-unverified']) ? '' : ' AND email_verified_at IS NOT NULL') . ' ORDER BY id';
$all = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$todo = array_values(array_filter($all, static fn ($u) => !isset($sent[(string) $u['id']]) && filter_var($u['email'], FILTER_VALIDATE_EMAIL)));
$room = max(0, $dailyLimit - $sentToday);
$now = array_slice($todo, 0, $room);
echo 'announce-brevo: earners matched: ' . count($all) . ', already sent: ' . count($sent) . ", sent today: $sentToday, to send now: " . count($now) . ($dry ? ' (dry run)' : '') . "\n";
if ($dry || $now === []) { echo 'announce-brevo: remaining ' . count($todo) . "\n"; exit(0); }

$button = is_array($msg['button'] ?? null) ? $msg['button'] : null;
$body = Mailer::render((string) ($msg['heading'] ?? $msg['subject']), $msg['lines'], $button);
$ok = 0; $fail = 0;
foreach (array_chunk($now, $batch) as $chunk) {
    $payload = [
        'sender' => $sender,
        'subject' => (string) $msg['subject'],
        'htmlContent' => $body['html'],
        'textContent' => $body['text'],
        'tags' => ['announce-' . $name],
        'messageVersions' => array_map(static fn ($u) => ['to' => [['email' => (string) $u['email']]]], $chunk),
    ];
    $ch = curl_init($apiBase . '/v3/smtp/email');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $apiKey],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) {
        $ok += count($chunk);
        file_put_contents($state, implode('', array_map(static fn ($u) => $u['id'] . ' ' . $today . "\n", $chunk)), FILE_APPEND | LOCK_EX);
    } else {
        $fail += count($chunk);
        fwrite(STDERR, "announce-brevo: Brevo answered HTTP $code: " . substr((string) $resp, 0, 300) . "\n");
        break; // stop on the first refusal (quota, unverified sender, bad key)
    }
}
if ($ok > 0 && $adminId > 0) Audit::record($pdo, $adminId, 'admin.broadcast_email', 'broadcast', $ok);
echo "announce-brevo: sent $ok, failed $fail, remaining " . (count($todo) - $ok) . "\n";
exit($fail > 0 ? 2 : 0);
