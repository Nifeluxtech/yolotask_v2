<?php
declare(strict_types=1);
namespace YoloTask\Messaging;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use YoloTask\Security\Audit;

/**
 * Admin announcements (Phase 6): a banner on every signed-in page of the
 * audience (all, earners or advertisers) between starts_at and ends_at, plus
 * one inbox item per active user in the audience, delivered once when the
 * start time arrives (immediately, or by the five-minute cron). Not emailed
 * and not pushed (a broadcast would mean thousands of sends per request).
 */
final class Announcements
{
    public const AUDIENCES = ['all' => 'Everyone', 'earner' => 'Earners', 'advertiser' => 'Advertisers'];

    /** $startsLocal / $endsLocal are "Y-m-d\TH:i" in Lagos time (from datetime-local inputs); empty start = now. */
    public static function create(PDO $pdo, int $adminId, string $title, string $body, string $audience, string $startsLocal, string $endsLocal): int
    {
        $title = trim($title);
        $body = trim($body);
        if (mb_strlen($title) < 3 || mb_strlen($title) > 140) throw new RuntimeException('Give the announcement a title of 3 to 140 characters.');
        if (mb_strlen($body) < 3 || mb_strlen($body) > 1000) throw new RuntimeException('Write a message of 3 to 1,000 characters.');
        if (!isset(self::AUDIENCES[$audience])) throw new RuntimeException('Choose who should see it.');
        $starts = trim($startsLocal) === '' ? gmdate('Y-m-d H:i:s') : self::toUtc($startsLocal);
        $ends = trim($endsLocal) === '' ? null : self::toUtc($endsLocal);
        if ($ends !== null && strtotime($ends . ' UTC') <= strtotime($starts . ' UTC')) throw new RuntimeException('The end time must be after the start time.');
        if ($ends !== null && strtotime($ends . ' UTC') <= time()) throw new RuntimeException('The end time is already in the past.');
        $pdo->prepare('INSERT INTO announcements (title, body, audience, starts_at, ends_at, created_by_user_id) VALUES (:t, :b, :a, :s, :e, :u)')
            ->execute(['t' => $title, 'b' => $body, 'a' => $audience, 's' => $starts, 'e' => $ends, 'u' => $adminId]);
        $id = (int) $pdo->lastInsertId();
        Audit::record($pdo, $adminId, 'admin.announcement_created', 'announcement', $id);
        self::deliverDue($pdo);
        return $id;
    }

    public static function withdraw(PDO $pdo, int $adminId, int $id): void
    {
        $stmt = $pdo->prepare("UPDATE announcements SET status = 'withdrawn' WHERE id = :id AND status = 'active'");
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) throw new RuntimeException('That announcement is not active.');
        Audit::record($pdo, $adminId, 'admin.announcement_withdrawn', 'announcement', $id);
    }

    /** Put started, undelivered announcements into the inbox of every active user in the audience. */
    public static function deliverDue(PDO $pdo): int
    {
        $due = $pdo->query("SELECT id, title, body, audience FROM announcements WHERE status = 'active' AND delivered_at IS NULL AND starts_at <= UTC_TIMESTAMP()
            AND (ends_at IS NULL OR ends_at > UTC_TIMESTAMP()) ORDER BY id LIMIT 20")->fetchAll() ?: [];
        $n = 0;
        foreach ($due as $a) {
            $roles = (string) $a['audience'] === 'all' ? "'earner', 'advertiser'" : "'" . ((string) $a['audience'] === 'earner' ? 'earner' : 'advertiser') . "'";
            $stmt = $pdo->prepare("INSERT IGNORE INTO notifications (user_id, category, title, body, url, dedupe_key)
                SELECT id, 'announcements', :t, :b, 'notifications.php', :k FROM users WHERE status = 'active' AND role IN ({$roles})");
            $stmt->execute(['t' => mb_substr((string) $a['title'], 0, 140), 'b' => mb_substr((string) $a['body'], 0, 500), 'k' => 'ann:' . (int) $a['id']]);
            $pdo->prepare('UPDATE announcements SET delivered_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['id' => (int) $a['id']]);
            $n += $stmt->rowCount();
        }
        return $n;
    }

    /** Banners live right now for a portal role. */
    public static function live(PDO $pdo, string $role): array
    {
        if (!in_array($role, ['earner', 'advertiser'], true)) return [];
        $stmt = $pdo->prepare("SELECT id, title, body FROM announcements WHERE status = 'active' AND starts_at <= UTC_TIMESTAMP() AND (ends_at IS NULL OR ends_at > UTC_TIMESTAMP())
            AND audience IN ('all', :r) ORDER BY id DESC LIMIT 3");
        $stmt->execute(['r' => $role]);
        return $stmt->fetchAll() ?: [];
    }

    public static function recent(PDO $pdo, int $limit = 30): array
    {
        return $pdo->query("SELECT a.*, (SELECT COUNT(*) FROM notifications n WHERE n.dedupe_key = CONCAT('ann:', a.id)) inbox_count,
            CASE WHEN a.status = 'withdrawn' THEN 'withdrawn' WHEN a.starts_at > UTC_TIMESTAMP() THEN 'scheduled' WHEN a.ends_at IS NOT NULL AND a.ends_at <= UTC_TIMESTAMP() THEN 'ended' ELSE 'live' END state
            FROM announcements a ORDER BY a.id DESC LIMIT " . max(1, $limit))->fetchAll() ?: [];
    }

    private static function toUtc(string $local): string
    {
        $local = trim($local);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $local, new DateTimeZone('Africa/Lagos'))
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $local, new DateTimeZone('Africa/Lagos'));
        if ($dt === false) throw new RuntimeException('Enter dates as day and time (Lagos time).');
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
