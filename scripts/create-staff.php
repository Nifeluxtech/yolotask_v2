<?php
declare(strict_types=1);

/**
 * Create the first YOLOTASK admin (or any staff member) from the command line
 * and email them a 48-hour link to set their own password.
 *
 * Usage (cPanel Terminal, from the current release):
 *   php scripts/create-staff.php --name="Ada Obi" --email=ada@example.com --role=admin [--config-dir=...] [--portal-url=https://admin.yolotask.com.ng]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opts = getopt('', ['name:', 'email:', 'role:', 'config-dir:', 'portal-url:']);
$root = dirname(__DIR__);
if (!empty($opts['config-dir'])) putenv('YOLOTASK_CONFIG_DIR=' . $opts['config-dir']);
define('YOLOTASK_APP_ROLE', 'admin');
define('YOLOTASK_APP_ROOT', $root);
require $root . '/shared/bootstrap.php';

$role = (string) ($opts['role'] ?? 'admin');
if (!empty($opts['portal-url'])) {
    $GLOBALS['config']['base_urls'][$role] = (string) $opts['portal-url'];
}
try {
    $pdo = \YoloTask\Core\Database::connection();
    $id = \YoloTask\Admin\Users::inviteStaff($pdo, 0, (string) ($opts['name'] ?? ''), (string) ($opts['email'] ?? ''), $role);
    fwrite(STDOUT, "Created {$role} #{$id}. An email with a set-password link was sent to " . $opts['email'] . ".\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'create-staff: ' . $e->getMessage() . "\n");
    exit(1);
}
