<?php
declare(strict_types=1);
namespace YoloTask\Campaigns;

use PDO;

/**
 * Campaign members are the earners taking part in a campaign: who started
 * it, who is working on it now, and how their submissions went. Advertisers
 * see first names only; admins see the full roster.
 */
final class CampaignMembers
{
    public static function summary(PDO $pdo, int $campaignId): array
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) started, COALESCE(SUM(status = 'active' AND expires_at > UTC_TIMESTAMP()), 0) working, COALESCE(SUM(status = 'expired' OR (status = 'active' AND expires_at <= UTC_TIMESTAMP())), 0) expired FROM task_reservations WHERE campaign_id = :c");
        $stmt->execute(['c' => $campaignId]);
        $r = $stmt->fetch() ?: [];
        $sub = $pdo->prepare("SELECT COALESCE(SUM(status = 'pending'), 0) pending, COALESCE(SUM(status = 'approved'), 0) approved, COALESCE(SUM(status = 'rejected'), 0) rejected,
            COALESCE(SUM(decision_source = 'auto'), 0) auto_approved FROM submissions WHERE campaign_id = :c");
        $sub->execute(['c' => $campaignId]);
        $s = $sub->fetch() ?: [];
        return array_map('intval', array_merge($r, $s));
    }

    /** Earners currently holding a slot (working on the task). */
    public static function working(PDO $pdo, int $campaignId, int $limit = 50): array
    {
        $stmt = $pdo->prepare("SELECT r.earner_user_id, r.expires_at, r.created_at, u.display_name FROM task_reservations r JOIN users u ON u.id = r.earner_user_id
            WHERE r.campaign_id = :c AND r.status = 'active' AND r.expires_at > UTC_TIMESTAMP() ORDER BY r.expires_at LIMIT " . max(1, min(200, $limit)));
        $stmt->execute(['c' => $campaignId]);
        return $stmt->fetchAll() ?: [];
    }

    /** First name only, for advertiser-facing lists. */
    public static function publicName(string $displayName): string
    {
        $first = trim((string) strtok(trim($displayName), ' '));
        return $first !== '' ? $first : 'Earner';
    }
}
