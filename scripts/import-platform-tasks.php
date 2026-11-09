<?php
declare(strict_types=1);

/**
 * One-shot bulk import of YOLOTASK tasks (migration 0019), safe to run again.
 *
 * Reads a UTF-8 CSV whose first line is the header
 *   bundle_key,platform,account_name,task_type,url,instructions
 * (columns in any order; instructions may be empty for the standard text).
 * Rows with the same bundle_key become one bundle (one social account); each
 * row is one task with its own link, type, instructions and reward (the task
 * type's normal reward x platform_tasks.reward_percent, default half).
 * Idempotent: a task is matched on bundle_key + task type + normalised link,
 * so re-running the same file adds nothing (and never revives a task an admin
 * removed); an existing bundle is reused and
 * keeps its name and status. Bad rows (unknown platform or task type, a link
 * not on the platform, ...) are listed and skipped; the rest are imported in
 * one transaction. --dry-run checks everything and saves nothing.
 *
 * Usage: php scripts/import-platform-tasks.php --file=tasks.csv [--dry-run] [--config-dir=/path/to/config-private]
 * Exit code: 0 = done (even with skipped rows), 1 = could not run, 2 = every row failed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opts = getopt('', ['config-dir:', 'file:', 'dry-run']);
if (isset($opts['config-dir'])) putenv('YOLOTASK_CONFIG_DIR=' . $opts['config-dir']);
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

use YoloTask\PlatformTasks\PlatformTasks;

$file = (string) ($opts['file'] ?? '');
if ($file === '' || !is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "import-platform-tasks: give --file=<csv> (a readable CSV file).\n");
    exit(1);
}
$pdo = \YoloTask\Core\Database::connection();
if (!(bool) $pdo->query("SHOW TABLES LIKE 'platform_task_bundles'")->fetchColumn()) {
    fwrite(STDERR, "import-platform-tasks: migration 0019_platform_tasks_activation.sql is not applied yet. Run scripts/migrate.php first.\n");
    exit(1);
}
$dry = isset($opts['dry-run']);
try {
    $rows = PlatformTasks::parseCsv((string) file_get_contents($file));
    $res = PlatformTasks::import($pdo, null, $rows, $dry);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'import-platform-tasks: ' . $e->getMessage() . "\n");
    exit(1);
}
$p = $dry ? 'import-platform-tasks (dry run, nothing saved)' : 'import-platform-tasks';
fwrite(STDOUT, "{$p}: rows read: " . count($rows) . "\n");
fwrite(STDOUT, "{$p}: bundles created: {$res['bundles_created']}, existing bundles reused: {$res['bundles_reused']}\n");
fwrite(STDOUT, "{$p}: tasks added: {$res['added']}, already there (skipped): {$res['skipped']}\n");
fwrite(STDOUT, "{$p}: rows not imported: " . count($res['errors']) . "\n");
foreach ($res['errors'] as $e) fwrite(STDOUT, "  {$e}\n");
exit($rows !== [] && count($res['errors']) === count($rows) ? 2 : 0);
