<?php
declare(strict_types=1);

/*
 * Activation gate smoke test. Needs only the php CLI (no database):
 *   php tests/smoke/activation-gate.php
 * 1. php -l on every file the gate touches.
 * 2. Wiring: yolotask_require_user() calls the gate for the earner portal only;
 *    the allowlist is a named array of real earner script basenames and holds
 *    activate.php; the gate starts with canWork (activation.required = 0 or
 *    activated = no-op); started work is checked per item; the per-feature
 *    checks are still in place.
 * 3. Behaviour (subprocesses, settings stubbed in memory, no database):
 *    activation.required = 0 -> no-op on a gated page; required = 1 ->
 *    allowlisted page passes; non-earner roles return before any check.
 * The redirects themselves run end to end in tests/Integration/Activation/activation_gate_e2e.php.
 */

$root = dirname(__DIR__, 2);
$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    if ($ok) { echo "PASS: {$label}\n"; return; }
    $failures++;
    fwrite(STDERR, "FAIL: {$label}\n");
};
$src = static fn (string $f): string => (string) file_get_contents($root . '/' . $f);

// 1. Lint.
foreach (['shared/bootstrap.php', 'shared/lib/Account/Activation.php', 'tests/Integration/Activation/activation_gate_e2e.php'] as $f) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $out, $rc);
    $check("php -l {$f}", $rc === 0);
}

// 2. Wiring.
$boot = $src('shared/bootstrap.php');
$req = preg_match('/function yolotask_require_user\(PDO \$pdo\): array\s*\{(.*?)\n\}/s', $boot, $m) ? $m[1] : '';
$check('gate called from yolotask_require_user', str_contains($req, 'yolotask_activation_gate($pdo, $user)'));
$check('gate only for the earner portal', str_contains($req, "if (yolotask_role() === 'earner') yolotask_activation_gate(") && str_contains($boot, "if (yolotask_role() !== 'earner' || (string) (\$user['role'] ?? '') !== 'earner') return;"));
$check('gate runs after the sign-in and role checks', strpos($req, 'yolotask_error_page(403)') < strpos($req, 'yolotask_activation_gate('));
$gate = preg_match('/function yolotask_activation_gate\(PDO \$pdo, array \$user\): void\s*\{(.*?)\n\}/s', $boot, $g) ? $g[1] : '';
$check('no-op when activation.required = 0 or activated (canWork before any redirect)', $gate !== '' && str_contains($gate, 'Activation::canWork($pdo, (int) $user[\'id\'])) return;') && strpos($gate, 'canWork') < strpos($gate, 'Location: activate.php'));
$check('canWork is false only while required and not activated', str_contains($src('shared/lib/Account/Activation.php'), "return !self::required(\$pdo) || self::isActivated(\$pdo, \$userId);"));
$check('activation.required is read through Settings', str_contains($src('shared/lib/Account/Activation.php'), "Settings::get(\$pdo, 'activation.required', '0') === '1'"));
$check('blocked request: 302 to activate.php', str_contains($gate, "header('Location: activate.php', true, 302);"));
$check('blocked JSON/XHR request: 403 JSON', str_contains($gate, 'Maintenance::wantsJson()') && str_contains($gate, 'http_response_code(403)') && str_contains($gate, 'application/json'));

$allow = preg_match('/const YOLOTASK_ACTIVATION_GATE_ALLOWLIST = \[(.*?)\];/', $boot, $a) ? array_map(static fn ($s) => trim($s, " '"), explode(',', $a[1])) : [];
$check('allowlist is a named array', $allow !== []);
$check('allowlist contains activate.php', in_array('activate.php', $allow, true));
$check('allowlist contains logout, account, verify, notifications', array_diff(['logout.php', 'account.php', 'verify.php', 'notifications.php'], $allow) === []);
$check('allowlist has no work pages', array_intersect(['tasks.php', 'task.php', 'jobs.php', 'job.php', 'wallet.php', 'withdraw.php', 'dashboard.php', 'marketplace.php'], $allow) === []);
$check('every allowlisted page exists in apps/earner/public', array_filter($allow, static fn ($p) => !is_file($root . '/apps/earner/public/' . $p)) === []);
$check('activation payment returns to activate.php', str_contains($src('shared/lib/Account/Activation.php'), "'/activate.php?o=' . rawurlencode(\$orderSn)"));

$act = $src('shared/lib/Account/Activation.php');
$check('started work: task slot, pending/rejected submission, active job attempt', str_contains($act, 'public static function startedWorkAllows(') && str_contains($act, "r.status = 'active' AND r.expires_at > UTC_TIMESTAMP()") && str_contains($act, "s.status = 'pending'") && str_contains($act, '\\YoloTask\\Jobs\\Attempts::ACTIVE'));
$check('started work is per item for task.php, submission.php, job.php', str_contains($act, "case 'task.php':") && str_contains($act, "case 'submission.php':") && str_contains($act, "case 'job.php':") && substr_count($act, 'if ($id <= 0) return false;') === 3);
$check('unknown pages are never opened by started work', str_contains($act, "default:\n                return false;"));
$check('per-feature checks kept: Tasks::start, Attempts::start, task/job pages, withdraw', str_contains($src('shared/lib/Tasks/Tasks.php'), 'Activation::canWork($pdo, $uid)') && str_contains($src('shared/routes/earner-task.php'), 'Activation::canWork($pdo, $uid)') && str_contains($src('shared/routes/earner-job.php'), 'Activation::canWork($pdo, $uid)') && str_contains($act, 'public static function canWithdraw('));
$check('proof endpoints do not go through require_user (owner checks only)', !str_contains($src('shared/routes/proof.php'), 'yolotask_require_user') && !str_contains($src('shared/routes/job-proof.php'), 'yolotask_require_user'));
$check('CHANGELOG and docs mention the gate', str_contains($src('CHANGELOG.md'), 'YOLOTASK_ACTIVATION_GATE_ALLOWLIST') && str_contains((string) shell_exec('grep -rl "YOLOTASK_ACTIVATION_GATE_ALLOWLIST" ' . escapeshellarg($root . '/docs')), '.md'));

// 3. Behaviour: run the gate in a subprocess with settings stubbed (no database).
$probe = static function (string $role, string $userRole, string $required, string $script) use ($root): string {
    $code = <<<'PHP'
[$root, $role, $userRole, $required, $script] = array_slice($argv, 1);
define('YOLOTASK_APP_ROLE', $role); define('YOLOTASK_APP_ROOT', $root); define('YOLOTASK_NO_SESSION', true);
putenv('YOLOTASK_CONFIG_DIR=/nonexistent-yt-config');
require $root . '/shared/bootstrap.php';
$p = new ReflectionProperty(\YoloTask\Core\Settings::class, 'cache');
$p->setValue(null, array_merge(\YoloTask\Core\Settings::DEFAULTS, ['activation.required' => $required]));
$pdo = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor(); // any query on it would throw
$_SERVER['SCRIPT_NAME'] = '/' . $script; $_GET = ['id' => '7'];
try { yolotask_activation_gate($pdo, ['id' => 42, 'role' => $userRole]); echo 'PASSED'; } catch (Throwable $e) { echo 'DB:' . get_class($e); }
PHP;
    return (string) shell_exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-r', $code, $root, $role, $userRole, $required, $script])) . ' 2>&1');
};
$check('required = 0: earner on tasks.php passes without touching the database', $probe('earner', 'earner', '0', 'tasks.php') === 'PASSED');
$check('required = 0: earner on job.php passes', $probe('earner', 'earner', '0', 'job.php') === 'PASSED');
$check('required = 1: allowlisted activate.php passes before any check', $probe('earner', 'earner', '1', 'activate.php') === 'PASSED');
$check('required = 1: tasks.php goes on to the activation lookup', str_starts_with($probe('earner', 'earner', '1', 'tasks.php'), 'DB:'));
foreach (['advertiser', 'reviewer', 'admin'] as $r) {
    $check("required = 1: {$r} portal untouched", $probe($r, $r, '1', 'dashboard.php') === 'PASSED');
}

echo $failures === 0 ? "OK: activation gate smoke test passed\n" : "FAILED: {$failures} check(s)\n";
exit($failures === 0 ? 0 : 1);
