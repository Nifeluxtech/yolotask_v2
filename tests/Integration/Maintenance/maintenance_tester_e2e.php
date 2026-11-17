<?php
declare(strict_types=1);
/*
 * Maintenance tester link end-to-end through the real admin, main and earner portals.
 * Needs MariaDB/MySQL with migrations applied and a private config dir:
 *   YOLOTASK_CONFIG_DIR=/tmp/ytcfg php tests/Integration/Maintenance/maintenance_tester_e2e.php
 * Starts php -S for main/earner/admin (ports YT_TESTER_PORT_BASE + 1/2/5, default
 * 8730), creates a tester link from Admin → Maintenance, switches maintenance ON
 * and checks: earner pages 503 without the link, pass with ?tester and then with
 * the yt_tester cookie alone, the tester can sign in, only the hash is stored,
 * revoke closes it again. Restores the original maintenance settings.
 */
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 3));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';
use YoloTask\Core\Settings;

$pdo = \YoloTask\Core\Database::connection();
$fail = 0;
$ok = function (string $l, bool $c, string $extra = '') use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . ": $l" . (!$c && $extra !== '' ? "\n    " . substr($extra, 0, 400) : '') . "\n"; if (!$c) $fail++; };
$base = (int) (getenv('YT_TESTER_PORT_BASE') ?: 8730);
$ports = ['main' => $base + 1, 'earner' => $base + 2, 'admin' => $base + 5];
$procs = [];
foreach ($ports as $role => $port) {
    $procs[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', YOLOTASK_APP_ROOT . "/apps/$role/public"], [['file', '/dev/null', 'r'], ['file', "/tmp/yt-tester-srv-$role.log", 'a'], ['file', "/tmp/yt-tester-srv-$role.log", 'a']], $pipes, null, ['YOLOTASK_CONFIG_DIR' => (string) getenv('YOLOTASK_CONFIG_DIR')]);
}
register_shutdown_function(function () use (&$procs) { foreach ($procs as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } } });
usleep(700000);

$sfx = bin2hex(random_bytes(3));
$pw = 'Correct-Horse-9!';
$mk = function (string $role, string $name) use ($pdo, $sfx, $pw) {
    $email = strtolower($name) . $sfx . '@t.test';
    $pdo->prepare("INSERT INTO users (display_name, email, password_hash, role, email_verified_at) VALUES (:n, :e, :p, :r, UTC_TIMESTAMP())")->execute(['n' => $name . $sfx, 'e' => $email, 'p' => password_hash($pw, PASSWORD_DEFAULT), 'r' => $role]);
    return ['id' => (int) $pdo->lastInsertId(), 'email' => $email, 'role' => $role, 'jar' => tempnam(sys_get_temp_dir(), 'jar')];
};
$req = function (array $u, string $path, array $headers = [], ?array $postFields = null, ?string $portal = null) use ($ports): array {
    $ch = curl_init('http://127.0.0.1:' . $ports[$portal ?? $u['role']] . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $u['jar'], CURLOPT_COOKIEFILE => $u['jar'], CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $headers]);
    if ($postFields !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields)); }
    $raw = (string) curl_exec($ch);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $head = substr($raw, 0, $hs);
    $loc = preg_match('/^Location: (.+)$/mi', $head, $m) ? trim($m[1]) : null;
    return ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'loc' => $loc, 'head' => $head, 'body' => substr($raw, $hs)];
};
$csrfOf = fn (string $body): string => preg_match('/name="_csrf" value="([^"]+)"/', $body, $m) ? html_entity_decode($m[1]) : '';
$login = function (array $u) use ($req, $pw, $csrfOf): bool {
    $p = $req($u, 'login.php');
    $r = $req($u, 'login.php', [], ['_csrf' => $csrfOf($p['body']), 'email' => $u['email'], 'password' => $pw]);
    return $r['code'] === 303 && str_contains((string) $r['loc'], 'dashboard.php');
};
$keys = ['maintenance.enabled', 'maintenance.message', 'maintenance.until', 'maintenance.preview_hash', 'maintenance.preview_expires_at'];
$original = [];
foreach ($keys as $k) $original[$k] = (string) Settings::get($pdo, $k, Settings::DEFAULTS[$k]);

$ADM = $mk('admin', 'Tadm'); $E = $mk('earner', 'Tea');
$ok('admin login', $login($ADM));
$set = function (array $v) use ($pdo, $ADM) { Settings::save($pdo, $ADM['id'], $v); };

try {
    $set(['maintenance.enabled' => '0', 'maintenance.preview_hash' => '', 'maintenance.preview_expires_at' => '0']);
    // Admin creates the link (maintenance still OFF).
    $page = $req($ADM, 'maintenance.php');
    $ok('admin Maintenance page has the Tester link section', $page['code'] === 200 && str_contains($page['body'], 'id="tester"') && str_contains($page['body'], 'Create tester link'));
    $r = $req($ADM, 'maintenance.php', [], ['_csrf' => $csrfOf($page['body']), 'action' => 'tester_create']);
    $ok('create tester link redirects', $r['code'] === 303, $r['head']);
    $page = $req($ADM, 'maintenance.php');
    $token = preg_match('#value="https?://[^"]+/\?tester=([0-9a-f]{64})"#', $page['body'], $m) ? $m[1] : '';
    $ok('link shown once with the token', $token !== '', substr($page['body'], 0, 300));
    $ok('link not shown again on reload', !str_contains($req($ADM, 'maintenance.php')['body'], 'data-tester-url'));
    Settings::flush();
    $storedHash = (string) Settings::get($pdo, 'maintenance.preview_hash');
    $storedExp = (int) Settings::get($pdo, 'maintenance.preview_expires_at');
    $ok('only the sha256 is stored', $storedHash === hash('sha256', $token) && (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE setting_value LIKE '%" . $token . "%'")->fetchColumn() === 0);
    $ok('expiry about 7 days ahead', abs($storedExp - (time() + 7 * 86400)) < 120);
    $ok('creation audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE event_key = 'admin.maintenance_tester_created' AND actor_user_id = {$ADM['id']}")->fetchColumn() >= 1);

    // OFF: token does nothing.
    $r = $req($E, 'login.php?tester=' . $token);
    $ok('OFF: earner login 200 and no tester cookie', $r['code'] === 200 && !str_contains($r['head'], 'yt_tester'));

    $set(['maintenance.enabled' => '1']);
    $anon = ['role' => 'earner', 'jar' => tempnam(sys_get_temp_dir(), 'jar')];
    $ok('ON: earner login 503 without the link', $req($anon, 'login.php')['code'] === 503);
    $ok('ON: earner dashboard 503 without the link', $req($anon, 'dashboard.php')['code'] === 503);
    $ok('ON: main site 503 without the link', $req($anon, '/', [], null, 'main')['code'] === 503);
    $ok('ON: wrong token 503', $req($anon, 'login.php?tester=' . bin2hex(random_bytes(32)))['code'] === 503);

    $T = $E; $T['jar'] = tempnam(sys_get_temp_dir(), 'jar');
    $r = $req($T, 'dashboard.php?tester=' . $token);
    $ok('ON, ?tester: earner dashboard redirects to login', in_array($r['code'], [302, 303], true) && str_contains((string) $r['loc'], 'login'), $r['head']);
    $ok('ON, ?tester: yt_tester cookie set HttpOnly SameSite=Lax', (bool) preg_match('/Set-Cookie: yt_tester=' . $token . ';[^\r\n]*HttpOnly/i', $r['head']) && stripos($r['head'], 'samesite=lax') !== false, $r['head']);
    $r = $req($T, 'login.php');
    $ok('ON, cookie only: earner login 200 with tester banner', $r['code'] === 200 && str_contains($r['body'], 'data-tester-banner'));
    $ok('ON, cookie only: tester can sign in', $login($T));
    $r = $req($T, 'dashboard.php');
    $ok('ON, cookie only: signed-in dashboard 200 with banner', $r['code'] === 200 && str_contains($r['body'], 'data-tester-banner'), (string) $r['code']);
    $ok('ON, cookie only: main site 200', $req($T, '/', [], null, 'main')['code'] === 200);

    // Revoke.
    $page = $req($ADM, 'maintenance.php');
    $r = $req($ADM, 'maintenance.php', [], ['_csrf' => $csrfOf($page['body']), 'action' => 'tester_revoke']);
    $ok('revoke redirects', $r['code'] === 303);
    $ok('revoked: cookie 503', $req($T, 'dashboard.php')['code'] === 503);
    $ok('revoked: ?tester 503', $req($anon, 'login.php?tester=' . $token)['code'] === 503);
    $ok('revocation audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE event_key = 'admin.maintenance_tester_revoked' AND actor_user_id = {$ADM['id']}")->fetchColumn() >= 1);

    // Expired.
    $set(['maintenance.preview_hash' => hash('sha256', $token), 'maintenance.preview_expires_at' => (string) (time() - 5)]);
    $ok('expired: cookie 503', $req($T, 'dashboard.php')['code'] === 503);
} finally {
    $set($original);
}
echo $fail === 0 ? "ALL PASS\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
