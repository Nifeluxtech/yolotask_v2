<?php
declare(strict_types=1);
namespace YoloTask\PlatformTasks;

use PDO;
use RuntimeException;
use YoloTask\Campaigns\CampaignLinks;
use YoloTask\Campaigns\Instructions;
use YoloTask\Campaigns\Pricing;
use YoloTask\Core\Settings;
use YoloTask\Money\Wallet;
use YoloTask\Security\Audit;
use YoloTask\Support\Pagination;

/**
 * YOLOTASK tasks: tasks the platform provides itself (migration 0019).
 *
 * Tasks are grouped in bundles: one social account (platform, account name,
 * profile link) with several tasks under it, each its own row with its own
 * link, task type, instructions, reward and proof (follow the profile, like
 * post A, comment on post B ...). Each task is a campaign row with
 * is_platform = 1 and bundle_id set, owned by the closed system account
 * platform_tasks.owner_user_id ("YOLOTASK"), so the normal task flow applies
 * (Tasks, Submissions, Approvals, Appeals, StaleQueue). Differences:
 *  - no escrow, no budget, no slot limit (Campaigns::capacity is unlimited);
 *    each task once per earner (submissions unique per campaign + earner);
 *  - listed in the normal feed after advertisers' tasks, with no label;
 *  - price = reward = the task type's normal reward x
 *    platform_tasks.reward_percent (default 50 %), rounded DOWN to the kobo,
 *    minimum 1 kobo; paid by the platform from system:platform_task_payouts
 *    (Approvals::postApproval);
 *  - proofs go straight to the reviewer queue (no advertiser to decide);
 *  - a task is live (enabled), paused (disabled; a held slot can still be
 *    submitted, like a paused campaign) or ended (removed); a paused bundle
 *    hides all its tasks.
 * Bulk import (admin page or scripts/import-platform-tasks.php) is keyed on
 * bundle_key + task type + normalised link, so re-running it adds nothing.
 */
final class PlatformTasks
{
    public const PAYOUT_ACCOUNT = 'system:platform_task_payouts';
    public const MAX_IMPORT_ROWS = 5000;
    public const PAGE_SIZE = 20;
    public const CSV_HEADER = ['bundle_key', 'platform', 'account_name', 'task_type', 'url', 'instructions'];
    private const PLATFORM_ALIASES = ['twitter' => 'x', 'ig' => 'instagram', 'insta' => 'instagram', 'fb' => 'facebook', 'yt' => 'youtube', 'tg' => 'telegram', 'wa' => 'whatsapp'];
    private const TYPE_ALIASES = ['repost' => 'share', 'retweet' => 'share', 'reshare' => 'share', 'view' => 'watch', 'views' => 'watch', 'likes' => 'like', 'follows' => 'follow',
        'followers' => 'follow', 'comments' => 'comment', 'shares' => 'share', 'subscribers' => 'subscribe', 'sub' => 'subscribe', 'react' => 'like', 'love' => 'like', 'heart' => 'like'];

    public static function rewardPercent(PDO $pdo): int
    {
        return min(100, max(1, Settings::int($pdo, 'platform_tasks.reward_percent')));
    }

    /** Reward for a normal reward: percent of it, rounded down to the kobo, at least 1 kobo. */
    public static function rewardFor(int $baseRewardKobo, int $percent): int
    {
        return max(1, intdiv(max(0, $baseRewardKobo) * min(100, max(1, $percent)), 100));
    }

    public static function ownerUserId(PDO $pdo): int
    {
        $id = Settings::int($pdo, 'platform_tasks.owner_user_id');
        if ($id <= 0) {
            $stmt = $pdo->query("SELECT id FROM users WHERE email = 'platform-tasks@system.yolotask.invalid'");
            $id = (int) ($stmt !== false ? $stmt->fetchColumn() : 0);
        }
        if ($id <= 0) throw new RuntimeException('The YOLOTASK system account is missing. Run migration 0019.');
        return $id;
    }

    /** Task types that can carry YOLOTASK tasks (priced, not the custom task). Inactive types too, so imports never depend on the advertiser catalog switch. */
    public static function taskTypes(PDO $pdo): array
    {
        return array_values(array_filter(Pricing::taskTypes($pdo, false), static fn (array $t): bool => !Pricing::isCustom($t) && (int) $t['base_reward_kobo'] > 0));
    }

    public static function platforms(PDO $pdo): array
    {
        return array_values(array_filter(Pricing::platforms($pdo, false), static fn (array $p): bool => !Pricing::isCustom($p)));
    }

    /** Find a platform or task type by slug, name, a name prefix ("X" for "X (Twitter)") or a common alias. */
    public static function match(array $list, string $key, array $aliases = []): ?array
    {
        $k = strtolower(trim($key));
        if ($k === '') return null;
        $k = $aliases[$k] ?? $k;
        foreach ($list as $r) {
            if ($k === strtolower((string) $r['slug']) || $k === strtolower((string) $r['name'])) return $r;
        }
        foreach ($list as $r) {
            $name = strtolower((string) $r['name']);
            if (str_starts_with($name, $k . ' ') || str_starts_with($name, $k . '/')) return $r;
        }
        $k2 = rtrim($k, 's');
        if ($k2 !== $k) return self::match($list, $k2, $aliases);
        return null;
    }

    public static function platformAliases(): array { return self::PLATFORM_ALIASES; }
    public static function typeAliases(): array { return self::TYPE_ALIASES; }

    // ------------------------------------------------------------------ bundles

    public static function bundle(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT b.*, p.name platform_name, p.slug platform_slug, p.domains platform_domains FROM platform_task_bundles b JOIN platforms p ON p.id = b.platform_id WHERE b.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function bundleByKey(PDO $pdo, string $key): ?array
    {
        $stmt = $pdo->prepare('SELECT b.*, p.name platform_name, p.slug platform_slug FROM platform_task_bundles b JOIN platforms p ON p.id = b.platform_id WHERE b.bundle_key = :k');
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function cleanKey(string $key): string
    {
        return strtolower(trim($key));
    }

    public static function validKey(string $key): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9._\-]{0,63}$/', $key);
    }

    private static function cleanAccount(string $name): string
    {
        $name = self::cleanText($name);
        if (mb_strlen($name) < 1 || mb_strlen($name) > 80) throw new RuntimeException('Give the account name (1 to 80 characters).');
        return $name;
    }

    /** Profile link for a bundle: optional, but when given it must be a link on the bundle's platform. */
    private static function cleanProfileUrl(string $url, array $platform): ?string
    {
        $url = trim($url);
        if ($url === '') return null;
        return CampaignLinks::validate($url, $platform)['url'];
    }

    /** Create a bundle (admin form). Returns its id. */
    public static function createBundle(PDO $pdo, int $adminId, int $platformId, string $accountName, string $profileUrl, string $key = ''): int
    {
        $platform = null;
        foreach (self::platforms($pdo) as $p) if ((int) $p['id'] === $platformId) $platform = $p;
        if ($platform === null) throw new RuntimeException('Choose the platform.');
        $account = self::cleanAccount($accountName);
        $profile = self::cleanProfileUrl($profileUrl, $platform);
        $key = self::cleanKey($key);
        if ($key === '') $key = self::suggestKey($pdo, (string) $platform['slug'], $account);
        if (!self::validKey($key)) throw new RuntimeException('The bundle key may use letters, digits, dot, dash and underscore (up to 64 characters).');
        if (self::bundleByKey($pdo, $key) !== null) throw new RuntimeException('A bundle with the key "' . $key . '" already exists.');
        $pdo->prepare('INSERT INTO platform_task_bundles (bundle_key, platform_id, account_name, profile_url, created_by_user_id) VALUES (:k, :p, :a, :u, :by)')
            ->execute(['k' => $key, 'p' => $platformId, 'a' => $account, 'u' => $profile, 'by' => $adminId]);
        $id = (int) $pdo->lastInsertId();
        Audit::record($pdo, $adminId, 'admin.platform_bundle_created', 'platform_task_bundle', $id);
        return $id;
    }

    private static function suggestKey(PDO $pdo, string $platformSlug, string $account): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($account)), '-');
        $base = substr($platformSlug . '-' . ($slug !== '' ? $slug : 'account'), 0, 56);
        $key = $base;
        for ($i = 2; self::bundleByKey($pdo, $key) !== null; $i++) $key = $base . '-' . $i;
        return $key;
    }

    public static function updateBundle(PDO $pdo, int $adminId, int $id, string $accountName, string $profileUrl): void
    {
        $b = self::bundle($pdo, $id);
        if ($b === null) throw new RuntimeException('Bundle not found.');
        $account = self::cleanAccount($accountName);
        $profile = self::cleanProfileUrl($profileUrl, ['name' => $b['platform_name'], 'domains' => $b['platform_domains']]);
        $pdo->prepare('UPDATE platform_task_bundles SET account_name = :a, profile_url = :u WHERE id = :id')->execute(['a' => $account, 'u' => $profile, 'id' => $id]);
        Audit::record($pdo, $adminId, 'admin.platform_bundle_updated', 'platform_task_bundle', $id);
    }

    /** Enable or disable whole bundles. @param list<int> $ids */
    public static function setBundlesEnabled(PDO $pdo, int $adminId, array $ids, bool $enabled): int
    {
        $ids = self::ids($ids);
        if ($ids === []) throw new RuntimeException('Select at least one bundle.');
        $n = $pdo->exec("UPDATE platform_task_bundles SET status = '" . ($enabled ? 'live' : 'paused') . "' WHERE id IN (" . implode(',', $ids) . ')');
        Audit::record($pdo, $adminId, $enabled ? 'admin.platform_bundles_enabled' : 'admin.platform_bundles_disabled', 'platform_task_bundle', $ids[0]);
        return (int) $n;
    }

    /** Enable (live) or disable (paused) single tasks. @param list<int> $ids */
    public static function setTasksEnabled(PDO $pdo, int $adminId, array $ids, bool $enabled): int
    {
        $ids = self::ids($ids);
        if ($ids === []) throw new RuntimeException('Select at least one task.');
        $n = $pdo->exec("UPDATE campaigns SET status = '" . ($enabled ? 'live' : 'paused') . "', status_reason = " . ($enabled ? 'NULL' : "'Disabled by an admin'")
            . ' WHERE is_platform = 1 AND id IN (' . implode(',', $ids) . ") AND status IN ('live', 'paused')");
        Audit::record($pdo, $adminId, $enabled ? 'admin.platform_tasks_enabled' : 'admin.platform_tasks_disabled', 'campaign', $ids[0]);
        return (int) $n;
    }

    /**
     * Remove a task from its bundle (status ended). Its history (proofs,
     * payouts) stays; a slot an earner already holds can no longer be
     * submitted. Adding the same link and task type again brings it back.
     */
    public static function removeTask(PDO $pdo, int $adminId, int $id): void
    {
        $n = $pdo->prepare("UPDATE campaigns SET status = 'ended', status_reason = 'Removed by an admin', closed_at = UTC_TIMESTAMP() WHERE id = :id AND is_platform = 1 AND status IN ('live', 'paused')");
        $n->execute(['id' => $id]);
        if ($n->rowCount() !== 1) throw new RuntimeException('Task not found.');
        $pdo->prepare("UPDATE task_reservations SET status = 'released' WHERE campaign_id = :id AND status = 'active'")->execute(['id' => $id]);
        Audit::record($pdo, $adminId, 'admin.platform_task_removed', 'campaign', $id);
    }

    /** Edit a task's title, instructions and proof text (a changed instruction is a new version, like campaigns). */
    public static function edit(PDO $pdo, int $adminId, int $id, string $title, string $instructions, string $proof): void
    {
        $title = self::cleanText($title);
        if (mb_strlen($title) < 3 || mb_strlen($title) > 120) throw new RuntimeException('Give the task a title of 3 to 120 characters.');
        $text = Instructions::clean($instructions, $proof);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = :id AND is_platform = 1 FOR UPDATE');
            $stmt->execute(['id' => $id]);
            $c = $stmt->fetch();
            if (!is_array($c)) throw new RuntimeException('Task not found.');
            $changed = $text['instructions'] !== (string) $c['instructions'] || $text['proof'] !== (string) $c['proof_requirements'];
            $version = (int) $c['instructions_version'] + ($changed ? 1 : 0);
            $pdo->prepare('UPDATE campaigns SET title = :t, instructions = :i, proof_requirements = :p, instructions_version = :v WHERE id = :id')
                ->execute(['t' => $title, 'i' => $text['instructions'], 'p' => $text['proof'], 'v' => $version, 'id' => $id]);
            if ($changed) Instructions::record($pdo, $id, $version, $text['instructions'], $text['proof'], $adminId);
            Audit::record($pdo, $adminId, 'admin.platform_task_edited', 'campaign', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------------ adding tasks

    /** Title for a task in a bundle, e.g. "Follow Nifelux on Facebook", "Like Nifelux's post on Facebook". */
    public static function title(array $type, string $account, string $platformName): string
    {
        $platformName = (string) preg_replace('/\s*\(.*\)$/', '', $platformName); // "X (Twitter)" -> "X"
        $t = match ((string) $type['slug']) {
            'follow' => 'Follow ' . $account,
            'like' => 'Like ' . $account . "'s post",
            'comment' => 'Comment on ' . $account . "'s post",
            'share' => 'Share ' . $account . "'s post",
            'subscribe' => 'Subscribe to ' . $account,
            'join' => 'Join ' . $account,
            'watch' => 'Watch ' . $account . "'s video",
            default => $type['name'] . ': ' . $account,
        };
        $t .= ' on ' . $platformName;
        return mb_strlen($t) > 120 ? rtrim(mb_substr($t, 0, 119)) . '…' : $t;
    }

    public static function defaultInstructions(string $platformName, array $type, string $account): string
    {
        $verb = match ((string) $type['slug']) {
            'follow' => 'follow ' . $account,
            'like' => 'like the post',
            'comment' => 'write a genuine comment (a few words, no spam) under the post',
            'share' => 'share or repost the post',
            'subscribe' => 'subscribe to ' . $account,
            'join' => 'join the group or channel',
            'watch' => 'watch the video to the end',
            default => strtolower((string) $type['name']),
        };
        return 'Open the link with your own ' . $platformName . ' account and ' . $verb . ".\n"
            . 'Take a screenshot that clearly shows it is done, with your account visible.' . "\n"
            . 'Do not undo it later: rewards for undone tasks can be reversed.';
    }

    /**
     * Add tasks to a bundle (inside the caller's transaction). Each link is
     * one task. An existing task for the same bundle + task type + link is
     * skipped; a removed one is brought back only when $restore is true (the
     * admin's add-tasks form), never by a bulk import, so re-running an
     * import never revives what an admin removed.
     * @param list<array{url:string,instructions:string,line?:int}> $links
     * @return array{added:int,skipped:int,restored:int,errors:list<string>,ids:list<int>}
     */
    public static function addTasksLocked(PDO $pdo, int $actorId, array $bundle, array $type, array $links, bool $restore = false): array
    {
        $out = ['added' => 0, 'skipped' => 0, 'restored' => 0, 'errors' => [], 'ids' => []];
        $platform = ['name' => $bundle['platform_name'], 'domains' => $bundle['platform_domains'] ?? self::platformDomains($pdo, (int) $bundle['platform_id'])];
        $owner = self::ownerUserId($pdo);
        $reward = self::rewardFor((int) $type['base_reward_kobo'], self::rewardPercent($pdo));
        $hours = max(1, Settings::int($pdo, 'tasks.auto_approve_default_hours'));
        $find = $pdo->prepare('SELECT id, status FROM campaigns WHERE bundle_id = :b AND task_type_id = :t AND link_hash = :h');
        $insert = $pdo->prepare("INSERT INTO campaigns (advertiser_user_id, platform_id, task_type_id, title, link_url, link_normalized, link_hash, instructions, proof_requirements, tier, is_platform, bundle_id,
                unit_price_kobo, unit_reward_kobo, quantity, budget_kobo, funded_kobo, auto_approve_hours, status, submitted_at, approved_at, approved_by_user_id)
            VALUES (:u, :p, :t, :ti, :l, :ln, :lh, :i, :pr, 'basic', 1, :b, :r, :r2, 100000, 0, 0, :aa, 'live', UTC_TIMESTAMP(), UTC_TIMESTAMP(), :a)");
        $title = self::title($type, (string) $bundle['account_name'], (string) $bundle['platform_name']);
        foreach ($links as $i => $row) {
            $ln = 'Line ' . (int) ($row['line'] ?? $i + 1) . ': ';
            try {
                $link = CampaignLinks::validate((string) $row['url'], $platform);
            } catch (RuntimeException $e) {
                $out['errors'][] = $ln . $e->getMessage();
                continue;
            }
            $find->execute(['b' => $bundle['id'], 't' => $type['id'], 'h' => $link['hash']]);
            $existing = $find->fetch();
            if (is_array($existing)) {
                if ($restore && (string) $existing['status'] === 'ended') {
                    $pdo->prepare("UPDATE campaigns SET status = 'live', status_reason = NULL, closed_at = NULL WHERE id = :id")->execute(['id' => $existing['id']]);
                    $out['restored']++;
                    $out['ids'][] = (int) $existing['id'];
                } else {
                    $out['skipped']++;
                }
                continue;
            }
            $instructions = trim(str_replace(['\\n', "\r\n"], "\n", (string) ($row['instructions'] ?? '')));
            if ($instructions === '') $instructions = self::defaultInstructions((string) preg_replace('/\s*\(.*\)$/', '', (string) $bundle['platform_name']), $type, (string) $bundle['account_name']);
            try {
                $text = Instructions::clean($instructions, (string) ($type['proof_hint'] ?: 'Screenshot that shows you completed the task.'));
            } catch (RuntimeException $e) {
                $out['errors'][] = $ln . $e->getMessage();
                continue;
            }
            $insert->execute(['u' => $owner, 'p' => $bundle['platform_id'], 't' => $type['id'], 'ti' => $title, 'l' => $link['url'], 'ln' => $link['normalized'], 'lh' => $link['hash'],
                'i' => $text['instructions'], 'pr' => $text['proof'], 'b' => $bundle['id'], 'r' => $reward, 'r2' => $reward, 'aa' => $hours, 'a' => $actorId]);
            $id = (int) $pdo->lastInsertId();
            Instructions::record($pdo, $id, 1, $text['instructions'], $text['proof'], $actorId);
            $out['added']++;
            $out['ids'][] = $id;
        }
        // A bundle without a profile link takes the first follow / subscribe / join link.
        if (empty($bundle['profile_url']) && in_array((string) $type['slug'], ['follow', 'subscribe', 'join'], true) && $out['ids'] !== []) {
            $pdo->prepare('UPDATE platform_task_bundles SET profile_url = (SELECT link_url FROM campaigns WHERE id = :c) WHERE id = :b AND profile_url IS NULL')->execute(['c' => $out['ids'][0], 'b' => $bundle['id']]);
        }
        return $out;
    }

    private static function platformDomains(PDO $pdo, int $platformId): string
    {
        $stmt = $pdo->prepare('SELECT domains FROM platforms WHERE id = :id');
        $stmt->execute(['id' => $platformId]);
        return (string) $stmt->fetchColumn();
    }

    /**
     * Admin form: add tasks of one type to a bundle, one link per line
     * (paste several post links at once).
     * @return array{added:int,skipped:int,restored:int,errors:list<string>,ids:list<int>}
     */
    public static function addTasks(PDO $pdo, int $adminId, int $bundleId, int $typeId, string $linksText, string $instructions): array
    {
        $bundle = self::bundle($pdo, $bundleId);
        if ($bundle === null) throw new RuntimeException('Bundle not found.');
        $type = null;
        foreach (self::taskTypes($pdo) as $t) if ((int) $t['id'] === $typeId) $type = $t;
        if ($type === null) throw new RuntimeException('Choose the task type.');
        $links = [];
        foreach (preg_split('/\R/', $linksText) ?: [] as $i => $line) {
            foreach (preg_split('/[\s,]+/', trim($line)) ?: [] as $url) {
                if ($url !== '') $links[] = ['url' => $url, 'instructions' => $instructions, 'line' => $i + 1];
            }
        }
        if ($links === []) throw new RuntimeException('Paste at least one link.');
        if (count($links) > 200) throw new RuntimeException('Add at most 200 links at a time.');
        Wallet::forUser($pdo, self::ownerUserId($pdo)); // approvals lock the owner's wallet row like any campaign's
        $pdo->beginTransaction();
        try {
            $res = self::addTasksLocked($pdo, $adminId, $bundle, $type, $links, true);
            if ($res['added'] + $res['restored'] > 0) Audit::record($pdo, $adminId, 'admin.platform_tasks_added', 'platform_task_bundle', $bundleId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $res;
    }

    // ------------------------------------------------------------------ bulk import

    /**
     * Parse CSV text with the header
     * bundle_key,platform,account_name,task_type,url,instructions
     * (columns in any order; instructions optional). Blank lines and lines
     * starting with # are skipped.
     * @return list<array{line:int,bundle_key:string,platform:string,account_name:string,task_type:string,url:string,instructions:string}>
     */
    public static function parseCsv(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (str_starts_with($text, "\xEF\xBB\xBF")) $text = substr($text, 3);
        if (!mb_check_encoding($text, 'UTF-8')) throw new RuntimeException('Save the file as UTF-8 and try again.');
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $text);
        rewind($fh);
        $cols = null;
        $rows = [];
        $line = 0;
        while (($f = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $line++;
            if ($f === [null] || (count($f) === 1 && trim((string) $f[0]) === '')) continue;
            if (str_starts_with(ltrim((string) $f[0]), '#')) continue;
            $f = array_map(static fn ($v): string => trim((string) $v), $f);
            if ($cols === null) {
                $head = array_map(static fn (string $h): string => (string) preg_replace('/[^a-z_]/', '', str_replace(' ', '_', strtolower($h))), $f);
                foreach (['bundle_key', 'platform', 'account_name', 'task_type', 'url'] as $need) {
                    if (!in_array($need, $head, true)) throw new RuntimeException('The first line must be the header: ' . implode(',', self::CSV_HEADER) . ' (missing "' . $need . '").');
                }
                $cols = array_flip($head);
                continue;
            }
            $get = static fn (string $k): string => isset($cols[$k]) ? (string) ($f[$cols[$k]] ?? '') : '';
            $rows[] = ['line' => $line, 'bundle_key' => $get('bundle_key'), 'platform' => $get('platform'), 'account_name' => $get('account_name'),
                'task_type' => $get('task_type'), 'url' => $get('url'), 'instructions' => $get('instructions')];
            if (count($rows) > self::MAX_IMPORT_ROWS) throw new RuntimeException('Import at most ' . number_format(self::MAX_IMPORT_ROWS) . ' rows at a time.');
        }
        fclose($fh);
        if ($cols === null) throw new RuntimeException('The file is empty. The first line must be the header: ' . implode(',', self::CSV_HEADER) . '.');
        return $rows;
    }

    /**
     * Import parsed rows: rows with the same bundle_key form one bundle (made
     * on first sight; an existing bundle is reused and keeps its name and
     * status). Idempotent: a task already in the bundle for the same task type
     * and normalised link is skipped (also when an admin removed it). Bad rows are reported, the rest are
     * added. Everything runs in one transaction; $dryRun rolls it back.
     * @return array{bundles_created:int,bundles_reused:int,added:int,skipped:int,restored:int,errors:list<string>}
     */
    public static function import(PDO $pdo, ?int $actorId, array $rows, bool $dryRun = false): array
    {
        if ($rows === []) throw new RuntimeException('There are no task rows to import.');
        $actor = $actorId ?? self::ownerUserId($pdo);
        $platforms = self::platforms($pdo);
        $types = self::taskTypes($pdo);
        $out = ['bundles_created' => 0, 'bundles_reused' => 0, 'added' => 0, 'skipped' => 0, 'restored' => 0, 'errors' => []];
        // Group rows: bundle_key -> task type id -> links.
        $groups = [];
        $meta = [];
        foreach ($rows as $row) {
            $ln = 'Line ' . (int) $row['line'] . ': ';
            $key = self::cleanKey((string) $row['bundle_key']);
            if (!self::validKey($key)) { $out['errors'][] = $ln . 'bundle_key must be letters, digits, dot, dash or underscore (up to 64 characters).'; continue; }
            $platform = self::match($platforms, (string) $row['platform'], self::PLATFORM_ALIASES);
            if ($platform === null) { $out['errors'][] = $ln . 'unknown platform "' . mb_substr((string) $row['platform'], 0, 40) . '".'; continue; }
            $type = self::match($types, (string) $row['task_type'], self::TYPE_ALIASES);
            if ($type === null) { $out['errors'][] = $ln . 'unknown task type "' . mb_substr((string) $row['task_type'], 0, 40) . '".'; continue; }
            try {
                CampaignLinks::validate((string) $row['url'], $platform); // a bundle is only made when at least one of its links is usable
            } catch (RuntimeException $e) {
                $out['errors'][] = $ln . $e->getMessage();
                continue;
            }
            $account = self::cleanText((string) $row['account_name']);
            if (isset($meta[$key])) {
                if ((int) $meta[$key]['platform']['id'] !== (int) $platform['id']) { $out['errors'][] = $ln . 'bundle "' . $key . '" is on ' . $meta[$key]['platform']['name'] . ', not ' . $platform['name'] . '.'; continue; }
            } else {
                if ($account === '' || mb_strlen($account) > 80) { $out['errors'][] = $ln . 'account_name must be 1 to 80 characters.'; continue; }
                $meta[$key] = ['platform' => $platform, 'account' => $account];
            }
            $groups[$key][(int) $type['id']]['type'] = $type;
            $groups[$key][(int) $type['id']]['links'][] = ['url' => (string) $row['url'], 'instructions' => (string) $row['instructions'], 'line' => (int) $row['line']];
        }
        Wallet::forUser($pdo, self::ownerUserId($pdo));
        $pdo->beginTransaction();
        try {
            foreach ($groups as $key => $byType) {
                $bundle = self::bundleByKey($pdo, $key);
                if ($bundle !== null && (int) $bundle['platform_id'] !== (int) $meta[$key]['platform']['id']) {
                    foreach ($byType as $g) foreach ($g['links'] as $l) $out['errors'][] = 'Line ' . $l['line'] . ': bundle "' . $key . '" already exists on ' . $bundle['platform_name'] . '.';
                    continue;
                }
                if ($bundle === null) {
                    $pdo->prepare('INSERT INTO platform_task_bundles (bundle_key, platform_id, account_name, created_by_user_id) VALUES (:k, :p, :a, :by)')
                        ->execute(['k' => $key, 'p' => $meta[$key]['platform']['id'], 'a' => $meta[$key]['account'], 'by' => $actorId]);
                    $bundle = self::bundle($pdo, (int) $pdo->lastInsertId());
                    $out['bundles_created']++;
                } else {
                    $bundle = self::bundle($pdo, (int) $bundle['id']);
                    $out['bundles_reused']++;
                }
                foreach ($byType as $g) {
                    $res = self::addTasksLocked($pdo, $actor, $bundle, $g['type'], $g['links']);
                    $bundle = self::bundle($pdo, (int) $bundle['id']); // profile_url may have been filled
                    foreach (['added', 'skipped', 'restored'] as $k) $out[$k] += $res[$k];
                    foreach ($res['errors'] as $e) $out['errors'][] = $e;
                }
            }
            if ($dryRun) {
                $pdo->rollBack();
            } else {
                if ($out['added'] + $out['restored'] > 0) Audit::record($pdo, $actorId, 'admin.platform_tasks_imported', 'platform_task_bundle', null);
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        usort($out['errors'], static fn (string $a, string $b): int => (int) preg_replace('/\D.*$/s', '', substr($a, 5)) <=> (int) preg_replace('/\D.*$/s', '', substr($b, 5)));
        return $out;
    }

    // ------------------------------------------------------------------ rewards

    /**
     * Re-price every YOLOTASK task to the current rule (task type reward x
     * percent). Slots already reserved and proofs already made keep their
     * price, exactly like a repriced campaign. Returns tasks changed.
     */
    public static function syncRewards(PDO $pdo): int
    {
        $percent = self::rewardPercent($pdo);
        $n = 0;
        foreach ($pdo->query("SELECT id, base_reward_kobo FROM task_types WHERE slug <> 'custom'")->fetchAll() ?: [] as $t) {
            $r = self::rewardFor((int) $t['base_reward_kobo'], $percent);
            $stmt = $pdo->prepare('UPDATE campaigns SET unit_price_kobo = :r, unit_reward_kobo = :r2 WHERE is_platform = 1 AND task_type_id = :t AND unit_reward_kobo <> :r3');
            $stmt->execute(['r' => $r, 'r2' => $r, 't' => (int) $t['id'], 'r3' => $r]);
            $n += $stmt->rowCount();
        }
        return $n;
    }

    public static function saveRewardPercent(PDO $pdo, int $adminId, int $percent): int
    {
        if ($percent < 1 || $percent > 100) throw new RuntimeException('The reward must be 1 to 100% of the normal reward.');
        Settings::save($pdo, $adminId, ['platform_tasks.reward_percent' => (string) $percent]);
        Audit::record($pdo, $adminId, 'admin.platform_tasks_settings_updated', 'settings', null);
        return self::syncRewards($pdo);
    }

    // ------------------------------------------------------------------ admin lists

    /** Bundles with task and completion counts. */
    public static function listBundles(PDO $pdo, string $status, int $platformId, string $q, int $page): array
    {
        $where = 'WHERE 1 = 1';
        $args = [];
        if (in_array($status, ['live', 'paused'], true)) { $where .= ' AND b.status = :s'; $args['s'] = $status; }
        if ($platformId > 0) { $where .= ' AND b.platform_id = :p'; $args['p'] = $platformId; }
        if ($q !== '') { $where .= ' AND (b.account_name LIKE :q OR b.bundle_key LIKE :q2 OR b.profile_url LIKE :q3)'; $like = '%' . addcslashes($q, '%_\\') . '%'; $args += ['q' => $like, 'q2' => $like, 'q3' => $like]; }
        $sql = "SELECT b.*, p.name platform_name, p.slug platform_slug,
                COALESCE(SUM(c.status = 'live'), 0) live_tasks, COALESCE(SUM(c.status = 'paused'), 0) paused_tasks,
                COALESCE(SUM(c.approved_count), 0) approved, COALESCE(SUM(c.pending_count), 0) pending
            FROM platform_task_bundles b JOIN platforms p ON p.id = b.platform_id LEFT JOIN campaigns c ON c.bundle_id = b.id AND c.status IN ('live', 'paused')
            {$where} GROUP BY b.id ORDER BY b.id DESC";
        return Pagination::query($pdo, "SELECT COUNT(*) FROM platform_task_bundles b {$where}", $sql, $args, $page, self::PAGE_SIZE);
    }

    /** Tasks of a bundle (live and disabled, then removed), with completion counts. */
    public static function bundleTasks(PDO $pdo, int $bundleId): array
    {
        $stmt = $pdo->prepare("SELECT c.id, c.title, c.link_url, c.status, c.unit_reward_kobo, c.approved_count, c.pending_count, c.rejected_count, c.instructions, c.proof_requirements, c.instructions_version,
                t.name task_type_name, t.slug task_type_slug
            FROM campaigns c JOIN task_types t ON t.id = c.task_type_id WHERE c.bundle_id = :b AND c.is_platform = 1 ORDER BY c.status = 'ended', t.sort_order, c.id");
        $stmt->execute(['b' => $bundleId]);
        return $stmt->fetchAll() ?: [];
    }

    public static function stats(PDO $pdo): array
    {
        $r = $pdo->query("SELECT COALESCE(SUM(c.status = 'live' AND b.status = 'live'), 0) live, COALESCE(SUM(c.status = 'paused' OR b.status = 'paused'), 0) paused,
                COALESCE(SUM(c.approved_count), 0) approved, COALESCE(SUM(c.pending_count), 0) pending
            FROM campaigns c JOIN platform_task_bundles b ON b.id = c.bundle_id WHERE c.is_platform = 1 AND c.status IN ('live', 'paused')")->fetch() ?: [];
        $out = array_map('intval', $r);
        $out['bundles'] = (int) $pdo->query('SELECT COUNT(*) FROM platform_task_bundles')->fetchColumn();
        $out['payouts'] = (int) $pdo->query("SELECT COALESCE((SELECT balance_kobo FROM ledger_accounts WHERE code = '" . self::PAYOUT_ACCOUNT . "'), 0)")->fetchColumn();
        return $out;
    }

    /** Live tasks per platform and task type. */
    public static function categories(PDO $pdo): array
    {
        return $pdo->query("SELECT p.name platform_name, t.name task_type_name, c.unit_reward_kobo, COUNT(*) n, COALESCE(SUM(c.approved_count), 0) approved
            FROM campaigns c JOIN platform_task_bundles b ON b.id = c.bundle_id AND b.status = 'live' JOIN platforms p ON p.id = c.platform_id JOIN task_types t ON t.id = c.task_type_id
            WHERE c.is_platform = 1 AND c.status = 'live' GROUP BY p.name, t.name, c.unit_reward_kobo, p.sort_order, t.sort_order ORDER BY p.sort_order, t.sort_order")->fetchAll() ?: [];
    }

    /** @return list<int> */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
    }

    private static function cleanText(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s)));
    }
}
