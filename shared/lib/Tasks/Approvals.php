<?php
declare(strict_types=1);
namespace YoloTask\Tasks;

use PDO;
use RuntimeException;
use YoloTask\Campaigns\Campaigns;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Messaging\Mailer;
use YoloTask\Money\Ledger;
use YoloTask\Money\Money;
use YoloTask\Money\Wallet;
use YoloTask\Security\Audit;

/**
 * Submission decisions and the money they move.
 *
 * Approve: Dr campaign escrow (price) / Cr earner wallet (reward) — or
 * Cr system:reward_hold when a reward hold window is set — / Cr
 * system:revenue (margin). Idempotency key sub-approve:<id>.
 * Reject: no money moves; the slot returns to the pool (or, on a closed
 * campaign, its price is refunded to the advertiser).
 * Auto-approve: pending submissions past auto_approve_at (cron).
 * Reviewers decide stale submissions through the same approve/reject
 * (source 'reviewer'); an appeal overturn reuses postApproval() with its own
 * idempotency key (see Appeals).
 * Release: held rewards past reward_release_at move to the earner (cron).
 */
final class Approvals
{
    /** @param 'advertiser'|'auto'|'admin'|'reviewer' $source */
    public static function approve(PDO $pdo, int $submissionId, string $source, ?int $actorId, ?int $advertiserId = null, ?callable $guard = null): void
    {
        $s = Submissions::find($pdo, $submissionId);
        if ($s === null || ($advertiserId !== null && (int) $s['advertiser_user_id'] !== $advertiserId)) throw new RuntimeException('Submission not found.');
        $earnerId = (int) $s['earner_user_id'];
        $advId = (int) $s['advertiser_user_id'];
        Wallet::forUser($pdo, $earnerId);
        $pdo->beginTransaction();
        try {
            self::lockWallets($pdo, [$earnerId, $advId]);
            $c = Campaigns::lockRow($pdo, (int) $s['campaign_id']);
            $row = self::lockSubmission($pdo, $submissionId);
            if ((string) $row['status'] !== 'pending') throw new RuntimeException('This submission has already been decided.');
            if ($source === 'auto' && strtotime((string) $row['auto_approve_at'] . ' UTC') > time()) throw new RuntimeException('Not due for auto-approval yet.');
            if ($guard !== null) $guard($pdo, $row);
            $price = (int) $row['unit_price_kobo'];
            $pay = self::postApproval($pdo, $c, $row, 'sub-approve:' . $submissionId, $actorId);
            self::markApproved($pdo, $submissionId, $source, $actorId, $pay);
            $pdo->prepare('UPDATE campaigns SET approved_count = approved_count + 1, pending_count = pending_count - 1, spent_kobo = spent_kobo + :p WHERE id = :id')->execute(['p' => $price, 'id' => $c['id']]);
            $c = self::completeIfFull($pdo, (int) $c['id']);
            Campaigns::settle($pdo, $c, (string) $c['status'] === 'completed' ? 'campaign completed' : 'campaign closed', $actorId);
            Audit::record($pdo, $actorId, match ($source) { 'auto' => 'task.auto_approved', 'admin' => 'admin.submission_approved', 'reviewer' => 'reviewer.submission_approved', default => 'campaign.submission_approved' }, 'submission', $submissionId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * The approve money path, shared by every approval (advertiser, auto,
     * admin, reviewer and appeal overturn): Dr campaign escrow (price) /
     * Cr earner available or system:reward_hold (reward) / Cr system:revenue
     * (margin). Runs inside the caller's transaction with the wallets, the
     * campaign and the submission already locked.
     * @return array{tx_id:int,created:bool,held:bool,release_at:?string}
     */
    public static function postApproval(PDO $pdo, array $c, array $row, string $key, ?int $actorId): array
    {
        $holdHours = max(0, Settings::int($pdo, 'tasks.reward_hold_hours'));
        $price = (int) $row['unit_price_kobo'];
        $reward = (int) $row['unit_reward_kobo'];
        $margin = $price - $reward;
        $earner = Wallet::find($pdo, (int) $row['earner_user_id']);
        if ($earner === null) throw new RuntimeException('Earner wallet missing.');
        $toAccount = $holdHours > 0 ? Ledger::systemAccountId($pdo, Ledger::REWARD_HOLD) : (int) $earner['available_account_id'];
        $legs = [[(int) $c['escrow_account_id'], 'debit', $price], [$toAccount, 'credit', $reward]];
        if ($margin > 0) $legs[] = [Ledger::systemAccountId($pdo, Ledger::REVENUE), 'credit', $margin];
        $tx = Ledger::post($pdo, $key, 'task_reward', 'Task reward: ' . mb_substr((string) $c['title'], 0, 120), $legs, 'submission', (int) $row['id'], $actorId);
        return ['tx_id' => $tx['id'], 'created' => $tx['created'], 'held' => $holdHours > 0, 'release_at' => $holdHours > 0 ? gmdate('Y-m-d H:i:s', time() + $holdHours * 3600) : null];
    }

    /** Record the approval on the submission row (after postApproval). */
    public static function markApproved(PDO $pdo, int $submissionId, string $source, ?int $actorId, array $pay): void
    {
        $pdo->prepare("UPDATE submissions SET status = 'approved', decision_source = :src, decided_by_user_id = :a, decided_at = UTC_TIMESTAMP(), reject_reason = NULL,
            reward_status = :rs, reward_release_at = :rra, reward_released_at = :rrd, approve_transaction_id = :t WHERE id = :id")
            ->execute(['src' => $source, 'a' => $actorId, 'rs' => $pay['held'] ? 'held' : 'released',
                'rra' => $pay['release_at'], 'rrd' => $pay['held'] ? null : gmdate('Y-m-d H:i:s'), 't' => $pay['tx_id'], 'id' => $submissionId]);
    }

    /**
     * Complete an open campaign whose approvals reached its capacity
     * (quantity + approvals paid on appeal outside the budget). Returns the
     * freshly locked campaign row.
     */
    public static function completeIfFull(PDO $pdo, int $campaignId): array
    {
        $c = Campaigns::lockRow($pdo, $campaignId);
        if ((int) $c['approved_count'] >= Campaigns::capacity($c) && in_array((string) $c['status'], ['live', 'paused'], true)) {
            $pdo->prepare("UPDATE campaigns SET status = 'completed', status_reason = 'All tasks approved', closed_at = UTC_TIMESTAMP() WHERE id = :id")->execute(['id' => $c['id']]);
            $pdo->prepare("UPDATE task_reservations SET status = 'released' WHERE campaign_id = :id AND status = 'active'")->execute(['id' => $c['id']]);
            $c['status'] = 'completed';
            Audit::record($pdo, null, 'campaign.completed', 'campaign', (int) $c['id']);
        }
        return $c;
    }

    /** @param 'advertiser'|'admin'|'reviewer' $source */
    public static function reject(PDO $pdo, int $submissionId, string $source, int $actorId, string $reason, ?int $advertiserId = null, ?callable $guard = null): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) throw new RuntimeException('Tell the earner why (3 to 255 characters).');
        $s = Submissions::find($pdo, $submissionId);
        if ($s === null || ($advertiserId !== null && (int) $s['advertiser_user_id'] !== $advertiserId)) throw new RuntimeException('Submission not found.');
        $pdo->beginTransaction();
        try {
            Wallet::lock($pdo, (int) $s['advertiser_user_id']);
            $c = Campaigns::lockRow($pdo, (int) $s['campaign_id']);
            $row = self::lockSubmission($pdo, $submissionId);
            if ((string) $row['status'] !== 'pending') throw new RuntimeException('This submission has already been decided.');
            if ($guard !== null) $guard($pdo, $row);
            $pdo->prepare("UPDATE submissions SET status = 'rejected', decision_source = :src, decided_by_user_id = :a, decided_at = UTC_TIMESTAMP(), reject_reason = :r WHERE id = :id")
                ->execute(['src' => $source, 'a' => $actorId, 'r' => $reason, 'id' => $submissionId]);
            $pdo->prepare('UPDATE campaigns SET pending_count = pending_count - 1, rejected_count = rejected_count + 1 WHERE id = :id')->execute(['id' => $c['id']]);
            $c['pending_count'] = (int) $c['pending_count'] - 1;
            Campaigns::settle($pdo, $c, 'unused slot after a rejection', $actorId);
            Audit::record($pdo, $actorId, match ($source) { 'admin' => 'admin.submission_rejected', 'reviewer' => 'reviewer.submission_rejected', default => 'campaign.submission_rejected' }, 'submission', $submissionId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        try {
            $after = array_merge($s, ['status' => 'rejected', 'decision_source' => $source, 'decided_at' => gmdate('Y-m-d H:i:s')]);
            $can = Submissions::canResubmit($pdo, $after);
            $appeal = Appeals::eligibility($pdo, $after, false) === null;
            $lines = ['Your proof for "' . $s['campaign_title'] . '" was not accepted.', 'Reason: ' . $reason];
            if ($can) $lines[] = 'You can fix it and resubmit once from your submissions page.';
            if ($appeal) $lines[] = 'If you followed the instructions and believe this is wrong, you can appeal to a YOLOTASK reviewer within ' . Appeals::windowHours($pdo) . ' hours' . ($can ? ' instead of resubmitting' : '') . '.';
            if (!$can && !$appeal) $lines[] = 'This task is closed for you, but there are many more waiting in your feed.';
            Mailer::send((string) $s['earner_email'], 'Your task proof was not accepted', 'Proof not accepted', $lines,
                ['label' => 'Open my submissions', 'url' => yolotask_portal_url('earner') . '/submission.php?id=' . $submissionId]);
        } catch (\Throwable $e) {
            Logger::error('task.reject_notify_failed', ['exception' => get_class($e)]);
        }
    }

    /** Cron: approve pending submissions whose advertiser did not decide in time. */
    public static function autoApproveDue(PDO $pdo, int $limit = 500): int
    {
        $stmt = $pdo->prepare("SELECT id FROM submissions WHERE status = 'pending' AND auto_approve_at <= UTC_TIMESTAMP() ORDER BY auto_approve_at LIMIT " . max(1, $limit));
        $stmt->execute();
        $n = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            try {
                self::approve($pdo, (int) $id, 'auto', null);
                $n++;
            } catch (RuntimeException $e) {
                Logger::error('task.auto_approve_skipped', ['submission_id' => (int) $id, 'message' => $e->getMessage()]);
            }
        }
        return $n;
    }

    /** Cron: move held rewards to earners once the hold window passes. */
    public static function releaseDue(PDO $pdo, int $limit = 500): int
    {
        $stmt = $pdo->prepare("SELECT id, earner_user_id FROM submissions WHERE reward_status = 'held' AND reward_release_at <= UTC_TIMESTAMP() ORDER BY reward_release_at LIMIT " . max(1, $limit));
        $stmt->execute();
        $n = 0;
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $pdo->beginTransaction();
            try {
                $wallet = Wallet::lock($pdo, (int) $r['earner_user_id']);
                $row = self::lockSubmission($pdo, (int) $r['id']);
                if ((string) $row['reward_status'] !== 'held') { $pdo->commit(); continue; }
                $amount = (int) $row['unit_reward_kobo'];
                $tx = Ledger::post($pdo, 'sub-release:' . $r['id'], 'task_reward_release', 'Task reward released (submission #' . $r['id'] . ')', [
                    [Ledger::systemAccountId($pdo, Ledger::REWARD_HOLD), 'debit', $amount],
                    [$wallet['available_account_id'], 'credit', $amount],
                ], 'submission', (int) $r['id']);
                $pdo->prepare("UPDATE submissions SET reward_status = 'released', reward_released_at = UTC_TIMESTAMP(), release_transaction_id = :t WHERE id = :id")->execute(['t' => $tx['id'], 'id' => $r['id']]);
                $pdo->commit();
                $n++;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                Logger::error('task.reward_release_failed', ['submission_id' => (int) $r['id'], 'exception' => get_class($e)]);
            }
        }
        return $n;
    }

    public static function lockSubmission(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM submissions WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new RuntimeException('Submission not found.');
        return $row;
    }

    /** Lock wallet rows in user-id order so concurrent decisions cannot deadlock. */
    public static function lockWallets(PDO $pdo, array $userIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        sort($ids);
        foreach ($ids as $id) Wallet::lock($pdo, $id);
    }

    public static function describeMoney(int $price, int $reward): string
    {
        return Money::format($reward) . ' to the earner, ' . Money::format($price - $reward) . ' service fee';
    }
}
