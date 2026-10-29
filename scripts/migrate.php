<?php
declare(strict_types=1);

/**
 * YOLOTASK V2 migration runner.
 *
 * Applies every executable file in database/migrations in filename order,
 * once, recording each in schema_migrations. Placeholder files (marked
 * "NOT executable") are skipped. A database that already has the 0001
 * tables but no history is adopted: 0001 is recorded without re-running.
 *
 * Usage: php scripts/migrate.php [--config-dir=/path/to/config-private] [--status]
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$opts = getopt('', ['config-dir:', 'status']);
$configDir = (string) ($opts['config-dir'] ?? (getenv('YOLOTASK_CONFIG_DIR') ?: dirname($root, 2) . '/config-private'));
$dbFile = rtrim($configDir, '/') . '/database.local.php';
if (!is_file($dbFile)) {
    fwrite(STDOUT, "migrate: no database config at {$dbFile}; skipping.\n");
    exit(0);
}
$cfg = require $dbFile;
if (!is_array($cfg) || empty($cfg['host']) || empty($cfg['database']) || empty($cfg['username'])) {
    fwrite(STDERR, "migrate: database config is incomplete.\n");
    exit(1);
}
$charset = (string) ($cfg['charset'] ?? 'utf8mb4');
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, 'migrate: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
});
if (!extension_loaded('pdo_mysql')) { fwrite(STDERR, "migrate: pdo_mysql extension is not loaded in this PHP CLI.\n"); exit(1); }
$pdo = new PDO(
    "mysql:host={$cfg['host']};dbname={$cfg['database']};charset={$charset}",
    (string) $cfg['username'],
    (string) ($cfg['password'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    filename VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$applied = [];
foreach ($pdo->query('SELECT filename FROM schema_migrations') as $row) {
    $applied[(string) $row['filename']] = true;
}

$files = glob($root . '/database/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

$record = $pdo->prepare('INSERT INTO schema_migrations (filename, checksum) VALUES (:f, :c)');

// Adopt a database where 0001 was applied by hand before the runner existed.
if ($applied === []) {
    $hasUsers = (bool) $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    if ($hasUsers) {
        foreach ($files as $file) {
            if (str_starts_with(basename($file), '0001_')) {
                $record->execute(['f' => basename($file), 'c' => hash_file('sha256', $file)]);
                $applied[basename($file)] = true;
                fwrite(STDOUT, "migrate: adopted existing " . basename($file) . "\n");
            }
        }
    }
}

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    $sql = (string) file_get_contents($file);
    if (isset($applied[$name])) continue;
    if (stripos($sql, 'NOT executable') !== false) continue;
    if (isset($opts['status'])) { fwrite(STDOUT, "pending: {$name}\n"); continue; }

    // Strip comment lines, split on semicolons at line ends.
    $clean = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', (string) $clean)));
    try {
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        $record->execute(['f' => $name, 'c' => hash('sha256', $sql)]);
    } catch (Throwable $e) {
        fwrite(STDERR, "migrate: {$name} failed: " . $e->getMessage() . "\n");
        exit(1);
    }
    fwrite(STDOUT, "migrate: applied {$name}\n");
    $ran++;
}
fwrite(STDOUT, $ran === 0 ? "migrate: database is up to date.\n" : "migrate: {$ran} migration(s) applied.\n");
