<?php
declare(strict_types=1);

/**
 * One-off: create the two BetFugu YOLOTASK jobs (migration 0024), safe to run again.
 *
 *   A  "Sign up on BetFugu and use the free bet"              ₦50,  4 steps
 *   B  "Sign up on BetFugu, use the free bet and deposit"     ₦150, 5 steps
 *
 * Both: online, link https://s.betfugu01.com/hhdcwxfv6tstuba, 10,000 slots
 * (a cap: YOLOTASK jobs hold no escrow), 24 hours to finish, 48-hour review
 * window. Owned by the YOLOTASK system account; the earner gets the full pay,
 * paid by YOLOTASK on approval. Idempotent: a job is skipped when a YOLOTASK
 * job with the same title already exists (whatever its status), so a cron
 * line can run it more than once; remove the cron line after the first run.
 * --dry-run validates both jobs and saves nothing.
 *
 * cPanel has no shell, so run it once from Cron Jobs, e.g.
 *   /opt/cpanel/ea-php84/root/usr/bin/php $HOME/yolotask-v2-live/current/scripts/create-betfugu-jobs.php --config-dir=$HOME/yolotask-v2-live/config-private >> $HOME/yolotask-v2-live/runtime/logs/betfugu-jobs.log 2>&1
 *
 * Usage: php scripts/create-betfugu-jobs.php [--dry-run] [--config-dir=/path/to/config-private]
 * Exit code: 0 = done (created or already there), 1 = could not run.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opts = getopt('', ['config-dir:', 'dry-run']);
if (isset($opts['config-dir'])) putenv('YOLOTASK_CONFIG_DIR=' . $opts['config-dir']);
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

use YoloTask\Jobs\Jobs;
use YoloTask\Money\Money;

$dry = isset($opts['dry-run']);
$p = $dry ? 'create-betfugu-jobs (dry run, nothing saved)' : 'create-betfugu-jobs';

$common = [
    'mode' => 'online', 'location' => '', 'link_url' => 'https://s.betfugu01.com/hhdcwxfv6tstuba',
    'instructions' => "18+ only. Create a brand-new BetFugu account using the link above. One account per person; existing accounts don't count.",
    'slots' => '10000', 'time_limit' => '24', 'time_unit' => 'hours', 'review_hours' => '48',
];
$register = ['title' => 'Register with the link', 'instructions' => 'Open the link and sign up with your phone number.', 'kinds' => ['image']];
$verify = ['title' => 'Verify your account', 'instructions' => 'Complete the phone/OTP verification.', 'kinds' => ['image']];
$freeBet = ['title' => 'Use the free bet', 'instructions' => 'Claim the welcome free bet and place it on any game.', 'kinds' => ['image']];
$deposit = ['title' => 'Make a deposit', 'instructions' => 'Deposit any amount into your BetFugu account.', 'kinds' => ['image']];
$userId = ['title' => 'Send your user ID', 'instructions' => 'Open your BetFugu profile and type your BetFugu user ID.', 'kinds' => ['image', 'text']];
$jobs = [
    ['title' => 'Sign up on BetFugu and use the free bet', 'pay' => '50', 'steps' => [$register, $verify, $freeBet, $userId]] + $common,
    ['title' => 'Sign up on BetFugu, use the free bet and deposit', 'pay' => '150', 'steps' => [$register, $verify, $freeBet, $deposit, $userId]] + $common,
];

try {
    $pdo = \YoloTask\Core\Database::connection();
    if (!(bool) $pdo->query("SHOW COLUMNS FROM jobs LIKE 'is_system'")->fetchColumn()) {
        fwrite(STDERR, "{$p}: migration 0024_system_jobs.sql is not applied yet. Run scripts/migrate.php first.\n");
        exit(1);
    }
    // One run at a time, so two overlapping cron runs cannot both create a job.
    if ((int) $pdo->query("SELECT GET_LOCK('yolotask.create_betfugu_jobs', 0)")->fetchColumn() !== 1) {
        fwrite(STDERR, "{$p}: another run is in progress; nothing done.\n");
        exit(1);
    }
    $find = $pdo->prepare('SELECT id, status FROM jobs WHERE is_system = 1 AND title = :t ORDER BY id LIMIT 1');
    foreach ($jobs as $in) {
        $find->execute(['t' => $in['title']]);
        $have = $find->fetch();
        if (is_array($have)) {
            fwrite(STDOUT, "{$p}: already there, skipped: job #{$have['id']} ({$have['status']}) \"{$in['title']}\"\n");
            continue;
        }
        $f = Jobs::validate($pdo, $in, true);
        $what = '"' . $f['title'] . '" · ' . Money::format($f['pay']) . ' · ' . count($f['steps']) . ' steps · ' . number_format($f['slots']) . ' slots · '
            . Jobs::hoursLabel($f['time_limit_hours']) . ' to finish · ' . $f['review_hours'] . ' h review';
        if ($dry) {
            fwrite(STDOUT, "{$p}: would create {$what}\n");
            continue;
        }
        $id = Jobs::publishSystem($pdo, null, $in);
        fwrite(STDOUT, "{$p}: created job #{$id} {$what}\n");
    }
    $pdo->query("SELECT RELEASE_LOCK('yolotask.create_betfugu_jobs')");
} catch (Throwable $e) {
    fwrite(STDERR, "{$p}: " . ($e instanceof RuntimeException ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage()) . "\n");
    exit(1);
}
exit(0);
