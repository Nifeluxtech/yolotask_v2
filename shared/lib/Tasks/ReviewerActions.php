<?php
declare(strict_types=1);
namespace YoloTask\Tasks;

use PDO;
use YoloTask\Support\Pagination;

/**
 * Log of every decision made in the reviewer queues (appeals and stale
 * submissions) by reviewers and admins: who, what, which item and when.
 * Reviewer pay (a later phase) will be computed from these rows.
 */
final class ReviewerActions
{
    /** Inside the caller's transaction. */
    public static function record(PDO $pdo, int $userId, string $role, string $action, string $itemType, int $itemId, int $submissionId, ?string $note = null): void
    {
        $pdo->prepare('INSERT INTO reviewer_actions (reviewer_user_id, reviewer_role, action, item_type, item_id, submission_id, note, decided_at) VALUES (:u, :r, :a, :t, :i, :s, :n, UTC_TIMESTAMP())')
            ->execute(['u' => $userId, 'r' => $role === 'admin' ? 'admin' : 'reviewer', 'a' => $action, 't' => $itemType, 'i' => $itemId, 's' => $submissionId, 'n' => $note === null ? null : mb_substr($note, 0, 500)]);
    }

    public static function forReviewer(PDO $pdo, int $userId, int $page): array
    {
        $sql = 'SELECT ra.*, c.title campaign_title FROM reviewer_actions ra JOIN submissions s ON s.id = ra.submission_id JOIN campaigns c ON c.id = s.campaign_id WHERE ra.reviewer_user_id = :u ORDER BY ra.id DESC';
        return Pagination::query($pdo, 'SELECT COUNT(*) FROM reviewer_actions WHERE reviewer_user_id = :u', $sql, ['u' => $userId], $page, 25);
    }

    /** Counts for the reviewer dashboard: decisions today (Lagos day) and in the last 30 days. */
    public static function counts(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(decided_at >= :today), 0) today, COUNT(*) last30 FROM reviewer_actions WHERE reviewer_user_id = :u AND decided_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)');
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Africa/Lagos')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $stmt->execute(['u' => $userId, 'today' => $today]);
        $r = $stmt->fetch() ?: [];
        return ['today' => (int) ($r['today'] ?? 0), 'last30' => (int) ($r['last30'] ?? 0)];
    }

    public static function label(string $action): string
    {
        return match ($action) {
            'appeal_upheld' => 'Appeal: rejection upheld',
            'appeal_overturned' => 'Appeal: overturned, earner paid',
            'appeal_override_upheld' => 'Admin override: rejection upheld',
            'appeal_override_overturned' => 'Admin override: overturned',
            'submission_approved' => 'Stale proof approved',
            'submission_rejected' => 'Stale proof rejected',
            default => ucfirst(str_replace('_', ' ', $action)),
        };
    }
}
