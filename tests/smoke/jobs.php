<?php
declare(strict_types=1);

/*
 * Jobs smoke test (migration 0023). Needs only the php CLI (no database):
 *   php tests/smoke/jobs.php
 * 1. php -l on every Jobs file and every file the build touched.
 * 2. Migration 0023 is additive and seeds the accounts and settings.
 * 3. Pure rules: 90/10 split rounded down to the kobo, proof kinds, step
 *    completion order, form validation with the default settings, file proof
 *    type/size checks, labels.
 * 4. Wiring: settings defaults, ledger reconciliation, cron, nav, reviewer and
 *    admin tabs, notification category, proof endpoint access rules.
 * The money flows themselves run end to end in tests/Integration/Jobs/jobs_e2e.php.
 */

$root = dirname(__DIR__, 2);
require $root . '/shared/autoload.php';

use YoloTask\Jobs\Attempts;
use YoloTask\Jobs\JobProofs;
use YoloTask\Jobs\Jobs;

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    if ($ok) { echo "PASS: {$label}\n"; return; }
    $failures++;
    fwrite(STDERR, "FAIL: {$label}\n");
};
$throws = static function (callable $f, string $needle): bool {
    try { $f(); return false; } catch (RuntimeException $e) { return $needle === '' || str_contains($e->getMessage(), $needle); }
};

// 1. Lint.
$files = array_merge(
    glob($root . '/shared/lib/Jobs/*.php') ?: [],
    glob($root . '/shared/routes/*job*.php') ?: [],
    glob($root . '/apps/*/public/job*.php') ?: [],
    array_map(static fn (string $f): string => $root . '/' . $f, [
        'shared/bootstrap.php', 'shared/lib/Core/Settings.php', 'shared/lib/Money/Ledger.php', 'shared/lib/Money/Wallet.php', 'shared/lib/Messaging/Notifications.php',
        'shared/lib/Tasks/ReviewClaims.php', 'shared/lib/Tasks/ReviewerActions.php', 'shared/routes/review-helpers.php', 'shared/routes/tasks-helpers.php', 'shared/routes/reviewer-history.php',
        'cron/every-five-minutes.php', 'cron/every-fifteen-minutes.php', 'cron/hourly.php',
    ])
);
foreach (array_unique($files) as $f) {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $check('php -l ' . substr($f, strlen($root) + 1), $rc === 0);
}
foreach (['advertiser' => ['jobs', 'job', 'job-new', 'job-review', 'job-proof'], 'earner' => ['jobs', 'job', 'job-proof'], 'reviewer' => ['job-appeals', 'job-appeal', 'job-proof'], 'admin' => ['jobs', 'job-proof']] as $role => $pages) {
    foreach ($pages as $p) {
        $src = (string) @file_get_contents("{$root}/apps/{$role}/public/{$p}.php");
        $check("front controller apps/{$role}/public/{$p}.php defines role {$role}", str_contains($src, "define('YOLOTASK_APP_ROLE', '{$role}')"));
    }
}

// 2. Migration.
$sql = (string) @file_get_contents($root . '/database/migrations/0023_jobs.sql');
$check('migration 0023 exists', $sql !== '');
foreach (['jobs', 'job_steps', 'job_attempts', 'job_files', 'job_step_proofs', 'job_send_backs', 'job_funding_events', 'job_appeals'] as $t) $check("migration creates {$t}", str_contains($sql, "CREATE TABLE {$t} ("));
$check('migration is additive (no DROP / RENAME / TRUNCATE / DELETE)', !preg_match('/\b(DROP\s+(TABLE|COLUMN|INDEX|KEY|FOREIGN)|RENAME|TRUNCATE|DELETE\s+FROM)\b/i', (string) preg_replace('/--.*$/m', '', $sql)));
foreach (['system:jobs_escrow', 'system:jobs_revenue', 'system:jobs_appeal_payouts'] as $a) $check("migration seeds ledger account {$a}", str_contains($sql, "'{$a}'"));
$defaults = \YoloTask\Core\Settings::DEFAULTS;
foreach (['jobs.enabled' => '1', 'jobs.min_pay_kobo' => '20000', 'jobs.earner_percent' => '90', 'jobs.max_steps' => '20', 'jobs.time_limit_min_hours' => '1', 'jobs.time_limit_max_hours' => '336', 'jobs.send_back_max_rounds' => '2', 'jobs.send_back_fix_hours' => '48', 'jobs.max_active_per_earner' => '1'] as $k => $v) {
    $check("setting {$k} default {$v} (Settings + migration)", ($defaults[$k] ?? null) === $v && str_contains($sql, "('{$k}', '{$v}')"));
}
$check('review_claims / reviewer_actions learn job_appeal', str_contains($sql, "ENUM('appeal', 'submission', 'job_appeal')") && str_contains($sql, "'job_appeal_overturned'"));

// 3. Pure rules. A PDO with no database: Settings falls back to its defaults.
$pdo = new class extends PDO {
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { throw new PDOException('no database in smoke test'); }
};
$s = Jobs::split(100000, 90);
$check('split ₦1,000 at 90% = ₦900 / ₦100', $s['earner'] === 90000 && $s['fee'] === 10000);
$s = Jobs::split(33333, 90);
$check('split rounds the earner share down to the kobo (33333 -> 29999 / 3334)', $s['earner'] === 29999 && $s['fee'] === 3334);
$s = Jobs::split(20000, 100);
$check('split at 100% leaves no platform share', $s['earner'] === 20000 && $s['fee'] === 0);
$check('defaults: min pay ₦200, 90%, 20 steps, 1-336 h, 2 rounds, 48 h fix, 1 active', Jobs::minPay($pdo) === 20000 && Jobs::earnerPercent($pdo) === 90 && Jobs::maxSteps($pdo) === 20
    && Jobs::timeLimitBounds($pdo) === ['min' => 1, 'max' => 336] && Jobs::sendBackMaxRounds($pdo) === 2 && Jobs::fixHours($pdo) === 48 && Jobs::maxActivePerEarner($pdo) === 1 && Jobs::enabled($pdo));
$check('proof kinds parse and ignore unknown kinds', Jobs::kinds('image,text,bogus,file') === ['image', 'text', 'file']);

$step = static fn (int $id, int $pos, string $kinds): array => ['id' => $id, 'position' => $pos, 'proof_kinds' => $kinds];
$proof = static fn (array $over = []): array => $over + ['text_answer' => null, 'link_url' => null, 'image_file_id' => null, 'file_file_id' => null];
$steps = [$step(1, 1, 'image'), $step(2, 2, 'text,link'), $step(3, 3, 'file')];
$check('step with every required kind is done', Attempts::stepDone($steps[1], $proof(['text_answer' => 'red', 'link_url' => 'https://x.test'])));
$check('step missing one required kind is not done', !Attempts::stepDone($steps[1], $proof(['text_answer' => 'red'])));
$check('done count stops at the first unfinished step', Attempts::doneCount($steps, [1 => $proof(['image_file_id' => 5]), 3 => $proof(['file_file_id' => 9])]) === 1);
$check('done count = all when every step is done', Attempts::doneCount($steps, [1 => $proof(['image_file_id' => 5]), 2 => $proof(['text_answer' => 'a', 'link_url' => 'https://x.test']), 3 => $proof(['file_file_id' => 9])]) === 3);

$in = ['title' => 'Mystery shopper', 'instructions' => 'Visit our store and report back.', 'link_url' => '', 'mode' => 'offline', 'location' => 'Ikeja, Lagos', 'pay' => '1000', 'slots' => '3',
    'time_limit' => '2', 'time_unit' => 'days', 'review_hours' => '48', 'steps' => [
        ['title' => 'Visit', 'instructions' => 'Go to the shop.', 'kinds' => ['image']],
        ['title' => 'Answer', 'instructions' => 'What colour is the sign?', 'kinds' => ['link', 'text', 'nonsense']],
        ['title' => '', 'instructions' => '', 'kinds' => []],
    ]];
$v = Jobs::validate($pdo, $in);
$check('validate: offline job with 2 real steps (empty block skipped), 48 h limit', $v['mode'] === 'offline' && $v['location'] === 'Ikeja, Lagos' && count($v['steps']) === 2 && $v['time_limit_hours'] === 48 && $v['pay'] === 100000 && $v['slots'] === 3);
$check('validate: proof kinds ordered and unknown dropped', $v['steps'][1]['kinds'] === ['text', 'link']);
$check('validate: online job drops the location', Jobs::validate($pdo, ['mode' => 'online'] + $in)['location'] === null);
$check('validate: offline job needs a location', $throws(fn () => Jobs::validate($pdo, ['location' => ''] + $in), 'location'));
$check('validate: pay below ₦200 refused', $throws(fn () => Jobs::validate($pdo, ['pay' => '199'] + $in), 'at least'));
$check('validate: one step refused', $throws(fn () => Jobs::validate($pdo, ['steps' => [$in['steps'][0]]] + $in), 'at least 2'));
$check('validate: 21 steps refused', $throws(fn () => Jobs::validate($pdo, ['steps' => array_fill(0, 21, $in['steps'][0])] + $in), 'at most 20'));
$check('validate: step without proof kind refused', $throws(fn () => Jobs::validate($pdo, ['steps' => [$in['steps'][0], ['title' => 'Two', 'instructions' => 'Do it now', 'kinds' => []]]] + $in), 'proof'));
$check('validate: time limit over 14 days refused', $throws(fn () => Jobs::validate($pdo, ['time_limit' => '15'] + $in), 'time limit'));
$check('validate: time limit under 1 hour refused', $throws(fn () => Jobs::validate($pdo, ['time_limit' => '0', 'time_unit' => 'hours'] + $in), 'time limit'));
$check('validate: review window outside task auto-approve bounds refused', $throws(fn () => Jobs::validate($pdo, ['review_hours' => '2'] + $in), 'review window'));
$check('validate: javascript: link refused', $throws(fn () => Jobs::validate($pdo, ['link_url' => 'javascript:alert(1)'] + $in), 'link'));
$check('validate: zero slots refused', $throws(fn () => Jobs::validate($pdo, ['slots' => '0'] + $in), 'Slots'));

$f = JobProofs::fileFromBytes("%PDF-1.4\nhello\n", '../../My Report.pdf');
$check('file proof: pdf accepted, name cleaned', $f['ext'] === 'pdf' && $f['name'] === 'My Report.pdf' && strlen($f['sha']) === 64);
$check('file proof: php refused by extension', $throws(fn () => JobProofs::fileFromBytes('<?php echo 1;', 'x.php'), 'Upload a'));
$check('file proof: html disguised as .txt refused by content', $throws(fn () => JobProofs::fileFromBytes("<!DOCTYPE html><html><body><script>alert(1)</script></body></html>", 'x.txt'), 'not allowed'));
$check('file proof: over 10 MB refused', $throws(fn () => JobProofs::fileFromBytes(str_repeat('a', JobProofs::FILE_MAX_BYTES + 1), 'big.txt'), 'larger'));
$check('file proof: empty refused', $throws(fn () => JobProofs::fileFromBytes('', 'e.pdf'), 'empty'));
$check('proof access: owner earner, owning advertiser, admin; reviewer only when appealed', JobProofs::canView(['earner_user_id' => 5, 'advertiser_user_id' => 9, 'appeal_count' => 0], ['role' => 'earner', 'id' => 5])
    && !JobProofs::canView(['earner_user_id' => 5, 'advertiser_user_id' => 9, 'appeal_count' => 0], ['role' => 'earner', 'id' => 6])
    && JobProofs::canView(['earner_user_id' => 5, 'advertiser_user_id' => 9, 'appeal_count' => 0], ['role' => 'advertiser', 'id' => 9])
    && !JobProofs::canView(['earner_user_id' => 5, 'advertiser_user_id' => 9, 'appeal_count' => 0], ['role' => 'advertiser', 'id' => 8])
    && JobProofs::canView(['earner_user_id' => 5, 'advertiser_user_id' => 9, 'appeal_count' => 0], ['role' => 'admin', 'id' => 1])
    && !JobProofs::canView(['earner_user_id' => 5, 'advertiser_user_id' => 9, 'appeal_count' => 0], ['role' => 'reviewer', 'id' => 2])
    && JobProofs::canView(['earner_user_id' => 5, 'advertiser_user_id' => 9, 'appeal_count' => 1], ['role' => 'reviewer', 'id' => 2]));
$check('proof path refuses traversal', JobProofs::path(['storage_path' => '../../config-private/app.local.php']) === null);
$check('labels', Jobs::hoursLabel(336) === '14 days' && Jobs::hoursLabel(1) === '1 hour' && Jobs::statusLabel('paused', true)[0] === 'Paused by YOLOTASK' && Attempts::statusLabel(['status' => 'sent_back'])[0] === 'Sent back for a fix');
$check('free slots: slots - approved - active, 0 when closed', Jobs::freeSlots(['status' => 'live', 'slots' => 5, 'approved_count' => 2, 'active_count' => 1]) === 2 && Jobs::freeSlots(['status' => 'closed', 'slots' => 5, 'approved_count' => 0, 'active_count' => 0]) === 0);

// 4. Wiring.
$src = static fn (string $f): string => (string) @file_get_contents($root . '/' . $f);
$check('Ledger::reconcile includes Jobs::reconcile', str_contains($src('shared/lib/Money/Ledger.php'), 'Jobs\\Jobs::reconcile($pdo)'));
$check('reward hold reconciliation counts held job rewards', str_contains($src('shared/lib/Money/Ledger.php'), "FROM job_attempts WHERE reward_status = 'held'"));
$check('cron: expiry every 5 min, auto-approve every 15 min, release + reconcile hourly', str_contains($src('cron/every-five-minutes.php'), 'Attempts::expireDue') && str_contains($src('cron/every-fifteen-minutes.php'), 'Jobs\\Reviews::autoApproveDue') && str_contains($src('cron/hourly.php'), 'Jobs\\Reviews::releaseDue') && str_contains($src('cron/hourly.php'), 'Jobs\\Jobs::reconcile'));
$boot = $src('shared/bootstrap.php');
$check('nav: earner and advertiser get Jobs; Marketplace dock untouched', str_contains($boot, "\$role === 'earner') \$items[] = ['key' => 'jobs'") && str_contains($boot, "\$role === 'advertiser') \$items[] = ['key' => 'jobs'") && str_contains($boot, '$marketplaceDock = in_array(yolotask_role(), [\'earner\', \'advertiser\'], true);'));
$check('reviewer tabs include Job appeals', str_contains($src('shared/routes/review-helpers.php'), "'job_appeals' => ['Job appeals', 'job-appeals.php'"));
$check('admin task tabs include Jobs', str_contains($src('shared/routes/tasks-helpers.php'), "'jobs' => ['Jobs', 'jobs.php'"));
$check('notification category jobs for earners and advertisers', isset(\YoloTask\Messaging\Notifications::CATEGORIES['jobs']) && \YoloTask\Messaging\Notifications::CATEGORIES['jobs'][2] === ['earner', 'advertiser']);
$check('review claims accept job_appeal', in_array('job_appeal', \YoloTask\Tasks\ReviewClaims::TYPES, true));
$check('wallet labels job postings', \YoloTask\Money\Wallet::entryLabel(['type' => 'job_reward', 'kind' => 'wallet_available']) === 'Job reward' && \YoloTask\Money\Wallet::entryLabel(['type' => 'job_fund', 'kind' => 'wallet_available']) === 'Job funded');
$check('job proof endpoint is a sandboxed download for non-images', str_contains($src('shared/routes/job-proof.php'), "'application/octet-stream'") && str_contains($src('shared/routes/job-proof.php'), 'sandbox'));
$check('docs/jobs.md and CHANGELOG entry exist', is_file($root . '/docs/jobs.md') && str_contains($src('CHANGELOG.md'), '0023_jobs.sql'));

echo $failures === 0 ? "OK: jobs smoke test passed\n" : "FAILED: {$failures} check(s)\n";
exit($failures === 0 ? 0 : 1);
