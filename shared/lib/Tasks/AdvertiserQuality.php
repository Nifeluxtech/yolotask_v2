<?php
declare(strict_types=1);
namespace YoloTask\Tasks;

use PDO;
use YoloTask\Core\Settings;

/**
 * Advertiser quality flag (Phase 5): how often an advertiser's rejections
 * are overturned on appeal. Over the last appeals.flag_window_days (30),
 * counting appeals against rejections the advertiser made (not auto,
 * reviewer or admin rejections) by their final decision time:
 *   overturned = appeals finally overturned,
 *   decided    = appeals finally upheld or overturned,
 *   rate       = overturned / decided.
 * Flagged when overturned >= appeals.flag_min_overturns (3) AND
 * rate >= appeals.flag_rate_percent (30 %). Shown to admins only
 * (Appeals → Flagged advertisers, user list); no automatic penalty.
 * Computed live from submission_appeals, so a flag clears by itself when
 * old overturns leave the window.
 */
final class AdvertiserQuality
{
    public static function rules(PDO $pdo): array
    {
        return ['min' => max(1, Settings::int($pdo, 'appeals.flag_min_overturns')), 'rate' => max(1, min(100, Settings::int($pdo, 'appeals.flag_rate_percent'))), 'days' => max(1, Settings::int($pdo, 'appeals.flag_window_days'))];
    }

    private const BASE = "FROM submission_appeals a WHERE a.rejection_source = 'advertiser' AND a.status IN ('upheld', 'overturned')
        AND COALESCE(a.overridden_at, a.resolved_at) >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :d DAY)";

    /** @return array{overturned:int,decided:int,rate:float,flagged:bool,rejections:int} */
    public static function stats(PDO $pdo, int $advertiserId): array
    {
        $r = self::rules($pdo);
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(a.status = 'overturned'), 0) o, COUNT(*) n " . self::BASE . ' AND a.advertiser_user_id = :a');
        $stmt->bindValue('d', $r['days'], PDO::PARAM_INT);
        $stmt->bindValue('a', $advertiserId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch() ?: ['o' => 0, 'n' => 0];
        $rej = $pdo->prepare("SELECT COUNT(*) FROM submissions s JOIN campaigns c ON c.id = s.campaign_id WHERE c.advertiser_user_id = :a AND s.decision_source = 'advertiser' AND s.status = 'rejected' AND s.decided_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :d DAY)");
        $rej->bindValue('a', $advertiserId, PDO::PARAM_INT);
        $rej->bindValue('d', $r['days'], PDO::PARAM_INT);
        $rej->execute();
        return self::shape((int) $row['o'], (int) $row['n'], $r) + ['rejections' => (int) $rej->fetchColumn()];
    }

    /** Every flagged advertiser with their numbers, worst first. */
    public static function flagged(PDO $pdo): array
    {
        $r = self::rules($pdo);
        $stmt = $pdo->prepare("SELECT a.advertiser_user_id, u.display_name, u.email, u.status, COALESCE(SUM(a.status = 'overturned'), 0) o, COUNT(*) n
            " . str_replace('FROM submission_appeals a', 'FROM submission_appeals a JOIN users u ON u.id = a.advertiser_user_id', self::BASE) . "
            GROUP BY a.advertiser_user_id, u.display_name, u.email, u.status HAVING o >= :m AND o * 100 >= :r * n ORDER BY o DESC, n ASC");
        $stmt->bindValue('d', $r['days'], PDO::PARAM_INT);
        $stmt->bindValue('m', $r['min'], PDO::PARAM_INT);
        $stmt->bindValue('r', $r['rate'], PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) $out[] = $row + self::shape((int) $row['o'], (int) $row['n'], $r);
        return $out;
    }

    /** advertiser id => true for the flagged ones among $ids. */
    public static function flaggedIds(PDO $pdo): array
    {
        $ids = [];
        foreach (self::flagged($pdo) as $row) $ids[(int) $row['advertiser_user_id']] = true;
        return $ids;
    }

    private static function shape(int $overturned, int $decided, array $r): array
    {
        $rate = $decided > 0 ? round($overturned * 100 / $decided, 1) : 0.0;
        return ['overturned' => $overturned, 'decided' => $decided, 'rate' => $rate, 'flagged' => $overturned >= $r['min'] && $overturned * 100 >= $r['rate'] * $decided];
    }
}
