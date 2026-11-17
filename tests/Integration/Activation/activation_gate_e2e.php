<?php
declare(strict_types=1);
/*
 * Activation gate end-to-end test through the real earner and advertiser portals.
 * Needs MariaDB/MySQL with migrations applied and a private config dir:
 *   YOLOTASK_CONFIG_DIR=/tmp/ytcfg php tests/Integration/Activation/activation_gate_e2e.php
 * Starts php -S for the earner and advertiser portals (ports YT_GATE_PORT_BASE + 2/3,
 * default 8710), switches activation.required off and on, and checks: OFF = no
 * redirects; ON = unactivated earner 302 -> activate.php except the allowlist,
 * XHR gets a 403 JSON, started work (a job attempt, a held task slot) opens only
 * its own page, activated earners and advertisers are never gated. Restores the
 * original activation.required and removes the rows it created except the users.
 */
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 3));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';
use YoloTask\Core\Settings;

$pdo = \YoloTask\Core\Database::connection();
$fail = 0;
$ok = function (string $l, bool $c, string $extra = '') use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . ": $l" . (!$c && $extra !== '' ? "\n    " . substr($extra, 0, 400) : '') . "\n"; if (!$c) $fail++; };
$base = (int) (getenv('YT_GATE_PORT_BASE') ?: 8710);
$ports = ['earner' => $base + 2, 'advertiser' => $base + 3];
$procs = [];
foreach ($ports as $role => $port) {
    $procs[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', YOLOTASK_APP_ROOT . "/apps/$role/public"], [['file', '/dev/null', 'r'], ['file', "/tmp/yt-gate-srv-$role.log", 'a'], ['file', "/tmp/yt-gate-srv-$role.log", 'a']], $pipes, null, ['YOLOTASK_CONFIG_DIR' => (string) getenv('YOLOTASK_CONFIG_DIR')]);
}
usleep(700000);

$sfx = bin2hex(random_bytes(3));
$pw = 'Correct-Horse-9!';
$mk = function (string $role, string $name) use ($pdo, $sfx, $pw) {
    $email = strtolower($name) . $sfx . '@t.test';
    $pdo->prepare("INSERT INTO users (display_name, email, password_hash, role, email_verified_at) VALUES (:n, :e, :p, :r, UTC_TIMESTAMP())")->execute(['n' => $name . $sfx, 'e' => $email, 'p' => password_hash($pw, PASSWORD_DEFAULT), 'r' => $role]);
    return ['id' => (int) $pdo->lastInsertId(), 'email' => $email, 'role' => $role, 'jar' => tempnam(sys_get_temp_dir(), 'jar')];
};
$req = function (array $u, string $path, array $headers = [], ?array $postFields = null) use ($ports): array {
    $ch = curl_init('http://127.0.0.1:' . $ports[$u['role']] . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $u['jar'], CURLOPT_COOKIEFILE => $u['jar'], CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $headers]);
    if ($postFields !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields)); }
    $raw = (string) curl_exec($ch);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $loc = preg_match('/^Location: (.+)$/mi', substr($raw, 0, $hs), $m) ? trim($m[1]) : null;
    return ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'loc' => $loc, 'type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE), 'body' => substr($raw, $hs)];
};
$login = function (array $u) use ($req, $pw): bool {
    $p = $req($u, 'login.php');
    $tok = preg_match('/name="_csrf" value="([^"]+)"/', $p['body'], $m) ? html_entity_decode($m[1]) : '';
    $r = $req($u, 'login.php', [], ['_csrf' => $tok, 'email' => $u['email'], 'password' => $pw]);
    return $r['code'] === 303 && str_contains((string) $r['loc'], 'dashboard.php');
};
$gated = fn (array $r): bool => $r['code'] === 302 && $r['loc'] === 'activate.php';

$adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
$ADM = $adminId > 0 ? ['id' => $adminId] : $mk('admin', 'Gadm');
$original = Settings::get($pdo, 'activation.required', '0');
$set = function (string $v) use ($pdo, $ADM) { Settings::save($pdo, (int) $ADM['id'], ['activation.required' => $v]); };

$E = $mk('earner', 'Gea'); $EA = $mk('earner', 'Geb'); $EW = $mk('earner', 'Gec'); $ADV = $mk('advertiser', 'Gadv');
$pdo->prepare("INSERT INTO account_activations (user_id, source, amount_kobo, note) VALUES (:u, 'admin', 0, 'gate test')")->execute(['u' => $EA['id']]);
foreach ([$E, $EA, $EW, $ADV] as $u) $ok("login {$u['role']} {$u['email']}", $login($u));

// Started work for EW: a job attempt in progress (job 9000001) and a held task slot (campaign 9000002); FK checks off for the fake parents.
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->prepare("INSERT INTO job_attempts (job_id, earner_user_id, advertiser_user_id, status, pay_kobo, earner_kobo, fee_kobo, started_at, deadline_at) VALUES (9000001, :u, :a, 'in_progress', 100, 90, 10, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY))")->execute(['u' => $EW['id'], 'a' => $ADV['id']]);
$attemptId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO task_reservations (campaign_id, earner_user_id, status, expires_at) VALUES (9000002, :u, 'active', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))")->execute(['u' => $EW['id']]);
$resId = (int) $pdo->lastInsertId();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

try {
    // OFF: nothing changes.
    $set('0');
    foreach (['dashboard.php', 'tasks.php', 'jobs.php', 'wallet.php', 'submissions.php', 'referrals.php'] as $p) {
        $r = $req($E, $p);
        $ok("OFF: earner $p not gated (HTTP {$r['code']})", !$gated($r) && $r['code'] === 200, (string) $r['loc']);
    }
    // ON.
    $set('1');
    foreach (['dashboard.php', 'tasks.php', 'jobs.php', 'wallet.php', 'withdraw.php', 'submissions.php', 'referrals.php', 'marketplace.php', 'leaderboard.php', 'checkin.php', 'task.php?id=9000002', 'job.php?id=9000001'] as $p) {
        $ok("ON: unactivated earner $p -> 302 activate.php", $gated($req($E, $p)));
    }
    foreach (['activate.php', 'account.php', 'notifications.php'] as $p) {
        $r = $req($E, $p);
        $ok("ON: allowlisted $p reachable (HTTP {$r['code']})", $r['code'] === 200);
    }
    $r = $req($E, 'activate.php?o=YTA000');
    $ok("ON: activation payment return activate.php?o= passes the gate (HTTP {$r['code']}; unknown order = the page's own 404)", !$gated($r) && in_array($r['code'], [200, 404], true));
    $r = $req($E, 'tasks.php', ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
    $ok('ON: XHR/JSON request gets 403 JSON', $r['code'] === 403 && str_contains($r['type'], 'application/json') && str_contains($r['body'], 'activation_required'), $r['code'] . ' ' . $r['type']);
    $r = $req($E, 'logout.php');
    $ok('ON: logout.php not gated', !$gated($r));
    $login($E);

    // Started work: only its own pages open.
    $r = $req($EW, 'job.php?id=9000001');
    $ok("ON: earner with in-progress attempt passes the gate on job.php for that job (HTTP {$r['code']})", !$gated($r));
    $ok('ON: same earner, another job -> gated', $gated($req($EW, 'job.php?id=9000003')));
    $r = $req($EW, 'task.php?id=9000002');
    $ok("ON: earner with a held slot passes the gate on task.php for that campaign (HTTP {$r['code']})", !$gated($r));
    $ok('ON: same earner, another task -> gated', $gated($req($EW, 'task.php?id=9000004')));
    $r = $req($EW, 'submissions.php');
    $ok("ON: earner with started work reaches My work (HTTP {$r['code']})", $r['code'] === 200);
    $ok('ON: same earner, tasks.php still gated', $gated($req($EW, 'tasks.php')));
    $ok('ON: same earner, dashboard.php still gated', $gated($req($EW, 'dashboard.php')));

    // Activated earner and advertiser: never gated.
    foreach (['dashboard.php', 'tasks.php', 'wallet.php'] as $p) {
        $r = $req($EA, $p);
        $ok("ON: activated earner $p (HTTP {$r['code']})", $r['code'] === 200);
    }
    foreach (['dashboard.php', 'campaigns.php', 'wallet.php'] as $p) {
        $r = $req($ADV, $p);
        $ok("ON: advertiser $p not gated (HTTP {$r['code']})", $r['code'] === 200);
    }
    // OFF again.
    $set('0');
    $r = $req($E, 'tasks.php');
    $ok('OFF again: earner tasks.php 200', $r['code'] === 200);
} finally {
    $set($original);
    $pdo->prepare('DELETE FROM job_attempts WHERE id = :i')->execute(['i' => $attemptId]);
    $pdo->prepare('DELETE FROM task_reservations WHERE id = :i')->execute(['i' => $resId]);
    foreach ($procs as $p) proc_terminate($p);
}
echo $fail === 0 ? "ALL PASS\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
