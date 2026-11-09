<?php
declare(strict_types=1);
namespace YoloTask\Tasks;

use PDO;
use RuntimeException;
use YoloTask\Campaigns\Campaigns;
use YoloTask\Core\Settings;
use YoloTask\Growth\Levels;
use YoloTask\Account\Activation;
use YoloTask\Security\Audit;
use YoloTask\Security\Throttle;
use YoloTask\Support\Pagination;

/**
 * The earner task feed and slot reservations.
 *
 * A campaign has `quantity` slots. A slot is taken by an approved
 * submission, a pending submission, or an active, unexpired reservation.
 * Starting a task reserves a slot for tasks.reservation_minutes; the check
 * and the reservation happen under the campaign row lock, so two earners
 * can never take the last slot together. Expired reservations stop counting
 * immediately; cron marks them expired.
 * Phase 6: the earner's level (Growth\Levels) sets how many tasks they may
 * hold at once. Every live campaign is open to every earner.
 * A reservation records the campaign's price and reward when it is made
 * (migration 0017), and the submission is made at that price, so a later
 * price change never alters a task the earner already started.
 * YOLOTASK tasks (migration 0019, PlatformTasks\PlatformTasks): listed after
 * advertisers' tasks, tasks of one bundle (social account) together; no slot
 * limit, each once per earner; the level's held-task limit still applies.
 * Account activation (Account\Activation, migration 0019): while
 * activation.required is on, an earner who has not activated sees no task
 * and cannot start one; slots already held can still be submitted.
 */
final class Tasks
{
    public const PAGE_SIZE = 20;

    /**
     * Where-clause of the feed: live campaigns with free slots that this
     * earner has not done (or done for the same link and task type), and
     * YOLOTASK tasks of enabled bundles.
     * @return array{0:string,1:array}
     */
    private static function feedWhere(int $earnerId, ?int $platformId): array
    {
        $where = "WHERE c.status = 'live' AND (c.ends_at IS NULL OR c.ends_at > UTC_TIMESTAMP()) AND c.advertiser_user_id <> :me
            AND NOT EXISTS (SELECT 1 FROM submissions s WHERE s.campaign_id = c.id AND s.earner_user_id = :me2)
            AND NOT EXISTS (SELECT 1 FROM task_reservations r WHERE r.campaign_id = c.id AND r.earner_user_id = :me3 AND r.status IN ('active', 'submitted') AND (r.status = 'submitted' OR r.expires_at > UTC_TIMESTAMP()))
            AND NOT EXISTS (SELECT 1 FROM submissions s2 JOIN campaigns c2 ON c2.id = s2.campaign_id WHERE s2.earner_user_id = :me4 AND s2.status IN ('pending', 'approved') AND c2.link_hash = c.link_hash AND c2.task_type_id = c.task_type_id)
            AND (c.is_platform = 1 OR c.quantity + c.extra_approved_count > c.approved_count + c.pending_count + (SELECT COUNT(*) FROM task_reservations r2 WHERE r2.campaign_id = c.id AND r2.status = 'active' AND r2.expires_at > UTC_TIMESTAMP()))
            AND (c.bundle_id IS NULL OR EXISTS (SELECT 1 FROM platform_task_bundles b WHERE b.id = c.bundle_id AND b.status = 'live'))";
        $args = ['me' => $earnerId, 'me2' => $earnerId, 'me3' => $earnerId, 'me4' => $earnerId];
        if ($platformId !== null && $platformId > 0) { $where .= ' AND c.platform_id = :p'; $args['p'] = $platformId; }
        return [$where, $args];
    }

    /** How many tasks this earner could start (ignoring account activation). */
    public static function availableCount(PDO $pdo, int $earnerId): int
    {
        [$where, $args] = self::feedWhere($earnerId, null);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM campaigns c {$where}");
        $stmt->execute($args);
        return (int) $stmt->fetchColumn();
    }

    /**
     * The earner's feed. Advertisers' tasks first (newest approval first),
     * then YOLOTASK tasks bundle by bundle. A page holds PAGE_SIZE units: an
     * advertiser task, or one bundle's tasks for this earner (shown together).
     * 'rows' lists every task on the page in order; 'units' groups them.
     * While account activation is required and the earner has not activated,
     * the feed is empty and 'locked' is true.
     * @return array{rows:list<array>,units:list<array{bundle:?array,tasks:list<array>}>,total:int,count:int,page:int,pages:int,locked:bool}
     * (total = units, for the pager; count = tasks)
     */
    public static function feed(PDO $pdo, int $earnerId, int $page, ?int $platformId = null): array
    {
        $page = max(1, $page);
        if (!Activation::canWork($pdo, $earnerId)) return ['rows' => [], 'units' => [], 'total' => 0, 'count' => 0, 'page' => 1, 'pages' => 1, 'locked' => true];
        [$where, $args] = self::feedWhere($earnerId, $platformId);
        $stmt = $pdo->prepare("SELECT c.id, c.is_platform, c.bundle_id FROM campaigns c {$where}
            ORDER BY c.is_platform ASC, CASE WHEN c.is_platform = 0 THEN c.approved_at END DESC, CASE WHEN c.is_platform = 0 THEN c.id END DESC, c.bundle_id ASC, c.id ASC");
        $stmt->execute($args);
        $units = [];
        $count = 0;
        $lastBundle = null;
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $count++;
            $bid = (int) $r['is_platform'] === 1 && $r['bundle_id'] !== null ? (int) $r['bundle_id'] : null;
            if ($bid !== null && $bid === $lastBundle) { $units[count($units) - 1][] = (int) $r['id']; continue; }
            $units[] = [(int) $r['id']];
            $lastBundle = $bid;
        }
        $total = count($units);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $pageUnits = array_slice($units, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        $ids = array_merge([], ...$pageUnits);
        $byId = [];
        if ($ids !== []) {
            $rows = $pdo->query("SELECT c.id, c.title, c.is_platform, c.bundle_id, c.unit_reward_kobo, c.quantity, c.approved_count, c.pending_count, c.ends_at, COALESCE(c.custom_platform_name, p.name) platform_name, p.slug platform_slug, COALESCE(c.custom_task_name, t.name) task_type_name,
                    b.account_name bundle_account, b.profile_url bundle_profile_url,
                    (SELECT COUNT(*) FROM task_reservations r3 WHERE r3.campaign_id = c.id AND r3.status = 'active' AND r3.expires_at > UTC_TIMESTAMP()) reserved_count
                FROM campaigns c JOIN platforms p ON p.id = c.platform_id JOIN task_types t ON t.id = c.task_type_id LEFT JOIN platform_task_bundles b ON b.id = c.bundle_id
                WHERE c.id IN (" . implode(',', $ids) . ')')->fetchAll() ?: [];
            foreach ($rows as $row) $byId[(int) $row['id']] = $row;
        }
        $out = ['rows' => [], 'units' => [], 'total' => $total, 'count' => $count, 'page' => $page, 'pages' => $pages, 'locked' => false];
        foreach ($pageUnits as $u) {
            $tasks = array_values(array_filter(array_map(static fn (int $id): ?array => $byId[$id] ?? null, $u)));
            if ($tasks === []) continue;
            $first = $tasks[0];
            $bundle = (int) $first['is_platform'] === 1 && $first['bundle_id'] !== null
                ? ['id' => (int) $first['bundle_id'], 'account_name' => (string) $first['bundle_account'], 'platform_name' => (string) $first['platform_name'], 'platform_slug' => (string) $first['platform_slug'],
                    'profile_url' => $first['bundle_profile_url'], 'reward_total' => array_sum(array_map(static fn (array $t): int => (int) $t['unit_reward_kobo'], $tasks))]
                : null;
            $out['units'][] = ['bundle' => $bundle, 'tasks' => $tasks];
            foreach ($tasks as $t) $out['rows'][] = $t;
        }
        return $out;
    }

    /** Tasks the earner has started and not yet submitted. */
    public static function inProgress(PDO $pdo, int $earnerId): array
    {
        $stmt = $pdo->prepare("SELECT r.campaign_id, r.expires_at, c.title, c.is_platform, COALESCE(r.unit_reward_kobo, c.unit_reward_kobo) unit_reward_kobo, COALESCE(c.custom_platform_name, p.name) platform_name, COALESCE(c.custom_task_name, t.name) task_type_name FROM task_reservations r
            JOIN campaigns c ON c.id = r.campaign_id JOIN platforms p ON p.id = c.platform_id JOIN task_types t ON t.id = c.task_type_id
            WHERE r.earner_user_id = :u AND r.status = 'active' AND r.expires_at > UTC_TIMESTAMP() AND c.status IN ('live', 'paused') ORDER BY r.expires_at");
        $stmt->execute(['u' => $earnerId]);
        return $stmt->fetchAll() ?: [];
    }

    /** The earner's reservation for a campaign, or null. */
    public static function reservation(PDO $pdo, int $campaignId, int $earnerId): ?array
    {
        $stmt = $pdo->prepare('SELECT id, status, expires_at, started_count, unit_price_kobo, unit_reward_kobo, expires_at > UTC_TIMESTAMP() AS still_valid FROM task_reservations WHERE campaign_id = :c AND earner_user_id = :u');
        $stmt->execute(['c' => $campaignId, 'u' => $earnerId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** Start a task: reserve a slot. Returns the reservation expiry (UTC). Repeating while active is a no-op. */
    public static function start(PDO $pdo, array $user, int $campaignId): string
    {
        $uid = self::requireEarner($user);
        // Account activation (migration 0019): while it is required, a non-activated earner cannot start any task.
        if (!Activation::canWork($pdo, $uid)) throw new RuntimeException('Activate your account with ' . \YoloTask\Money\Money::format(Activation::fee($pdo)) . ' to access tasks.');
        if (Throttle::tooMany($pdo, 'task_start', (string) $uid, 30, 600)) throw new RuntimeException('You are starting tasks too quickly. Wait a few minutes.');
        $minutes = max(5, Settings::int($pdo, 'tasks.reservation_minutes'));
        $level = Levels::forEarner($pdo, $uid); // Phase 6: the earner's level sets how many tasks they may hold at once
        $maxActive = max(1, (int) $level['max_active']);
        $pdo->beginTransaction();
        try {
            $c = Campaigns::lockRow($pdo, $campaignId);
            if ((string) $c['status'] !== 'live' || ($c['ends_at'] !== null && strtotime((string) $c['ends_at'] . ' UTC') <= time())) throw new RuntimeException('This task is no longer available.');
            if ((int) $c['advertiser_user_id'] === $uid) throw new RuntimeException('This task is no longer available.');
            if ((int) $c['is_platform'] === 1 && $c['bundle_id'] !== null) { // YOLOTASK task: its bundle must be enabled
                $b = $pdo->prepare('SELECT status FROM platform_task_bundles WHERE id = :b');
                $b->execute(['b' => (int) $c['bundle_id']]);
                if ((string) $b->fetchColumn() !== 'live') throw new RuntimeException('This task is no longer available.');
            }
            $sub = $pdo->prepare('SELECT id FROM submissions WHERE campaign_id = :c AND earner_user_id = :u');
            $sub->execute(['c' => $campaignId, 'u' => $uid]);
            if ($sub->fetchColumn() !== false) throw new RuntimeException('You have already submitted this task.');
            $dup = $pdo->prepare("SELECT COUNT(*) FROM submissions s JOIN campaigns c2 ON c2.id = s.campaign_id WHERE s.earner_user_id = :u AND s.status IN ('pending', 'approved') AND c2.link_hash = :h AND c2.task_type_id = :t");
            $dup->execute(['u' => $uid, 'h' => $c['link_hash'], 't' => $c['task_type_id']]);
            if ((int) $dup->fetchColumn() > 0) throw new RuntimeException('You have already done this task for another campaign.');

            $mine = self::lockReservation($pdo, $campaignId, $uid);
            if ($mine !== null && (string) $mine['status'] === 'active' && strtotime((string) $mine['expires_at'] . ' UTC') > time()) { $pdo->commit(); return (string) $mine['expires_at']; }

            $active = $pdo->prepare("SELECT COUNT(*) FROM task_reservations WHERE earner_user_id = :u AND status = 'active' AND expires_at > UTC_TIMESTAMP()");
            $active->execute(['u' => $uid]);
            if ((int) $active->fetchColumn() >= $maxActive) throw new RuntimeException("You can work on {$maxActive} tasks at a time. Submit or release one first.");

            $held = $pdo->prepare("SELECT COUNT(*) FROM task_reservations WHERE campaign_id = :c AND status = 'active' AND expires_at > UTC_TIMESTAMP()");
            $held->execute(['c' => $campaignId]);
            if ((int) $c['approved_count'] + (int) $c['pending_count'] + (int) $held->fetchColumn() >= Campaigns::capacity($c)) throw new RuntimeException('All slots for this task are taken right now. Check back later.');

            // The slot is reserved at the campaign's current price and reward.
            $price = ['pp' => (int) $c['unit_price_kobo'], 'pr' => (int) $c['unit_reward_kobo']];
            if ($mine === null) {
                $pdo->prepare('INSERT INTO task_reservations (campaign_id, earner_user_id, status, expires_at, unit_price_kobo, unit_reward_kobo) VALUES (:c, :u, \'active\', DATE_ADD(UTC_TIMESTAMP(), INTERVAL :m MINUTE), :pp, :pr)')
                    ->execute(['c' => $campaignId, 'u' => $uid, 'm' => $minutes] + $price);
            } else {
                $pdo->prepare("UPDATE task_reservations SET status = 'active', expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :m MINUTE), started_count = started_count + 1, unit_price_kobo = :pp, unit_reward_kobo = :pr WHERE id = :id")
                    ->execute(['m' => $minutes, 'id' => $mine['id']] + $price);
            }
            $exp = self::lockReservation($pdo, $campaignId, $uid);
            Audit::record($pdo, $uid, 'task.started', 'campaign', $campaignId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        Throttle::hit($pdo, 'task_start', (string) $uid);
        return (string) $exp['expires_at'];
    }

    /** Give the slot back. */
    public static function release(PDO $pdo, array $user, int $campaignId): void
    {
        $uid = self::requireEarner($user);
        $pdo->beginTransaction();
        try {
            Campaigns::lockRow($pdo, $campaignId);
            $mine = self::lockReservation($pdo, $campaignId, $uid);
            if ($mine === null || (string) $mine['status'] !== 'active') throw new RuntimeException('You are not working on this task.');
            $pdo->prepare("UPDATE task_reservations SET status = 'released' WHERE id = :id")->execute(['id' => $mine['id']]);
            Audit::record($pdo, $uid, 'task.released', 'campaign', $campaignId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Cron: mark expired reservations (they already stopped counting). */
    public static function expireReservations(PDO $pdo): int
    {
        return (int) $pdo->exec("UPDATE task_reservations SET status = 'expired' WHERE status = 'active' AND expires_at <= UTC_TIMESTAMP()");
    }

    public static function lockReservation(PDO $pdo, int $campaignId, int $earnerId): ?array
    {
        $stmt = $pdo->prepare('SELECT id, status, expires_at, unit_price_kobo, unit_reward_kobo FROM task_reservations WHERE campaign_id = :c AND earner_user_id = :u FOR UPDATE');
        $stmt->execute(['c' => $campaignId, 'u' => $earnerId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function earnerStats(PDO $pdo, int $uid): array
    {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(status = 'pending'), 0) pending, COALESCE(SUM(status = 'approved'), 0) approved, COALESCE(SUM(status = 'rejected'), 0) rejected,
            COALESCE(SUM(CASE WHEN status = 'approved' THEN unit_reward_kobo END), 0) earned, COALESCE(SUM(CASE WHEN reward_status = 'held' THEN unit_reward_kobo END), 0) on_hold FROM submissions WHERE earner_user_id = :u");
        $stmt->execute(['u' => $uid]);
        return array_map('intval', $stmt->fetch() ?: []);
    }

    private static function requireEarner(array $user): int
    {
        if ((string) $user['role'] !== 'earner') throw new RuntimeException('Only earner accounts can do tasks.');
        if (empty($user['email_verified_at'])) throw new RuntimeException('Confirm your email to start earning.');
        return (int) $user['id'];
    }
}
