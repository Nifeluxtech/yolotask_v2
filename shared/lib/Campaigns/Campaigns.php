<?php
declare(strict_types=1);
namespace YoloTask\Campaigns;

use PDO;
use RuntimeException;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Messaging\Mailer;
use YoloTask\Money\Ledger;
use YoloTask\Money\Money;
use YoloTask\Money\Wallet;
use YoloTask\Security\Audit;
use YoloTask\Support\Pagination;

/**
 * Campaign lifecycle and escrow.
 *
 *   draft --submit (budget locked in escrow)--> pending_approval
 *   pending_approval --admin approve--> live | --admin reject (full refund)--> rejected
 *   live <--> paused (advertiser)
 *   live/paused --cancel (advertiser or admin)--> cancelled
 *   live/paused --end date passed (cron)--> ended
 *   any open state --approved count reaches quantity--> completed
 *
 * Each funded campaign has its own escrow ledger account. Closed campaigns
 * (completed, cancelled, ended, rejected) keep in escrow only the price of
 * submissions still pending; settle() refunds the rest, and runs again as
 * each pending submission is decided. Lock order: wallet rows (user id
 * order), then the campaign row, then submissions/reservations, then ledger
 * accounts (inside Ledger::post).
 */
final class Campaigns
{
    public const OPEN = ['pending_approval', 'live', 'paused'];
    public const CLOSED = ['completed', 'cancelled', 'ended', 'rejected'];
    public const PAGE_SIZE = 20;

    // ---------------------------------------------------------------- settings

    public static function autoApproveBounds(PDO $pdo): array
    {
        return ['min' => Settings::int($pdo, 'tasks.auto_approve_min_hours'), 'max' => Settings::int($pdo, 'tasks.auto_approve_max_hours'), 'default' => Settings::int($pdo, 'tasks.auto_approve_default_hours')];
    }

    public static function advertiserAutoApproveHours(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare('SELECT auto_approve_hours FROM advertiser_settings WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        $v = $stmt->fetchColumn();
        return $v === false ? self::autoApproveBounds($pdo)['default'] : (int) $v;
    }

    /** Save the advertiser's auto-approve window; applies to their open campaigns' future submissions. */
    public static function saveAdvertiserSettings(PDO $pdo, array $user, int $hours): void
    {
        if ((string) $user['role'] !== 'advertiser') throw new RuntimeException('Only advertisers have campaign settings.');
        $b = self::autoApproveBounds($pdo);
        if ($hours < $b['min'] || $hours > $b['max']) throw new RuntimeException("Choose between {$b['min']} and {$b['max']} hours.");
        $uid = (int) $user['id'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO advertiser_settings (user_id, auto_approve_hours) VALUES (:u, :h) ON DUPLICATE KEY UPDATE auto_approve_hours = VALUES(auto_approve_hours)')->execute(['u' => $uid, 'h' => $hours]);
            $pdo->prepare("UPDATE campaigns SET auto_approve_hours = :h WHERE advertiser_user_id = :u AND status IN ('draft', 'pending_approval', 'live', 'paused')")->execute(['h' => $hours, 'u' => $uid]);
            Audit::record($pdo, $uid, 'campaign.settings_updated', 'user', $uid);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // ---------------------------------------------------------------- create / edit

    /**
     * Validate campaign form input.
     * @return array<string,mixed>
     */
    private static function validateInput(PDO $pdo, array $in, ?int $exceptId = null): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 120) throw new RuntimeException('Give the campaign a title of 3 to 120 characters.');
        $platform = Pricing::platform($pdo, (int) ($in['platform_id'] ?? 0));
        if ($platform === null || !(int) $platform['is_active']) throw new RuntimeException('Choose a platform.');
        $type = Pricing::taskType($pdo, (int) ($in['task_type_id'] ?? 0));
        if ($type === null || !(int) $type['is_active']) throw new RuntimeException('Choose a task type.');
        $tier = ($in['tier'] ?? 'basic') === 'targeted' ? 'targeted' : 'basic';
        $link = CampaignLinks::validate((string) ($in['link_url'] ?? ''), $platform);
        if (CampaignLinks::duplicateOpen($pdo, $link['hash'], (int) $type['id'], $exceptId)) {
            throw new RuntimeException('A campaign for this link and task type is already running. Wait for it to finish, or choose a different task type.');
        }
        $text = Instructions::clean((string) ($in['instructions'] ?? ''), (string) ($in['proof_requirements'] ?? ''));
        $qtyRaw = trim((string) ($in['quantity'] ?? ''));
        $min = Settings::int($pdo, 'campaigns.min_quantity');
        $max = Settings::int($pdo, 'campaigns.max_quantity');
        if (!preg_match('/^\d{1,7}$/', str_replace(',', '', $qtyRaw))) throw new RuntimeException("Enter how many people should do the task ({$min} to " . number_format($max) . ').');
        $qty = (int) str_replace(',', '', $qtyRaw);
        if ($qty < $min || $qty > $max) throw new RuntimeException("Quantity must be between {$min} and " . number_format($max) . '.');
        $endsAt = null;
        $ends = trim((string) ($in['ends_at'] ?? ''));
        if ($ends !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $ends, new \DateTimeZone('Africa/Lagos'));
            if ($d === false || $d->format('Y-m-d') !== $ends) throw new RuntimeException('Choose a valid end date.');
            $endLocal = $d->setTime(23, 59, 59);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Lagos'));
            if ($endLocal <= $now) throw new RuntimeException('The end date must be in the future.');
            if ($endLocal > $now->modify('+366 days')) throw new RuntimeException('The end date must be within a year.');
            $endsAt = $endLocal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $unit = Pricing::unit($type, $tier);
        if ($unit['reward'] <= 0 || $unit['margin'] <= 0) throw new RuntimeException('This task type is not priced yet. Choose another.');
        return ['title' => $title, 'platform' => $platform, 'type' => $type, 'tier' => $tier, 'link' => $link, 'instructions' => $text['instructions'],
            'proof' => $text['proof'], 'quantity' => $qty, 'ends_at' => $endsAt, 'price' => $unit['price'], 'reward' => $unit['reward'], 'budget' => $unit['price'] * $qty];
    }

    /** Create a draft (no money moves). */
    public static function create(PDO $pdo, array $user, array $in): int
    {
        if ((string) $user['role'] !== 'advertiser') throw new RuntimeException('Only advertisers can create campaigns.');
        if (empty($user['email_verified_at'])) throw new RuntimeException('Confirm your email before creating campaigns.');
        $uid = (int) $user['id'];
        $v = self::validateInput($pdo, $in);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO campaigns (advertiser_user_id, platform_id, task_type_id, title, link_url, link_normalized, link_hash, instructions, proof_requirements, tier, unit_price_kobo, unit_reward_kobo, quantity, budget_kobo, auto_approve_hours, ends_at)
                VALUES (:u, :p, :t, :ti, :l, :ln, :lh, :i, :pr, :tier, :price, :reward, :q, :b, :aa, :e)')
                ->execute(['u' => $uid, 'p' => $v['platform']['id'], 't' => $v['type']['id'], 'ti' => $v['title'], 'l' => $v['link']['url'], 'ln' => $v['link']['normalized'], 'lh' => $v['link']['hash'],
                    'i' => $v['instructions'], 'pr' => $v['proof'], 'tier' => $v['tier'], 'price' => $v['price'], 'reward' => $v['reward'], 'q' => $v['quantity'], 'b' => $v['budget'],
                    'aa' => self::advertiserAutoApproveHours($pdo, $uid), 'e' => $v['ends_at']]);
            $id = (int) $pdo->lastInsertId();
            Instructions::record($pdo, $id, 1, $v['instructions'], $v['proof'], $uid);
            Audit::record($pdo, $uid, 'campaign.created', 'campaign', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    /** Edit a draft completely (prices are re-quoted from the current catalog). */
    public static function updateDraft(PDO $pdo, array $user, int $id, array $in): void
    {
        $c = self::ownedBy($pdo, $id, (int) $user['id']);
        if ((string) $c['status'] !== 'draft') throw new RuntimeException('Only drafts can be fully edited. You can still update the instructions.');
        $v = self::validateInput($pdo, $in, $id);
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE campaigns SET platform_id = :p, task_type_id = :t, title = :ti, link_url = :l, link_normalized = :ln, link_hash = :lh, instructions = :i, proof_requirements = :pr, tier = :tier,
                unit_price_kobo = :price, unit_reward_kobo = :reward, quantity = :q, budget_kobo = :b, ends_at = :e WHERE id = :id AND status = 'draft'");
            $upd->execute(['p' => $v['platform']['id'], 't' => $v['type']['id'], 'ti' => $v['title'], 'l' => $v['link']['url'], 'ln' => $v['link']['normalized'], 'lh' => $v['link']['hash'],
                'i' => $v['instructions'], 'pr' => $v['proof'], 'tier' => $v['tier'], 'price' => $v['price'], 'reward' => $v['reward'], 'q' => $v['quantity'], 'b' => $v['budget'], 'e' => $v['ends_at'], 'id' => $id]);
            Instructions::replaceDraft($pdo, $id, $v['instructions'], $v['proof']);
            Audit::record($pdo, (int) $user['id'], 'campaign.updated', 'campaign', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** New instructions version for a funded, open campaign. */
    public static function updateInstructions(PDO $pdo, array $user, int $id, string $instructions, string $proof): void
    {
        $uid = (int) $user['id'];
        self::ownedBy($pdo, $id, $uid);
        $text = Instructions::clean($instructions, $proof);
        $pdo->beginTransaction();
        try {
            $c = self::lockRow($pdo, $id);
            if (!in_array((string) $c['status'], self::OPEN, true)) throw new RuntimeException('This campaign is closed.');
            if ($text['instructions'] === (string) $c['instructions'] && $text['proof'] === (string) $c['proof_requirements']) { $pdo->commit(); return; }
            $version = (int) $c['instructions_version'] + 1;
            Instructions::record($pdo, $id, $version, $text['instructions'], $text['proof'], $uid);
            $pdo->prepare('UPDATE campaigns SET instructions = :i, proof_requirements = :p, instructions_version = :v WHERE id = :id')
                ->execute(['i' => $text['instructions'], 'p' => $text['proof'], 'v' => $version, 'id' => $id]);
            Audit::record($pdo, $uid, 'campaign.instructions_updated', 'campaign', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function deleteDraft(PDO $pdo, array $user, int $id): void
    {
        $c = self::ownedBy($pdo, $id, (int) $user['id']);
        if ((string) $c['status'] !== 'draft') throw new RuntimeException('Only drafts can be deleted.');
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM campaign_instructions WHERE campaign_id = :id')->execute(['id' => $id]);
            $pdo->prepare("DELETE FROM campaigns WHERE id = :id AND status = 'draft' AND funded_kobo = 0")->execute(['id' => $id]);
            Audit::record($pdo, (int) $user['id'], 'campaign.draft_deleted', 'campaign', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // ---------------------------------------------------------------- funding

    /** Lock the full budget from the advertiser wallet into escrow and send for approval. Idempotent. */
    public static function submit(PDO $pdo, array $user, int $id): void
    {
        $uid = (int) $user['id'];
        if (empty($user['email_verified_at'])) throw new RuntimeException('Confirm your email before funding campaigns.');
        self::ownedBy($pdo, $id, $uid);
        Wallet::forUser($pdo, $uid);
        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, $uid);
            $c = self::lockRow($pdo, $id);
            if ((string) $c['status'] === 'pending_approval' && (int) $c['funded_kobo'] === (int) $c['budget_kobo']) { $pdo->commit(); return; } // double click
            if ((string) $c['status'] !== 'draft') throw new RuntimeException('This campaign has already been submitted.');
            if ($c['ends_at'] !== null && strtotime((string) $c['ends_at'] . ' UTC') <= time()) throw new RuntimeException('The end date has passed. Edit the campaign first.');
            if (CampaignLinks::duplicateOpen($pdo, (string) $c['link_hash'], (int) $c['task_type_id'], $id)) throw new RuntimeException('A campaign for this link and task type is already running.');
            if ($wallet['status'] !== 'active') throw new RuntimeException('Your wallet is on hold. Contact support.');
            $budget = (int) $c['budget_kobo'];
            if ($wallet['available_kobo'] < $budget) {
                throw new RuntimeException('This campaign costs ' . Money::format($budget) . ' and your wallet has ' . Money::format($wallet['available_kobo']) . '. Top up ' . Money::format($budget - $wallet['available_kobo']) . ' to continue.');
            }
            $escrowId = (int) ($c['escrow_account_id'] ?? 0);
            if ($escrowId === 0) {
                $pdo->prepare("INSERT INTO ledger_accounts (code, name, kind, normal_side, campaign_id) VALUES (:c, :n, 'campaign_escrow', 'credit', :id)")
                    ->execute(['c' => 'campaign:' . $id . ':escrow', 'n' => 'Campaign #' . $id . ' escrow', 'id' => $id]);
                $escrowId = (int) $pdo->lastInsertId();
            }
            $tx = Ledger::post($pdo, 'camp-fund:' . $id, 'campaign_fund', 'Campaign #' . $id . ' funded', [
                [$wallet['available_account_id'], 'debit', $budget],
                [$escrowId, 'credit', $budget],
            ], 'campaign', $id, $uid);
            $pdo->prepare("UPDATE campaigns SET status = 'pending_approval', escrow_account_id = :e, funded_kobo = :b, submitted_at = UTC_TIMESTAMP(), status_reason = NULL WHERE id = :id")
                ->execute(['e' => $escrowId, 'b' => $budget, 'id' => $id]);
            $pdo->prepare("INSERT INTO campaign_funding_events (campaign_id, event, amount_kobo, ledger_transaction_id, note) VALUES (:c, 'lock', :a, :t, 'Budget locked on submission')")
                ->execute(['c' => $id, 'a' => $budget, 't' => $tx['id']]);
            Audit::record($pdo, $uid, 'campaign.submitted', 'campaign', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Refund whatever a closed campaign no longer needs. Caller holds the
     * advertiser wallet lock and the campaign row lock ($c is that row).
     * Returns the amount refunded.
     */
    public static function settle(PDO $pdo, array $c, string $note, ?int $actorId = null): int
    {
        if (!in_array((string) $c['status'], self::CLOSED, true)) return 0;
        $unspent = (int) $c['funded_kobo'] - (int) $c['spent_kobo'] - (int) $c['refunded_kobo'];
        $refund = $unspent - (int) $c['pending_count'] * (int) $c['unit_price_kobo'];
        if ($refund <= 0) return 0;
        $wallet = Wallet::find($pdo, (int) $c['advertiser_user_id']);
        if ($wallet === null) throw new RuntimeException('Advertiser wallet missing.');
        $seq = (int) $c['refund_seq'] + 1;
        $id = (int) $c['id'];
        $tx = Ledger::post($pdo, 'camp-refund:' . $id . ':' . $seq, 'campaign_refund', 'Campaign #' . $id . ' refund: ' . $note, [
            [(int) $c['escrow_account_id'], 'debit', $refund],
            [$wallet['available_account_id'], 'credit', $refund],
        ], 'campaign', $id, $actorId);
        $pdo->prepare('UPDATE campaigns SET refunded_kobo = refunded_kobo + :r, refund_seq = :s WHERE id = :id')->execute(['r' => $refund, 's' => $seq, 'id' => $id]);
        $pdo->prepare("INSERT INTO campaign_funding_events (campaign_id, event, amount_kobo, ledger_transaction_id, note) VALUES (:c, 'refund', :a, :t, :n)")
            ->execute(['c' => $id, 'a' => $refund, 't' => $tx['id'], 'n' => mb_substr($note, 0, 255)]);
        return $refund;
    }

    // ---------------------------------------------------------------- admin review

    public static function adminApprove(PDO $pdo, int $adminId, int $id): void
    {
        $pdo->beginTransaction();
        try {
            $c = self::lockRow($pdo, $id);
            if ((string) $c['status'] !== 'pending_approval') throw new RuntimeException('This campaign is not waiting for approval.');
            $pdo->prepare("UPDATE campaigns SET status = 'live', approved_at = UTC_TIMESTAMP(), approved_by_user_id = :a, status_reason = NULL WHERE id = :id")->execute(['a' => $adminId, 'id' => $id]);
            Audit::record($pdo, $adminId, 'admin.campaign_approved', 'campaign', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        self::notifyAdvertiser($pdo, $c, 'Your campaign is live', 'Campaign approved', ['"' . $c['title'] . '" has been approved and is now live. Earners can start working on it right away.']);
    }

    public static function adminReject(PDO $pdo, int $adminId, int $id, string $reason): int
    {
        $reason = self::reason($reason);
        $refund = self::closeWith($pdo, $adminId, $id, 'rejected', ['pending_approval'], $reason, 'admin.campaign_rejected', 'rejected by YOLOTASK');
        $c = self::find($pdo, $id);
        if ($c !== null) self::notifyAdvertiser($pdo, $c, 'Your campaign was not approved', 'Campaign not approved', ['"' . $c['title'] . '" was not approved.', 'Reason: ' . $reason, Money::format($refund) . ' has been returned to your wallet.']);
        return $refund;
    }

    /** Admin stops a live or paused campaign (abuse, policy). */
    public static function adminCancel(PDO $pdo, int $adminId, int $id, string $reason): int
    {
        $reason = self::reason($reason);
        return self::closeWith($pdo, $adminId, $id, 'cancelled', ['live', 'paused'], $reason, 'admin.campaign_cancelled', 'cancelled by YOLOTASK');
    }

    // ---------------------------------------------------------------- advertiser controls

    public static function pause(PDO $pdo, array $user, int $id): void
    {
        self::transition($pdo, $user, $id, ['live'], 'paused', 'campaign.paused');
    }

    public static function resume(PDO $pdo, array $user, int $id): void
    {
        $c = self::ownedBy($pdo, $id, (int) $user['id']);
        if ($c['ends_at'] !== null && strtotime((string) $c['ends_at'] . ' UTC') <= time()) throw new RuntimeException('The end date has passed.');
        self::transition($pdo, $user, $id, ['paused'], 'live', 'campaign.resumed');
    }

    /** Cancel: unspent escrow comes back now; pending submissions stay reserved until decided. */
    public static function cancel(PDO $pdo, array $user, int $id): int
    {
        self::ownedBy($pdo, $id, (int) $user['id']);
        return self::closeWith($pdo, (int) $user['id'], $id, 'cancelled', ['live', 'paused'], 'Cancelled by the advertiser', 'campaign.cancelled', 'cancelled');
    }

    /** Cron: close campaigns whose end date passed. */
    public static function endExpired(PDO $pdo): int
    {
        $ids = $pdo->query("SELECT id FROM campaigns WHERE status IN ('live', 'paused') AND ends_at IS NOT NULL AND ends_at <= UTC_TIMESTAMP() LIMIT 200")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $n = 0;
        foreach ($ids as $id) {
            try {
                self::closeWith($pdo, null, (int) $id, 'ended', ['live', 'paused'], 'End date reached', 'campaign.ended', 'end date reached');
                $n++;
            } catch (RuntimeException) {
                // state changed meanwhile
            }
        }
        return $n;
    }

    /** Close a campaign: release active reservations and refund what is not needed. */
    private static function closeWith(PDO $pdo, ?int $actorId, int $id, string $to, array $from, string $reason, string $event, string $note): int
    {
        $owner = self::find($pdo, $id);
        if ($owner === null) throw new RuntimeException('Campaign not found.');
        $pdo->beginTransaction();
        try {
            Wallet::lock($pdo, (int) $owner['advertiser_user_id']);
            $c = self::lockRow($pdo, $id);
            if (!in_array((string) $c['status'], $from, true)) throw new RuntimeException('This campaign can no longer be changed that way.');
            $pdo->prepare("UPDATE task_reservations SET status = 'released' WHERE campaign_id = :id AND status = 'active'")->execute(['id' => $id]);
            $pdo->prepare('UPDATE campaigns SET status = :s, status_reason = :r, closed_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['s' => $to, 'r' => $reason, 'id' => $id]);
            $c['status'] = $to;
            $refund = self::settle($pdo, $c, $note, $actorId);
            Audit::record($pdo, $actorId, $event, 'campaign', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $refund;
    }

    private static function transition(PDO $pdo, array $user, int $id, array $from, string $to, string $event): void
    {
        self::ownedBy($pdo, $id, (int) $user['id']);
        $pdo->beginTransaction();
        try {
            $c = self::lockRow($pdo, $id);
            if (!in_array((string) $c['status'], $from, true)) throw new RuntimeException($to === 'paused' ? 'Only live campaigns can be paused.' : 'Only paused campaigns can be resumed.');
            $pdo->prepare('UPDATE campaigns SET status = :s WHERE id = :id')->execute(['s' => $to, 'id' => $id]);
            Audit::record($pdo, (int) $user['id'], $event, 'campaign', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // ---------------------------------------------------------------- reads

    private const SELECT = 'SELECT c.*, p.name platform_name, p.slug platform_slug, t.name task_type_name, t.slug task_type_slug, u.display_name advertiser_name, u.email advertiser_email,
        (SELECT COUNT(*) FROM task_reservations r WHERE r.campaign_id = c.id AND r.status = \'active\' AND r.expires_at > UTC_TIMESTAMP()) reserved_count
        FROM campaigns c JOIN platforms p ON p.id = c.platform_id JOIN task_types t ON t.id = c.task_type_id JOIN users u ON u.id = c.advertiser_user_id';

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(self::SELECT . ' WHERE c.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** Campaign owned by this advertiser, or a not-found error (never reveals other advertisers' campaigns). */
    public static function ownedBy(PDO $pdo, int $id, int $advertiserId): array
    {
        $c = self::find($pdo, $id);
        if ($c === null || (int) $c['advertiser_user_id'] !== $advertiserId) throw new RuntimeException('Campaign not found.');
        return $c;
    }

    public static function lockRow(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new RuntimeException('Campaign not found.');
        return $row;
    }

    public static function slotsLeft(array $c): int
    {
        return max(0, (int) $c['quantity'] - (int) $c['approved_count'] - (int) $c['pending_count'] - (int) ($c['reserved_count'] ?? 0));
    }

    public static function listForAdvertiser(PDO $pdo, int $uid, string $status, int $page): array
    {
        $where = 'WHERE c.advertiser_user_id = :u';
        $args = ['u' => $uid];
        if ($status === 'open') $where .= " AND c.status IN ('draft', 'pending_approval', 'live', 'paused')";
        elseif ($status === 'closed') $where .= " AND c.status IN ('completed', 'cancelled', 'ended', 'rejected')";
        return Pagination::query($pdo, "SELECT COUNT(*) FROM campaigns c {$where}", self::SELECT . " {$where} ORDER BY c.id DESC", $args, $page, self::PAGE_SIZE);
    }

    public static function listForAdmin(PDO $pdo, string $status, string $q, int $page): array
    {
        $where = 'WHERE 1 = 1';
        $args = [];
        $all = ['draft', 'pending_approval', 'live', 'paused', 'rejected', 'completed', 'cancelled', 'ended'];
        if (in_array($status, $all, true)) { $where .= ' AND c.status = :s'; $args['s'] = $status; }
        if ($q !== '') { $where .= ' AND (c.title LIKE :q OR u.email LIKE :q2 OR c.link_url LIKE :q3)'; $like = '%' . addcslashes($q, '%_\\') . '%'; $args += ['q' => $like, 'q2' => $like, 'q3' => $like]; }
        $order = $status === 'pending_approval' ? 'c.submitted_at ASC' : 'c.id DESC';
        return Pagination::query($pdo, "SELECT COUNT(*) FROM campaigns c JOIN users u ON u.id = c.advertiser_user_id {$where}", self::SELECT . " {$where} ORDER BY {$order}", $args, $page, self::PAGE_SIZE);
    }

    public static function adminCounts(PDO $pdo): array
    {
        $out = ['pending_approval' => 0, 'live' => 0, 'paused' => 0, 'all' => 0];
        foreach ($pdo->query('SELECT status, COUNT(*) n FROM campaigns GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
            $out['all'] += (int) $r['n'];
        }
        return $out;
    }

    public static function advertiserStats(PDO $pdo, int $uid): array
    {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(status = 'live'), 0) live, COALESCE(SUM(status IN ('draft', 'pending_approval', 'live', 'paused')), 0) open, COALESCE(SUM(pending_count), 0) to_review,
            COALESCE(SUM(spent_kobo), 0) spent, COALESCE(SUM(approved_count), 0) approved, COALESCE(SUM(funded_kobo - spent_kobo - refunded_kobo), 0) escrow FROM campaigns WHERE advertiser_user_id = :u");
        $stmt->execute(['u' => $uid]);
        $r = $stmt->fetch() ?: [];
        return array_map('intval', $r);
    }

    public static function fundingEvents(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT event, amount_kobo, note, created_at FROM campaign_funding_events WHERE campaign_id = :c ORDER BY id');
        $stmt->execute(['c' => $id]);
        return $stmt->fetchAll() ?: [];
    }

    public static function statusLabel(string $status): array
    {
        return match ($status) {
            'draft' => ['Draft', 'badge-info'],
            'pending_approval' => ['Awaiting approval', 'badge-warning'],
            'live' => ['Live', 'badge-success'],
            'paused' => ['Paused', 'badge-warning'],
            'rejected' => ['Not approved', 'badge-danger'],
            'completed' => ['Completed', 'badge-success'],
            'cancelled' => ['Cancelled', 'badge-info'],
            'ended' => ['Ended', 'badge-info'],
            default => [ucfirst($status), 'badge-info'],
        };
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) throw new RuntimeException('Give a reason (3 to 255 characters).');
        return $reason;
    }

    private static function notifyAdvertiser(PDO $pdo, array $c, string $subject, string $heading, array $paragraphs): void
    {
        try {
            $email = (string) ($c['advertiser_email'] ?? '');
            if ($email === '') {
                $stmt = $pdo->prepare('SELECT email FROM users WHERE id = :id');
                $stmt->execute(['id' => (int) $c['advertiser_user_id']]);
                $email = (string) $stmt->fetchColumn();
            }
            Mailer::send($email, $subject, $heading, $paragraphs, ['label' => 'Open campaign', 'url' => yolotask_portal_url('advertiser') . '/campaign.php?id=' . (int) $c['id']]);
        } catch (\Throwable $e) {
            Logger::error('campaign.notify_failed', ['exception' => get_class($e)]);
        }
    }
}
