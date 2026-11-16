<?php
declare(strict_types=1);

/*
 * Maintenance mode smoke test. Needs only the php CLI (no database):
 *   php tests/smoke/maintenance-mode.php
 * 1. php -l on every maintenance file.
 * 2. Unit checks on YoloTask\Core\Maintenance (ETA parsing, Retry-After, JSON detection, exemptions).
 * 3. Starts PHP's built-in server for the main, earner, advertiser, reviewer and
 *    admin document roots with a stub settings source (auto_prepend_file calling
 *    Maintenance::useState from a JSON file) and an empty private config dir, then
 *    checks the gate: 503 + Retry-After + no-cache + escaped message when ON, JSON
 *    for XHR, admin/hooks/static files exempt, and fail-open when settings can't load.
 */

$root = dirname(__DIR__, 2);
require $root . '/shared/autoload.php';

use YoloTask\Core\Maintenance;

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    if ($ok) { echo "PASS: {$label}\n"; return; }
    $failures++;
    fwrite(STDERR, "FAIL: {$label}\n");
};

// 1. Lint.
$files = [
    'shared/lib/Core/Maintenance.php', 'shared/lib/Core/Settings.php', 'shared/bootstrap.php',
    'shared/routes/admin-maintenance.php', 'shared/routes/tasks-helpers.php', 'shared/routes/dashboard.php',
    'shared/views/errors/maintenance.php', 'shared/views/layouts/authenticated.php', 'apps/admin/public/maintenance.php',
];
foreach ($files as $f) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $out, $rc);
    $check("php -l {$f}", $rc === 0);
}

// 2. Unit checks.
$tz = new DateTimeZone('Africa/Lagos');
$now = new DateTimeImmutable('2026-11-16 12:00', $tz);
$check('parseUntil accepts Y-m-d H:i', Maintenance::parseUntil('2026-11-16 14:30')?->format('Y-m-d H:i T') === '2026-11-16 14:30 WAT');
$check('parseUntil accepts datetime-local', Maintenance::parseUntil('2026-11-16T14:30') !== null);
$check('parseUntil rejects junk', Maintenance::parseUntil('tomorrow') === null && Maintenance::parseUntil('2026-13-40 99:99') === null);
$check('parseUntil empty is null', Maintenance::parseUntil('') === null);
$check('Retry-After counts to the ETA', Maintenance::retryAfter(['until' => new DateTimeImmutable('2026-11-16 13:00', $tz)], $now) === 3600);
$check('Retry-After default without ETA', Maintenance::retryAfter(['until' => null], $now) === 600);
$check('Retry-After default when ETA passed', Maintenance::retryAfter(['until' => new DateTimeImmutable('2026-11-16 11:00', $tz)], $now) === 600);
$check('Retry-After at least 60s', Maintenance::retryAfter(['until' => new DateTimeImmutable('2026-11-16 12:00:10', $tz)], $now) === 60);
Maintenance::useState(['enabled' => '1', 'message' => '', 'until' => '']);
$s = Maintenance::state();
$check('useState stub ON with default message', $s['enabled'] && $s['message'] === Maintenance::DEFAULT_MESSAGE && $s['until'] === null);
Maintenance::useState(null);
$check('CLI is always exempt', Maintenance::exempt('earner'));
$check('settings default is OFF', \YoloTask\Core\Settings::DEFAULTS['maintenance.enabled'] === '0');

// 3. HTTP gate through the built-in server.
$tmp = sys_get_temp_dir() . '/yt-maint-' . bin2hex(random_bytes(4));
mkdir($tmp . '/config', 0700, true);
$stubFile = $tmp . '/state.json';
$prepend = $tmp . '/prepend.php';
file_put_contents($prepend, "<?php\nrequire " . var_export($root . '/shared/autoload.php', true) . ";\n"
    . "\$f = getenv('YT_MAINT_STUB');\n"
    . "if (\$f && is_file(\$f)) \\YoloTask\\Core\\Maintenance::useState(json_decode((string) file_get_contents(\$f), true));\n");
$setState = static function (?array $state) use ($stubFile): void {
    if ($state === null) { @unlink($stubFile); return; }
    file_put_contents($stubFile, json_encode($state));
};

$portals = ['main' => 8611, 'earner' => 8612, 'advertiser' => 8613, 'reviewer' => 8614, 'admin' => 8615];
$procs = [];
$env = array_merge(getenv(), ['YOLOTASK_CONFIG_DIR' => $tmp . '/config', 'YT_MAINT_STUB' => $stubFile]);
foreach ($portals as $portal => $port) {
    $cmd = [PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $tmp . '/php-' . $portal . '.log',
        '-S', '127.0.0.1:' . $port, '-t', $root . '/apps/' . $portal . '/public'];
    $procs[] = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/srv-' . $portal . '.out', 'a'], 2 => ['file', $tmp . '/srv-' . $portal . '.out', 'a']], $pipes, $root, $env);
}
$cleanup = static function () use (&$procs, $tmp): void {
    foreach ($procs as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
    $procs = [];
    exec('rm -rf ' . escapeshellarg($tmp));
};
register_shutdown_function($cleanup);

$request = static function (string $portal, string $path, string $method = 'GET', array $headers = [], string $body = '') use ($portals): array {
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", array_merge(['Host: 127.0.0.1'], $headers)), 'content' => $body, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
    $content = @file_get_contents('http://127.0.0.1:' . $portals[$portal] . $path, false, $ctx);
    $hdrs = $http_response_header ?? [];
    $status = 0; $map = [];
    foreach ($hdrs as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int) $m[1]; continue; }
        if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $map[strtolower(trim($k))][] = trim($v); }
    }
    return ['status' => $status, 'headers' => $map, 'body' => (string) $content];
};
// Wait for the servers.
foreach ($portals as $portal => $port) {
    for ($i = 0; $i < 50; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($fp) { fclose($fp); break; }
        usleep(100000);
    }
}

// Fail open: no stub, no database config -> the gate must not close the site.
$setState(null);
$r = $request('main', '/');
$check('fail open: main site serves 200 when settings cannot load', $r['status'] === 200);

// OFF.
$setState(['enabled' => '0', 'message' => 'x', 'until' => '']);
$r = $request('main', '/');
$check('OFF: main site serves 200', $r['status'] === 200 && !str_contains($r['body'], 'data-maintenance-message'));

// ON with an ETA two hours ahead and a hostile message.
$until = (new DateTimeImmutable('now', $tz))->modify('+2 hours')->format('Y-m-d H:i');
$setState(['enabled' => '1', 'message' => '<script>alert(1)</script> Back soon & stronger', 'until' => $until]);
$r = $request('main', '/');
$check('ON: main site returns 503', $r['status'] === 503);
$retry = (int) ($r['headers']['retry-after'][0] ?? 0);
$check('ON: Retry-After follows the ETA (~7200s)', $retry > 7000 && $retry <= 7200);
$check('ON: no-cache headers', str_contains($r['headers']['cache-control'][0] ?? '', 'no-store'));
$check('ON: message is escaped', str_contains($r['body'], '&lt;script&gt;alert(1)&lt;/script&gt; Back soon &amp; stronger') && !str_contains($r['body'], '<script>alert(1)'));
$check('ON: ETA line shown', str_contains($r['body'], 'We expect to be back by'));
$check('ON: page uses the site stylesheet and theme toggle', str_contains($r['body'], 'assets/css/app.css') && str_contains($r['body'], 'data-theme-toggle'));
$check('ON: no links into gated pages', !preg_match('/href="[^"]+"/', str_replace(['href="/assets/', 'href=""'], ['', ''], $r['body'])));
$check('ON: no session cookie', !isset($r['headers']['set-cookie']));
foreach (['earner' => '/login.php', 'advertiser' => '/register.php', 'reviewer' => '/', 'main' => '/terms.php'] as $portal => $path) {
    $check("ON: {$portal}{$path} returns 503", $request($portal, $path)['status'] === 503);
}
$r = $request('earner', '/login.php', 'POST', ['Content-Type: application/x-www-form-urlencoded'], 'email=a%40b.c&password=x');
$check('ON: POST login returns 503', $r['status'] === 503);
$r = $request('earner', '/tasks.php', 'POST', ['X-Requested-With: fetch', 'Content-Type: application/x-www-form-urlencoded'], 'a=1');
$json = json_decode($r['body'], true);
$check('ON: XHR gets 503 JSON', $r['status'] === 503 && str_contains($r['headers']['content-type'][0] ?? '', 'application/json') && is_array($json) && ($json['error'] ?? '') === 'maintenance' && ($json['retry_after'] ?? 0) > 7000);
$r = $request('advertiser', '/wallet.php', 'GET', ['Accept: application/json']);
$check('ON: Accept: application/json gets JSON', $r['status'] === 503 && is_array(json_decode($r['body'], true)));
$r = $request('earner', '/login.php', 'HEAD');
$check('ON: HEAD returns 503', $r['status'] === 503);

// Exemptions.
$r = $request('admin', '/login.php');
$check('ON: admin portal is not gated', $r['status'] !== 503 && $r['status'] !== 0);
$r = $request('main', '/hooks/korapay/webhook.php');
$check('ON: payment callback is not gated (405 for GET)', $r['status'] === 405);
$r = $request('main', '/assets/css/app.css');
$check('ON: static assets are served', $r['status'] === 200);
$check('ON: earner service worker is served', $request('earner', '/service-worker.js')['status'] === 200);
$check('ON: earner manifest is served', $request('earner', '/manifest.json')['status'] === 200);

// ETA passed / no ETA.
$setState(['enabled' => '1', 'message' => '', 'until' => '2020-01-01 10:00']);
$r = $request('main', '/');
$check('ON, ETA passed: default message, longer-than-planned line, Retry-After 600', $r['status'] === 503 && str_contains($r['body'], 'getting an upgrade') && str_contains($r['body'], 'longer than planned') && ($r['headers']['retry-after'][0] ?? '') === '600');

$cleanup();
if ($failures > 0) { fwrite(STDERR, "{$failures} check(s) failed\n"); exit(1); }
echo "PASS: maintenance mode gate\n";
