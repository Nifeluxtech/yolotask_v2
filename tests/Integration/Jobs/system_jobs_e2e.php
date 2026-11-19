<?php
declare(strict_types=1);
/*
 * YOLOTASK jobs ("system jobs", migration 0024) end-to-end test through the real portals.
 * Same setup as jobs_e2e.php: MariaDB/MySQL with migrations through 0024, a
 * private config dir (mail driver "log", storage.private_root) and the four
 * portals on php -S (ports 8602-8605, or YT_JOBS_PORT_BASE + 2..5):
 *   YOLOTASK_CONFIG_DIR=/tmp/ytcfg bash tests/Integration/Jobs/run-servers.sh
 *   YOLOTASK_CONFIG_DIR=/tmp/ytcfg php tests/Integration/Jobs/system_jobs_e2e.php
 * Covers: admin publishes a YOLOTASK job (no money moves, owner = the system
 * account, earner gets 100 %, ₦1 minimum), earner feed shows it as YOLOTASK,
 * activation gate, no advertiser notification, reviewer queue + proof access,
 * send back -> resubmit -> admin approve (platform pays full pay), reward hold
 * + hourly release, reviewer reject -> appeal -> second reviewer overturn
 * (platform-paid), auto-approve through the real cron, pause / resume / close
 * (nothing refunded, cap shrinks), admin decisions refused on advertisers'
 * jobs, scripts/create-betfugu-jobs.php (dry run, create, idempotent re-run),
 * and Ledger::reconcile after every money step. Creates its own users (random
 * suffix) and leaves them in the database.
 */
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 3));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';
use YoloTask\Core\Settings;
use YoloTask\Money\Ledger;
$pdo = \YoloTask\Core\Database::connection();
$fail = 0;
$ok = function (string $l, bool $c, string $extra = '') use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . ": $l" . (!$c && $extra !== '' ? "\n    " . substr($extra, 0, 600) : '') . "\n"; if (!$c) $fail++; };
$base = (int) (getenv('YT_JOBS_PORT_BASE') ?: 8600);
$ports = ['earner' => $base + 2, 'advertiser' => $base + 3, 'reviewer' => $base + 4, 'admin' => $base + 5];
$sfx = bin2hex(random_bytes(3));
$pw = 'Correct-Horse-9!';
$mk = function (string $role, string $name) use ($pdo, $sfx, $pw) {
    $email = strtolower($name) . $sfx . '@t.test';
    $pdo->prepare("INSERT INTO users (display_name, email, password_hash, role, email_verified_at) VALUES (:n, :e, :p, :r, UTC_TIMESTAMP())")->execute(['n' => $name . $sfx, 'e' => $email, 'p' => password_hash($pw, PASSWORD_DEFAULT), 'r' => $role]);
    return ['id' => (int) $pdo->lastInsertId(), 'email' => $email, 'role' => $role, 'jar' => tempnam(sys_get_temp_dir(), 'jar')];
};
$req = function (array $u, string $method, string $path, array $fields = [], bool $multipart = false) use ($ports): array {
    $ch = curl_init('http://127.0.0.1:' . $ports[$u['role']] . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $u['jar'], CURLOPT_COOKIEFILE => $u['jar'], CURLOPT_FOLLOWLOCATION => false]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $fields : http_build_query($fields));
    }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $headers = substr($raw, 0, $hs);
    $loc = preg_match('/^Location: (.+)$/mi', $headers, $m) ? trim($m[1]) : null;
    return ['code' => $code, 'body' => substr($raw, $hs), 'loc' => $loc, 'type' => $type];
};
$csrf = function (string $html): string { return preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : ''; };
$get = function (array $u, string $path) use ($req) { return $req($u, 'GET', $path); };
$post = function (array $u, string $path, array $fields, bool $multipart = false) use ($req, $get, $csrf) {
    $page = $get($u, $path);
    $fields['_csrf'] = $csrf($page['body']);
    $r = $req($u, 'POST', $path, $fields, $multipart);
    $r['next'] = $r['loc'] !== null ? $get($u, $r['loc']) : null;
    return $r;
};
$flash = function (array $r): string { $b = $r['next']['body'] ?? $r['body']; return preg_match('/<div class="toast-msg">(.*?)<\/div>/s', $b, $m) ? html_entity_decode(trim($m[1])) : '(no flash)'; };
$login = function (array $u) use ($req, $get, $csrf, $pw): bool {
    $p = $get($u, 'login.php');
    $r = $req($u, 'POST', 'login.php', ['_csrf' => $csrf($p['body']), 'email' => $u['email'], 'password' => $pw]);
    return $r['code'] === 303 && str_contains((string) $r['loc'], 'dashboard.php');
};
$png = function (int $seed): string {
    $w = 60; $h = 60; $raw = ''; $salt = random_bytes(8);
    for ($y = 0; $y < $h; $y++) { $raw .= "\0"; for ($x = 0; $x < $w; $x++) $raw .= chr(($x * $seed) % 256) . chr(($y + $seed) % 256) . $salt[($x + $y) % 8]; }
    $chunk = fn (string $t, string $d): string => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw)) . $chunk('IEND', '');
};
$fileOf = function (string $bytes, string $name, string $mime) { $t = tempnam(sys_get_temp_dir(), 'up'); file_put_contents($t, $bytes); return new CURLFile($t, $mime, $name); };
$col = fn (string $sql) => $pdo->query($sql)->fetchColumn();
$recon = function (string $l) use ($pdo, $ok) { $p = Ledger::reconcile($pdo); $ok("ledger reconcile clean after $l", $p === [], implode(' | ', $p)); };
$cron = function (string $f): string { return (string) shell_exec('YOLOTASK_CONFIG_DIR=' . escapeshellarg((string) getenv('YOLOTASK_CONFIG_DIR')) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . YOLOTASK_APP_ROOT . '/cron/' . $f . ' 2>&1'); };
$wal = fn (array $u) => (int) $col('SELECT available_kobo FROM wallets WHERE user_id = ' . $u['id']);
$bal = fn (string $code) => (int) $col("SELECT balance_kobo FROM ledger_accounts WHERE code = " . $pdo->quote($code));
$txCount = fn () => (int) $col('SELECT COUNT(*) FROM ledger_transactions');

$ok('migration 0024 applied (jobs.is_system, system:platform_job_payouts)', (bool) $col("SHOW COLUMNS FROM jobs LIKE 'is_system'") && $col("SELECT normal_side FROM ledger_accounts WHERE code = 'system:platform_job_payouts'") === 'debit');
$owner = (int) Settings::int($pdo, 'platform_tasks.owner_user_id');
$ok('system account exists and is closed', $owner > 0 && $col("SELECT status FROM users WHERE id = $owner") === 'closed');

$E = []; foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $k) $E[$k] = $mk('earner', 'Se' . $k);
$REV = $mk('reviewer', 'Srev'); $REV2 = $mk('reviewer', 'Srevb'); $ADM = $mk('admin', 'Sadm');
Settings::save($pdo, $ADM['id'], ['tasks.reward_hold_hours' => '0', 'activation.required' => '0', 'jobs.enabled' => '1', 'jobs.max_active_per_earner' => '1']);
foreach (array_merge(array_values($E), [$REV, $REV2, $ADM]) as $u) $ok('login ' . $u['role'] . ' ' . $u['email'], $login($u));

// 1. Admin publishes a YOLOTASK job: no money moves.
$ok('admin YOLOTASK jobs tab 200', $get($ADM, 'jobs.php?tab=system')['code'] === 200);
$form = $get($ADM, 'jobs.php?tab=new');
$ok('admin new YOLOTASK job form renders with step blocks', $form['code'] === 200 && str_contains($form['body'], 'data-system-job-form') && substr_count($form['body'], 'data-step="') === 20);
$fields = ['action' => 'system_new', 'title' => 'Sys test job ' . $sfx, 'instructions' => 'Sign up with the link and send proof for each step.', 'link_url' => 'https://example.com/signup', 'mode' => 'online', 'location' => '',
    'pay' => '50', 'slots' => '6', 'time_limit' => '24', 'time_unit' => 'hours', 'review_hours' => '48',
    'steps[0][title]' => 'Register', 'steps[0][instructions]' => 'Open the link and sign up.', 'steps[0][kinds][]' => 'image',
    'steps[1][title]' => 'Send your user ID', 'steps[1][instructions]' => 'Type your user ID and add a screenshot.', 'steps[1][kinds][0]' => 'image', 'steps[1][kinds][1]' => 'text'];
$r = $post($ADM, 'jobs.php?tab=new', ['pay' => '0'] + $fields);
$ok('pay ₦0 refused (minimum ₦1)', str_contains($flash($r), 'at least'), $flash($r));
$tx0 = $txCount();
$r = $post($ADM, 'jobs.php?tab=new', $fields);
$ok('publish redirects to the job', $r['code'] === 303 && preg_match('/jobs\.php\?id=(\d+)/', (string) $r['loc'], $m) === 1, $flash($r));
$jid = (int) ($m[1] ?? 0);
$job = $pdo->query("SELECT * FROM jobs WHERE id = $jid")->fetch();
$ok('job is a YOLOTASK job owned by the system account', (int) $job['is_system'] === 1 && (int) $job['advertiser_user_id'] === $owner);
$ok('earner gets the full ₦50 (100 %, fee 0), pay below jobs.min_pay_kobo allowed', (int) $job['pay_kobo'] === 5000 && (int) $job['earner_kobo'] === 5000 && (int) $job['fee_kobo'] === 0 && (int) $job['earner_percent'] === 100);
$ok('publishing moved no money (no ledger transaction, nothing funded)', $txCount() === $tx0 && (int) $job['funded_kobo'] === 0 && (int) $job['slots'] === 6 && (int) $col("SELECT COUNT(*) FROM job_funding_events WHERE job_id = $jid") === 0);
$ok('admin job page shows YOLOTASK controls (pause / close, no remove-and-refund)', str_contains($r['next']['body'], 'value="system_pause"') && str_contains($r['next']['body'], 'value="system_close"') && !str_contains($r['next']['body'], 'Remove and refund'));
$steps = $pdo->query("SELECT id FROM job_steps WHERE job_id = $jid ORDER BY position")->fetchAll(PDO::FETCH_COLUMN);
$recon('publish');

// 2. Earner feed and activation gate.
$l = $get($E['a'], 'jobs.php')['body'];
$ok('earner feed lists it with the YOLOTASK badge and ₦50', str_contains($l, 'data-job="' . $jid . '"') && str_contains($l, 'data-job-system') && str_contains($l, '₦50.00'));
$ok('job page says BY YOLOTASK', str_contains($get($E['a'], "job.php?id=$jid")['body'], 'BY YOLOTASK'));
Settings::save($pdo, $ADM['id'], ['activation.required' => '1']);
$r = $post($E['a'], "job.php?id=$jid", ['action' => 'start', 'job_id' => $jid]);
$ok('activation gate applies to YOLOTASK jobs', (int) $col("SELECT COUNT(*) FROM job_attempts WHERE job_id = $jid") === 0 && !str_contains($get($E['a'], 'jobs.php')['body'], 'data-job="' . $jid . '"'), $flash($r));
Settings::save($pdo, $ADM['id'], ['activation.required' => '0']);

$doJob = function (array $u, int $seed) use ($post, $col, $jid, $steps, $png, $fileOf, $flash, $ok): int {
    $r = $post($u, "job.php?id=$jid", ['action' => 'start', 'job_id' => $jid]);
    $aid = (int) $col("SELECT id FROM job_attempts WHERE job_id = $jid AND earner_user_id = {$u['id']}");
    $post($u, "job.php?id=$jid", ['action' => 'save_step', 'job_id' => $jid, 'step_id' => $steps[0], 'image' => $fileOf($png($seed), 'r.png', 'image/png')], true);
    $post($u, "job.php?id=$jid", ['action' => 'save_step', 'job_id' => $jid, 'step_id' => $steps[1], 'text' => 'UID-' . $seed, 'image' => $fileOf($png($seed + 1), 'u.png', 'image/png')], true);
    $r = $post($u, "job.php?id=$jid", ['action' => 'submit', 'job_id' => $jid]);
    $ok("earner {$u['email']} submitted", str_contains($flash($r), 'Submitted for review') && str_contains($flash($r), 'YOLOTASK'), $flash($r));
    return $aid;
};

// 3. Submit -> reviewer queue -> send back -> resubmit -> admin approve (platform pays the full pay).
$a1 = $doJob($E['a'], 11);
$ok('no notification to the closed system account', (int) $col("SELECT COUNT(*) FROM notifications WHERE user_id = $owner") === 0);
$q = $get($REV, 'job-reviews.php')['body'];
$ok('reviewer tab + queue list the submission', str_contains($q, 'job-reviews.php') && str_contains($q, 'data-system-job-attempt="' . $a1 . '"'));
$ok('admin YOLOTASK tab lists it', str_contains($get($ADM, 'jobs.php?tab=system')['body'], 'data-admin-system-attempt="' . $a1 . '"'));
$ok('admin attempt page offers approve / send back / reject', str_contains($get($ADM, "jobs.php?attempt=$a1")['body'], 'data-admin-system-decide="' . $a1 . '"'));
$rp = $get($REV, "job-review.php?id=$a1")['body'];
$ok('reviewer detail shows every step and a claim button', substr_count($rp, 'data-review-step=') === 2 && str_contains($rp, 'Claim this submission'));
$img = (int) $col("SELECT image_file_id FROM job_step_proofs WHERE attempt_id = $a1 AND position = 1");
$ok('reviewer can open proof of a YOLOTASK job', $get($REV, "job-proof.php?id=$img")['code'] === 200);
$r = $post($REV, "job-review.php?id=$a1", ['action' => 'approve', 'attempt_id' => $a1]);
$ok('reviewer cannot decide without a claim', str_contains($flash($r), 'Claim') && $col("SELECT status FROM job_attempts WHERE id = $a1") === 'submitted', $flash($r));
$r = $post($REV, "job-review.php?id=$a1", ['action' => 'claim', 'attempt_id' => $a1]);
$ok('reviewer claimed', str_contains($flash($r), 'Claimed'), $flash($r));
$r = $post($REV2, "job-review.php?id=$a1", ['action' => 'claim', 'attempt_id' => $a1]);
$ok('second reviewer cannot take a held claim', str_contains($flash($r), 'Another reviewer'), $flash($r));
$r = $post($REV, "job-review.php?id=$a1", ['action' => 'send_back', 'attempt_id' => $a1, 'step_id' => $steps[1], 'note' => 'The user ID is not readable']);
$ok('reviewer sent step 2 back', str_contains($flash($r), 'Step sent back') && $col("SELECT status FROM job_attempts WHERE id = $a1") === 'sent_back', $flash($r));
$ok('earner notified that YOLOTASK sent it back', (int) $col("SELECT COUNT(*) FROM notifications WHERE user_id = {$E['a']['id']} AND title LIKE 'Step 2 sent back%'") === 1
    && str_contains($get($E['a'], "job.php?id=$jid")['body'], "YOLOTASK's note"));
$post($E['a'], "job.php?id=$jid", ['action' => 'save_step', 'job_id' => $jid, 'step_id' => $steps[1], 'text' => 'UID-11-fixed']);
$r = $post($E['a'], "job.php?id=$jid", ['action' => 'resubmit', 'job_id' => $jid]);
$ok('resubmitted', str_contains($flash($r), 'Resubmitted'), $flash($r));
$pay0 = $bal('system:platform_job_payouts');
$r = $post($ADM, "jobs.php?attempt=$a1", ['action' => 'approve', 'attempt_id' => $a1]);
$ok('admin approved; earner paid the full ₦50 by YOLOTASK', str_contains($flash($r), 'Approved') && $wal($E['a']) === 5000 && $bal('system:platform_job_payouts') === $pay0 + 5000, $flash($r));
$t = $pdo->query("SELECT t.idempotency_key, t.type, t.amount_kobo FROM job_attempts a JOIN ledger_transactions t ON t.id = a.approve_transaction_id WHERE a.id = $a1")->fetch();
$ok('posting key job-approve:<attempt>, type job_reward', is_array($t) && $t['idempotency_key'] === 'job-approve:' . $a1 && $t['type'] === 'job_reward' && (int) $t['amount_kobo'] === 5000);
$ok('nothing spent from the job, revenue untouched', (int) $col("SELECT spent_kobo FROM jobs WHERE id = $jid") === 0 && (int) $col("SELECT approved_count FROM jobs WHERE id = $jid") === 1);
$ok('reviewer history shows the send back', str_contains($get($REV, 'history.php')['body'], 'YOLOTASK job: step sent back'));
$recon('approve');

// 4. Reward hold + hourly release.
Settings::save($pdo, $ADM['id'], ['tasks.reward_hold_hours' => '24']);
$a2 = $doJob($E['b'], 21);
$hold0 = $bal('system:reward_hold');
$post($REV, "job-review.php?id=$a2", ['action' => 'claim', 'attempt_id' => $a2]);
$r = $post($REV, "job-review.php?id=$a2", ['action' => 'approve', 'attempt_id' => $a2]);
$ok('reviewer approved with a reward hold: held, not in the wallet', str_contains($flash($r), 'Approved') && $col("SELECT reward_status FROM job_attempts WHERE id = $a2") === 'held' && $wal($E['b']) === 0 && $bal('system:reward_hold') === $hold0 + 5000, $flash($r));
$recon('held approve');
$pdo->exec("UPDATE job_attempts SET reward_release_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = $a2");
$out = $cron('hourly.php');
$ok('hourly cron released the held reward', $col("SELECT reward_status FROM job_attempts WHERE id = $a2") === 'released' && $wal($E['b']) === 5000, $out);
Settings::save($pdo, $ADM['id'], ['tasks.reward_hold_hours' => '0']);
$recon('release');

// 5. Reviewer reject -> appeal -> another reviewer overturns (platform pays).
$a3 = $doJob($E['c'], 31);
$post($REV, "job-review.php?id=$a3", ['action' => 'claim', 'attempt_id' => $a3]);
$r = $post($REV, "job-review.php?id=$a3", ['action' => 'reject', 'attempt_id' => $a3, 'reason' => 'This account already existed']);
$ok('reviewer rejected', str_contains($flash($r), 'Rejected') && $col("SELECT status FROM job_attempts WHERE id = $a3") === 'rejected' && $col("SELECT decision_source FROM job_attempts WHERE id = $a3") === 'reviewer', $flash($r));
$r = $post($E['c'], "job.php?id=$jid", ['action' => 'appeal', 'job_id' => $jid, 'reason' => 'The account is brand new, I signed up today with the link.']);
$ok('appeal filed', str_contains($flash($r), 'Appeal sent'), $flash($r));
$apid = (int) $col("SELECT id FROM job_appeals WHERE attempt_id = $a3");
$r = $post($REV, "job-appeal.php?id=$apid", ['action' => 'claim', 'appeal_id' => $apid]);
$r = $post($REV, "job-appeal.php?id=$apid", ['action' => 'overturn', 'appeal_id' => $apid, 'note' => 'Looks new to me after all.']);
$ok('the rejecting reviewer cannot decide the appeal', $col("SELECT status FROM job_appeals WHERE id = $apid") === 'open' && str_contains($flash($r), 'another reviewer'), $flash($r));
$post($REV, "job-appeal.php?id=$apid", ['action' => 'release', 'appeal_id' => $apid]);
$post($REV2, "job-appeal.php?id=$apid", ['action' => 'claim', 'appeal_id' => $apid]);
$pay0 = $bal('system:platform_job_payouts');
$tx0 = $txCount();
$r = $post($REV2, "job-appeal.php?id=$apid", ['action' => 'overturn', 'appeal_id' => $apid, 'note' => 'The account was created after the job started.']);
$ap = $pdo->query("SELECT * FROM job_appeals WHERE id = $apid")->fetch();
$ok('overturned; YOLOTASK paid the full ₦50, one posting, no funding step', str_contains($flash($r), 'Overturned') && $wal($E['c']) === 5000 && $bal('system:platform_job_payouts') === $pay0 + 5000 && $txCount() === $tx0 + 1
    && $ap['funding_transaction_id'] === null && (int) $ap['escrow_kobo'] === 0 && (int) $ap['wallet_kobo'] === 0 && (int) $ap['platform_kobo'] === 0, $flash($r));
$ok('overturn key job-appeal-approve:<appeal>', $col('SELECT t.idempotency_key FROM ledger_transactions t WHERE t.id = ' . (int) $ap['approve_transaction_id']) === 'job-appeal-approve:' . $apid);
$ok('still no notification to the system account', (int) $col("SELECT COUNT(*) FROM notifications WHERE user_id = $owner") === 0);
$recon('appeal overturn');

// 6. Auto-approve through the real cron.
$a4 = $doJob($E['d'], 41);
$pdo->exec("UPDATE job_attempts SET review_due_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = $a4");
$out = $cron('every-fifteen-minutes.php');
$ok('cron auto-approved; earner paid ₦50', str_contains($out, 'jobs: submissions auto-approved: 1') && $wal($E['d']) === 5000, $out);
$ok('auto-approve notice does not blame an advertiser', (int) $col("SELECT COUNT(*) FROM notifications WHERE user_id = {$E['d']['id']} AND title LIKE 'Job auto-approved%' AND body NOT LIKE '%advertiser%'") === 1);
$recon('auto-approve');

// 7. Pause / resume / close: nothing refunded, the cap shrinks to what is used.
$r = $post($ADM, "jobs.php?id=$jid", ['action' => 'system_pause', 'job_id' => $jid]);
$ok('admin paused', str_contains($flash($r), 'paused') && $col("SELECT status FROM jobs WHERE id = $jid") === 'paused', $flash($r));
$r = $post($E['e'], "job.php?id=$jid", ['action' => 'start', 'job_id' => $jid]);
$ok('paused job cannot be started', (int) $col("SELECT COUNT(*) FROM job_attempts WHERE job_id = $jid AND earner_user_id = {$E['e']['id']}") === 0, $flash($r));
$r = $post($ADM, "jobs.php?id=$jid", ['action' => 'system_resume', 'job_id' => $jid]);
$ok('admin resumed', $col("SELECT status FROM jobs WHERE id = $jid") === 'live', $flash($r));
$post($E['e'], "job.php?id=$jid", ['action' => 'start', 'job_id' => $jid]);
$a5 = (int) $col("SELECT id FROM job_attempts WHERE job_id = $jid AND earner_user_id = {$E['e']['id']}");
$tx0 = $txCount();
$r = $post($ADM, "jobs.php?id=$jid", ['action' => 'system_close', 'job_id' => $jid]);
$j = $pdo->query("SELECT * FROM jobs WHERE id = $jid")->fetch();
$ok('closed: no ledger posting, cap = approved 4 + active 1', str_contains($flash($r), 'closed') && $j['status'] === 'closed' && $txCount() === $tx0 && (int) $j['slots'] === 5 && (int) $j['refunded_kobo'] === 0, $flash($r) . ' slots ' . $j['slots']);
$ok('closed job hidden from new earners', $get($E['f'], "job.php?id=$jid")['code'] === 404);
$pdo->exec("UPDATE job_attempts SET deadline_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = $a5");
$out = $cron('every-five-minutes.php');
$ok('work still active on the closed job expires; cap shrinks to 4, nothing refunded', $col("SELECT status FROM job_attempts WHERE id = $a5") === 'expired' && (int) $col("SELECT slots FROM jobs WHERE id = $jid") === 4 && $txCount() === $tx0, $out);
$recon('close');

// 8. Admin decisions are for YOLOTASK jobs only.
$advAttempt = (int) $col("SELECT a.id FROM job_attempts a JOIN jobs j ON j.id = a.job_id WHERE j.is_system = 0 ORDER BY a.id DESC LIMIT 1");
if ($advAttempt > 0) {
    $before = $col("SELECT status FROM job_attempts WHERE id = $advAttempt");
    $r = $post($ADM, "jobs.php?attempt=$advAttempt", ['action' => 'approve', 'attempt_id' => $advAttempt]);
    $ok("admin cannot decide an advertiser's job submission", str_contains($flash($r), 'Only submissions on YOLOTASK jobs') && $col("SELECT status FROM job_attempts WHERE id = $advAttempt") === $before, $flash($r));
}
if ($advAttempt > 0) $ok("reviewer detail page is 404 for an advertiser's job submission", $get($REV, "job-review.php?id=$advAttempt")['code'] === 404);
$r = $post($REV, 'job-reviews.php', ['action' => 'claim', 'attempt_id' => $advAttempt > 0 ? $advAttempt : 999999999]);
$ok("reviewer cannot claim an advertiser's job submission", str_contains($flash($r), 'not found'), $flash($r));

// 9. BetFugu one-off script: dry run, create, idempotent re-run.
$run = function (string $args): array { exec('YOLOTASK_CONFIG_DIR=' . escapeshellarg((string) getenv('YOLOTASK_CONFIG_DIR')) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . YOLOTASK_APP_ROOT . '/scripts/create-betfugu-jobs.php ' . $args . ' 2>&1', $out, $rc); return [$rc, implode("\n", $out)]; };
$titles = ['Sign up on BetFugu and use the free bet', 'Sign up on BetFugu, use the free bet and deposit'];
$countBf = fn () => (int) $col("SELECT COUNT(*) FROM jobs WHERE is_system = 1 AND title IN (" . $pdo->quote($titles[0]) . ', ' . $pdo->quote($titles[1]) . ')');
$had = $countBf();
$tx0 = $txCount();
[$rc, $out] = $run('--dry-run');
$ok('betfugu --dry-run validates both and saves nothing', $rc === 0 && $countBf() === $had && ($had === 2 || str_contains($out, 'would create')), $out);
[$rc, $out] = $run('');
$ok('betfugu run creates both jobs (or finds them) and prints the ids', $rc === 0 && $countBf() === 2 && preg_match_all('/job #\d+/', $out) === 2, $out);
[$rc, $out] = $run('');
$ok('betfugu re-run is idempotent', $rc === 0 && $countBf() === 2 && substr_count($out, 'already there') === 2, $out);
$bf = $pdo->query('SELECT j.*, (SELECT GROUP_CONCAT(CONCAT(s.position, ":", s.title, ":", s.proof_kinds) ORDER BY s.position SEPARATOR "|") FROM job_steps s WHERE s.job_id = j.id) st FROM jobs j WHERE j.is_system = 1 AND j.title IN (' . $pdo->quote($titles[0]) . ', ' . $pdo->quote($titles[1]) . ') ORDER BY j.pay_kobo')->fetchAll();
$ok('betfugu A: ₦50, 4 steps, step 4 image+text', (int) $bf[0]['pay_kobo'] === 5000 && (int) $bf[0]['earner_kobo'] === 5000 && (int) $bf[0]['step_count'] === 4 && str_ends_with((string) $bf[0]['st'], '4:Send your user ID:image,text'));
$ok('betfugu B: ₦150, 5 steps, deposit step 4', (int) $bf[1]['pay_kobo'] === 15000 && (int) $bf[1]['step_count'] === 5 && str_contains((string) $bf[1]['st'], '4:Make a deposit:image') && str_ends_with((string) $bf[1]['st'], '5:Send your user ID:image,text'));
$ok('betfugu common: online, link, 10,000 slots, 24 h, 48 h review, no money', $bf[0]['mode'] === 'online' && $bf[1]['link_url'] === 'https://s.betfugu01.com/hhdcwxfv6tstuba' && (int) $bf[0]['slots'] === 10000
    && (int) $bf[1]['time_limit_hours'] === 24 && (int) $bf[0]['review_hours'] === 48 && (int) $bf[0]['funded_kobo'] === 0 && $txCount() === $tx0
    && str_starts_with((string) $bf[0]['instructions'], '18+ only.'));
$recon('betfugu script');

$ok('hourly cron reconciliation OK', str_contains($cron('hourly.php'), 'escrow reconciliation OK'));
$errs = trim((string) shell_exec("grep -i -E 'fatal|warning|deprecated|notice' /tmp/yt-jobs-srv-*.log 2>/dev/null | tail -5"));
$ok('no PHP errors/warnings in server logs', $errs === '', $errs);
echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
