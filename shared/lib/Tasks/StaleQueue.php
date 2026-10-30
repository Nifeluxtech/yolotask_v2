<?php
declare(strict_types=1);
namespace YoloTask\Tasks;

use PDO;
use YoloTask\Core\Settings;

/**
 * Stale submissions: pending longer than tasks.stale_hours (default 24 h)
 * without an advertiser decision. Admins see them under Submissions → Stale
 * and can decide them; anything still pending is auto-approved when the
 * campaign's auto-approve window ends. (The Phase 5 reviewer queue will
 * build on this list.)
 */
final class StaleQueue
{
    public static function hours(PDO $pdo): int
    {
        return max(1, Settings::int($pdo, 'tasks.stale_hours'));
    }

    public static function count(PDO $pdo): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE status = 'pending' AND submitted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :h HOUR)");
        $stmt->bindValue('h', self::hours($pdo), PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }
}
