<?php
declare(strict_types=1);
namespace YoloTask\Tasks;

use PDO;
use RuntimeException;
use YoloTask\Core\Settings;
use YoloTask\Support\Pagination;

/**
 * Stale submissions: pending longer than tasks.stale_hours (default 24 h)
 * without an advertiser decision. Admins see them under Submissions → Stale;
 * reviewers work them in their second queue (Phase 5): claim one
 * (ReviewClaims, reviews.claim_minutes), then approve or reject it through
 * the normal Approvals path with source 'reviewer' (same money path, the
 * reviewer recorded as decider and in reviewer_actions). Anything still
 * pending is auto-approved when the campaign's auto-approve window ends.
 */
final class StaleQueue
{
    public const PAGE_SIZE = 20;

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

    public static function isStale(PDO $pdo, array $s): bool
    {
        return (string) $s['status'] === 'pending' && strtotime((string) $s['submitted_at'] . ' UTC') < time() - self::hours($pdo) * 3600;
    }

    /** Reviewer queue: stale pending submissions, oldest first, with any active claim. */
    public static function queue(PDO $pdo, int $page): array
    {
        $where = "WHERE s.status = 'pending' AND s.submitted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :h HOUR)";
        $sql = "SELECT s.id, s.campaign_id, s.submitted_at, s.auto_approve_at, s.attempts, s.comment, s.instructions_version, s.unit_price_kobo, s.unit_reward_kobo, c.title campaign_title, p.name platform_name, t.name task_type_name,
                u.display_name earner_name, rc.reviewer_user_id claim_user_id, rc.expires_at claim_expires_at, cu.display_name claim_reviewer_name,
                (SELECT pr.id FROM submission_proofs pr WHERE pr.submission_id = s.id AND pr.is_appeal = 0 ORDER BY pr.attempt DESC, pr.id DESC LIMIT 1) latest_proof_id
            FROM submissions s JOIN campaigns c ON c.id = s.campaign_id JOIN platforms p ON p.id = c.platform_id JOIN task_types t ON t.id = c.task_type_id JOIN users u ON u.id = s.earner_user_id
            LEFT JOIN review_claims rc ON rc.item_type = 'submission' AND rc.item_id = s.id AND rc.expires_at > UTC_TIMESTAMP() LEFT JOIN users cu ON cu.id = rc.reviewer_user_id
            {$where} ORDER BY s.submitted_at ASC, s.id ASC";
        return Pagination::query($pdo, "SELECT COUNT(*) FROM submissions s {$where}", $sql, ['h' => self::hours($pdo)], $page, self::PAGE_SIZE);
    }

    public static function claim(PDO $pdo, array $reviewer, int $submissionId): string
    {
        $rid = self::requireStaff($reviewer);
        $pdo->beginTransaction();
        try {
            $s = Approvals::lockSubmission($pdo, $submissionId);
            if (!self::isStale($pdo, $s)) throw new RuntimeException('This proof is no longer in the stale queue.');
            $exp = ReviewClaims::claimLocked($pdo, 'submission', $submissionId, $rid);
            $pdo->commit();
            return $exp;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function release(PDO $pdo, array $reviewer, int $submissionId): void
    {
        $rid = self::requireStaff($reviewer);
        $pdo->beginTransaction();
        try {
            Approvals::lockSubmission($pdo, $submissionId);
            ReviewClaims::release($pdo, 'submission', $submissionId, $rid);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Reviewer approves or rejects a stale submission they have claimed.
     * @param 'approve'|'reject' $decision
     */
    public static function decide(PDO $pdo, array $reviewer, int $submissionId, string $decision, string $reason = ''): void
    {
        if ((string) $reviewer['role'] !== 'reviewer') throw new RuntimeException('Only reviewers work the stale queue.');
        $rid = (int) $reviewer['id'];
        $guard = static function (PDO $pdo, array $row) use ($rid, $submissionId, $decision, $reason): void {
            if (!self::isStale($pdo, $row)) throw new RuntimeException('This proof is no longer in the stale queue.');
            ReviewClaims::assertHeld($pdo, 'submission', $submissionId, $rid);
            ReviewClaims::clear($pdo, 'submission', $submissionId);
            ReviewerActions::record($pdo, $rid, 'reviewer', $decision === 'approve' ? 'submission_approved' : 'submission_rejected', 'submission', $submissionId, $submissionId, $decision === 'reject' ? trim($reason) : null);
        };
        if ($decision === 'approve') Approvals::approve($pdo, $submissionId, 'reviewer', $rid, null, $guard);
        elseif ($decision === 'reject') Approvals::reject($pdo, $submissionId, 'reviewer', $rid, $reason, null, $guard);
        else throw new RuntimeException('Choose approve or reject.');
    }

    private static function requireStaff(array $user): int
    {
        if ((string) $user['role'] !== 'reviewer') throw new RuntimeException('Only reviewers work the stale queue.');
        return (int) $user['id'];
    }
}
