<?php
declare(strict_types=1);
/*
 * Jobs end-to-end test (migration 0023) through the real portals.
 * Needs MariaDB/MySQL with migrations applied, a private config dir (mail driver
 * "log", storage.private_root) and the earner / advertiser / reviewer / admin
 * portals on php -S (ports 8602-8605, or YT_JOBS_PORT_BASE + 2..5):
 *   YOLOTASK_CONFIG_DIR=/tmp/ytcfg bash tests/Integration/Jobs/run-servers.sh
 *   YOLOTASK_CONFIG_DIR=/tmp/ytcfg php tests/Integration/Jobs/jobs_e2e.php
 * Covers create -> start -> steps -> submit -> send back -> resubmit -> approve,
 * reject -> appeal -> reviewer overturn, expiry and auto-approve through the
 * cron scripts, admin pause / settings / remove, close refund, proof access,
 * notifications and Ledger::reconcile after every money step. It creates its
 * own users (random suffix) and leaves them in the database.
 */
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 3));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';
use YoloTask\Core\Settings;
use YoloTask\Money\{Ledger, Wallet};
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

$ADV = $mk('advertiser', 'Hadv'); $E1 = $mk('earner', 'Hea'); $E2 = $mk('earner', 'Heb'); $E3 = $mk('earner', 'Hec'); $E4 = $mk('earner', 'Hed');
$REV = $mk('reviewer', 'Hrev'); $ADM = $mk('admin', 'Hadm');
Settings::save($pdo, $ADM['id'], ['tasks.reward_hold_hours' => '0', 'activation.required' => '0', 'jobs.enabled' => '1']);
Wallet::adjust($pdo, $ADM['id'], $ADV['id'], 'credit', 2000000, 'test deposit', bin2hex(random_bytes(16)));
foreach ([$ADV, $E1, $E2, $E3, $E4, $REV, $ADM] as $u) $ok('login ' . $u['role'] . ' ' . $u['email'], $login($u));
$wal = fn (array $u) => (int) $col('SELECT available_kobo FROM wallets WHERE user_id = ' . $u['id']);

// Navigation
$d = $get($E1, 'dashboard.php')['body'];
$ok('earner nav has Jobs and still Marketplace + Tasks', str_contains($d, 'href="jobs.php"') && str_contains($d, 'href="marketplace.php"') && str_contains($d, 'href="tasks.php"'));
$d = $get($ADV, 'dashboard.php')['body'];
$ok('advertiser nav has Jobs and still Marketplace + Campaigns', str_contains($d, 'href="jobs.php"') && str_contains($d, 'href="marketplace.php"') && str_contains($d, 'href="campaigns.php"'));
$ok('reviewer tabs show Job appeals', str_contains($get($REV, 'appeals.php')['body'], 'job-appeals.php'));
$ok('admin task tabs show Jobs', str_contains($get($ADM, 'campaigns.php')['body'], 'href="jobs.php"'));

// Advertiser creates a job
$ok('advertiser jobs page 200', $get($ADV, 'jobs.php')['code'] === 200);
$form = $get($ADV, 'job-new.php');
$ok('job-new form renders with 20 step blocks', $form['code'] === 200 && substr_count($form['body'], 'data-step="') === 20);
$fields = ['title' => 'HTTP mystery shop', 'instructions' => 'Visit the store, photograph it, answer and upload a report.', 'link_url' => 'https://example.com/store', 'mode' => 'offline', 'location' => 'Yaba, Lagos',
    'pay' => '1000', 'slots' => '4', 'time_limit' => '3', 'time_unit' => 'days', 'review_hours' => '48',
    'steps[0][title]' => 'Photo', 'steps[0][instructions]' => 'Take a photo of the shop front.', 'steps[0][kinds][]' => 'image',
    'steps[1][title]' => 'Answer', 'steps[1][instructions]' => 'What colour is the sign? Link the map.', 'steps[1][kinds][0]' => 'text', 'steps[1][kinds][1]' => 'link',
    'steps[2][title]' => 'Report', 'steps[2][instructions]' => 'Upload your PDF report.', 'steps[2][kinds][]' => 'file'];
$bad = $post($ADV, 'job-new.php', ['location' => ''] + $fields);
$ok('validation error shown (offline needs location)', str_contains($flash($bad), 'location'), $flash($bad));
$r = $post($ADV, 'job-new.php', $fields);
$ok('publish redirects to job page', $r['code'] === 303 && preg_match('/job\.php\?id=(\d+)/', (string) $r['loc'], $m) === 1, $flash($r));
$jid = (int) ($m[1] ?? 0);
$ok('job page shows live + 4 slots', str_contains($r['next']['body'], 'Live') && str_contains($r['next']['body'], 'of 4 approved'));
$ok('wallet charged ₦4,000', $wal($ADV) === 2000000 - 400000);
$steps = $pdo->query("SELECT id FROM job_steps WHERE job_id = $jid ORDER BY position")->fetchAll(PDO::FETCH_COLUMN);
$recon('publish');

// Earner 1 lists and starts
$l = $get($E1, 'jobs.php')['body'];
$ok('earner sees job with Offline badge and location', str_contains($l, 'HTTP mystery shop') && str_contains($l, 'data-job-mode="offline"') && str_contains($l, 'Yaba, Lagos') && str_contains($l, '₦900.00'));
$jp = $get($E1, "job.php?id=$jid")['body'];
$ok('job page lists all steps before start, link hidden', substr_count($jp, 'data-step="') === 3 && str_contains($jp, 'opens once you start') && !str_contains($jp, 'https://example.com/store'));
$r = $post($E1, "job.php?id=$jid", ['action' => 'start', 'job_id' => $jid]);
$ok('start ok', str_contains($flash($r), 'Job started'), $flash($r));
$a1 = (int) $col("SELECT id FROM job_attempts WHERE job_id = $jid AND earner_user_id = {$E1['id']}");
$jp = $get($E1, "job.php?id=$jid")['body'];
$ok('after start: link shown, step 1 open, steps 2-3 locked', str_contains($jp, 'https://example.com/store') && substr_count($jp, 'name="step_id"') === 1);
$r = $post($E1, "job.php?id=$jid", ['action' => 'save_step', 'job_id' => $jid, 'step_id' => $steps[1], 'text' => 'red', 'link' => 'https://maps.test/x']);
$ok('cannot save step 2 before step 1', str_contains($flash($r), 'step 1'), $flash($r));
$r = $post($E1, "job.php?id=$jid", ['action' => 'save_step', 'job_id' => $jid, 'step_id' => $steps[0], 'image' => $fileOf($png(3), 'shop.png', 'image/png')], true);
$ok('step 1 image saved', str_contains($flash($r), 'Step saved'), $flash($r));
$r = $post($E1, "job.php?id=$jid", ['action' => 'save_step', 'job_id' => $jid, 'step_id' => $steps[1], 'text' => 'red', 'link' => 'https://maps.test/x']);
$ok('step 2 saved', str_contains($flash($r), 'Step saved'), $flash($r));
$r = $post($E1, "job.php?id=$jid", ['action' => 'submit', 'job_id' => $jid]);
$ok('submit refused before step 3', str_contains($flash($r), 'step 3'), $flash($r));
$r = $post($E1, "job.php?id=$jid", ['action' => 'save_step', 'job_id' => $jid, 'step_id' => $steps[2], 'file' => $fileOf("%PDF-1.4\nreport\n", 'report.pdf', 'application/pdf')], true);
$ok('step 3 file saved', str_contains($flash($r), 'Step saved'), $flash($r));
$r = $post($E1, "job.php?id=$jid", ['action' => 'save_step', 'job_id' => $jid, 'step_id' => $steps[0], 'image' => $fileOf($png(4), 'shop2.png', 'image/png')], true);
$ok('earlier step editable before submit', str_contains($flash($r), 'Step saved'), $flash($r));
$r = $post($E1, "job.php?id=$jid", ['action' => 'submit', 'job_id' => $jid]);
$ok('submitted for review', str_contains($flash($r), 'Submitted for review'), $flash($r));
$ok('advertiser notified of new submission', (int) $col("SELECT COUNT(*) FROM notifications WHERE user_id = {$ADV['id']} AND category = 'jobs' AND title LIKE 'New job submission%'") === 1);

// Advertiser reviews, sends back, earner fixes, advertiser approves
$rv = $get($ADV, "job-review.php?id=$a1")['body'];
$ok('review page shows every step proof', substr_count($rv, 'data-review-step=') === 3 && str_contains($rv, 'job-proof.php?id=') && str_contains($rv, 'red') && str_contains($rv, 'report.pdf'));
$img = (int) $col("SELECT image_file_id FROM job_step_proofs WHERE attempt_id = $a1 AND position = 1");
$doc = (int) $col("SELECT file_file_id FROM job_step_proofs WHERE attempt_id = $a1 AND position = 3");
$pi = $get($ADV, "job-proof.php?id=$img");
$ok('advertiser can open image proof inline', $pi['code'] === 200 && str_starts_with($pi['type'], 'image/png'));
$pd = $get($ADV, "job-proof.php?id=$doc");
$ok('file proof is an octet-stream download', $pd['code'] === 200 && str_starts_with($pd['type'], 'application/octet-stream') && str_starts_with($pd['body'], '%PDF'));
$ok('other earner gets 404 for the proof', $get($E2, "job-proof.php?id=$img")['code'] === 404);
$ok('reviewer gets 404 (not appealed)', $get($REV, "job-proof.php?id=$img")['code'] === 404);
$ok('admin can open the proof', $get($ADM, "job-proof.php?id=$img")['code'] === 200);
$ok('other advertiser cannot open review page', ($x = $mk('advertiser', 'Hother')) && $login($x) && $get($x, "job-review.php?id=$a1")['code'] === 404);
$r = $post($ADV, "job-review.php?id=$a1", ['action' => 'send_back', 'attempt_id' => $a1, 'step_id' => $steps[1], 'note' => 'The sign is blue, check again']);
$ok('send back ok', str_contains($flash($r), 'Step sent back'), $flash($r));
$jp = $get($E1, "job.php?id=$jid")['body'];
$ok('earner sees sent-back note and only step 2 form, resubmit disabled', str_contains($jp, 'The sign is blue') && substr_count($jp, 'name="step_id"') === 1 && str_contains($jp, 'value="' . $steps[1] . '"') && preg_match('/value="resubmit" disabled/', $jp) === 1);
$ok('earner notified (sent back)', (int) $col("SELECT COUNT(*) FROM notifications WHERE user_id = {$E1['id']} AND title LIKE 'Step 2 sent back%'") === 1);
$r = $post($E1, "job.php?id=$jid", ['action' => 'save_step', 'job_id' => $jid, 'step_id' => $steps[1], 'text' => 'blue']);
$r = $post($E1, "job.php?id=$jid", ['action' => 'resubmit', 'job_id' => $jid]);
$ok('resubmitted', str_contains($flash($r), 'Resubmitted'), $flash($r));
$r = $post($ADV, "job-review.php?id=$a1", ['action' => 'approve', 'attempt_id' => $a1]);
$ok('approved; earner paid ₦900', str_contains($flash($r), 'Approved') && $wal($E1) === 90000, $flash($r));
$ok('earner notified (approved)', (int) $col("SELECT COUNT(*) FROM notifications WHERE user_id = {$E1['id']} AND title LIKE 'Job approved%'") === 1);
$ok('wallet history label "Job reward"', str_contains($get($E1, 'wallet.php')['body'], 'Job reward'));
$recon('approve');

// Earner 2: reject -> appeal -> reviewer overturn
$post($E2, "job.php?id=$jid", ['action' => 'start', 'job_id' => $jid]);
$a2 = (int) $col("SELECT id FROM job_attempts WHERE job_id = $jid AND earner_user_id = {$E2['id']}");
$post($E2, "job.php?id=$jid", ['action' => 'save_step', 'job_id' => $jid, 'step_id' => $steps[0], 'image' => $fileOf($png(7), 'p.png', 'image/png')], true);
$post($E2, "job.php?id=$jid", ['action' => 'save_step', 'job_id' => $jid, 'step_id' => $steps[1], 'text' => 'blue', 'link' => 'https://maps.test/y']);
$post($E2, "job.php?id=$jid", ['action' => 'save_step', 'job_id' => $jid, 'step_id' => $steps[2], 'file' => $fileOf("%PDF-1.4\nE2\n", 'e2.pdf', 'application/pdf')], true);
$post($E2, "job.php?id=$jid", ['action' => 'submit', 'job_id' => $jid]);
$r = $post($ADV, "job-review.php?id=$a2", ['action' => 'reject', 'attempt_id' => $a2, 'reason' => 'Photo is of another store']);
$ok('rejected', str_contains($flash($r), 'Rejected'), $flash($r));
$ok('earner notified (rejected)', (int) $col("SELECT COUNT(*) FROM notifications WHERE user_id = {$E2['id']} AND title LIKE 'Job not accepted%'") === 1);
$r = $post($E2, "job.php?id=$jid", ['action' => 'appeal', 'job_id' => $jid, 'reason' => 'This is the right store, the sign is in the photo.']);
$ok('appeal filed', str_contains($flash($r), 'Appeal sent'), $flash($r));
$q = $get($REV, 'job-appeals.php')['body'];
$apid = (int) $col("SELECT id FROM job_appeals WHERE attempt_id = $a2");
$ok('reviewer queue lists the job appeal', str_contains($q, 'data-job-appeal="' . $apid . '"'));
$r = $post($REV, "job-appeal.php?id=$apid", ['action' => 'claim', 'appeal_id' => $apid]);
$ok('reviewer claimed', str_contains($flash($r), 'Claimed'), $flash($r));
$img2 = (int) $col("SELECT image_file_id FROM job_step_proofs WHERE attempt_id = $a2 AND position = 1");
$ok('reviewer can open appealed proof', $get($REV, "job-proof.php?id=$img2")['code'] === 200);
$ok('appeal page shows all steps', substr_count($get($REV, "job-appeal.php?id=$apid")['body'], 'data-review-step=') === 3);
$r = $post($REV, "job-appeal.php?id=$apid", ['action' => 'overturn', 'appeal_id' => $apid, 'note' => 'The sign matches the store in the link.']);
$ok('overturned; earner 2 paid', str_contains($flash($r), 'Overturned') && $wal($E2) === 90000, $flash($r));
$ok('appeal outcome notifications (earner + advertiser)', (int) $col("SELECT COUNT(*) FROM notifications WHERE category = 'appeals' AND title LIKE '%overturned%' AND user_id = {$ADV['id']}") === 1 && (int) $col("SELECT COUNT(*) FROM notifications WHERE title LIKE 'Appeal successful%' AND user_id = {$E2['id']}") === 1);
$ok('reviewer history shows it', str_contains($get($REV, 'history.php')['body'], 'Job appeal'));
$recon('appeal overturn');

// Earner 3: expiry via cron
$post($E3, "job.php?id=$jid", ['action' => 'start', 'job_id' => $jid]);
$a3 = (int) $col("SELECT id FROM job_attempts WHERE job_id = $jid AND earner_user_id = {$E3['id']}");
$pdo->exec("UPDATE job_attempts SET deadline_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = $a3");
$out = $cron('every-five-minutes.php');
$ok('cron expired the attempt', str_contains($out, 'jobs: attempts expired: 1') && $col("SELECT status FROM job_attempts WHERE id = $a3") === 'expired', $out);
$ok('earner 3 sees ended state', str_contains($get($E3, "job.php?id=$jid")['body'], 'This job ended'));
$recon('expiry');

// Earner 4: auto-approve via cron
$post($E4, "job.php?id=$jid", ['action' => 'start', 'job_id' => $jid]);
$a4 = (int) $col("SELECT id FROM job_attempts WHERE job_id = $jid AND earner_user_id = {$E4['id']}");
$post($E4, "job.php?id=$jid", ['action' => 'save_step', 'job_id' => $jid, 'step_id' => $steps[0], 'image' => $fileOf($png(9), 'p.png', 'image/png')], true);
$post($E4, "job.php?id=$jid", ['action' => 'save_step', 'job_id' => $jid, 'step_id' => $steps[1], 'text' => 'blue', 'link' => 'https://maps.test/z']);
$post($E4, "job.php?id=$jid", ['action' => 'save_step', 'job_id' => $jid, 'step_id' => $steps[2], 'file' => $fileOf("%PDF-1.4\nE4\n", 'e4.pdf', 'application/pdf')], true);
$post($E4, "job.php?id=$jid", ['action' => 'submit', 'job_id' => $jid]);
$pdo->exec("UPDATE job_attempts SET review_due_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = $a4");
$out = $cron('every-fifteen-minutes.php');
$ok('cron auto-approved', str_contains($out, 'jobs: submissions auto-approved: 1') && $wal($E4) === 90000, $out);
$ok('earner notified (auto-approved)', (int) $col("SELECT COUNT(*) FROM notifications WHERE user_id = {$E4['id']} AND title LIKE 'Job auto-approved%'") === 1);
$recon('auto-approve');

// Admin
foreach (['jobs.php', "jobs.php?id=$jid", "jobs.php?attempt=$a1", 'jobs.php?tab=appeals&status=all', "jobs.php?appeal=$apid", 'jobs.php?tab=settings'] as $p) $ok("admin $p 200", $get($ADM, $p)['code'] === 200);
$r = $post($ADM, 'jobs.php?tab=settings', ['action' => 'settings', 'enabled' => '1', 'min_pay' => '200', 'earner_percent' => '90', 'max_steps' => '20', 'time_min' => '1', 'time_max' => '336', 'send_back_rounds' => '2', 'fix_hours' => '48', 'max_active' => '1']);
$ok('admin settings save', str_contains($flash($r), 'settings saved'), $flash($r));
$r = $post($ADM, "jobs.php?id=$jid", ['action' => 'pause', 'job_id' => $jid, 'reason' => 'Checking the job']);
$ok('admin pause', str_contains($flash($r), 'paused') && $col("SELECT admin_paused FROM jobs WHERE id = $jid") == 1, $flash($r));
$r = $post($ADV, "job.php?id=$jid", ['action' => 'resume', 'job_id' => $jid]);
$ok('advertiser cannot resume an admin pause', str_contains($flash($r), 'YOLOTASK paused'), $flash($r));
$post($ADM, "jobs.php?id=$jid", ['action' => 'unpause', 'job_id' => $jid]);
$ok('admin unpause', $col("SELECT status FROM jobs WHERE id = $jid") === 'live');

// Advertiser close refund: 4 slots, 3 approved (E1, E2 overturn used free slot, E4) -> 1 free -> refund ₦1,000
$before = $wal($ADV);
$r = $post($ADV, "job.php?id=$jid", ['action' => 'close', 'job_id' => $jid]);
$ok('close refunds unused slot ₦1,000', str_contains($flash($r), 'Job closed') && $wal($ADV) === $before + 100000, $flash($r) . ' wallet ' . $wal($ADV) . ' vs ' . $before);
$recon('close');

// A second job removed by admin with refund
$r = $post($ADV, 'job-new.php', ['title' => 'Online survey job', 'mode' => 'online', 'location' => '', 'slots' => '2', 'pay' => '300'] + $fields);
preg_match('/job\.php\?id=(\d+)/', (string) $r['loc'], $m); $jid2 = (int) ($m[1] ?? 0);
$ok('online job shows Online badge to earners', str_contains($get($E1, 'jobs.php')['body'], 'data-job-mode="online"'));
$before = $wal($ADV);
$r = $post($ADM, "jobs.php?id=$jid2", ['action' => 'remove', 'job_id' => $jid2, 'reason' => 'Duplicate job']);
$ok('admin remove refunds ₦600', str_contains($flash($r), 'Job removed') && $wal($ADV) === $before + 60000, $flash($r));
$ok('removed job is 404 to earners who never took it', $get($E1, "job.php?id=$jid2")['code'] === 404);
$recon('admin remove');
$ok('hourly cron reconciliation OK', str_contains($cron('hourly.php'), 'escrow reconciliation OK'));
$errs = trim((string) shell_exec("grep -i -E 'fatal|warning|deprecated|notice' /tmp/yt-jobs-srv-*.log 2>/dev/null | tail -5"));
$ok('no PHP errors/warnings in server logs', $errs === '', $errs);
echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
