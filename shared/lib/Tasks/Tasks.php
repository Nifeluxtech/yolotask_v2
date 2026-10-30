<?php
declare(strict_types=1);
namespace YoloTask\Tasks;

use PDO;
use RuntimeException;
use YoloTask\Campaigns\Campaigns;
use YoloTask\Core\Settings;
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
 */
final class Tasks
{
    public const PAGE_SIZE = 20;

    /** Live campaigns with free slots that this earner has not done (or done for the same link and task type). */
    public static function feed(PDO $pdo, int $earnerId, int $page, ?int $platformId = null): array
    {
        $where = "WHERE c.status = 'live' AND (c.ends_at IS NULL OR c.ends_at > UTC_TIMESTAMP()) AND c.advertiser_user_id <> :me
            AND NOT EXISTS (SELECT 1 FROM submissions s WHERE s.campaign_id = c.id AND s.earner_user_id = :me2)
            AND NOT EXISTS (SELECT 1 FROM task_reservations r WHERE r.campaign_id = c.id AND r.earner_user_id = :me3 AND r.status IN ('active', 'submitted') AND (r.status = 'submitted' OR r.expires_at > UTC_TIMESTAMP()))
            AND NOT EXISTS (SELECT 1 FROM submissions s2 JOIN campaigns c2 ON c2.id = s2.campaign_id WHERE s2.earner_user_id = :me4 AND s2.status IN ('pending', 'approved') AND c2.link_hash = c.link_hash AND c2.task_type_id = c.task_type_id)
            AND c.quantity > c.approved_count + c.pending_count + (SELECT COUNT(*) FROM task_reservations r2 WHERE r2.campaign_id = c.id AND r2.status = 'active' AND r2.expires_at > UTC_TIMESTAMP())";
        $args = ['me' => $earnerId, 'me2' => $earnerId, 'me3' => $earnerId, 'me4' => $earnerId];
        if ($platformId !== null && $platformId > 0) { $where .= ' AND c.platform_id = :p'; $args['p'] = $platformId; }
        $select = "SELECT c.id, c.title, c.tier, c.unit_reward_kobo, c.quantity, c.approved_count, c.pending_count, c.ends_at, p.name platform_name, p.slug platform_slug, t.name task_type_name,
            (SELECT COUNT(*) FROM task_reservations r3 WHERE r3.campaign_id = c.id AND r3.status = 'active' AND r3.expires_at > UTC_TIMESTAMP()) reserved_count
            FROM campaigns c JOIN platforms p ON p.id = c.platform_id JOIN task_types t ON t.id = c.task_type_id {$where}";
        return Pagination::query($pdo, "SELECT COUNT(*) FROM campaigns c {$where}", $select . " ORDER BY c.tier = 'targeted' DESC, c.approved_at DESC, c.id DESC", $args, $page, self::PAGE_SIZE);
    }

    /** Tasks the earner has started and not yet submitted. */
    public static function inProgress(PDO $pdo, int $earnerId): array
    {
        $stmt = $pdo->prepare("SELECT r.campaign_id, r.expires_at, c.title, c.unit_reward_kobo, p.name platform_name, t.name task_type_name FROM task_reservations r
            JOIN campaigns c ON c.id = r.campaign_id JOIN platforms p ON p.id = c.platform_id JOIN task_types t ON t.id = c.task_type_id
            WHERE r.earner_user_id = :u AND r.status = 'active' AND r.expires_at > UTC_TIMESTAMP() AND c.status IN ('live', 'paused') ORDER BY r.expires_at");
        $stmt->execute(['u' => $earnerId]);
        return $stmt->fetchAll() ?: [];
    }

    /** The earner's reservation for a campaign, or null. */
    public static function reservation(PDO $pdo, int $campaignId, int $earnerId): ?array
    {
        $stmt = $pdo->prepare('SELECT id, status, expires_at, started_count, expires_at > UTC_TIMESTAMP() AS still_valid FROM task_reservations WHERE campaign_id = :c AND earner_user_id = :u');
        $stmt->execute(['c' => $campaignId, 'u' => $earnerId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** Start a task: reserve a slot. Returns the reservation expiry (UTC). Repeating while active is a no-op. */
    public static function start(PDO $pdo, array $user, int $campaignId): string
    {
        $uid = self::requireEarner($user);
        if (Throttle::tooMany($pdo, 'task_start', (string) $uid, 30, 600)) throw new RuntimeException('You are starting tasks too quickly. Wait a few minutes.');
        $minutes = max(5, Settings::int($pdo, 'tasks.reservation_minutes'));
        $maxActive = max(1, Settings::int($pdo, 'tasks.max_active_reservations'));
        $pdo->beginTransaction();
        try {
            $c = Campaigns::lockRow($pdo, $campaignId);
            if ((string) $c['status'] !== 'live' || ($c['ends_at'] !== null && strtotime((string) $c['ends_at'] . ' UTC') <= time())) throw new RuntimeException('This task is no longer available.');
            if ((int) $c['advertiser_user_id'] === $uid) throw new RuntimeException('This task is no longer available.');
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
            if ((int) $c['approved_count'] + (int) $c['pending_count'] + (int) $held->fetchColumn() >= (int) $c['quantity']) throw new RuntimeException('All slots for this task are taken right now. Check back later.');

            if ($mine === null) {
                $pdo->prepare('INSERT INTO task_reservations (campaign_id, earner_user_id, status, expires_at) VALUES (:c, :u, \'active\', DATE_ADD(UTC_TIMESTAMP(), INTERVAL :m MINUTE))')
                    ->execute(['c' => $campaignId, 'u' => $uid, 'm' => $minutes]);
            } else {
                $pdo->prepare("UPDATE task_reservations SET status = 'active', expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :m MINUTE), started_count = started_count + 1 WHERE id = :id")
                    ->execute(['m' => $minutes, 'id' => $mine['id']]);
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
        $stmt = $pdo->prepare('SELECT id, status, expires_at FROM task_reservations WHERE campaign_id = :c AND earner_user_id = :u FOR UPDATE');
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
