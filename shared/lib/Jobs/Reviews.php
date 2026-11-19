<?php
declare(strict_types=1);
namespace YoloTask\Jobs;

use PDO;
use RuntimeException;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Messaging\Notifications;
use YoloTask\Money\Ledger;
use YoloTask\Money\Money;
use YoloTask\Money\Wallet;
use YoloTask\Security\Audit;
use YoloTask\Tasks\Approvals;
use YoloTask\Tasks\ReviewClaims;

/**
 * Advertiser decisions on a submitted job attempt (migration 0023) and the
 * money they move.
 *
 * Approve (advertiser, auto, admin, appeal): Dr system:jobs_escrow (pay) /
 * Cr earner available (earner share) — or Cr system:reward_hold when
 * tasks.reward_hold_hours is above 0, released by the hourly cron
 * (job-release:<attempt>) — / Cr system:jobs_revenue (platform share).
 * Key job-approve:<attempt> (job-appeal-approve:<appeal> on appeal).
 * Send back: one step, with a note, at most jobs.send_back_max_rounds times;
 * the earner gets jobs.send_back_fix_hours to fix it and resubmit. No money.
 * Reject: with a reason; the slot returns to the job (refunded at once when
 * the job is closed); the earner may appeal (JobAppeals).
 * Auto-approve: submitted attempts past review_due_at (cron).
 * YOLOTASK jobs (migration 0024): no advertiser and no escrow. Admins and
 * reviewers (SystemReviews) decide them with the same rules; approve pays the
 * full pay from the platform: Dr system:platform_job_payouts / Cr earner
 * available or system:reward_hold, same key. Nothing is spent from the job.
 */
final class Reviews
{
    /**
     * @param 'advertiser'|'auto'|'admin'|'reviewer' $source
     * @param ?callable $guard runs inside the transaction with the attempt row locked (reviewer claim checks, SystemReviews)
     */
    public static function approve(PDO $pdo, int $attemptId, string $source, ?int $actorId, ?int $advertiserId = null, ?callable $guard = null): void
    {
        $a = Attempts::find($pdo, $attemptId);
        if ($a === null || ($advertiserId !== null && (int) $a['advertiser_user_id'] !== $advertiserId)) throw new RuntimeException('Job submission not found.');
        Wallet::forUser($pdo, (int) $a['earner_user_id']);
        $pdo->beginTransaction();
        try {
            Approvals::lockWallets($pdo, [(int) $a['earner_user_id'], (int) $a['advertiser_user_id']]);
            $job = Jobs::lockRow($pdo, (int) $a['job_id']);
            $row = Attempts::lockAttempt($pdo, $attemptId);
            if ((string) $row['status'] !== 'submitted') throw new RuntimeException('This job submission has already been decided or is waiting for a fix.');
            if ($source === 'auto' && strtotime((string) $row['review_due_at'] . ' UTC') > time()) throw new RuntimeException('Not due for auto-approval yet.');
            if ($guard !== null) $guard($pdo, $row);
            $pay = self::postApproval($pdo, $job, $row, 'job-approve:' . $attemptId, $actorId);
            self::markApproved($pdo, $attemptId, $source, $actorId, $pay);
            // A YOLOTASK job spends nothing from the job: the platform pays (system:platform_job_payouts).
            $pdo->prepare('UPDATE jobs SET approved_count = approved_count + 1, active_count = active_count - 1, spent_kobo = spent_kobo + :p WHERE id = :id')->execute(['p' => Jobs::isSystem($job) ? 0 : (int) $row['pay_kobo'], 'id' => $job['id']]);
            if (Jobs::isSystem($job)) ReviewClaims::clear($pdo, 'job_attempt', $attemptId);
            $job = Jobs::completeIfFull($pdo, (int) $job['id']);
            Jobs::settle($pdo, $job, 'job settled after an approval', $actorId);
            Audit::record($pdo, $actorId, match ($source) { 'auto' => 'job.auto_approved', 'admin' => 'admin.job_approved', 'reviewer' => 'reviewer.job_approved', default => 'job.approved' }, 'job_attempt', $attemptId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        self::notifyApproved($pdo, $a, $pay, $source === 'auto');
    }

    /**
     * The approve money path (inside the caller's transaction, wallets, job
     * and attempt locked).
     * @return array{tx_id:int,held:bool,release_at:?string}
     */
    public static function postApproval(PDO $pdo, array $job, array $row, string $key, ?int $actorId): array
    {
        $holdHours = max(0, Settings::int($pdo, 'tasks.reward_hold_hours'));
        $pay = (int) $row['pay_kobo'];
        $earnerShare = (int) $row['earner_kobo'];
        $fee = (int) $row['fee_kobo'];
        if ($earnerShare + $fee !== $pay) throw new RuntimeException('Job pay split does not add up.');
        $earner = Wallet::find($pdo, (int) $row['earner_user_id']);
        if ($earner === null) throw new RuntimeException('Earner wallet missing.');
        $to = $holdHours > 0 ? Ledger::systemAccountId($pdo, Ledger::REWARD_HOLD) : (int) $earner['available_account_id'];
        if (Jobs::isSystem($job)) {
            // YOLOTASK job (migration 0024): no escrow; the platform pays the full pay (Dr system:platform_job_payouts).
            if ($fee !== 0 || $earnerShare !== $pay) throw new RuntimeException('YOLOTASK job pay must go to the earner in full.');
            $tx = Ledger::post($pdo, $key, 'job_reward', 'YOLOTASK job reward: ' . mb_substr((string) $job['title'], 0, 110), [
                [Ledger::systemAccountId($pdo, Ledger::PLATFORM_JOB_PAYOUTS), 'debit', $pay], [$to, 'credit', $pay],
            ], 'job_attempt', (int) $row['id'], $actorId);
            $held = $holdHours > 0;
            return ['tx_id' => $tx['id'], 'held' => $held, 'release_at' => $held ? gmdate('Y-m-d H:i:s', time() + $holdHours * 3600) : null];
        }
        $legs = [[Ledger::systemAccountId($pdo, Ledger::JOBS_ESCROW), 'debit', $pay]];
        if ($earnerShare > 0) $legs[] = [$to, 'credit', $earnerShare];
        if ($fee > 0) $legs[] = [Ledger::systemAccountId($pdo, Ledger::JOBS_REVENUE), 'credit', $fee];
        $tx = Ledger::post($pdo, $key, 'job_reward', 'Job reward: ' . mb_substr((string) $job['title'], 0, 120), $legs, 'job_attempt', (int) $row['id'], $actorId);
        $held = $holdHours > 0 && $earnerShare > 0;
        return ['tx_id' => $tx['id'], 'held' => $held, 'release_at' => $held ? gmdate('Y-m-d H:i:s', time() + $holdHours * 3600) : null];
    }

    public static function markApproved(PDO $pdo, int $attemptId, string $source, ?int $actorId, array $pay): void
    {
        $pdo->prepare("UPDATE job_attempts SET status = 'approved', decision_source = :src, decided_by_user_id = :a, decided_at = UTC_TIMESTAMP(), reject_reason = NULL, review_due_at = NULL,
            reward_status = :rs, reward_release_at = :rra, reward_released_at = :rrd, approve_transaction_id = :t WHERE id = :id")
            ->execute(['src' => $source, 'a' => $actorId, 'rs' => $pay['held'] ? 'held' : 'released', 'rra' => $pay['release_at'],
                'rrd' => $pay['held'] ? null : gmdate('Y-m-d H:i:s'), 't' => $pay['tx_id'], 'id' => $attemptId]);
    }

    /**
     * Send one step back for a fix (advertiser, or admin / reviewer when $advertiserId is null).
     * @param 'admin'|'reviewer' $staffRole who decides when $advertiserId is null
     */
    public static function sendBack(PDO $pdo, int $attemptId, int $actorId, int $stepId, string $note, ?int $advertiserId = null, ?callable $guard = null, string $staffRole = 'admin'): void
    {
        $note = trim(str_replace(["\r\n", "\r"], "\n", $note));
        if (mb_strlen($note) < 3 || mb_strlen($note) > 500) throw new RuntimeException('Tell the earner what to fix (3 to 500 characters).');
        $a = Attempts::find($pdo, $attemptId);
        if ($a === null || ($advertiserId !== null && (int) $a['advertiser_user_id'] !== $advertiserId)) throw new RuntimeException('Job submission not found.');
        $max = Jobs::sendBackMaxRounds($pdo);
        $hours = Jobs::fixHours($pdo);
        $pdo->beginTransaction();
        try {
            Jobs::lockRow($pdo, (int) $a['job_id']);
            $row = Attempts::lockAttempt($pdo, $attemptId);
            if ((string) $row['status'] !== 'submitted') throw new RuntimeException('Only a submission waiting for review can be sent back.');
            if ($guard !== null) $guard($pdo, $row);
            if ((int) $row['send_back_count'] >= $max) throw new RuntimeException($max === 0 ? 'Sending steps back is switched off. Approve or reject this submission.' : "This submission was already sent back {$max} time" . ($max === 1 ? '' : 's') . '. Approve or reject it now.');
            $st = $pdo->prepare('SELECT id, position FROM job_steps WHERE id = :s AND job_id = :j');
            $st->execute(['s' => $stepId, 'j' => $row['job_id']]);
            $step = $st->fetch();
            if (!is_array($step)) throw new RuntimeException('Choose the step to send back.');
            $round = (int) $row['send_back_count'] + 1;
            $pdo->prepare("UPDATE job_attempts SET status = 'sent_back', send_back_count = :r, sent_back_step_id = :s, send_back_note = :n, review_due_at = NULL,
                fix_deadline_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :h HOUR) WHERE id = :id")
                ->execute(['r' => $round, 's' => $stepId, 'n' => $note, 'h' => $hours, 'id' => $attemptId]);
            $pdo->prepare('INSERT INTO job_send_backs (attempt_id, step_id, round, note, sent_by_user_id, fix_deadline_at) VALUES (:a, :s, :r, :n, :u, DATE_ADD(UTC_TIMESTAMP(), INTERVAL :h HOUR))')
                ->execute(['a' => $attemptId, 's' => $stepId, 'r' => $round, 'n' => $note, 'u' => $actorId, 'h' => $hours]);
            ReviewClaims::clear($pdo, 'job_attempt', $attemptId);
            Audit::record($pdo, $actorId, $advertiserId === null ? ($staffRole === 'reviewer' ? 'reviewer.job_sent_back' : 'admin.job_sent_back') : 'job.sent_back', 'job_attempt', $attemptId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $title = mb_substr((string) $a['job_title'], 0, 90);
        $by = Jobs::isSystem($a) ? 'YOLOTASK' : 'The advertiser';
        Notifications::notify($pdo, (int) $a['earner_user_id'], 'jobs', 'Step ' . (int) $step['position'] . ' sent back: ' . $title, 'Fix it within ' . Jobs::hoursLabel($hours) . ' and resubmit. Note: ' . $note,
            'job.php?id=' . (int) $a['job_id'], 'job-sendback:' . $attemptId . ':' . $round, [
                'subject' => 'Please fix a step of your job: ' . $title, 'heading' => 'Step sent back for a fix',
                'lines' => [$by . ' reviewed your work on "' . $a['job_title'] . '" and sent step ' . (int) $step['position'] . ' back.', 'Their note: ' . $note,
                    'Fix that step and resubmit within ' . Jobs::hoursLabel($hours) . '. If you do not, the job ends without pay.'],
                'button' => ['label' => 'Fix the step', 'url' => yolotask_portal_url('earner') . '/job.php?id=' . (int) $a['job_id']],
            ]);
    }

    /** @param 'advertiser'|'admin'|'reviewer' $source */
    public static function reject(PDO $pdo, int $attemptId, string $source, int $actorId, string $reason, ?int $advertiserId = null, ?callable $guard = null): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) throw new RuntimeException('Tell the earner why (3 to 255 characters).');
        $a = Attempts::find($pdo, $attemptId);
        if ($a === null || ($advertiserId !== null && (int) $a['advertiser_user_id'] !== $advertiserId)) throw new RuntimeException('Job submission not found.');
        $pdo->beginTransaction();
        try {
            Wallet::lock($pdo, (int) $a['advertiser_user_id']);
            $job = Jobs::lockRow($pdo, (int) $a['job_id']);
            $row = Attempts::lockAttempt($pdo, $attemptId);
            if ((string) $row['status'] !== 'submitted') throw new RuntimeException('Only a submission waiting for review can be rejected.');
            if ($guard !== null) $guard($pdo, $row);
            $pdo->prepare("UPDATE job_attempts SET status = 'rejected', decision_source = :src, decided_by_user_id = :a, decided_at = UTC_TIMESTAMP(), reject_reason = :r, review_due_at = NULL WHERE id = :id")
                ->execute(['src' => $source, 'a' => $actorId, 'r' => $reason, 'id' => $attemptId]);
            $pdo->prepare('UPDATE jobs SET active_count = active_count - 1, rejected_count = rejected_count + 1 WHERE id = :id')->execute(['id' => $job['id']]);
            Jobs::settle($pdo, Jobs::lockRow($pdo, (int) $job['id']), 'unused slot after a rejection', $actorId);
            ReviewClaims::clear($pdo, 'job_attempt', $attemptId);
            Audit::record($pdo, $actorId, match ($source) { 'admin' => 'admin.job_rejected', 'reviewer' => 'reviewer.job_rejected', default => 'job.rejected' }, 'job_attempt', $attemptId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $title = mb_substr((string) $a['job_title'], 0, 90);
        $hours = \YoloTask\Tasks\Appeals::windowHours($pdo);
        Notifications::notify($pdo, (int) $a['earner_user_id'], 'jobs', 'Job not accepted: ' . $title, 'Reason: ' . $reason . ' You can appeal within ' . $hours . ' hours.',
            'job.php?id=' . (int) $a['job_id'], 'job-reject:' . $attemptId, [
                'subject' => 'Your job was not accepted', 'heading' => 'Job not accepted',
                'lines' => ['Your work on "' . $a['job_title'] . '" was not accepted.', 'Reason: ' . $reason,
                    'If you followed the instructions and believe this is wrong, you can appeal to a YOLOTASK reviewer within ' . $hours . ' hours from the job page.'],
                'button' => ['label' => 'Open the job', 'url' => yolotask_portal_url('earner') . '/job.php?id=' . (int) $a['job_id']],
            ]);
    }

    /** Cron: approve submitted attempts whose advertiser did not decide within the review window. */
    public static function autoApproveDue(PDO $pdo, int $limit = 500): int
    {
        $stmt = $pdo->query("SELECT id FROM job_attempts WHERE status = 'submitted' AND review_due_at <= UTC_TIMESTAMP() ORDER BY review_due_at LIMIT " . max(1, $limit));
        $n = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            try {
                self::approve($pdo, (int) $id, 'auto', null);
                $n++;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                Logger::error('jobs.auto_approve_skipped', ['attempt_id' => (int) $id, 'message' => $e->getMessage()]);
            }
        }
        return $n;
    }

    /** Cron: move held job rewards to earners once the hold passes (job-release:<attempt>). */
    public static function releaseDue(PDO $pdo, int $limit = 500): int
    {
        $stmt = $pdo->query("SELECT id, earner_user_id FROM job_attempts WHERE reward_status = 'held' AND reward_release_at <= UTC_TIMESTAMP() ORDER BY reward_release_at LIMIT " . max(1, $limit));
        $n = 0;
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $pdo->beginTransaction();
            try {
                $wallet = Wallet::lock($pdo, (int) $r['earner_user_id']);
                $row = Attempts::lockAttempt($pdo, (int) $r['id']);
                if ((string) $row['reward_status'] !== 'held') { $pdo->commit(); continue; }
                $amount = (int) $row['earner_kobo'];
                $tx = Ledger::post($pdo, 'job-release:' . $r['id'], 'job_reward_release', 'Job reward released (attempt #' . $r['id'] . ')', [
                    [Ledger::systemAccountId($pdo, Ledger::REWARD_HOLD), 'debit', $amount],
                    [$wallet['available_account_id'], 'credit', $amount],
                ], 'job_attempt', (int) $r['id']);
                $pdo->prepare("UPDATE job_attempts SET reward_status = 'released', reward_released_at = UTC_TIMESTAMP(), release_transaction_id = :t WHERE id = :id")->execute(['t' => $tx['id'], 'id' => $r['id']]);
                $pdo->commit();
                $n++;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                Logger::error('jobs.reward_release_failed', ['attempt_id' => (int) $r['id'], 'exception' => get_class($e)]);
            }
        }
        return $n;
    }

    public static function notifyApproved(PDO $pdo, array $a, array $pay, bool $auto, bool $onAppeal = false): void
    {
        $amount = Money::format((int) $a['earner_kobo']);
        $title = mb_substr((string) $a['job_title'], 0, 90);
        $head = $onAppeal ? 'Job approved on appeal: ' : ($auto ? 'Job auto-approved: ' : 'Job approved: ');
        $body = ($auto ? (Jobs::isSystem($a) ? 'Your job was approved automatically. ' : 'The advertiser did not review in time, so your job was approved automatically. ') : '')
            . (!empty($pay['held']) ? "Your {$amount} reward is on a short hold and then reaches your wallet." : "{$amount} was added to your wallet.");
        Notifications::notify($pdo, (int) $a['earner_user_id'], $onAppeal ? 'appeals' : 'jobs', $head . $title, $body, 'job.php?id=' . (int) $a['job_id'], 'job-approve:' . (int) $a['id']);
    }
}
