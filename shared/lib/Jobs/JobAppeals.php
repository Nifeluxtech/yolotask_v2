<?php
declare(strict_types=1);
namespace YoloTask\Jobs;

use PDO;
use RuntimeException;
use YoloTask\Core\Logger;
use YoloTask\Messaging\Notifications;
use YoloTask\Money\Ledger;
use YoloTask\Money\Money;
use YoloTask\Money\Wallet;
use YoloTask\Security\Audit;
use YoloTask\Security\Throttle;
use YoloTask\Support\Pagination;
use YoloTask\Tasks\Appeals;
use YoloTask\Tasks\Approvals;
use YoloTask\Tasks\ReviewClaims;
use YoloTask\Tasks\ReviewerActions;

/**
 * Earner appeals against a rejected job attempt (migration 0023), worked in
 * the reviewer portal's "Job appeals" queue with the same claim rules as task
 * appeals (ReviewClaims item type job_appeal) and logged in reviewer_actions.
 *
 * Filing: one appeal per rejected attempt, within appeals.window_hours of the
 * rejection, reason 10-1,000 characters. Deciding: a reviewer claims it and
 * upholds or overturns with a note; admins decide without a claim.
 *
 * Uphold: nothing moves. The slot already went back to the job on rejection
 * (or was refunded to the advertiser if the job is closed).
 * Overturn: the earner is paid as on approval (key job-appeal-approve:<id>).
 * The pay comes from a free slot of the job when it is live/paused and has
 * one (that slot is used); otherwise one slot is added and funded first
 * (key job-appeal-fund:<id>) from the advertiser's wallet, and anything the
 * wallet cannot cover is paid by YOLOTASK (system:jobs_appeal_payouts),
 * recorded on the appeal and shown to admins.
 */
final class JobAppeals
{
    public const PAGE_SIZE = 20;

    /** Why this attempt cannot be appealed, or null when it can. */
    public static function eligibility(PDO $pdo, array $a): ?string
    {
        if ((string) $a['status'] !== 'rejected') return 'Only rejected jobs can be appealed.';
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM job_appeals WHERE attempt_id = :id');
        $stmt->execute(['id' => $a['id']]);
        if ((int) $stmt->fetchColumn() > 0) return 'You have already appealed this decision.';
        if ((string) ($a['decision_source'] ?? '') === 'appeal') return 'This decision was made on appeal and is final.';
        $decided = $a['decided_at'] ? strtotime((string) $a['decided_at'] . ' UTC') : false;
        if ($decided === false || $decided + Appeals::windowHours($pdo) * 3600 < time()) return 'The ' . Appeals::windowHours($pdo) . '-hour appeal window for this rejection has closed.';
        return null;
    }

    public static function file(PDO $pdo, array $user, int $attemptId, string $reason): int
    {
        if ((string) $user['role'] !== 'earner') throw new RuntimeException('Only earner accounts can appeal.');
        $uid = (int) $user['id'];
        $reason = trim(str_replace(["\r\n", "\r"], "\n", $reason));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) throw new RuntimeException('Explain why the rejection is wrong (10 to 1,000 characters).');
        $own = Attempts::findForEarner($pdo, $attemptId, $uid);
        if ($own === null) throw new RuntimeException('Job not found.');
        if (($why = self::eligibility($pdo, $own)) !== null) throw new RuntimeException($why);
        if (Throttle::tooMany($pdo, 'appeal_file', (string) $uid, 10, 3600)) throw new RuntimeException('You are sending appeals too quickly. Wait a while and try again.');
        $pdo->beginTransaction();
        try {
            $row = Attempts::lockAttempt($pdo, $attemptId);
            if (($why = self::eligibility($pdo, $row)) !== null) throw new RuntimeException($why);
            $pdo->prepare("INSERT INTO job_appeals (attempt_id, job_id, earner_user_id, advertiser_user_id, status, message, rejection_reason, rejected_by_user_id, rejected_at) VALUES (:at, :j, :e, :a, 'open', :m, :rr, :rb, :ra)")
                ->execute(['at' => $attemptId, 'j' => $row['job_id'], 'e' => $uid, 'a' => $row['advertiser_user_id'], 'm' => $reason, 'rr' => $row['reject_reason'], 'rb' => $row['decided_by_user_id'], 'ra' => $row['decided_at']]);
            $id = (int) $pdo->lastInsertId();
            Audit::record($pdo, $uid, 'job.appealed', 'job_appeal', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof \PDOException && $e->getCode() === '23000') throw new RuntimeException('You have already appealed this decision.');
            throw $e;
        }
        Throttle::hit($pdo, 'appeal_file', (string) $uid);
        Notifications::notify($pdo, $uid, 'appeals', 'Appeal received: ' . mb_substr((string) $own['job_title'], 0, 90), 'Your appeal is in the reviewer queue. We will let you know the outcome.',
            'job.php?id=' . (int) $own['job_id'], 'job-appeal:' . $id . ':filed');
        return $id;
    }

    private const SELECT = "SELECT ap.*, a.status attempt_status, a.pay_kobo, a.earner_kobo, a.reward_status, a.send_back_count, a.submitted_at,
        j.title job_title, j.status job_status, j.mode, j.location, j.link_url, j.instructions job_instructions,
        eu.display_name earner_name, eu.email earner_email, au.display_name advertiser_name, au.email advertiser_email,
        rv.display_name resolver_name, rj.display_name rejecter_name,
        rc.reviewer_user_id claim_user_id, rc.expires_at claim_expires_at, cu.display_name claim_reviewer_name
        FROM job_appeals ap JOIN job_attempts a ON a.id = ap.attempt_id JOIN jobs j ON j.id = ap.job_id
        JOIN users eu ON eu.id = ap.earner_user_id JOIN users au ON au.id = ap.advertiser_user_id
        LEFT JOIN users rv ON rv.id = ap.resolved_by_user_id LEFT JOIN users rj ON rj.id = ap.rejected_by_user_id
        LEFT JOIN review_claims rc ON rc.item_type = 'job_appeal' AND rc.item_id = ap.id AND rc.expires_at > UTC_TIMESTAMP() LEFT JOIN users cu ON cu.id = rc.reviewer_user_id";

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(self::SELECT . ' WHERE ap.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function forAttempt(PDO $pdo, int $attemptId): ?array
    {
        $stmt = $pdo->prepare(self::SELECT . ' WHERE ap.attempt_id = :a');
        $stmt->execute(['a' => $attemptId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function queue(PDO $pdo, int $page): array
    {
        return Pagination::query($pdo, "SELECT COUNT(*) FROM job_appeals WHERE status = 'open'", self::SELECT . " WHERE ap.status = 'open' ORDER BY ap.created_at ASC, ap.id ASC", [], $page, self::PAGE_SIZE);
    }

    public static function openCount(PDO $pdo): int
    {
        try {
            return (int) $pdo->query("SELECT COUNT(*) FROM job_appeals WHERE status = 'open'")->fetchColumn();
        } catch (\PDOException) {
            return 0; // before migration 0023
        }
    }

    public static function listForAdmin(PDO $pdo, string $status, int $page): array
    {
        $where = 'WHERE 1 = 1';
        $args = [];
        if (in_array($status, ['open', 'upheld', 'overturned'], true)) { $where .= ' AND ap.status = :s'; $args['s'] = $status; }
        $order = $status === 'open' ? 'ap.created_at ASC, ap.id ASC' : 'ap.id DESC';
        return Pagination::query($pdo, "SELECT COUNT(*) FROM job_appeals ap {$where}", self::SELECT . " {$where} ORDER BY {$order}", $args, $page, self::PAGE_SIZE);
    }

    public static function claim(PDO $pdo, array $reviewer, int $appealId): string
    {
        $rid = self::requireStaff($reviewer);
        $pdo->beginTransaction();
        try {
            $ap = self::lockAppeal($pdo, $appealId);
            if ((string) $ap['status'] !== 'open') throw new RuntimeException('This appeal has already been decided.');
            $exp = ReviewClaims::claimLocked($pdo, 'job_appeal', $appealId, $rid);
            $pdo->commit();
            return $exp;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function release(PDO $pdo, array $reviewer, int $appealId): void
    {
        $rid = self::requireStaff($reviewer);
        $pdo->beginTransaction();
        try {
            self::lockAppeal($pdo, $appealId);
            ReviewClaims::release($pdo, 'job_appeal', $appealId, $rid);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** @param 'uphold'|'overturn' $decision */
    public static function decide(PDO $pdo, array $actor, int $appealId, string $decision, string $note): void
    {
        $actorId = self::requireStaff($actor);
        $isAdmin = (string) $actor['role'] === 'admin';
        $note = trim(str_replace(["\r\n", "\r"], "\n", $note));
        if (mb_strlen($note) < 5 || mb_strlen($note) > 500) throw new RuntimeException('Write a decision note of 5 to 500 characters; the earner sees it.');
        if (!in_array($decision, ['uphold', 'overturn'], true)) throw new RuntimeException('Choose uphold or overturn.');
        $ap0 = self::find($pdo, $appealId);
        if ($ap0 === null) throw new RuntimeException('Appeal not found.');
        Wallet::forUser($pdo, (int) $ap0['earner_user_id']);
        $pay = null;
        $pdo->beginTransaction();
        try {
            Approvals::lockWallets($pdo, [(int) $ap0['earner_user_id'], (int) $ap0['advertiser_user_id']]);
            $job = Jobs::lockRow($pdo, (int) $ap0['job_id']);
            $row = Attempts::lockAttempt($pdo, (int) $ap0['attempt_id']);
            $ap = self::lockAppeal($pdo, $appealId);
            if ((string) $ap['status'] !== 'open') throw new RuntimeException('This appeal has already been decided.');
            if ((string) $row['status'] !== 'rejected') throw new RuntimeException('This job attempt is no longer rejected.');
            if (!$isAdmin) {
                if ((int) ($ap['rejected_by_user_id'] ?? 0) === $actorId) throw new RuntimeException('You made the original decision, so another reviewer must handle this appeal.');
                ReviewClaims::assertHeld($pdo, 'job_appeal', $appealId, $actorId);
            }
            $f = ['escrow' => 0, 'wallet' => 0, 'platform' => 0, 'fund_tx' => null, 'approve_tx' => null];
            if ($decision === 'overturn') {
                [$f, $pay] = self::payOverturn($pdo, $job, $row, $ap, $actorId);
            }
            $status = $decision === 'overturn' ? 'overturned' : 'upheld';
            $pdo->prepare('UPDATE job_appeals SET status = :s, resolution_note = :n, resolved_by_user_id = :u, decided_by_role = :r, resolved_at = UTC_TIMESTAMP(),
                escrow_kobo = :ek, wallet_kobo = :wk, platform_kobo = :pk, funding_transaction_id = :ft, approve_transaction_id = :at WHERE id = :id')
                ->execute(['s' => $status, 'n' => $note, 'u' => $actorId, 'r' => $isAdmin ? 'admin' : 'reviewer',
                    'ek' => $f['escrow'], 'wk' => $f['wallet'], 'pk' => $f['platform'], 'ft' => $f['fund_tx'], 'at' => $f['approve_tx'], 'id' => $appealId]);
            ReviewClaims::clear($pdo, 'job_appeal', $appealId);
            ReviewerActions::recordJob($pdo, $actorId, (string) $actor['role'], 'job_appeal_' . $status, $appealId, (int) $row['id'], $note);
            Audit::record($pdo, $actorId, ($isAdmin ? 'admin.job_appeal_' : 'reviewer.job_appeal_') . $status, 'job_appeal', $appealId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        self::notifyDecided($pdo, $appealId, $pay);
    }

    /** @return array{0:array,1:array} [funding, pay] */
    private static function payOverturn(PDO $pdo, array $job, array $row, array $ap, int $actorId): array
    {
        $appealId = (int) $ap['id'];
        $jobId = (int) $job['id'];
        $price = (int) $row['pay_kobo'];
        $fromEscrow = Jobs::freeSlots($job) > 0 ? $price : 0;
        $fromWallet = 0;
        $fromPlatform = 0;
        $fundTx = null;
        if ($fromEscrow === 0) {
            // No free slot: add one slot and fund it (advertiser wallet first, YOLOTASK covers the rest).
            $adv = Wallet::find($pdo, (int) $job['advertiser_user_id']);
            if ($adv === null) throw new RuntimeException('Advertiser wallet missing.');
            $fromWallet = max(0, min($price, (int) $adv['available_kobo']));
            $fromPlatform = $price - $fromWallet;
            $legs = [];
            if ($fromWallet > 0) $legs[] = [(int) $adv['available_account_id'], 'debit', $fromWallet];
            if ($fromPlatform > 0) $legs[] = [Ledger::systemAccountId($pdo, Ledger::JOBS_APPEAL_PAYOUTS), 'debit', $fromPlatform];
            $legs[] = [Ledger::systemAccountId($pdo, Ledger::JOBS_ESCROW), 'credit', $price];
            $tx = Ledger::post($pdo, 'job-appeal-fund:' . $appealId, 'job_appeal_funding', 'Job appeal #' . $appealId . ' payout for job #' . $jobId, $legs, 'job_appeal', $appealId, $actorId);
            $fundTx = $tx['id'];
            $pdo->prepare('UPDATE jobs SET funded_kobo = funded_kobo + :a, slots = slots + 1 WHERE id = :id')->execute(['a' => $price, 'id' => $jobId]);
            $note = 'Appeal #' . $appealId . ' overturned with no free slot: ' . ($fromWallet > 0 ? Money::format($fromWallet) . ' from the advertiser wallet' : '')
                . ($fromWallet > 0 && $fromPlatform > 0 ? ', ' : '') . ($fromPlatform > 0 ? Money::format($fromPlatform) . ' paid by YOLOTASK' : '');
            $pdo->prepare("INSERT INTO job_funding_events (job_id, event, slots, amount_kobo, ledger_transaction_id, note) VALUES (:j, 'appeal_topup', 1, :a, :t, :n)")
                ->execute(['j' => $jobId, 'a' => $price, 't' => $fundTx, 'n' => mb_substr($note, 0, 255)]);
        }
        $pay = Reviews::postApproval($pdo, $job, $row, 'job-appeal-approve:' . $appealId, $actorId);
        Reviews::markApproved($pdo, (int) $row['id'], 'appeal', $actorId, $pay);
        $pdo->prepare('UPDATE jobs SET approved_count = approved_count + 1, rejected_count = GREATEST(rejected_count, 1) - 1, spent_kobo = spent_kobo + :p WHERE id = :id')->execute(['p' => $price, 'id' => $jobId]);
        $job = Jobs::completeIfFull($pdo, $jobId);
        Jobs::settle($pdo, $job, 'job appeal settled', $actorId);
        return [['escrow' => $fromEscrow, 'wallet' => $fromWallet, 'platform' => $fromPlatform, 'fund_tx' => $fundTx, 'approve_tx' => $pay['tx_id']], $pay];
    }

    private static function notifyDecided(PDO $pdo, int $appealId, ?array $pay): void
    {
        try {
            $ap = self::find($pdo, $appealId);
            if ($ap === null) return;
            $title = mb_substr((string) $ap['job_title'], 0, 90);
            $url = 'job.php?id=' . (int) $ap['job_id'];
            if ((string) $ap['status'] === 'overturned') {
                $amount = Money::format((int) $ap['earner_kobo']);
                Notifications::notify($pdo, (int) $ap['earner_user_id'], 'appeals', 'Appeal successful: ' . $title,
                    'Your job was approved on appeal. ' . (!empty($pay['held']) ? "Your {$amount} reward is on a short hold and then reaches your wallet." : "{$amount} was added to your wallet.") . ' Note: ' . $ap['resolution_note'],
                    $url, 'job-appeal:' . $appealId . ':overturned', [
                        'subject' => 'Your job appeal was successful', 'heading' => 'Appeal overturned',
                        'lines' => ['Your work on "' . $ap['job_title'] . '" has been approved on appeal.', 'Reviewer note: ' . $ap['resolution_note'],
                            !empty($pay['held']) ? "Your {$amount} reward is on a short hold and then reaches your wallet." : "Your {$amount} reward is in your wallet."],
                        'button' => ['label' => 'Open the job', 'url' => yolotask_portal_url('earner') . '/' . $url],
                    ]);
                $lines = ['A YOLOTASK reviewer overturned your rejection on "' . $ap['job_title'] . '" after the earner appealed.', 'Your rejection reason: ' . (string) $ap['rejection_reason'], 'Reviewer note: ' . $ap['resolution_note']];
                if ((int) $ap['wallet_kobo'] > 0) $lines[] = 'The job had no free slot, so ' . Money::format((int) $ap['wallet_kobo']) . ' was charged from your wallet for this payout.';
                Notifications::notify($pdo, (int) $ap['advertiser_user_id'], 'appeals', 'Job rejection overturned: ' . $title, 'A reviewer overturned your rejection after the earner appealed.',
                    'job.php?id=' . (int) $ap['job_id'], 'job-appeal:' . $appealId . ':overturned', [
                        'subject' => 'A job rejection was overturned on appeal', 'heading' => 'Rejection overturned', 'lines' => $lines,
                        'button' => ['label' => 'Open the job', 'url' => yolotask_portal_url('advertiser') . '/job.php?id=' . (int) $ap['job_id']],
                    ]);
            } else {
                Notifications::notify($pdo, (int) $ap['earner_user_id'], 'appeals', 'Appeal decided: rejection upheld', 'After checking your work on "' . $title . '", the rejection stands. Note: ' . $ap['resolution_note'],
                    $url, 'job-appeal:' . $appealId . ':upheld', [
                        'subject' => 'Your job appeal was reviewed', 'heading' => 'Rejection upheld',
                        'lines' => ['After checking your work on "' . $ap['job_title'] . '" against the instructions, the rejection stands.', 'Reviewer note: ' . $ap['resolution_note'], 'This decision is final.'],
                        'button' => ['label' => 'Find more jobs', 'url' => yolotask_portal_url('earner') . '/jobs.php'],
                    ]);
            }
        } catch (\Throwable $e) {
            Logger::error('job_appeal.notify_failed', ['appeal_id' => $appealId, 'exception' => get_class($e)]);
        }
    }

    private static function lockAppeal(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM job_appeals WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new RuntimeException('Appeal not found.');
        return $row;
    }

    private static function requireStaff(array $user): int
    {
        if (!in_array((string) $user['role'], ['reviewer', 'admin'], true)) throw new RuntimeException('Only reviewers and admins can decide appeals.');
        return (int) $user['id'];
    }

    public static function statusLabel(array $ap): array
    {
        return match ((string) $ap['status']) {
            'open' => ['Appeal open', 'badge-warning'],
            'upheld' => ['Rejection upheld', 'badge-danger'],
            'overturned' => ['Overturned on appeal', 'badge-success'],
            default => [ucfirst((string) $ap['status']), 'badge-info'],
        };
    }
}
