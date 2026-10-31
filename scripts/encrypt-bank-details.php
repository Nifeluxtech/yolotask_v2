<?php
declare(strict_types=1);

/**
 * One-off (safe to repeat): encrypt bank account numbers that were saved in
 * plain text before encryption at rest existed, in bank_accounts and
 * withdrawals. Needs the same security_key the web app uses. Writes the
 * enc2: (OpenSSL AES-256-GCM) format, so ext-sodium is not required.
 *
 * Usage: php scripts/encrypt-bank-details.php [--config-dir=/path/to/config-private] [--dry-run]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opts = getopt('', ['config-dir:', 'dry-run']);
if (isset($opts['config-dir'])) putenv('YOLOTASK_CONFIG_DIR=' . $opts['config-dir']);
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

use YoloTask\Security\Crypto;

$pdo = \YoloTask\Core\Database::connection();
$dry = isset($opts['dry-run']);
// Rows already sealed in any format (enc2: AES-256-GCM, legacy enc1: libsodium) are skipped.
$notSealed = implode(' AND ', array_map(static fn (string $p): string => 'account_number NOT LIKE ' . $pdo->quote($p . '%'), Crypto::SEAL_PREFIXES));
if (!$dry && !Crypto::gcmAvailable()) { fwrite(STDERR, "encrypt-bank-details: the PHP openssl extension (aes-256-gcm) is required.\n"); exit(1); }
foreach (['bank_accounts', 'withdrawals'] as $table) {
    $rows = $pdo->query("SELECT id, account_number FROM {$table} WHERE {$notSealed}")->fetchAll();
    $upd = $pdo->prepare("UPDATE {$table} SET account_number = :a, account_last4 = :l WHERE id = :id AND account_number = :old");
    $n = 0;
    foreach ($rows as $r) {
        $plain = (string) $r['account_number'];
        if (Crypto::isSealed($plain)) continue;
        if (!$dry) $upd->execute(['a' => Crypto::seal($plain), 'l' => substr($plain, -4), 'id' => $r['id'], 'old' => $plain]);
        $n++;
    }
    fwrite(STDOUT, "{$table}: " . ($dry ? 'would encrypt ' : 'encrypted ') . "{$n} row(s)\n");
}
