<?php
declare(strict_types=1);

/*
 * Maintenance tester-link smoke test. Needs only the php CLI (no database):
 *   php tests/smoke/maintenance-tester.php
 * 1. Unit checks on the tester token (format, hash only, valid / wrong /
 *    expired / revoked) and the shared cookie domain.
 * 2. Starts PHP's built-in server for the main and earner document roots with a
 *    stub settings source (Maintenance::useState from a JSON file) and checks:
 *    ?tester=<token> passes and sets the yt_tester cookie (HttpOnly, Secure,
 *    SameSite=Lax, Domain=.yolotask.com.ng, path /, expiry = link expiry); the
 *    cookie alone passes; wrong / expired / revoked tokens get the 503; with
 *    maintenance OFF the token does nothing; the tester banner shows only on the pass.
 */

$root = dirname(__DIR__, 2);
require $root . '/shared/autoload.php';

use YoloTask\Core\Maintenance;

$failures = 0;
$check = static function (string $label, bool $ok, string $extra = '') use (&$failures): void {
    if ($ok) { echo "PASS: {$label}\n"; return; }
    $failures++;
    fwrite(STDERR, "FAIL: {$label}" . ($extra !== '' ? "\n    " . substr($extra, 0, 400) : '') . "\n");
};

// 1. Lint.
foreach (['shared/lib/Core/Maintenance.php', 'shared/lib/Core/Settings.php', 'shared/routes/admin-maintenance.php',
    'shared/views/layouts/auth.php', 'shared/views/layouts/authenticated.php', 'shared/views/layouts/public.php',
    'shared/views/layouts/market.php', 'shared/views/layouts/document.php'] as $f) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $out, $rc);
    $check("php -l {$f}", $rc === 0);
}

// 2. Unit checks.
$token = Maintenance::newTesterToken();
$hash = Maintenance::testerHash($token);
$future = time() + 7 * 86400;
$state = ['enabled' => true, 'preview_hash' => $hash, 'preview_expires_at' => $future];
$check('token is 64 hex characters (32 random bytes)', (bool) preg_match('/^[0-9a-f]{64}$/', $token) && $token !== Maintenance::newTesterToken());
$check('stored value is the sha256, not the token', $hash === hash('sha256', $token) && $hash !== $token && !str_contains($hash, $token));
$check('valid token accepted', Maintenance::testerTokenValid($token, $state));
$check('wrong token rejected', !Maintenance::testerTokenValid(Maintenance::newTesterToken(), $state));
$check('hash itself is not a token', !Maintenance::testerTokenValid($hash, $state));
$check('malformed token rejected', !Maintenance::testerTokenValid(strtoupper($token), $state) && !Maintenance::testerTokenValid('', $state) && !Maintenance::testerTokenValid($token . 'a', $state));
$check('expired token rejected', !Maintenance::testerTokenValid($token, ['preview_hash' => $hash, 'preview_expires_at' => time() - 1]));
$check('revoked (empty hash) rejected', !Maintenance::testerTokenValid($token, ['preview_hash' => '', 'preview_expires_at' => $future]));
$check('settings defaults: no tester link', \YoloTask\Core\Settings::DEFAULTS['maintenance.preview_hash'] === '' && \YoloTask\Core\Settings::DEFAULTS['maintenance.preview_expires_at'] === '0');
$_SERVER['HTTP_HOST'] = 'earner.yolotask.com.ng';
$check('cookie domain .yolotask.com.ng from an earner host', Maintenance::cookieDomain('https://yolotask.com.ng') === '.yolotask.com.ng');
$_SERVER['HTTP_HOST'] = 'reviewer-staging.yolotask.com.ng';
$check('cookie domain on staging covers the -staging portals', Maintenance::cookieDomain('https://staging.yolotask.com.ng') === '.yolotask.com.ng');
$_SERVER['HTTP_HOST'] = '127.0.0.1:8000';
$check('host-only cookie on an IP address', Maintenance::cookieDomain('https://127.0.0.1') === null);
$_SERVER['HTTP_HOST'] = 'evil.example.com';
$check('host-only cookie when the host is outside the main domain', Maintenance::cookieDomain('https://yolotask.com.ng') === null);
unset($_SERVER['HTTP_HOST']);
$check('admin route stores only the hash', (bool) preg_match("/'maintenance\\.preview_hash' => Maintenance::testerHash\\(\\\$token\\)/", (string) file_get_contents($root . '/shared/routes/admin-maintenance.php')));
$check('no banner outside the pass', Maintenance::testerBanner() === '');

// 3. HTTP gate through the built-in server.
$tmp = sys_get_temp_dir() . '/yt-tester-' . bin2hex(random_bytes(4));
mkdir($tmp . '/config', 0700, true);
file_put_contents($tmp . '/config/app.local.php', "<?php\nreturn ['session' => ['secure' => true], 'base_urls' => ['main' => 'https://yolotask.com.ng']];\n");
$stubFile = $tmp . '/state.json';
$prepend = $tmp . '/prepend.php';
file_put_contents($prepend, "<?php\nrequire " . var_export($root . '/shared/autoload.php', true) . ";\n"
    . "\$f = getenv('YT_MAINT_STUB');\n"
    . "if (\$f && is_file(\$f)) \\YoloTask\\Core\\Maintenance::useState(json_decode((string) file_get_contents(\$f), true));\n");
$setState = static function (array $s) use ($stubFile): void { file_put_contents($stubFile, json_encode($s)); };

$portals = ['main' => 8621, 'earner' => 8622];
$procs = [];
$env = array_merge(getenv(), ['YOLOTASK_CONFIG_DIR' => $tmp . '/config', 'YT_MAINT_STUB' => $stubFile]);
foreach ($portals as $portal => $port) {
    $cmd = [PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $tmp . '/php-' . $portal . '.log',
        '-S', '127.0.0.1:' . $port, '-t', $root . '/apps/' . $portal . '/public'];
    $procs[] = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/srv.out', 'a'], 2 => ['file', $tmp . '/srv.out', 'a']], $pipes, $root, $env);
}
$cleanup = static function () use (&$procs, $tmp): void {
    foreach ($procs as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
    $procs = [];
    exec('rm -rf ' . escapeshellarg($tmp));
};
register_shutdown_function($cleanup);
foreach ($portals as $port) {
    for ($i = 0; $i < 50; $i++) { $fp = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($fp) { fclose($fp); break; } usleep(100000); }
}

$request = static function (string $portal, string $path, array $headers = []) use ($portals): array {
    $host = $portal === 'main' ? 'yolotask.com.ng' : $portal . '.yolotask.com.ng';
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", array_merge(['Host: ' . $host], $headers)), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
    $content = @file_get_contents('http://127.0.0.1:' . $portals[$portal] . $path, false, $ctx);
    $status = 0; $map = [];
    foreach ($http_response_header ?? [] as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int) $m[1]; continue; }
        if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $map[strtolower(trim($k))][] = trim($v); }
    }
    return ['status' => $status, 'headers' => $map, 'body' => (string) $content];
};
$testerCookie = static function (array $r): ?string {
    foreach ($r['headers']['set-cookie'] ?? [] as $c) if (str_starts_with($c, Maintenance::TESTER_COOKIE . '=')) return $c;
    return null;
};

$on = ['enabled' => '1', 'message' => '', 'until' => '', 'preview_hash' => $hash, 'preview_expires_at' => $future];
$setState($on);
$r = $request('main', '/');
$check('ON, no token: 503', $r['status'] === 503);
$r = $request('main', '/?tester=' . $token);
$check('ON, valid ?tester: main site 200', $r['status'] === 200, (string) $r['status']);
$check('ON, valid ?tester: tester banner shown', str_contains($r['body'], 'data-tester-banner') && str_contains($r['body'], 'Tester mode — site is under maintenance for everyone else'));
$check('ON, valid ?tester: X-Robots-Tag noindex', in_array('noindex', $r['headers']['x-robots-tag'] ?? [], true));
$c = (string) $testerCookie($r);
$check('cookie yt_tester = the token', str_starts_with($c, 'yt_tester=' . $token . ';'), $c);
$check('cookie HttpOnly, Secure, SameSite=Lax, path /', stripos($c, 'HttpOnly') !== false && stripos($c, 'secure') !== false && stripos($c, 'SameSite=Lax') !== false && stripos($c, 'path=/') !== false, $c);
$check('cookie Domain=.yolotask.com.ng', stripos($c, 'domain=.yolotask.com.ng') !== false, $c);
$check('cookie expires with the link', preg_match('/expires=([^;]+)/i', $c, $m) === 1 && abs(strtotime($m[1]) - $future) <= 1, $c);
$r = $request('earner', '/login.php?tester=' . $token);
$check('ON, valid ?tester on the earner portal: not 503', $r['status'] !== 503 && $r['status'] !== 0, (string) $r['status']);
$check('earner cookie Domain=.yolotask.com.ng', stripos((string) $testerCookie($r), 'domain=.yolotask.com.ng') !== false);
$r = $request('main', '/', ['Cookie: yt_tester=' . $token]);
$check('ON, cookie only: main site 200, no new cookie', $r['status'] === 200 && $testerCookie($r) === null);
$r = $request('main', '/terms.php', ['Cookie: yt_tester=' . $token]);
$check('ON, cookie only: other main page 200', $r['status'] === 200);
$r = $request('main', '/?tester=' . Maintenance::newTesterToken());
$check('ON, wrong token: 503, no cookie', $r['status'] === 503 && $testerCookie($r) === null);
$r = $request('main', '/', ['Cookie: yt_tester=' . Maintenance::newTesterToken()]);
$check('ON, wrong cookie: 503', $r['status'] === 503);
$r = $request('main', '/?tester=' . $hash);
$check('ON, the stored hash used as a token: 503', $r['status'] === 503);

$setState(array_merge($on, ['preview_expires_at' => time() - 60]));
$check('ON, expired link: ?tester 503', $request('main', '/?tester=' . $token)['status'] === 503);
$check('ON, expired link: cookie 503', $request('main', '/', ['Cookie: yt_tester=' . $token])['status'] === 503);

$setState(array_merge($on, ['preview_hash' => '', 'preview_expires_at' => 0]));
$check('ON, revoked link: ?tester 503', $request('main', '/?tester=' . $token)['status'] === 503);
$check('ON, revoked link: cookie 503', $request('main', '/', ['Cookie: yt_tester=' . $token])['status'] === 503);

$newToken = Maintenance::newTesterToken();
$setState(array_merge($on, ['preview_hash' => Maintenance::testerHash($newToken)]));
$check('ON, replaced link: old token 503', $request('main', '/?tester=' . $token)['status'] === 503);
$check('ON, replaced link: new token 200', $request('main', '/?tester=' . $newToken)['status'] === 200);

$setState(array_merge($on, ['enabled' => '0']));
$r = $request('main', '/?tester=' . $token);
$check('OFF: token does nothing (200, no cookie, no banner)', $r['status'] === 200 && $testerCookie($r) === null && !str_contains($r['body'], 'data-tester-banner'));
$r = $request('main', '/');
$check('OFF: site open, no banner', $r['status'] === 200 && !str_contains($r['body'], 'data-tester-banner'));

$cleanup();
if ($failures > 0) { fwrite(STDERR, "{$failures} check(s) failed\n"); exit(1); }
echo "PASS: maintenance tester link\n";
