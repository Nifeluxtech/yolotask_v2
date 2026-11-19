<?php
declare(strict_types=1);

/*
 * YOLOTASK jobs ("system jobs", migration 0024) smoke test. Needs only the php CLI (no database):
 *   php tests/smoke/system-jobs.php
 * 1. php -l on every file the build touched.
 * 2. Migration 0024 is additive and safe with the previous release: one
 *    defaulted column, a CHECK that only binds is_system = 1, new ENUM values,
 *    the system account and the platform_job_payouts expense account.
 * 3. Pure rules: ₦1 minimum for YOLOTASK jobs (jobs.min_pay_kobo still binds
 *    advertisers), 100 % split, labels, proof access for reviewers.
 * 4. Wiring: platform-paid approval, reconcile skips system jobs and checks
 *    platform payouts, no advertiser notification, reviewer queue, admin form
 *    and actions, the BetFugu one-off script.
 * The money flows run end to end in tests/Integration/Jobs/system_jobs_e2e.php.
 */

$root = dirname(__DIR__, 2);
require $root . '/shared/autoload.php';

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
$src = static fn (string $f): string => (string) @file_get_contents($root . '/' . $f);

// 1. Lint.
$files = ['database/migrations/0024_system_jobs.sql'];
$php = ['shared/lib/Jobs/Jobs.php', 'shared/lib/Jobs/Reviews.php', 'shared/lib/Jobs/Attempts.php', 'shared/lib/Jobs/JobAppeals.php', 'shared/lib/Jobs/JobProofs.php', 'shared/lib/Jobs/SystemReviews.php',
    'shared/lib/Money/Ledger.php', 'shared/lib/Tasks/ReviewClaims.php', 'shared/lib/Tasks/ReviewerActions.php',
    'shared/routes/admin-jobs.php', 'shared/routes/reviewer-job-reviews.php', 'shared/routes/review-helpers.php', 'shared/routes/reviewer-history.php', 'shared/routes/jobs-helpers.php',
    'shared/routes/earner-job.php', 'shared/routes/earner-jobs.php', 'shared/routes/job-proof.php',
    'apps/reviewer/public/job-reviews.php', 'apps/reviewer/public/job-review.php', 'scripts/create-betfugu-jobs.php', 'tests/Integration/Jobs/system_jobs_e2e.php'];
foreach ($php as $f) {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $out, $rc);
    $check("php -l {$f}", $rc === 0);
}
foreach (['job-reviews', 'job-review'] as $p) {
    $c = $src("apps/reviewer/public/{$p}.php");
    $check("front controller apps/reviewer/public/{$p}.php defines role reviewer and loads reviewer-job-reviews.php", str_contains($c, "define('YOLOTASK_APP_ROLE', 'reviewer')") && str_contains($c, 'shared/routes/reviewer-job-reviews.php'));
}
$check('job-review.php is the detail view', str_contains($src('apps/reviewer/public/job-review.php'), "define('YOLOTASK_JOB_REVIEW_DETAIL', true)") && !str_contains($src('apps/reviewer/public/job-reviews.php'), 'DETAIL'));

// 2. Migration.
$sql = $src('database/migrations/0024_system_jobs.sql');
$code = (string) preg_replace('/--.*$/m', '', $sql);
$check('migration 0024 exists', $sql !== '');
$check('migration is additive (no DROP / RENAME / TRUNCATE / DELETE / UPDATE)', !preg_match('/\b(DROP\s+(TABLE|COLUMN|INDEX|KEY|FOREIGN|CONSTRAINT)|RENAME|TRUNCATE|DELETE\s+FROM|UPDATE\s+\w+\s+SET)\b/i', $code));
$check('jobs.is_system TINYINT default 0', str_contains($code, 'ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0'));
$check('CHECK binds only system jobs: no money columns, 100 % to the earner', (bool) preg_match('/CHECK \(\s*is_system = 0\s*OR \(funded_kobo = 0 AND spent_kobo = 0 AND refunded_kobo = 0 AND fee_kobo = 0 AND earner_percent = 100 AND earner_kobo = pay_kobo\)\)/', $code));
$check('ENUMs gain reviewer decisions on job attempts', str_contains($code, "ENUM('advertiser', 'auto', 'admin', 'appeal', 'reviewer')") && substr_count($code, "'job_appeal', 'job_attempt')") === 2 && str_contains($code, "'job_approved', 'job_sent_back', 'job_rejected'"));
$check('migration seeds system:platform_job_payouts (debit-normal)', str_contains($code, "('system:platform_job_payouts', 'YOLOTASK job pay paid by the platform (expense)', 'system', 'debit', 0)"));
$check('migration keeps the system account and owner setting (idempotent)', str_contains($code, "platform-tasks@system.yolotask.invalid") && str_contains($code, 'WHERE NOT EXISTS') && str_contains($code, "'platform_tasks.owner_user_id'") && str_contains($code, 'ON DUPLICATE KEY UPDATE setting_value = setting_value'));
$check('migration has no "NOT executable" marker (the runner would skip it)', stripos($sql, 'NOT executable') === false);

// 3. Pure rules (no database: Settings falls back to its defaults).
$pdo = new class extends PDO {
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { throw new PDOException('no database in smoke test'); }
};
$in = ['title' => 'Sign up on BetFugu and use the free bet', 'instructions' => "18+ only. Create a brand-new BetFugu account using the link above. One account per person; existing accounts don't count.",
    'link_url' => 'https://s.betfugu01.com/hhdcwxfv6tstuba', 'mode' => 'online', 'location' => '', 'pay' => '50', 'slots' => '10000', 'time_limit' => '24', 'time_unit' => 'hours', 'review_hours' => '48',
    'steps' => [['title' => 'Register with the link', 'instructions' => 'Open the link and sign up with your phone number.', 'kinds' => ['image']],
        ['title' => 'Send your user ID', 'instructions' => 'Open your BetFugu profile and type your BetFugu user ID.', 'kinds' => ['image', 'text']]]];
$v = Jobs::validate($pdo, $in, true);
$check('system job: ₦50 with 10,000 slots, 24 h, 48 h review validates', $v['pay'] === 5000 && $v['slots'] === 10000 && $v['time_limit_hours'] === 24 && $v['review_hours'] === 48 && $v['steps'][1]['kinds'] === ['image', 'text']);
$check('system job: ₦1 allowed', Jobs::validate($pdo, ['pay' => '1'] + $in, true)['pay'] === 100);
$check('system job: ₦0 refused', $throws(fn () => Jobs::validate($pdo, ['pay' => '0'] + $in, true), 'at least'));
$check('system job: 10,001 slots refused', $throws(fn () => Jobs::validate($pdo, ['slots' => '10001'] + $in, true), 'Slots'));
$check('advertiser job: ₦50 still refused (jobs.min_pay_kobo ₦200)', $throws(fn () => Jobs::validate($pdo, $in), 'at least'));
$check('system job: steps still capped by jobs.max_steps', $throws(fn () => Jobs::validate($pdo, ['steps' => array_fill(0, 21, $in['steps'][0])] + $in, true), 'at most 20'));
$check('split at 100 % pays the earner in full', Jobs::split(5000, 100) === ['earner' => 5000, 'fee' => 0] && Jobs::split(15000, 100) === ['earner' => 15000, 'fee' => 0]);
$check('isSystem / reviewerLabel', Jobs::isSystem(['is_system' => 1]) && !Jobs::isSystem(['is_system' => 0]) && !Jobs::isSystem([]) && Jobs::reviewerLabel(['is_system' => '1']) === 'YOLOTASK' && Jobs::reviewerLabel(['is_system' => 0]) === 'the advertiser');
$check('settle on a closed system job never posts money (code path)', str_contains($src('shared/lib/Jobs/Jobs.php'), "if (self::isSystem(\$job)) {\n            \$pdo->prepare('UPDATE jobs SET slots = :k WHERE id = :id')"));
$f = ['earner_user_id' => 5, 'advertiser_user_id' => 9, 'appeal_count' => 0];
$check('proof access: reviewers see YOLOTASK job proofs, still not advertisers\' unappealed ones', JobProofs::canView($f + ['is_system' => 1], ['role' => 'reviewer', 'id' => 2]) && !JobProofs::canView($f + ['is_system' => 0], ['role' => 'reviewer', 'id' => 2])
    && JobProofs::canView($f + ['is_system' => 1], ['role' => 'earner', 'id' => 5]) && !JobProofs::canView($f + ['is_system' => 1], ['role' => 'earner', 'id' => 6]));
$check('review claims accept job_attempt', in_array('job_attempt', \YoloTask\Tasks\ReviewClaims::TYPES, true));
$check('reviewer action labels', \YoloTask\Tasks\ReviewerActions::label('job_approved') === 'YOLOTASK job approved' && \YoloTask\Tasks\ReviewerActions::label('job_rejected') === 'YOLOTASK job rejected');
$check('ledger constant', \YoloTask\Money\Ledger::PLATFORM_JOB_PAYOUTS === 'system:platform_job_payouts');

// 4. Wiring.
$reviews = $src('shared/lib/Jobs/Reviews.php');
$check('approve posts Dr system:platform_job_payouts / Cr earner or hold for system jobs', str_contains($reviews, "[Ledger::systemAccountId(\$pdo, Ledger::PLATFORM_JOB_PAYOUTS), 'debit', \$pay], [\$to, 'credit', \$pay]"));
$check('approve spends nothing from a system job', str_contains($reviews, "'p' => Jobs::isSystem(\$job) ? 0 : (int) \$row['pay_kobo']"));
$check('reject / send back / approve accept a reviewer guard', substr_count($reviews, 'if ($guard !== null) $guard($pdo, $row);') === 3);
$appeals = $src('shared/lib/Jobs/JobAppeals.php');
$check('overturn on a system job: no funding step, spends nothing, no advertiser notice', str_contains($appeals, "if (\$system) {") && str_contains($appeals, "'p' => \$system ? 0 : \$price") && str_contains($appeals, "if ((int) \$ap['is_system'] === 1) return;"));
$check('no advertiser notification on system job submissions', str_contains($src('shared/lib/Jobs/Attempts.php'), 'if (Jobs::isSystem($a)) return;'));
$jobs = $src('shared/lib/Jobs/Jobs.php');
$check('reconcile: per-job escrow check skips system jobs; platform payouts checked', str_contains($jobs, "FROM jobs WHERE {\$adv} AND (funded_kobo") && str_contains($jobs, 'YOLOTASK job payouts {$paid} != pay of approved YOLOTASK job attempts {$owed}'));
$check('publishSystem: owner = system account, 100 %, fee 0, no ledger posting', str_contains($jobs, 'public static function publishSystem') && (bool) preg_match('/function publishSystem.*?VALUES \(:a, 1, .*?:p, 100, :ek, 0, :s,.*?return \$id;/s', $jobs) && !preg_match('/function publishSystem.*?Ledger::post.*?function systemJob/s', $jobs));
$admin = $src('shared/routes/admin-jobs.php');
foreach (['system_new', 'system_pause', 'system_resume', 'system_close', "case 'send_back':", 'Only submissions on YOLOTASK jobs are decided here.', "'new'", 'data-system-job-form'] as $needle) $check("admin jobs route has {$needle}", str_contains($admin, $needle));
$check('reviewer tabs include YOLOTASK jobs', str_contains($src('shared/routes/review-helpers.php'), "'job_reviews' => ['YOLOTASK jobs', 'job-reviews.php'"));
$check('earner feed shows the YOLOTASK badge', str_contains($src('shared/routes/earner-jobs.php'), 'yolotask_job_system_badge($j)'));
$script = $src('scripts/create-betfugu-jobs.php');
$check('betfugu script: CLI only, bootstraps like other scripts, --dry-run, --config-dir', str_contains($script, "if (PHP_SAPI !== 'cli')") && str_contains($script, "require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';") && str_contains($script, "'dry-run'") && str_contains($script, "putenv('YOLOTASK_CONFIG_DIR='"));
$check('betfugu script: idempotent on title, uses publishSystem, both jobs and pays', str_contains($script, 'WHERE is_system = 1 AND title = :t') && str_contains($script, 'Jobs::publishSystem') && str_contains($script, "'pay' => '50'") && str_contains($script, "'pay' => '150'")
    && str_contains($script, 'https://s.betfugu01.com/hhdcwxfv6tstuba') && str_contains($script, "'slots' => '10000'") && str_contains($script, "'review_hours' => '48'"));
$check('docs and changelog mention migration 0024', str_contains($src('docs/jobs.md'), '0024_system_jobs.sql') && str_contains($src('CHANGELOG.md'), '0024_system_jobs.sql') && str_contains($src('tests/README.md'), 'system_jobs_e2e.php'));

echo $failures === 0 ? "OK: system jobs smoke test passed\n" : "FAILED: {$failures} check(s)\n";
exit($failures === 0 ? 0 : 1);
