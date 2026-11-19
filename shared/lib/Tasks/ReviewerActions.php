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

    /** A decision on a job appeal (migration 0023): no submission, the job attempt instead. Inside the caller's transaction. */
    public static function recordJob(PDO $pdo, int $userId, string $role, string $action, int $appealId, int $attemptId, ?string $note = null): void
    {
        $pdo->prepare('INSERT INTO reviewer_actions (reviewer_user_id, reviewer_role, action, item_type, item_id, submission_id, job_attempt_id, note, decided_at) VALUES (:u, :r, :a, \'job_appeal\', :i, NULL, :j, :n, UTC_TIMESTAMP())')
            ->execute(['u' => $userId, 'r' => $role === 'admin' ? 'admin' : 'reviewer', 'a' => $action, 'i' => $appealId, 'j' => $attemptId, 'n' => $note === null ? null : mb_substr($note, 0, 500)]);
    }

    /** A decision on a YOLOTASK job submission (migration 0024): item = the job attempt. Inside the caller's transaction. */
    public static function recordJobAttempt(PDO $pdo, int $userId, string $role, string $action, int $attemptId, ?string $note = null): void
    {
        $pdo->prepare('INSERT INTO reviewer_actions (reviewer_user_id, reviewer_role, action, item_type, item_id, submission_id, job_attempt_id, note, decided_at) VALUES (:u, :r, :a, \'job_attempt\', :i, NULL, :j, :n, UTC_TIMESTAMP())')
            ->execute(['u' => $userId, 'r' => $role === 'admin' ? 'admin' : 'reviewer', 'a' => $action, 'i' => $attemptId, 'j' => $attemptId, 'n' => $note === null || $note === '' ? null : mb_substr($note, 0, 500)]);
    }

    public static function forReviewer(PDO $pdo, int $userId, int $page): array
    {
        // Job appeal decisions (migration 0023) have no submission; their title comes from the job.
        $hasJobs = (bool) $pdo->query("SHOW COLUMNS FROM reviewer_actions LIKE 'job_attempt_id'")->fetchColumn();
        $sql = $hasJobs
            ? 'SELECT ra.*, COALESCE(c.title, j.title) campaign_title FROM reviewer_actions ra LEFT JOIN submissions s ON s.id = ra.submission_id LEFT JOIN campaigns c ON c.id = s.campaign_id
               LEFT JOIN job_attempts ja ON ja.id = ra.job_attempt_id LEFT JOIN jobs j ON j.id = ja.job_id WHERE ra.reviewer_user_id = :u ORDER BY ra.id DESC'
            : 'SELECT ra.*, c.title campaign_title FROM reviewer_actions ra JOIN submissions s ON s.id = ra.submission_id JOIN campaigns c ON c.id = s.campaign_id WHERE ra.reviewer_user_id = :u ORDER BY ra.id DESC';
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
            'job_appeal_upheld' => 'Job appeal: rejection upheld',
            'job_appeal_overturned' => 'Job appeal: overturned, earner paid',
            'job_approved' => 'YOLOTASK job approved',
            'job_sent_back' => 'YOLOTASK job: step sent back',
            'job_rejected' => 'YOLOTASK job rejected',
            default => ucfirst(str_replace('_', ' ', $action)),
        };
    }
}
