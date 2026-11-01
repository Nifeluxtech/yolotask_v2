<?php
declare(strict_types=1);

/**
 * One-off import of YOLOTASK v1 (Supabase) data into the v2 database.
 *
 * Input is a JSON bundle built from a read-only Supabase extract (see
 * docs/data-migration-v1-to-v2.md). Everything runs in ONE database
 * transaction: any error rolls the whole import back. With --dry-run the
 * import runs fully, prints the reconciliation and then rolls back.
 *
 * Money model: every v1 balance arrives as an opening-balance ledger
 * transaction against system:v1_migration, campaign budgets are re-funded
 * into per-campaign escrow, and approved v1 work is booked as spent so
 * Ledger::reconcile() stays clean. v1 history (ledger, withdrawals, top-ups,
 * check-ins) is archived in v1_* tables, not replayed.
 *
 * Passwords: Supabase stores bcrypt ($2a$) hashes, which password_verify()
 * accepts, so they are copied unchanged. Existing v2 users with the same
 * email are merged (their v2 password and role are kept).
 *
 * Usage: php scripts/import-v1.php --bundle=/path/v1-bundle.json [--dry-run]
 *        [--config-dir=/path/config-private] [--now="2026-11-01 12:00:00"]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opts = getopt('', ['bundle:', 'dry-run', 'config-dir:', 'now:', 'skip-proofs']);
if (isset($opts['config-dir'])) putenv('YOLOTASK_CONFIG_DIR=' . $opts['config-dir']);
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

use YoloTask\Core\Database;
use YoloTask\Money\Ledger;
use YoloTask\Money\Wallet;
use YoloTask\Growth\Referrals;
use YoloTask\Security\Crypto;
use YoloTask\Tasks\Proofs;
use YoloTask\Campaigns\CampaignLinks;

const V1_ACCOUNT = 'system:v1_migration';

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "import-v1: {$s}\n"); exit(1); }
function kobo(mixed $naira): int { return (int) round(((float) ($naira ?? 0)) * 100); }
/** ISO-8601 (any offset) -> UTC "Y-m-d H:i:s", or null. */
function utc(?string $iso): ?string
{
    if ($iso === null || $iso === '') return null;
    $d = new DateTimeImmutable($iso);
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
function cut(?string $s, int $n): string { return mb_substr(trim((string) $s), 0, $n); }
function slugify(string $s): string { $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s) ?? ''); return substr(trim($s, '-'), 0, 40) ?: 'item'; }

$bundleFile = (string) ($opts['bundle'] ?? '');
if ($bundleFile === '' || !is_file($bundleFile)) fail('pass --bundle=/path/to/v1-bundle.json');
$B = json_decode((string) file_get_contents($bundleFile), true, 512, JSON_THROW_ON_ERROR);
$dry = isset($opts['dry-run']);
$now = isset($opts['now']) ? (string) $opts['now'] : gmdate('Y-m-d H:i:s');
$pdo = Database::connection();
$pdo->exec("SET time_zone = '+00:00'");

$report = ['warnings' => [], 'counts' => []];
$warn = static function (string $w) use (&$report): void { $report['warnings'][] = $w; };
$count = static function (string $k, int $n = 1) use (&$report): void { $report['counts'][$k] = ($report['counts'][$k] ?? 0) + $n; };

// ------------------------------------------------------------ archive tables (DDL: before the transaction)
$pdo->exec("CREATE TABLE IF NOT EXISTS v1_user_map (
    v1_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, user_id BIGINT UNSIGNED NOT NULL, merged TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (v1_id), KEY ix_v1_user_map_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS v1_archive (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, kind VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, v1_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    user_id BIGINT UNSIGNED NULL, amount_kobo BIGINT NULL, status VARCHAR(40) NULL, occurred_at DATETIME NULL, data JSON NOT NULL,
    PRIMARY KEY (id), KEY ix_v1_archive_user (user_id, kind, occurred_at), KEY ix_v1_archive_kind (kind, occurred_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$done = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'migration.v1_imported'")->fetchColumn();
if ($done !== false) fail("v1 data was already imported ({$done}). Refusing to run twice.");

$sealOk = Crypto::gcmAvailable();
try { Crypto::key(); } catch (Throwable $e) { $sealOk = false; }
if (!$sealOk) $warn('Bank numbers stored in plain text (no security key here); run scripts/encrypt-bank-details.php afterwards.');

$pdo->beginTransaction();
try {
    $pdo->exec("INSERT IGNORE INTO ledger_accounts (code, name, kind, normal_side, allow_negative) VALUES ('" . V1_ACCOUNT . "', 'Opening balances and history imported from YOLOTASK v1', 'system', 'debit', 1)");
    $v1Acct = Ledger::systemAccountId($pdo, V1_ACCOUNT);
    $revenueAcct = Ledger::systemAccountId($pdo, Ledger::REVENUE);
    $archive = $pdo->prepare('INSERT INTO v1_archive (kind, v1_id, user_id, amount_kobo, status, occurred_at, data) VALUES (:k, :v, :u, :a, :s, :o, :d)');
    $arch = static function (string $kind, array $row, ?int $userId, ?int $amount, ?string $status, ?string $at) use ($archive): void {
        $archive->execute(['k' => $kind, 'v' => $row['id'] ?? null, 'u' => $userId, 'a' => $amount, 's' => $status, 'o' => utc($at), 'd' => json_encode($row, JSON_UNESCAPED_UNICODE)]);
    };

    // ------------------------------------------------------------ lookups: platforms, task types, interests
    $platformMap = [];
    $byName = [];
    foreach ($pdo->query('SELECT id, slug, name FROM platforms') as $r) { $byName[strtolower($r['name'])] = (int) $r['id']; $slugs[$r['slug']] = true; }
    $aliases = ['x (twitter)' => 'x (twitter)', 'twitter' => 'x (twitter)'];
    $insPlat = $pdo->prepare('INSERT INTO platforms (slug, name, domains, is_active, sort_order, created_at) VALUES (:s, :n, \'\', :a, :o, :c)');
    foreach ($B['platforms'] as $p) {
        $key = strtolower(trim($p['name']));
        $key = $aliases[$key] ?? $key;
        if (!isset($byName[$key])) {
            $slug = slugify($p['name']); $i = 2; while (isset($slugs[$slug])) $slug = slugify($p['name']) . '-' . $i++;
            $slugs[$slug] = true;
            $insPlat->execute(['s' => $slug, 'n' => cut($p['name'], 60), 'a' => $p['is_active'] ? 1 : 0, 'o' => 100 + (int) $p['sort_order'], 'c' => utc($p['created_at'])]);
            $byName[$key] = (int) $pdo->lastInsertId();
            $count('platforms_added');
        }
        $platformMap[$p['id']] = $byName[$key];
    }
    $otherPlatform = $byName['website'] ?? null;

    $ttMap = []; $ttByName = []; $ttSlugs = [];
    foreach ($pdo->query('SELECT id, slug, name FROM task_types') as $r) { $ttByName[strtolower($r['name'])] = (int) $r['id']; $ttSlugs[$r['slug']] = true; }
    $insTt = $pdo->prepare('INSERT INTO task_types (slug, name, proof_hint, base_price_kobo, base_reward_kobo, premium_price_kobo, premium_reward_kobo, is_active, sort_order, created_at) VALUES (:s, :n, \'\', :bp, :br, :pp, :pr, :a, :o, :c)');
    $ttV1 = [];
    foreach ($B['task_types'] as $t) {
        $ttV1[$t['id']] = $t;
        $key = strtolower(trim($t['name']));
        if (!isset($ttByName[$key])) {
            $slug = slugify($t['name']); $i = 2; while (isset($ttSlugs[$slug])) $slug = slugify($t['name']) . '-' . $i++;
            $ttSlugs[$slug] = true;
            $insTt->execute(['s' => $slug, 'n' => cut($t['name'], 60), 'bp' => kobo($t['base_advertiser_cost']), 'br' => kobo($t['base_earner_reward']),
                'pp' => kobo($t['premium_advertiser_cost']), 'pr' => kobo($t['premium_earner_reward']), 'a' => $t['is_active'] ? 1 : 0, 'o' => 200, 'c' => utc($t['created_at'])]);
            $ttByName[$key] = (int) $pdo->lastInsertId();
            $count('task_types_added');
        }
        $ttMap[$t['id']] = $ttByName[$key];
    }

    $intMap = []; $intByName = [];
    foreach ($pdo->query('SELECT id, name FROM interests') as $r) $intByName[mb_strtolower($r['name'])] = (int) $r['id'];
    $insInt = $pdo->prepare('INSERT INTO interests (name, sort_order, is_active, created_at) VALUES (:n, 500, :a, :c)');
    foreach ($B['interests'] as $it) {
        $name = cut($it['name'], 40); $key = mb_strtolower($name);
        if (!isset($intByName[$key])) {
            $insInt->execute(['n' => $name, 'a' => $it['is_active'] ? 1 : 0, 'c' => utc($it['created_at'])]);
            $intByName[$key] = (int) $pdo->lastInsertId();
            $count('interests_added');
        }
        $intMap[$it['id']] = $intByName[$key];
    }

    // ------------------------------------------------------------ users
    $profiles = [];
    foreach ($B['profiles'] as $p) $profiles[$p['id']] = $p;
    $userMap = []; $roleOf = []; $merged = [];
    $findUser = $pdo->prepare('SELECT id, role FROM users WHERE LOWER(email) = :e OR email_normalized = :n ORDER BY id LIMIT 1');
    $codeTaken = $pdo->prepare('SELECT COUNT(*) FROM users WHERE referral_code = :c');
    $insUser = $pdo->prepare('INSERT INTO users (display_name, email, referral_code, email_normalized, phone, password_hash, role, status, email_verified_at, last_login_at, created_at)
        VALUES (:n, :e, :rc, :en, :ph, :h, :r, \'active\', :v, :l, :c)');
    $insMap = $pdo->prepare('INSERT INTO v1_user_map (v1_id, user_id, merged) VALUES (:v, :u, :m)');
    foreach ($B['auth_users'] as $u) {
        $p = $profiles[$u['id']] ?? null;
        $email = strtolower(trim((string) $u['email']));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $warn("Skipped v1 user {$u['id']}: no valid email"); continue; }
        $role = in_array($p['role'] ?? 'earner', ['earner', 'advertiser', 'reviewer', 'admin'], true) ? (string) ($p['role'] ?? 'earner') : 'earner';
        $norm = Referrals::normalizeEmail($email);
        $findUser->execute(['e' => $email, 'n' => $norm]);
        $existing = $findUser->fetch();
        if (is_array($existing)) {
            $id = (int) $existing['id'];
            $merged[$u['id']] = true;
            $roleOf[$u['id']] = (string) $existing['role'];
            if ((string) $existing['role'] !== $role) $warn("Merged {$email} into existing v2 user #{$id}: kept v2 role {$existing['role']} (v1 role {$role}) and v2 password");
            $count('users_merged');
        } else {
            $hash = (string) ($u['encrypted_password'] ?? '');
            if (!preg_match('/^\$2[aby]\$\d\d\$/', $hash)) { $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT); $warn("{$email}: no bcrypt hash in v1, must use Forgot password"); }
            $code = Referrals::cleanCode(str_replace('-', '', (string) ($p['referral_code'] ?? '')));
            if ($code !== null) { $codeTaken->execute(['c' => $code]); if ((int) $codeTaken->fetchColumn() > 0) { $warn("{$email}: referral code {$code} taken, a new one will be issued"); $code = null; } }
            $name = cut($p['full_name'] ?? '', 80);
            if ($name === '') $name = cut(strstr($email, '@', true) ?: 'Member', 80);
            $phone = preg_replace('/[^0-9+]/', '', (string) ($p['phone'] ?? $u['phone'] ?? ''));
            $insUser->execute(['n' => $name, 'e' => $email, 'rc' => $code, 'en' => $norm, 'ph' => $phone !== '' ? substr($phone, 0, 20) : null, 'h' => $hash,
                'r' => $role, 'v' => utc($u['email_confirmed_at']), 'l' => utc($u['last_sign_in_at']), 'c' => utc($u['created_at']) ?? $now]);
            $id = (int) $pdo->lastInsertId();
            $roleOf[$u['id']] = $role;
            if (in_array($role, ['admin', 'reviewer'], true)) $warn("Imported staff account {$email} with v1 role {$role}");
            if (!empty($p['is_suspended'])) { $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = :id")->execute(['id' => $id]); $count('users_suspended'); }
            $count('users_created');
        }
        $userMap[$u['id']] = $id;
        $insMap->execute(['v' => $u['id'], 'u' => $id, 'm' => isset($merged[$u['id']]) ? 1 : 0]);
    }
    $uid = static function (?string $v1) use ($userMap): ?int { return $v1 !== null && isset($userMap[$v1]) ? $userMap[$v1] : null; };

    // advertiser settings
    $insAdv = $pdo->prepare('INSERT INTO advertiser_settings (user_id, auto_approve_hours) VALUES (:u, :h) ON DUPLICATE KEY UPDATE user_id = user_id');
    $autoHours = [];
    foreach ($profiles as $v1 => $p) {
        $h = max(12, min(336, (int) ($p['auto_approve_hours'] ?? 48)));
        $autoHours[$v1] = $h;
        if (($roleOf[$v1] ?? '') === 'advertiser' && isset($userMap[$v1])) $insAdv->execute(['u' => $userMap[$v1], 'h' => $h]);
    }

    // ------------------------------------------------------------ wallets: opening balances
    $wallets = [];
    foreach ($B['wallets'] as $w) $wallets[$w['user_id']] = $w;
    $openTotal = 0;
    foreach ($userMap as $v1 => $id) {
        $wal = Wallet::forUser($pdo, $id);
        $amt = kobo($wallets[$v1]['available_balance'] ?? 0);
        if ($amt < 0) { $warn("v1 user {$v1}: negative balance {$amt} kobo ignored"); $amt = 0; }
        if ($amt > 0) {
            Ledger::post($pdo, 'v1-open:' . $v1, 'v1_opening', 'Balance brought over from YOLOTASK v1', [[$v1Acct, 'debit', $amt], [$wal['available_account_id'], 'credit', $amt]], 'user', $id);
            $openTotal += $amt;
            $count('opening_balances');
        }
    }
    $report['opening_total_kobo'] = $openTotal;

    // ------------------------------------------------------------ campaigns + submissions
    $ledgerBySub = [];
    foreach ($B['wallet_ledger'] as $l) {
        if ($l['related_submission_id'] === null) continue;
        if ($l['transaction_type'] === 'CAMPAIGN_TASK_DEBIT') $ledgerBySub[$l['related_submission_id']]['price'] = kobo($l['amount']);
        if ($l['transaction_type'] === 'TASK_REWARD') $ledgerBySub[$l['related_submission_id']]['reward'] = kobo($l['amount']);
    }
    $subsByCampaign = [];
    foreach ($B['task_submissions'] as $s) $subsByCampaign[$s['campaign_id']][] = $s;

    // v1 SUBMITTED campaigns were never charged (no locked funds): they arrive as drafts to re-submit and pay
    $statusMap = ['LIVE' => 'live', 'PAUSED' => 'paused', 'SUBMITTED' => 'draft', 'DRAFT' => 'draft', 'COMPLETED' => 'completed', 'CANCELLED' => 'cancelled', 'REJECTED' => 'rejected', 'ENDED' => 'ended'];
    $insCamp = $pdo->prepare('INSERT INTO campaigns (advertiser_user_id, platform_id, task_type_id, title, link_url, link_normalized, link_hash, instructions, proof_requirements,
        tier, unit_price_kobo, unit_reward_kobo, quantity, budget_kobo, auto_approve_hours, status, ends_at, submitted_at, approved_at, closed_at, created_at)
        VALUES (:a, :p, :t, :ti, :l, :ln, :lh, :i, :pr, :tier, :up, :ur, :q, :b, :h, :s, :e, :sa, :aa, :ca, :c)');
    $insInstr = $pdo->prepare('INSERT INTO campaign_instructions (campaign_id, version, instructions, proof_requirements, created_by_user_id, created_at) VALUES (:c, 1, :i, :p, :u, :t)');
    $insSub = $pdo->prepare('INSERT INTO submissions (campaign_id, earner_user_id, status, attempts, comment, instructions_version, unit_price_kobo, unit_reward_kobo, reject_reason,
        decision_source, decided_by_user_id, submitted_at, auto_approve_at, decided_at, reward_status, reward_released_at, created_at)
        VALUES (:c, :e, :s, 1, :cm, 1, :up, :ur, :rr, :ds, :db, :sa, :aa, :da, :rs, :rra, :sa2)');
    $insEvt = $pdo->prepare("INSERT INTO campaign_funding_events (campaign_id, event, amount_kobo, ledger_transaction_id, note, created_at) VALUES (:c, 'lock', :a, :t, 'Imported from YOLOTASK v1', :d)");
    $campMap = [];
    $pendingProofs = [];
    $lockedByAdv = [];
    foreach ($B['wallets'] as $w) $lockedByAdv[$w['user_id']] = kobo($w['locked_balance']);
    $escrowByAdv = [];
    foreach ($B['campaigns'] as $c) {
        $adv = $uid($c['advertiser_id']);
        if ($adv === null) { $warn("Campaign {$c['id']} skipped: unknown advertiser"); continue; }
        $tt = $ttMap[$c['task_type_id']] ?? null;
        if ($tt === null) { $warn("Campaign {$c['id']} skipped: unknown task type"); continue; }
        $plat = $c['platform_id'] !== null ? ($platformMap[$c['platform_id']] ?? $otherPlatform) : $otherPlatform;
        if ($plat === null) $plat = (int) $pdo->query('SELECT id FROM platforms ORDER BY id LIMIT 1')->fetchColumn();
        $qty = max(1, (int) $c['target_quantity']);
        $budget = kobo($c['total_budget']);
        $subs = $subsByCampaign[$c['id']] ?? [];
        // unit price/reward: what v1 actually charged/paid, else budget/qty and the task type reward
        $prices = []; $rewards = [];
        foreach ($subs as $s) { if (isset($ledgerBySub[$s['id']]['price'])) $prices[] = $ledgerBySub[$s['id']]['price']; if (isset($ledgerBySub[$s['id']]['reward'])) $rewards[] = $ledgerBySub[$s['id']]['reward']; }
        $mode = static function (array $a): ?int { if ($a === []) return null; $c = array_count_values($a); arsort($c); return (int) array_key_first($c); };
        $tv1 = $ttV1[$c['task_type_id']];
        $unitPrice = $mode($prices) ?? intdiv($budget, $qty);
        $unitReward = $mode($rewards) ?? kobo($c['is_targeted'] ? $tv1['premium_earner_reward'] : $tv1['base_earner_reward']);
        if ($unitReward > $unitPrice) $unitReward = $unitPrice;

        $approved = 0; $pending = 0; $rejected = 0; $spent = 0; $rewardSum = 0;
        foreach ($subs as $s) {
            if ($s['status'] === 'APPROVED') { $approved++; $spent += $ledgerBySub[$s['id']]['price'] ?? $unitPrice; $rewardSum += min($ledgerBySub[$s['id']]['reward'] ?? $unitReward, $ledgerBySub[$s['id']]['price'] ?? $unitPrice); }
            elseif ($s['status'] === 'PENDING_REVIEW') $pending++;
            else $rejected++;
        }
        $status = $statusMap[strtoupper((string) $c['status'])] ?? 'paused';
        if (strtoupper((string) $c['status']) === 'SUBMITTED') $warn("Campaign '{$c['title']}' was awaiting approval in v1 and never charged: imported as a draft");
        $remaining = kobo($c['remaining_budget']);
        if ($status === 'draft') { $remaining = 0; }
        if (in_array($status, ['live', 'paused'], true) && $approved >= $qty && $pending === 0) $status = 'completed';
        if ($status === 'completed' && $remaining > $pending * $unitPrice) {
            // closed campaigns keep only what pending work needs; the rest goes back to the advertiser
            $refundBack = $remaining - $pending * $unitPrice; $remaining -= $refundBack;
        } else $refundBack = 0;
        if ($status !== 'draft' && $remaining < $pending * $unitPrice) {
            $warn("Campaign '{$c['title']}': v1 remaining budget {$remaining} < pending work " . ($pending * $unitPrice) . ' kobo; escrow topped up to cover it');
            $remaining = $pending * $unitPrice;
        }
        $link = trim((string) ($c['destination_link'] ?? ''));
        try {
            $platRow = $pdo->query('SELECT * FROM platforms WHERE id = ' . (int) $plat)->fetch();
            $lk = CampaignLinks::validate($link, ['domains' => '', 'name' => $platRow['name']]);
            $ln = $lk['normalized']; $lh = $lk['hash'];
        } catch (Throwable $e) { $ln = strtolower($link); $lh = hash('sha256', $ln); }
        $created = utc($c['created_at']) ?? $now;
        $instr = trim((string) ($c['instructions'] ?? '')) !== '' ? (string) $c['instructions'] : (string) ($c['description'] ?? '');
        $insCamp->execute(['a' => $adv, 'p' => $plat, 't' => $tt, 'ti' => cut($c['title'], 120), 'l' => mb_substr($link, 0, 500), 'ln' => mb_substr($ln, 0, 500), 'lh' => $lh,
            'i' => $instr, 'pr' => cut($c['proof_requirements'] ?? '', 500), 'tier' => $c['is_targeted'] ? 'targeted' : 'basic', 'up' => $unitPrice, 'ur' => $unitReward,
            'q' => $qty, 'b' => $budget, 'h' => $autoHours[$c['advertiser_id']] ?? 48, 's' => $status, 'e' => utc($c['end_date']),
            'sa' => $status === 'draft' ? null : $created, 'aa' => in_array($status, ['live', 'paused', 'completed'], true) ? $created : null,
            'ca' => $status === 'completed' ? (utc($c['updated_at']) ?? $now) : null, 'c' => $created]);
        $cid = (int) $pdo->lastInsertId();
        $campMap[$c['id']] = $cid;
        $insInstr->execute(['c' => $cid, 'i' => $instr, 'p' => cut($c['proof_requirements'] ?? '', 500), 'u' => $adv, 't' => $created]);
        $count('campaigns');

        // submissions
        foreach ($subs as $s) {
            $earner = $uid($s['earner_id']);
            if ($earner === null) { $warn("Submission {$s['id']} skipped: unknown earner"); continue; }
            $st = ['APPROVED' => 'approved', 'REJECTED' => 'rejected', 'PENDING_REVIEW' => 'pending'][$s['status']] ?? 'rejected';
            $sub = utc($s['created_at']) ?? $created;
            $price = $st === 'approved' ? ($ledgerBySub[$s['id']]['price'] ?? $unitPrice) : $unitPrice;
            $reward = $st === 'approved' ? min($ledgerBySub[$s['id']]['reward'] ?? $unitReward, $price) : $unitReward;
            $hours = $autoHours[$c['advertiser_id']] ?? 48;
            $auto = gmdate('Y-m-d H:i:s', max(strtotime($sub . ' UTC') + $hours * 3600, strtotime($now . ' UTC') + 24 * 3600));
            if ($st !== 'pending') $auto = gmdate('Y-m-d H:i:s', strtotime($sub . ' UTC') + $hours * 3600);
            $decider = $uid($s['reviewed_by']);
            $source = $st === 'pending' ? null : ($s['reviewed_by'] === null ? 'auto' : ($s['reviewed_by'] === $c['advertiser_id'] ? 'advertiser' : 'admin'));
            $decided = $st === 'pending' ? null : (utc($s['reviewed_at']) ?? $sub);
            $insSub->execute(['c' => $cid, 'e' => $earner, 's' => $st, 'cm' => $s['comment'] !== null ? cut($s['comment'], 500) : null, 'up' => $price, 'ur' => $reward,
                'rr' => $st === 'rejected' ? 'Rejected in YOLOTASK v1' : null, 'ds' => $source, 'db' => $decider, 'sa' => $sub, 'aa' => $auto, 'da' => $decided,
                'rs' => $st === 'approved' ? 'released' : 'none', 'rra' => $st === 'approved' ? $decided : null, 'sa2' => $sub]);
            $sid = (int) $pdo->lastInsertId();
            if ($st === 'pending' && $s['proof_url']) $pendingProofs[] = [$sid, (string) $s['proof_url']];
            $count('submissions_' . $st);
        }

        // escrow: funded = spent on approved work + what is still unspent
        if ($status !== 'draft') {
            $funded = $spent + $remaining;
            $pdo->prepare("INSERT INTO ledger_accounts (code, name, kind, normal_side, campaign_id) VALUES (:c, :n, 'campaign_escrow', 'credit', :id)")
                ->execute(['c' => 'campaign:' . $cid . ':escrow', 'n' => 'Campaign #' . $cid . ' escrow', 'id' => $cid]);
            $esc = (int) $pdo->lastInsertId();
            if ($funded > 0) {
                $tx = Ledger::post($pdo, 'v1-fund:' . $c['id'], 'v1_campaign_funding', 'Campaign budget brought over from YOLOTASK v1', [[$v1Acct, 'debit', $funded], [$esc, 'credit', $funded]], 'campaign', $cid);
                $insEvt->execute(['c' => $cid, 'a' => $funded, 't' => $tx['id'], 'd' => $created]);
            }
            if ($spent > 0) {
                $legs = [[$esc, 'debit', $spent]];
                if ($spent - $rewardSum > 0) $legs[] = [$revenueAcct, 'credit', $spent - $rewardSum];
                if ($rewardSum > 0) $legs[] = [$v1Acct, 'credit', $rewardSum];
                Ledger::post($pdo, 'v1-spent:' . $c['id'], 'v1_campaign_spent', 'Approved v1 tasks (rewards already in earner balances)', $legs, 'campaign', $cid);
            }
            $pdo->prepare('UPDATE campaigns SET escrow_account_id = :e, funded_kobo = :f, spent_kobo = :s, approved_count = :a, pending_count = :p, rejected_count = :r WHERE id = :id')
                ->execute(['e' => $esc, 'f' => $funded, 's' => $spent, 'a' => $approved, 'p' => $pending, 'r' => $rejected, 'id' => $cid]);
            $escrowByAdv[$c['advertiser_id']] = ($escrowByAdv[$c['advertiser_id']] ?? 0) + $remaining;
            if ($refundBack > 0) {
                $wal = Wallet::find($pdo, $adv);
                Ledger::post($pdo, 'v1-refund:' . $c['id'], 'v1_opening', 'Unused budget of a finished v1 campaign', [[$v1Acct, 'debit', $refundBack], [$wal['available_account_id'], 'credit', $refundBack]], 'campaign', $cid);
                $warn("Campaign '{$c['title']}' was complete in v1; unused " . $refundBack . ' kobo credited to the advertiser wallet');
            }
        } else {
            $pdo->prepare('UPDATE campaigns SET approved_count = :a, pending_count = :p, rejected_count = :r WHERE id = :id')->execute(['a' => $approved, 'p' => $pending, 'r' => $rejected, 'id' => $cid]);
        }
    }
    foreach ($lockedByAdv as $v1 => $locked) {
        $esc = $escrowByAdv[$v1] ?? 0;
        if ($locked !== $esc && ($locked > 0 || $esc > 0)) $warn("Advertiser v1 {$v1}: v1 locked balance {$locked} kobo vs escrow re-created {$esc} kobo (escrow follows each campaign's remaining budget)");
    }

    // proofs for pending submissions (the only ones v1 kept files for)
    if (!isset($opts['skip-proofs'])) {
        foreach ($pendingProofs as [$sid, $url]) {
            $ctx = stream_context_create(['http' => ['timeout' => 20], 'ssl' => ['verify_peer' => true]]);
            $bytes = @file_get_contents($url, false, $ctx);
            $info = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
            $mimeExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            if (!is_string($bytes) || $bytes === '' || !is_array($info) || !isset($mimeExt[$info['mime']])) { $warn("Proof for submission #{$sid} could not be copied ({$url})"); $count('proofs_missing'); continue; }
            Proofs::store($pdo, $sid, 1, ['bytes' => $bytes, 'mime' => $info['mime'], 'ext' => $mimeExt[$info['mime']], 'width' => (int) $info[0], 'height' => (int) $info[1], 'upload_sha256' => hash('sha256', $bytes)]);
            $count('proofs_copied');
        }
    }

    // ------------------------------------------------------------ bank accounts
    $insBank = $pdo->prepare('INSERT INTO bank_accounts (user_id, bank_code, bank_name, account_number, account_last4, account_name, name_verified_via, created_at) VALUES (:u, :c, :n, :a, :l, :an, \'none\', :t)');
    $hasBank = $pdo->prepare('SELECT COUNT(*) FROM bank_accounts WHERE user_id = :u');
    $banks = $B['earner_bank_accounts'];
    usort($banks, static fn ($a, $b) => [(int) !$a['is_default'], $b['created_at']] <=> [(int) !$b['is_default'], $a['created_at']]);
    foreach ($banks as $b) {
        $id = $uid($b['user_id']); if ($id === null) continue;
        $hasBank->execute(['u' => $id]); if ((int) $hasBank->fetchColumn() > 0) { $count('bank_accounts_skipped_existing'); continue; }
        $num = preg_replace('/\D/', '', (string) $b['account_number']);
        if (strlen($num) !== 10) { $warn("Bank account for user #{$id} skipped: not a 10-digit NUBAN"); continue; }
        $insBank->execute(['u' => $id, 'c' => substr((string) $b['bank_code'], 0, 8), 'n' => cut($b['bank_name'], 120), 'a' => $sealOk ? Crypto::seal($num) : $num, 'l' => substr($num, -4), 'an' => cut($b['account_name'], 100), 't' => utc($b['created_at']) ?? $now]);
        $count('bank_accounts');
    }

    // ------------------------------------------------------------ referrals
    $codeOf = $pdo->prepare('SELECT referral_code FROM users WHERE id = :id');
    $insRef = $pdo->prepare("INSERT INTO referrals (referrer_user_id, referee_user_id, referee_role, code_used, status, created_at) VALUES (:a, :b, :r, :c, 'active', :t)");
    $insSkip = $pdo->prepare("INSERT INTO referral_payouts (referral_id, referrer_user_id, referee_user_id, kind, milestone_id, milestone_task_count, base_kobo, amount_kobo, status, flag_note, created_at)
        VALUES (:r, :a, :b, 'milestone', :m, :tc, 0, 0, 'skipped', 'Reached in YOLOTASK v1 (v1 bonus already in balance)', :t)");
    $approvedBy = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE earner_user_id = :u AND status = 'approved'");
    $milestones = $pdo->query('SELECT id, task_count FROM referral_milestones WHERE is_active = 1')->fetchAll();
    $refs = $B['referrals'];
    usort($refs, static fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
    $seenReferee = [];
    foreach ($pdo->query('SELECT referee_user_id FROM referrals') as $r) $seenReferee[(int) $r['referee_user_id']] = true;
    foreach ($refs as $r) {
        $a = $uid($r['referrer_id']); $b = $uid($r['referred_id']);
        if ($a === null || $b === null || $a === $b) continue;
        if (isset($seenReferee[$b])) { $count('referrals_duplicate'); continue; }
        $seenReferee[$b] = true;
        $codeOf->execute(['id' => $a]); $code = $codeOf->fetchColumn();
        if (!is_string($code) || $code === '') $code = Referrals::codeFor($pdo, $a);
        $role = ($roleOf[$r['referred_id']] ?? 'earner') === 'advertiser' ? 'advertiser' : 'earner';
        $insRef->execute(['a' => $a, 'b' => $b, 'r' => $role, 'c' => $code, 't' => utc($r['created_at']) ?? $now]);
        $rid = (int) $pdo->lastInsertId();
        $count('referrals');
        $approvedBy->execute(['u' => $b]); $n = (int) $approvedBy->fetchColumn();
        foreach ($milestones as $m) if ($n >= (int) $m['task_count']) { $insSkip->execute(['r' => $rid, 'a' => $a, 'b' => $b, 'm' => $m['id'], 'tc' => $m['task_count'], 't' => $now]); $count('referral_milestones_marked_reached'); }
    }

    // ------------------------------------------------------------ interests, notifications, announcements
    $insUi = $pdo->prepare('INSERT IGNORE INTO user_interests (user_id, interest_id) VALUES (:u, :i)');
    foreach ($B['user_interests'] as $ui) { $id = $uid($ui['user_id']); $iid = $intMap[$ui['interest_id']] ?? null; if ($id && $iid) { $insUi->execute(['u' => $id, 'i' => $iid]); $count('user_interests', $insUi->rowCount()); } }

    $cat = ['TASK' => 'tasks', 'WALLET' => 'payments', 'CAMPAIGN' => 'campaigns', 'ANNOUNCEMENT' => 'announcements', 'GENERAL' => 'announcements', 'REFERRAL' => 'rewards'];
    $insN = $pdo->prepare('INSERT INTO notifications (user_id, category, title, body, dedupe_key, read_at, created_at) VALUES (:u, :c, :t, :b, :k, :r, :d)');
    foreach ($B['notifications'] as $n) {
        $id = $uid($n['user_id']); if ($id === null) continue;
        $at = utc($n['created_at']) ?? $now;
        $insN->execute(['u' => $id, 'c' => $cat[strtoupper((string) $n['type'])] ?? 'announcements', 't' => cut($n['title'], 140) ?: 'Notification', 'b' => cut($n['message'], 500),
            'k' => 'v1:' . $n['id'], 'r' => $n['is_read'] ? $at : null, 'd' => $at]);
        $count('notifications');
    }
    $insA = $pdo->prepare("INSERT INTO announcements (title, body, audience, starts_at, status, delivered_at, created_at) VALUES (:t, :b, 'all', :s, :st, :d, :c)");
    foreach ($B['announcements'] as $a) {
        $at = utc($a['created_at']) ?? $now;
        $insA->execute(['t' => cut($a['title'], 140), 'b' => cut($a['message'], 1000), 's' => $at, 'st' => $a['is_active'] ? 'active' : 'withdrawn', 'd' => $at, 'c' => $at]);
        $count('announcements');
    }

    // ------------------------------------------------------------ archive v1 history
    foreach ($B['wallet_ledger'] as $l) { $arch('wallet_ledger', $l, $uid($l['user_id']), kobo($l['amount']) * ($l['direction'] === 'DEBIT' ? -1 : 1), $l['transaction_type'], $l['created_at']); $count('archived_ledger'); }
    foreach ($B['withdrawals'] as $w) {
        $w2 = $w; $num = preg_replace('/\D/', '', (string) $w['account_number']); $w2['account_number'] = $sealOk ? Crypto::seal($num) : $num; $w2['account_last4'] = substr($num, -4);
        $arch('withdrawal', $w2, $uid($w['user_id']), kobo($w['amount']), $w['status'], $w['created_at']); $count('archived_withdrawals');
    }
    foreach ($B['payment_transactions'] as $p) { $arch('topup', $p, $uid($p['user_id']), kobo($p['amount']), $p['status'], $p['created_at']); $count('archived_topups'); }
    foreach ($B['daily_checkins'] as $d) { $arch('daily_checkin', $d, $uid($d['user_id']), kobo($d['reward_amount']), null, $d['created_at']); }
    foreach ($B['sms_verifications'] as $s) { unset($s['otp']); $arch('sms_verification', $s, $uid($s['user_id']), kobo($s['charged_ngn']), $s['status'], $s['created_at']); }

    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('migration.v1_imported', :v)")->execute(['v' => $now . ' UTC']);

    // ------------------------------------------------------------ reconciliation
    $problems = Ledger::reconcile($pdo);
    $report['ledger_problems'] = $problems;
    $report['totals'] = [
        'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'wallet_available_kobo' => (int) $pdo->query('SELECT COALESCE(SUM(available_kobo),0) FROM wallets')->fetchColumn(),
        'earner_available_kobo' => (int) $pdo->query("SELECT COALESCE(SUM(w.available_kobo),0) FROM wallets w JOIN users u ON u.id = w.user_id WHERE u.role = 'earner'")->fetchColumn(),
        'escrow_kobo' => (int) $pdo->query("SELECT COALESCE(SUM(balance_kobo),0) FROM ledger_accounts WHERE kind = 'campaign_escrow'")->fetchColumn(),
        'v1_migration_account_kobo' => (int) $pdo->query("SELECT balance_kobo FROM ledger_accounts WHERE code = '" . V1_ACCOUNT . "'")->fetchColumn(),
    ];
    if ($problems !== []) throw new RuntimeException('Ledger does not reconcile after import: ' . implode(' | ', array_slice($problems, 0, 5)));

    if ($dry) { $pdo->rollBack(); Proofs::discardWritten(); $report['result'] = 'DRY RUN: rolled back, nothing kept'; }
    else { $pdo->commit(); Proofs::forgetWritten(); $report['result'] = 'COMMITTED'; }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    Proofs::discardWritten();
    out(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fail(get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
}
out(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
