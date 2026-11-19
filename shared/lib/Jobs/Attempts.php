<?php
declare(strict_types=1);
namespace YoloTask\Jobs;

use PDO;
use RuntimeException;
use YoloTask\Account\Activation;
use YoloTask\Core\Logger;
use YoloTask\Messaging\Notifications;
use YoloTask\Money\Money;
use YoloTask\Money\Wallet;
use YoloTask\Security\Audit;
use YoloTask\Security\Throttle;
use YoloTask\Support\Pagination;

/**
 * An earner's attempt at a job (migration 0023).
 *
 *   start -> in_progress (slot taken, timer = job time limit)
 *   save step N (needs steps 1..N-1 done; earlier steps stay editable)
 *   submit (every step done, before the deadline) -> submitted (review window starts)
 *   advertiser: approve -> approved | send a step back -> sent_back (fix deadline) | reject -> rejected
 *   sent_back: fix that step, resubmit -> submitted
 *   deadline / fix deadline missed (cron) -> expired;  earner gives up -> abandoned
 *   admin removes the job while in progress -> cancelled
 * Every way out except approved gives the slot back (refunded at once if the job is closed).
 */
final class Attempts
{
    public const PAGE_SIZE = 20;
    public const ACTIVE = ['in_progress', 'submitted', 'sent_back'];

    /** Start a job: take a slot and start the timer. Returns the attempt id. */
    public static function start(PDO $pdo, array $user, int $jobId): int
    {
        if ((string) $user['role'] !== 'earner') throw new RuntimeException('Only earner accounts can do jobs.');
        $uid = (int) $user['id'];
        if (!Jobs::enabled($pdo)) throw new RuntimeException('Jobs are switched off right now.');
        if (empty($user['email_verified_at'])) throw new RuntimeException('Confirm your email before starting a job.');
        if (!Activation::canWork($pdo, $uid)) throw new RuntimeException('Activate your account with ' . Money::format(Activation::fee($pdo)) . ' to access jobs.');
        if (Throttle::tooMany($pdo, 'job_start', (string) $uid, 20, 3600)) throw new RuntimeException('You are starting jobs too quickly. Wait a while.');
        $max = Jobs::maxActivePerEarner($pdo);
        $pdo->beginTransaction();
        try {
            $job = Jobs::lockRow($pdo, $jobId);
            if ((string) $job['status'] !== 'live') throw new RuntimeException('This job is not open right now.');
            if ((int) $job['advertiser_user_id'] === $uid) throw new RuntimeException('This job is not open right now.');
            $dup = $pdo->prepare('SELECT status FROM job_attempts WHERE job_id = :j AND earner_user_id = :u');
            $dup->execute(['j' => $jobId, 'u' => $uid]);
            if ($dup->fetchColumn() !== false) throw new RuntimeException('You have already taken this job. Each job can be done once per earner.');
            $act = $pdo->prepare("SELECT COUNT(*) FROM job_attempts WHERE earner_user_id = :u AND status IN ('in_progress', 'sent_back')");
            $act->execute(['u' => $uid]);
            if ((int) $act->fetchColumn() >= $max) throw new RuntimeException($max === 1 ? 'You already have a job in progress. Finish or submit it first.' : "You can have {$max} jobs in progress at a time. Finish or submit one first.");
            if (Jobs::freeSlots($job) <= 0) throw new RuntimeException('All slots for this job are taken right now. Check back later.');
            $pdo->prepare("INSERT INTO job_attempts (job_id, earner_user_id, advertiser_user_id, status, pay_kobo, earner_kobo, fee_kobo, started_at, deadline_at)
                VALUES (:j, :u, :a, 'in_progress', :p, :e, :f, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL :h HOUR))")
                ->execute(['j' => $jobId, 'u' => $uid, 'a' => $job['advertiser_user_id'], 'p' => $job['pay_kobo'], 'e' => $job['earner_kobo'], 'f' => $job['fee_kobo'], 'h' => (int) $job['time_limit_hours']]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE jobs SET active_count = active_count + 1 WHERE id = :id')->execute(['id' => $jobId]);
            Audit::record($pdo, $uid, 'job.started', 'job_attempt', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof \PDOException && $e->getCode() === '23000') throw new RuntimeException('You have already taken this job. Each job can be done once per earner.');
            throw $e;
        }
        Throttle::hit($pdo, 'job_start', (string) $uid);
        return $id;
    }

    /**
     * Save (or replace) the proof of one step. $in: text, link; $files: raw
     * $_FILES entries 'image' and 'file'. Every proof kind the step requires
     * must be present after the save (a new value, or the one already saved).
     */
    public static function saveStep(PDO $pdo, array $user, int $attemptId, int $stepId, array $in, array $files): void
    {
        $uid = (int) $user['id'];
        $a = self::findForEarner($pdo, $attemptId, $uid);
        if ($a === null) throw new RuntimeException('Job not found.');
        $steps = Jobs::steps($pdo, (int) $a['job_id']);
        $step = null;
        foreach ($steps as $s) if ((int) $s['id'] === $stepId) $step = $s;
        if ($step === null) throw new RuntimeException('Step not found.');
        self::assertEditable($pdo, $a, $step, $steps);
        $kinds = Jobs::kinds((string) $step['proof_kinds']);
        $current = self::stepProofs($pdo, $attemptId)[$stepId] ?? null;

        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['text'] ?? '')));
        $link = trim((string) ($in['link'] ?? ''));
        $set = [];
        if (in_array('text', $kinds, true)) {
            if ($text !== '') {
                if (mb_strlen($text) > 5000) throw new RuntimeException('Keep the answer under 5,000 characters.');
                $set['text_answer'] = $text;
            } elseif ($current === null || (string) $current['text_answer'] === '') {
                throw new RuntimeException('Write your answer for this step.');
            }
        }
        if (in_array('link', $kinds, true)) {
            if ($link !== '') {
                $p = parse_url($link);
                if (strlen($link) > 500 || !is_array($p) || empty($p['host']) || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true) || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $link)) {
                    throw new RuntimeException('Enter the link as a full http(s):// address.');
                }
                $set['link_url'] = $link;
            } elseif ($current === null || (string) $current['link_url'] === '') {
                throw new RuntimeException('Paste the link for this step.');
            }
        }
        $image = null;
        $doc = null;
        if (in_array('image', $kinds, true)) {
            if (JobProofs::hasUpload($files['image'] ?? null)) $image = JobProofs::image($pdo, $files['image'], $attemptId);
            elseif ($current === null || $current['image_file_id'] === null) throw new RuntimeException('Attach a screenshot or image for this step.');
        }
        if (in_array('file', $kinds, true)) {
            if (JobProofs::hasUpload($files['file'] ?? null)) $doc = JobProofs::file($files['file']);
            elseif ($current === null || $current['file_file_id'] === null) throw new RuntimeException('Attach the file for this step.');
        }
        if ($set === [] && $image === null && $doc === null) throw new RuntimeException('Nothing to save: add or change your proof first.');

        $pdo->beginTransaction();
        try {
            $row = self::lockAttempt($pdo, $attemptId);
            self::assertEditable($pdo, $row, $step, $steps);
            if ($image !== null) $set['image_file_id'] = JobProofs::store($pdo, $attemptId, $stepId, 'image', $image);
            if ($doc !== null) $set['file_file_id'] = JobProofs::store($pdo, $attemptId, $stepId, 'file', $doc);
            $cols = ['attempt_id' => $attemptId, 'step_id' => $stepId, 'position' => (int) $step['position']] + $set;
            $names = implode(', ', array_keys($cols));
            $vals = implode(', ', array_map(static fn (string $k): string => ':' . $k, array_keys($cols)));
            $upd = implode(', ', array_map(static fn (string $k): string => "{$k} = VALUES({$k})", array_keys($set)));
            $pdo->prepare("INSERT INTO job_step_proofs ({$names}) VALUES ({$vals}) ON DUPLICATE KEY UPDATE {$upd}, edits = edits + 1, updated_at = UTC_TIMESTAMP()")->execute($cols);
            if ((string) $row['status'] === 'sent_back') {
                $pdo->prepare('UPDATE job_send_backs SET fixed_at = UTC_TIMESTAMP() WHERE attempt_id = :a AND round = :r')->execute(['a' => $attemptId, 'r' => (int) $row['send_back_count']]);
            }
            $pdo->commit();
            JobProofs::forgetWritten();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            JobProofs::discardWritten();
            throw $e;
        }
    }

    /** Refuse unless this step may be edited now. */
    private static function assertEditable(PDO $pdo, array $a, array $step, array $steps): void
    {
        $status = (string) $a['status'];
        if ($status === 'in_progress') {
            if (strtotime((string) $a['deadline_at'] . ' UTC') <= time()) throw new RuntimeException('Your time for this job ran out.');
            $done = self::doneCount($steps, self::stepProofs($pdo, (int) $a['id']));
            if ((int) $step['position'] > $done + 1) throw new RuntimeException('Finish step ' . ($done + 1) . ' first.');
            return;
        }
        if ($status === 'sent_back') {
            if (strtotime((string) $a['fix_deadline_at'] . ' UTC') <= time()) throw new RuntimeException('The time to fix this step ran out.');
            if ((int) $a['sent_back_step_id'] !== (int) $step['id']) throw new RuntimeException('Only the step that was sent back can be changed.');
            return;
        }
        throw new RuntimeException($status === 'submitted' ? 'This job is waiting for review, so it cannot be changed.' : 'This job can no longer be changed.');
    }

    /** Submit every step for review. */
    public static function submit(PDO $pdo, array $user, int $attemptId): void
    {
        $uid = (int) $user['id'];
        $a = self::findForEarner($pdo, $attemptId, $uid);
        if ($a === null) throw new RuntimeException('Job not found.');
        $steps = Jobs::steps($pdo, (int) $a['job_id']);
        $pdo->beginTransaction();
        try {
            $row = self::lockAttempt($pdo, $attemptId);
            if ((string) $row['status'] !== 'in_progress') throw new RuntimeException('This job has already been submitted or has ended.');
            if (strtotime((string) $row['deadline_at'] . ' UTC') <= time()) throw new RuntimeException('Your time for this job ran out.');
            $done = self::doneCount($steps, self::stepProofs($pdo, $attemptId));
            if ($done < count($steps)) throw new RuntimeException('Finish every step first (step ' . ($done + 1) . ' is not done).');
            $job = Jobs::lockRow($pdo, (int) $row['job_id']);
            $pdo->prepare("UPDATE job_attempts SET status = 'submitted', submitted_at = UTC_TIMESTAMP(), first_submitted_at = UTC_TIMESTAMP(), review_due_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :h HOUR) WHERE id = :id")
                ->execute(['h' => (int) $job['review_hours'], 'id' => $attemptId]);
            Audit::record($pdo, $uid, 'job.submitted', 'job_attempt', $attemptId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        self::tellAdvertiser($pdo, $a, false);
    }

    /** Resubmit after a send-back (the sent-back step must have been changed). */
    public static function resubmit(PDO $pdo, array $user, int $attemptId): void
    {
        $uid = (int) $user['id'];
        $a = self::findForEarner($pdo, $attemptId, $uid);
        if ($a === null) throw new RuntimeException('Job not found.');
        $pdo->beginTransaction();
        try {
            $row = self::lockAttempt($pdo, $attemptId);
            if ((string) $row['status'] !== 'sent_back') throw new RuntimeException('This job is not waiting for a fix.');
            if (strtotime((string) $row['fix_deadline_at'] . ' UTC') <= time()) throw new RuntimeException('The time to fix this step ran out.');
            $sb = $pdo->prepare('SELECT * FROM job_send_backs WHERE attempt_id = :a AND round = :r FOR UPDATE');
            $sb->execute(['a' => $attemptId, 'r' => (int) $row['send_back_count']]);
            $back = $sb->fetch();
            if (!is_array($back) || $back['fixed_at'] === null) {
                throw new RuntimeException('Update the step that was sent back before you resubmit.');
            }
            $job = Jobs::lockRow($pdo, (int) $row['job_id']);
            $pdo->prepare("UPDATE job_attempts SET status = 'submitted', submitted_at = UTC_TIMESTAMP(), review_due_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :h HOUR), fix_deadline_at = NULL WHERE id = :id")
                ->execute(['h' => (int) $job['review_hours'], 'id' => $attemptId]);
            $pdo->prepare('UPDATE job_send_backs SET resubmitted_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['id' => $back['id']]);
            Audit::record($pdo, $uid, 'job.resubmitted', 'job_attempt', $attemptId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        self::tellAdvertiser($pdo, $a, true);
    }

    /** Earner gives up (in progress or sent back): the slot goes back, nothing is paid. */
    public static function abandon(PDO $pdo, array $user, int $attemptId): void
    {
        $uid = (int) $user['id'];
        $a = self::findForEarner($pdo, $attemptId, $uid);
        if ($a === null) throw new RuntimeException('Job not found.');
        $pdo->beginTransaction();
        try {
            Wallet::lock($pdo, (int) $a['advertiser_user_id']);
            $job = Jobs::lockRow($pdo, (int) $a['job_id']);
            $row = self::lockAttempt($pdo, $attemptId);
            if (!in_array((string) $row['status'], ['in_progress', 'sent_back'], true)) throw new RuntimeException('This job can no longer be given up.');
            self::end($pdo, $job, $row, 'abandoned', 'Given up by the earner', $uid);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * End an active attempt without pay (inside the caller's transaction:
     * advertiser wallet, job and attempt locked): the slot goes back to the
     * job, or is refunded when the job is closed.
     */
    public static function end(PDO $pdo, array $job, array $row, string $status, string $reason, ?int $actorId): void
    {
        $pdo->prepare('UPDATE job_attempts SET status = :s, ended_reason = :r, review_due_at = NULL, fix_deadline_at = NULL WHERE id = :id')->execute(['s' => $status, 'r' => $reason, 'id' => $row['id']]);
        $pdo->prepare('UPDATE jobs SET active_count = active_count - 1, expired_count = expired_count + :x WHERE id = :id')->execute(['x' => $status === 'expired' ? 1 : 0, 'id' => $job['id']]);
        Jobs::settle($pdo, Jobs::lockRow($pdo, (int) $job['id']), 'unused slot after an attempt ended', $actorId);
        Audit::record($pdo, $actorId, 'job.attempt_' . $status, 'job_attempt', (int) $row['id']);
    }

    /** Cron: expire attempts past their time limit (in progress) or fix deadline (sent back). */
    public static function expireDue(PDO $pdo, int $limit = 500): int
    {
        $stmt = $pdo->query("SELECT id FROM job_attempts WHERE (status = 'in_progress' AND deadline_at <= UTC_TIMESTAMP()) OR (status = 'sent_back' AND fix_deadline_at <= UTC_TIMESTAMP()) ORDER BY id LIMIT " . max(1, $limit));
        $n = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $a = self::find($pdo, (int) $id);
            if ($a === null) continue;
            try {
                $pdo->beginTransaction();
                Wallet::lock($pdo, (int) $a['advertiser_user_id']);
                $job = Jobs::lockRow($pdo, (int) $a['job_id']);
                $row = self::lockAttempt($pdo, (int) $id);
                $due = ((string) $row['status'] === 'in_progress' && strtotime((string) $row['deadline_at'] . ' UTC') <= time())
                    || ((string) $row['status'] === 'sent_back' && strtotime((string) $row['fix_deadline_at'] . ' UTC') <= time());
                if (!$due) { $pdo->commit(); continue; }
                $wasFix = (string) $row['status'] === 'sent_back';
                self::end($pdo, $job, $row, 'expired', $wasFix ? 'Fix deadline passed' : 'Time limit passed', null);
                $pdo->commit();
                $n++;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                Logger::error('jobs.expire_failed', ['attempt_id' => (int) $id, 'exception' => get_class($e), 'message' => $e->getMessage()]);
                continue;
            }
            Notifications::notify($pdo, (int) $a['earner_user_id'], 'jobs', 'Job time ran out: ' . mb_substr((string) $a['job_title'], 0, 90),
                $wasFix ? 'The step was not fixed and resubmitted in time, so the job ended. Nothing was paid.' : 'The job was not submitted before its time limit, so it ended. Nothing was paid.',
                'job.php?id=' . (int) $a['job_id'], 'job-expired:' . (int) $id);
        }
        return $n;
    }

    // ---------------------------------------------------------------- reads

    private const SELECT = 'SELECT a.*, j.title job_title, j.status job_status, j.is_system, j.step_count, j.review_hours, j.mode, j.location, j.link_url, j.instructions job_instructions,
        eu.display_name earner_name, eu.email earner_email, au.display_name advertiser_name, au.email advertiser_email,
        st.position sent_back_position, st.title sent_back_title,
        (SELECT ap.id FROM job_appeals ap WHERE ap.attempt_id = a.id) appeal_id
        FROM job_attempts a JOIN jobs j ON j.id = a.job_id JOIN users eu ON eu.id = a.earner_user_id JOIN users au ON au.id = a.advertiser_user_id
        LEFT JOIN job_steps st ON st.id = a.sent_back_step_id';

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(self::SELECT . ' WHERE a.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function findForEarner(PDO $pdo, int $id, int $earnerId): ?array
    {
        $a = self::find($pdo, $id);
        return $a !== null && (int) $a['earner_user_id'] === $earnerId ? $a : null;
    }

    public static function forJobEarner(PDO $pdo, int $jobId, int $earnerId): ?array
    {
        $stmt = $pdo->prepare('SELECT id FROM job_attempts WHERE job_id = :j AND earner_user_id = :u');
        $stmt->execute(['j' => $jobId, 'u' => $earnerId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : self::find($pdo, (int) $id);
    }

    public static function lockAttempt(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM job_attempts WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new RuntimeException('Job attempt not found.');
        return $row;
    }

    /** @return array<int,array> step_id => proof row (with file names) */
    public static function stepProofs(PDO $pdo, int $attemptId): array
    {
        $stmt = $pdo->prepare('SELECT p.*, fi.mime image_mime, ff.original_name file_name, ff.bytes file_bytes FROM job_step_proofs p
            LEFT JOIN job_files fi ON fi.id = p.image_file_id LEFT JOIN job_files ff ON ff.id = p.file_file_id WHERE p.attempt_id = :a');
        $stmt->execute(['a' => $attemptId]);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $r) $out[(int) $r['step_id']] = $r;
        return $out;
    }

    /** Is every proof kind of the step present? */
    public static function stepDone(array $step, ?array $proof): bool
    {
        if ($proof === null) return false;
        foreach (Jobs::kinds((string) $step['proof_kinds']) as $k) {
            $ok = match ($k) {
                'text' => trim((string) $proof['text_answer']) !== '',
                'link' => trim((string) $proof['link_url']) !== '',
                'image' => $proof['image_file_id'] !== null,
                'file' => $proof['file_file_id'] !== null,
                default => true,
            };
            if (!$ok) return false;
        }
        return true;
    }

    /** How many steps are done in order from step 1. */
    public static function doneCount(array $steps, array $proofs): int
    {
        $n = 0;
        foreach ($steps as $s) {
            if (!self::stepDone($s, $proofs[(int) $s['id']] ?? null)) break;
            $n++;
        }
        return $n;
    }

    public static function sendBacks(PDO $pdo, int $attemptId): array
    {
        $stmt = $pdo->prepare('SELECT b.*, s.position, s.title step_title FROM job_send_backs b JOIN job_steps s ON s.id = b.step_id WHERE b.attempt_id = :a ORDER BY b.round');
        $stmt->execute(['a' => $attemptId]);
        return $stmt->fetchAll() ?: [];
    }

    public static function listForEarner(PDO $pdo, int $uid, int $page): array
    {
        return Pagination::query($pdo, 'SELECT COUNT(*) FROM job_attempts WHERE earner_user_id = :u', self::SELECT . ' WHERE a.earner_user_id = :u ORDER BY a.id DESC', ['u' => $uid], $page, self::PAGE_SIZE);
    }

    public static function activeForEarner(PDO $pdo, int $uid): array
    {
        $stmt = $pdo->prepare(self::SELECT . " WHERE a.earner_user_id = :u AND a.status IN ('in_progress', 'sent_back') ORDER BY a.id DESC");
        $stmt->execute(['u' => $uid]);
        return $stmt->fetchAll() ?: [];
    }

    /** $status: review (submitted), active, approved, rejected, ended, all */
    public static function listForJob(PDO $pdo, int $jobId, string $status, int $page): array
    {
        $where = 'WHERE a.job_id = :j';
        $map = ['review' => "('submitted')", 'active' => "('in_progress', 'sent_back')", 'approved' => "('approved')", 'rejected' => "('rejected')", 'ended' => "('expired', 'abandoned', 'cancelled')"];
        if (isset($map[$status])) $where .= ' AND a.status IN ' . $map[$status];
        $order = $status === 'review' ? 'a.review_due_at ASC, a.id ASC' : 'a.id DESC';
        return Pagination::query($pdo, "SELECT COUNT(*) FROM job_attempts a {$where}", self::SELECT . " {$where} ORDER BY {$order}", ['j' => $jobId], $page, self::PAGE_SIZE);
    }

    public static function statusLabel(array $a): array
    {
        return match ((string) $a['status']) {
            'in_progress' => ['In progress', 'badge-info'],
            'submitted' => ['Waiting for review', 'badge-warning'],
            'sent_back' => ['Sent back for a fix', 'badge-warning'],
            'approved' => [(string) ($a['reward_status'] ?? '') === 'held' ? 'Approved (reward on hold)' : 'Approved', 'badge-success'],
            'rejected' => [!empty($a['appeal_id']) ? 'Rejected · appealed' : 'Rejected', 'badge-danger'],
            'expired' => ['Time ran out', 'badge-danger'],
            'abandoned' => ['Given up', 'badge-info'],
            'cancelled' => ['Cancelled', 'badge-info'],
            default => [ucfirst((string) $a['status']), 'badge-info'],
        };
    }

    private static function tellAdvertiser(PDO $pdo, array $a, bool $again): void
    {
        if (Jobs::isSystem($a)) return; // YOLOTASK job (migration 0024): no advertiser; it waits in the admin and reviewer queues
        try {
            $job = Jobs::find($pdo, (int) $a['job_id']);
            $title = mb_substr((string) $a['job_title'], 0, 90);
            $due = Jobs::hoursLabel((int) ($job['review_hours'] ?? 48));
            Notifications::notify($pdo, (int) $a['advertiser_user_id'], 'jobs', ($again ? 'Fixed job resubmitted: ' : 'New job submission: ') . $title,
                ($again ? 'An earner fixed the step you sent back.' : 'An earner finished every step.') . " Review it within {$due} or it is approved automatically.",
                'job-review.php?id=' . (int) $a['id'], 'job-submit:' . (int) $a['id'] . ':' . (int) $a['send_back_count'], [
                    'subject' => ($again ? 'A fixed job was resubmitted: ' : 'New job submission: ') . $title,
                    'heading' => $again ? 'Job resubmitted' : 'New job submission',
                    'lines' => [($again ? 'An earner fixed the step you sent back on "' : 'An earner finished every step of "') . $a['job_title'] . '".',
                        "Please review their proof within {$due}. If you take no action, it is approved and the earner is paid automatically."],
                    'button' => ['label' => 'Review the proof', 'url' => yolotask_portal_url('advertiser') . '/job-review.php?id=' . (int) $a['id']],
                ]);
        } catch (\Throwable $e) {
            Logger::error('jobs.notify_advertiser_failed', ['exception' => get_class($e)]);
        }
    }
}
