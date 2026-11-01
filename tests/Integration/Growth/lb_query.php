<?php
declare(strict_types=1);

/*
 * CLI helper for tests/Integration/Growth/leaderboard_test.py: prints JSON from
 * YoloTask\Growth\Leaderboard without going through a portal.
 *   php lb_query.php bounds <week|month> <ISO-8601 now>
 *   php lb_query.php get <workers|referrers> <week|month> [viewerId]
 *   php lb_query.php mask <display name>
 * Needs YOLOTASK_CONFIG_DIR for "get".
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('YOLOTASK_APP_ROLE', 'main');
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 3));
define('YOLOTASK_NO_SESSION', true);
require_once YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

use YoloTask\Growth\Leaderboard;

$mode = $argv[1] ?? '';
if ($mode === 'bounds') {
    $b = Leaderboard::bounds((string) ($argv[2] ?? 'week'), new DateTimeImmutable((string) ($argv[3] ?? 'now')));
    echo json_encode(['from' => $b['from'], 'to' => $b['to'], 'label' => $b['label'], 'start_wat' => $b['start_wat']->format('Y-m-d H:i:s P')]);
} elseif ($mode === 'get') {
    $viewer = isset($argv[4]) ? (int) $argv[4] : null;
    echo json_encode(Leaderboard::get(\YoloTask\Core\Database::connection(), (string) $argv[2], (string) $argv[3], $viewer));
} elseif ($mode === 'mask') {
    $m = Leaderboard::maskName((string) ($argv[2] ?? ''));
    echo json_encode(['masked' => $m, 'initials' => Leaderboard::initials($m)]);
} else {
    fwrite(STDERR, "usage: lb_query.php bounds|get|mask ...\n");
    exit(1);
}
