<?php
declare(strict_types=1);

/*
 * CLI helper for tests/Integration/Growth/checkin_test.py: runs the daily
 * check-in with a pinned clock (Core\Clock::freeze) and prints JSON.
 *   php checkin_cli.php checkin <userId> <ISO-8601 now>   -> {"ok":true,...} | {"ok":false,"error":"..."}
 *   php checkin_cli.php status <userId> <ISO-8601 now>    -> Checkin::status()
 *   php checkin_cli.php lagos-date <ISO-8601 now>         -> {"date":"Y-m-d"}
 *   php checkin_cli.php repost <userId> <Y-m-d> <kobo>    -> Ledger::post with the same key (idempotency)
 * Needs YOLOTASK_CONFIG_DIR (except lagos-date).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('YOLOTASK_APP_ROLE', 'earner');
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 3));
define('YOLOTASK_NO_SESSION', true);
require_once YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

use YoloTask\Core\Clock;
use YoloTask\Core\Database;
use YoloTask\Growth\Checkin;
use YoloTask\Money\Ledger;
use YoloTask\Money\Wallet;

$mode = $argv[1] ?? '';
$user = static function (PDO $pdo, int $id): array {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $u = $stmt->fetch();
    if (!is_array($u)) { fwrite(STDERR, "no such user\n"); exit(1); }
    return $u;
};
if ($mode === 'lagos-date') {
    echo json_encode(['date' => Clock::lagosDate(new DateTimeImmutable((string) ($argv[2] ?? 'now')))]);
} elseif ($mode === 'checkin' || $mode === 'status') {
    Clock::freeze(new DateTimeImmutable((string) ($argv[3] ?? 'now')));
    $pdo = Database::connection();
    $u = $user($pdo, (int) $argv[2]);
    if ($mode === 'status') { echo json_encode(Checkin::status($pdo, $u)); exit; }
    try {
        echo json_encode(['ok' => true] + Checkin::checkIn($pdo, $u));
    } catch (RuntimeException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
} elseif ($mode === 'repost') {
    $pdo = Database::connection();
    $uid = (int) $argv[2];
    $pdo->beginTransaction();
    $w = Wallet::lock($pdo, $uid);
    $tx = Ledger::post($pdo, 'checkin:' . $uid . ':' . $argv[3], 'checkin_reward', 'replay', [
        [Ledger::systemAccountId($pdo, Ledger::CHECKIN_REWARDS), 'debit', (int) $argv[4]],
        [$w['available_account_id'], 'credit', (int) $argv[4]],
    ], 'user', $uid, $uid);
    $pdo->commit();
    echo json_encode($tx);
} else {
    fwrite(STDERR, "usage: checkin_cli.php checkin|status|lagos-date|repost ...\n");
    exit(1);
}
