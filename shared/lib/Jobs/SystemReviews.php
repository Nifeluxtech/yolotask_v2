<?php
declare(strict_types=1);
namespace YoloTask\Jobs;

use PDO;
use RuntimeException;
use YoloTask\Support\Pagination;
use YoloTask\Tasks\ReviewClaims;
use YoloTask\Tasks\ReviewerActions;

/**
 * Submissions on YOLOTASK jobs (migration 0024) waiting for a decision.
 *
 * A YOLOTASK job has no advertiser (its owner is the closed system account),
 * so, like proofs on YOLOTASK tasks in the stale queue, its submissions go
 * straight to staff: reviewers work them in the reviewer portal's "YOLOTASK
 * jobs" queue (claim with ReviewClaims item type job_attempt, then approve,
 * send one step back or reject through Reviews with the same rules as an
 * advertiser, logged in reviewer_actions), and admins decide them in
 * Admin -> Jobs without a claim. Anything left undecided is auto-approved when
 * the job's review window ends (cron), as for every job.
 */
final class SystemReviews
{
    public const PAGE_SIZE = 20;

    private const WHERE = "WHERE a.status = 'submitted' AND j.is_system = 1";

    public static function count(PDO $pdo): int
    {
        try {
            return (int) $pdo->query('SELECT COUNT(*) FROM job_attempts a JOIN jobs j ON j.id = a.job_id ' . self::WHERE)->fetchColumn();
        } catch (\PDOException) {
            return 0; // before migration 0024
        }
    }

    /** Waiting submissions on YOLOTASK jobs, oldest first, with any active reviewer claim. */
    public static function queue(PDO $pdo, int $page): array
    {
        $sql = "SELECT a.id, a.job_id, a.submitted_at, a.review_due_at, a.send_back_count, a.pay_kobo, a.earner_kobo, j.title job_title, j.mode, j.location, j.step_count,
                eu.display_name earner_name, rc.reviewer_user_id claim_user_id, rc.expires_at claim_expires_at, cu.display_name claim_reviewer_name
            FROM job_attempts a JOIN jobs j ON j.id = a.job_id JOIN users eu ON eu.id = a.earner_user_id
            LEFT JOIN review_claims rc ON rc.item_type = 'job_attempt' AND rc.item_id = a.id AND rc.expires_at > UTC_TIMESTAMP() LEFT JOIN users cu ON cu.id = rc.reviewer_user_id
            " . self::WHERE . ' ORDER BY a.review_due_at ASC, a.id ASC';
        return Pagination::query($pdo, 'SELECT COUNT(*) FROM job_attempts a JOIN jobs j ON j.id = a.job_id ' . self::WHERE, $sql, [], $page, self::PAGE_SIZE);
    }

    /** One attempt on a YOLOTASK job (any status), with the active claim; null for advertisers' jobs. */
    public static function find(PDO $pdo, int $attemptId): ?array
    {
        $a = Attempts::find($pdo, $attemptId);
        if ($a === null || !Jobs::isSystem($a)) return null;
        $claim = ReviewClaims::current($pdo, 'job_attempt', $attemptId);
        $active = $claim !== null && (int) $claim['active'] === 1;
        $a['claim_user_id'] = $active ? (int) $claim['reviewer_user_id'] : null;
        $a['claim_expires_at'] = $active ? (string) $claim['expires_at'] : null;
        $a['claim_reviewer_name'] = $active ? (string) $claim['reviewer_name'] : null;
        return $a;
    }

    public static function claim(PDO $pdo, array $reviewer, int $attemptId): string
    {
        $rid = self::requireReviewer($reviewer);
        self::systemAttempt($pdo, $attemptId);
        $pdo->beginTransaction();
        try {
            $row = Attempts::lockAttempt($pdo, $attemptId);
            if ((string) $row['status'] !== 'submitted') throw new RuntimeException('This job submission has already been decided or is waiting for a fix.');
            $exp = ReviewClaims::claimLocked($pdo, 'job_attempt', $attemptId, $rid);
            $pdo->commit();
            return $exp;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function release(PDO $pdo, array $reviewer, int $attemptId): void
    {
        $rid = self::requireReviewer($reviewer);
        $pdo->beginTransaction();
        try {
            Attempts::lockAttempt($pdo, $attemptId);
            ReviewClaims::release($pdo, 'job_attempt', $attemptId, $rid);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * A reviewer decides a YOLOTASK job submission they have claimed.
     * @param 'approve'|'send_back'|'reject' $decision
     * $in: step_id + note (send back), reason (reject).
     */
    public static function decide(PDO $pdo, array $reviewer, int $attemptId, string $decision, array $in = []): void
    {
        $rid = self::requireReviewer($reviewer);
        self::systemAttempt($pdo, $attemptId);
        $action = match ($decision) { 'approve' => 'job_approved', 'send_back' => 'job_sent_back', 'reject' => 'job_rejected', default => throw new RuntimeException('Choose approve, send back or reject.') };
        $note = match ($decision) { 'send_back' => trim((string) ($in['note'] ?? '')), 'reject' => trim((string) ($in['reason'] ?? '')), default => null };
        $guard = static function (PDO $pdo, array $row) use ($rid, $attemptId, $action, $note): void {
            ReviewClaims::assertHeld($pdo, 'job_attempt', $attemptId, $rid);
            ReviewClaims::clear($pdo, 'job_attempt', $attemptId);
            ReviewerActions::recordJobAttempt($pdo, $rid, 'reviewer', $action, $attemptId, $note);
        };
        match ($decision) {
            'approve' => Reviews::approve($pdo, $attemptId, 'reviewer', $rid, null, $guard),
            'send_back' => Reviews::sendBack($pdo, $attemptId, $rid, (int) ($in['step_id'] ?? 0), (string) $note, null, $guard, 'reviewer'),
            'reject' => Reviews::reject($pdo, $attemptId, 'reviewer', $rid, (string) $note, null, $guard),
        };
    }

    private static function systemAttempt(PDO $pdo, int $attemptId): array
    {
        $a = Attempts::find($pdo, $attemptId);
        if ($a === null || !Jobs::isSystem($a)) throw new RuntimeException('Job submission not found.');
        return $a;
    }

    private static function requireReviewer(array $user): int
    {
        if ((string) $user['role'] !== 'reviewer') throw new RuntimeException('Only reviewers work the YOLOTASK jobs queue.');
        return (int) $user['id'];
    }
}
