<?php
declare(strict_types=1);
namespace YoloTask\Tasks;

use PDO;
use RuntimeException;
use YoloTask\Campaigns\Campaigns;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Messaging\Mailer;
use YoloTask\Money\AdvertiserDebts;
use YoloTask\Money\Ledger;
use YoloTask\Money\Money;
use YoloTask\Money\Wallet;
use YoloTask\Security\Audit;
use YoloTask\Security\Throttle;
use YoloTask\Support\Pagination;
use YoloTask\Support\Uploads;

/**
 * Earner appeals against a rejected proof (Phase 5).
 *
 * Filing: one appeal per rejected submission, within appeals.window_hours
 * (72) of the rejection, with a reason (10–1,000 characters) and an optional
 * extra screenshot (same upload rules as proofs). Not allowed after a
 * resubmission, and an appealed submission cannot be resubmitted.
 *
 * Deciding: a reviewer claims the appeal (ReviewClaims, 30 min) and upholds
 * the rejection or overturns it, always with a note. Admins can decide any
 * open appeal and override any reviewer decision once; an admin decision is
 * final. Every decision is audited and logged in reviewer_actions.
 *
 * Overturn money (same approve path as Approvals::postApproval, key
 * appeal-approve:<appealId>): the campaign escrow pays when it has room
 * beyond pending proofs and other earners' active slots. Otherwise the
 * shortfall is first moved into the escrow from the advertiser's wallet and,
 * if that is short too, from system:advertiser_receivable (the platform
 * fronts it and an advertiser_debts row is opened for admins), key
 * appeal-fund:<appealId>. Such an approval is "over budget": it is recorded
 * on the appeal, counted in campaigns.extra_approved_count and shown to the
 * advertiser and admins; it never takes another earner's slot.
 *
 * Admin override of an overturn reverses the payment (key
 * appeal-reverse:<appealId>) and returns any top-up to where it came from
 * (appeal-unfund:<appealId>); it is refused when the earner no longer has
 * the reward, so admins recover that with a wallet adjustment first.
 */
final class Appeals
{
    public const PAGE_SIZE = 20;

    public static function windowHours(PDO $pdo): int
    {
        return max(1, Settings::int($pdo, 'appeals.window_hours'));
    }

    /**
     * Why this submission cannot be appealed, or null when it can.
     * $checkExisting=false skips the database lookup (used before a row can exist).
     */
    public static function eligibility(PDO $pdo, array $s, bool $checkExisting = true): ?string
    {
        if ((string) $s['status'] !== 'rejected') return 'Only rejected proofs can be appealed.';
        $existing = !empty($s['appeal_id']);
        if (!$existing && $checkExisting) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM submission_appeals WHERE submission_id = :id');
            $stmt->execute(['id' => $s['id']]);
            $existing = (int) $stmt->fetchColumn() > 0;
        }
        if ($existing) return 'You have already appealed this decision. Each rejection can be appealed once.';
        if ((string) ($s['decision_source'] ?? '') === 'appeal') return 'This proof was rejected on appeal; that decision is final.';
        if ((int) $s['attempts'] > 1) return 'You resubmitted this task, so it can no longer be appealed.';
        $decided = $s['decided_at'] ? strtotime((string) $s['decided_at'] . ' UTC') : false;
        if ($decided === false || $decided + self::windowHours($pdo) * 3600 < time()) return 'The ' . self::windowHours($pdo) . '-hour appeal window for this rejection has closed.';
        return null;
    }

    /** When the appeal window closes (UTC), for display. */
    public static function windowEndsAt(PDO $pdo, array $s): ?string
    {
        if (empty($s['decided_at'])) return null;
        return gmdate('Y-m-d H:i:s', strtotime((string) $s['decided_at'] . ' UTC') + self::windowHours($pdo) * 3600);
    }

    /** Earner files an appeal. Returns the appeal id. */
    public static function file(PDO $pdo, array $user, int $submissionId, string $reason, mixed $file): int
    {
        if ((string) $user['role'] !== 'earner') throw new RuntimeException('Only earner accounts can appeal.');
        if (empty($user['email_verified_at'])) throw new RuntimeException('Confirm your email first.');
        $uid = (int) $user['id'];
        $reason = trim(str_replace(["\r\n", "\r"], "\n", $reason));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) throw new RuntimeException('Explain why the rejection is wrong (10 to 1,000 characters).');
        $own = Submissions::findForEarner($pdo, $submissionId, $uid);
        if ($own === null) throw new RuntimeException('Submission not found.');
        if (($why = self::eligibility($pdo, $own)) !== null) throw new RuntimeException($why);
        if (Throttle::tooMany($pdo, 'appeal_file', (string) $uid, 10, 3600)) throw new RuntimeException('You are sending appeals too quickly. Wait a while and try again.');
        $img = null;
        if (is_array($file) && isset($file['error']) && !is_array($file['error']) && (int) $file['error'] !== UPLOAD_ERR_NO_FILE) {
            $img = Uploads::image($file, Settings::int($pdo, 'tasks.proof_max_bytes'));
            FakeProofGuard::assertFresh($pdo, $img['upload_sha256'], $submissionId);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT s.*, c.advertiser_user_id FROM submissions s JOIN campaigns c ON c.id = s.campaign_id WHERE s.id = :id AND s.earner_user_id = :u FOR UPDATE');
            $stmt->execute(['id' => $submissionId, 'u' => $uid]);
            $s = $stmt->fetch();
            if (!is_array($s)) throw new RuntimeException('Submission not found.');
            if (($why = self::eligibility($pdo, $s)) !== null) throw new RuntimeException($why);
            $pdo->prepare("INSERT INTO submission_appeals (submission_id, campaign_id, earner_user_id, advertiser_user_id, status, message, instructions_version, rejection_reason, rejection_source, rejected_by_user_id, rejected_at)
                VALUES (:s, :c, :e, :a, 'open', :m, :v, :rr, :rs, :rb, :ra)")
                ->execute(['s' => $submissionId, 'c' => $s['campaign_id'], 'e' => $uid, 'a' => $s['advertiser_user_id'], 'm' => $reason, 'v' => $s['instructions_version'],
                    'rr' => $s['reject_reason'], 'rs' => $s['decision_source'], 'rb' => $s['decided_by_user_id'], 'ra' => $s['decided_at']]);
            $id = (int) $pdo->lastInsertId();
            if ($img !== null) {
                $proofId = Proofs::store($pdo, $submissionId, (int) $s['attempts'], $img, true);
                $pdo->prepare('UPDATE submission_appeals SET proof_id = :p WHERE id = :id')->execute(['p' => $proofId, 'id' => $id]);
            }
            Audit::record($pdo, $uid, 'task.appealed', 'appeal', $id);
            $pdo->commit();
            Proofs::forgetWritten();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Proofs::discardWritten();
            if ($e instanceof \PDOException && $e->getCode() === '23000') throw new RuntimeException('You have already appealed this decision. Each rejection can be appealed once.');
            throw $e;
        }
        Throttle::hit($pdo, 'appeal_file', (string) $uid);
        self::mail((string) $own['earner_email'], 'We received your appeal', 'Appeal received', [
            'Thanks. Your appeal for "' . $own['campaign_title'] . '" is in the reviewer queue.',
            'A YOLOTASK reviewer will compare your proof with the advertiser\'s instructions and decide. We will email you the outcome.',
        ], 'Open my submission', yolotask_portal_url('earner') . '/submission.php?id=' . $submissionId);
        return $id;
    }

    private const SELECT = "SELECT a.*, s.status submission_status, s.attempts, s.comment earner_note, s.unit_price_kobo, s.unit_reward_kobo, s.decision_source submission_decision_source, s.reward_status,
        c.title campaign_title, c.status campaign_status, c.link_url, c.instructions current_instructions, c.instructions_version current_version, p.name platform_name, t.name task_type_name,
        eu.display_name earner_name, eu.email earner_email, au.display_name advertiser_name, au.email advertiser_email,
        rv.display_name resolver_name, ov.display_name overrider_name, rj.display_name rejecter_name,
        (SELECT pr.id FROM submission_proofs pr WHERE pr.submission_id = a.submission_id AND pr.is_appeal = 0 ORDER BY pr.attempt DESC, pr.id DESC LIMIT 1) latest_proof_id,
        rc.reviewer_user_id claim_user_id, rc.expires_at claim_expires_at, cu.display_name claim_reviewer_name
        FROM submission_appeals a JOIN submissions s ON s.id = a.submission_id JOIN campaigns c ON c.id = a.campaign_id
        JOIN platforms p ON p.id = c.platform_id JOIN task_types t ON t.id = c.task_type_id
        JOIN users eu ON eu.id = a.earner_user_id LEFT JOIN users au ON au.id = a.advertiser_user_id
        LEFT JOIN users rv ON rv.id = a.resolved_by_user_id LEFT JOIN users ov ON ov.id = a.overridden_by_user_id LEFT JOIN users rj ON rj.id = a.rejected_by_user_id
        LEFT JOIN review_claims rc ON rc.item_type = 'appeal' AND rc.item_id = a.id AND rc.expires_at > UTC_TIMESTAMP() LEFT JOIN users cu ON cu.id = rc.reviewer_user_id";

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(self::SELECT . ' WHERE a.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function forSubmission(PDO $pdo, int $submissionId): ?array
    {
        $stmt = $pdo->prepare(self::SELECT . ' WHERE a.submission_id = :s');
        $stmt->execute(['s' => $submissionId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** Reviewer queue: open appeals, oldest first. */
    public static function queue(PDO $pdo, int $page): array
    {
        return Pagination::query($pdo, "SELECT COUNT(*) FROM submission_appeals WHERE status = 'open'", self::SELECT . " WHERE a.status = 'open' ORDER BY a.created_at ASC, a.id ASC", [], $page, self::PAGE_SIZE);
    }

    public static function openCount(PDO $pdo): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM submission_appeals WHERE status = 'open'")->fetchColumn();
    }

    /** Admin list: status open/upheld/overturned/override/all, search earner/advertiser email or campaign title. */
    public static function listForAdmin(PDO $pdo, string $status, string $q, int $page, ?int $advertiserId = null): array
    {
        $where = 'WHERE 1 = 1';
        $args = [];
        if (in_array($status, ['open', 'upheld', 'overturned'], true)) { $where .= ' AND a.status = :st'; $args['st'] = $status; }
        if ($status === 'override') $where .= ' AND a.admin_override = 1';
        if ($status === 'over_budget') $where .= ' AND a.over_budget = 1';
        if ($advertiserId !== null) { $where .= ' AND a.advertiser_user_id = :adv'; $args['adv'] = $advertiserId; }
        if ($q !== '') { $where .= ' AND (eu.email LIKE :q OR au.email LIKE :q2 OR c.title LIKE :q3)'; $like = '%' . addcslashes($q, '%_\\') . '%'; $args += ['q' => $like, 'q2' => $like, 'q3' => $like]; }
        $order = $status === 'open' ? 'a.created_at ASC, a.id ASC' : 'a.id DESC';
        $count = "SELECT COUNT(*) FROM submission_appeals a JOIN campaigns c ON c.id = a.campaign_id JOIN users eu ON eu.id = a.earner_user_id LEFT JOIN users au ON au.id = a.advertiser_user_id {$where}";
        return Pagination::query($pdo, $count, self::SELECT . " {$where} ORDER BY {$order}", $args, $page, self::PAGE_SIZE);
    }

    /** Decided appeals on one campaign (advertiser campaign page). */
    public static function forCampaign(PDO $pdo, int $campaignId, int $limit = 50): array
    {
        $stmt = $pdo->prepare(self::SELECT . ' WHERE a.campaign_id = :c ORDER BY a.id DESC LIMIT ' . max(1, $limit));
        $stmt->execute(['c' => $campaignId]);
        return $stmt->fetchAll() ?: [];
    }

    /** Reviewer claims an open appeal for reviews.claim_minutes. */
    public static function claim(PDO $pdo, array $reviewer, int $appealId): string
    {
        $rid = self::requireStaff($reviewer);
        $pdo->beginTransaction();
        try {
            $a = self::lockAppeal($pdo, $appealId);
            if ((string) $a['status'] !== 'open') throw new RuntimeException('This appeal has already been decided.');
            if ((string) $reviewer['role'] === 'reviewer' && (int) ($a['rejected_by_user_id'] ?? 0) === $rid) throw new RuntimeException('You made the original decision, so another reviewer must handle this appeal.');
            $exp = ReviewClaims::claimLocked($pdo, 'appeal', $appealId, $rid);
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
            ReviewClaims::release($pdo, 'appeal', $appealId, $rid);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Decide an open appeal. Reviewers need their own claim; admins decide
     * without one and their decision is final (no further override).
     * @param 'uphold'|'overturn' $decision
     */
    public static function decide(PDO $pdo, array $actor, int $appealId, string $decision, string $note): void
    {
        $actorId = self::requireStaff($actor);
        $isAdmin = (string) $actor['role'] === 'admin';
        $note = self::cleanNote($note);
        if (!in_array($decision, ['uphold', 'overturn'], true)) throw new RuntimeException('Choose uphold or overturn.');
        $a0 = self::find($pdo, $appealId);
        if ($a0 === null) throw new RuntimeException('Appeal not found.');
        Wallet::forUser($pdo, (int) $a0['earner_user_id']);
        $pdo->beginTransaction();
        try {
            Approvals::lockWallets($pdo, [(int) $a0['earner_user_id'], (int) $a0['advertiser_user_id']]);
            $c = Campaigns::lockRow($pdo, (int) $a0['campaign_id']);
            $s = Approvals::lockSubmission($pdo, (int) $a0['submission_id']);
            $a = self::lockAppeal($pdo, $appealId);
            if ((string) $a['status'] !== 'open') throw new RuntimeException('This appeal has already been decided.');
            if ((string) $s['status'] !== 'rejected') throw new RuntimeException('This submission is no longer rejected, so the appeal cannot be decided.');
            if (!$isAdmin) {
                if ((int) ($a['rejected_by_user_id'] ?? 0) === $actorId) throw new RuntimeException('You made the original decision, so another reviewer must handle this appeal.');
                ReviewClaims::assertHeld($pdo, 'appeal', $appealId, $actorId);
            }
            $funding = ['escrow' => 0, 'wallet' => 0, 'platform' => 0, 'over' => false, 'fund_tx' => null, 'approve_tx' => null];
            if ($decision === 'overturn') $funding = self::payOverturn($pdo, $c, $s, $a, $actorId);
            $status = $decision === 'overturn' ? 'overturned' : 'upheld';
            $pdo->prepare('UPDATE submission_appeals SET status = :st, resolution_note = :n, resolved_by_user_id = :u, resolved_at = UTC_TIMESTAMP(), decided_by_role = :role,
                escrow_kobo = :ek, wallet_kobo = :wk, platform_kobo = :pk, over_budget = :ob, funding_transaction_id = :ft, approve_transaction_id = :at,
                admin_override = :ao, override_from_status = :of, overridden_by_user_id = :obu, overridden_at = :oa, override_note = :on WHERE id = :id')
                ->execute(['st' => $status, 'n' => $note, 'u' => $actorId, 'role' => $isAdmin ? 'admin' : 'reviewer',
                    'ek' => $funding['escrow'], 'wk' => $funding['wallet'], 'pk' => $funding['platform'], 'ob' => $funding['over'] ? 1 : 0, 'ft' => $funding['fund_tx'], 'at' => $funding['approve_tx'],
                    'ao' => $isAdmin ? 1 : 0, 'of' => $isAdmin ? 'open' : null, 'obu' => $isAdmin ? $actorId : null, 'oa' => $isAdmin ? gmdate('Y-m-d H:i:s') : null, 'on' => $isAdmin ? $note : null, 'id' => $appealId]);
            ReviewClaims::clear($pdo, 'appeal', $appealId);
            ReviewerActions::record($pdo, $actorId, (string) $actor['role'], $isAdmin ? 'appeal_override_' . $status : 'appeal_' . $status, 'appeal', $appealId, (int) $s['id'], $note);
            Audit::record($pdo, $actorId, ($isAdmin ? 'admin.appeal_' : 'reviewer.appeal_') . $status, 'appeal', $appealId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        self::notifyDecided($pdo, $appealId);
    }

    /**
     * Admin override of a reviewer's decision (final, audited). An open
     * appeal is simply decided by the admin.
     * @param 'uphold'|'overturn' $decision
     */
    public static function override(PDO $pdo, array $admin, int $appealId, string $decision, string $note): void
    {
        if ((string) $admin['role'] !== 'admin') throw new RuntimeException('Only admins can override appeal decisions.');
        $adminId = (int) $admin['id'];
        $note = self::cleanNote($note);
        if (!in_array($decision, ['uphold', 'overturn'], true)) throw new RuntimeException('Choose uphold or overturn.');
        $a0 = self::find($pdo, $appealId);
        if ($a0 === null) throw new RuntimeException('Appeal not found.');
        if ((string) $a0['status'] === 'open') { self::decide($pdo, $admin, $appealId, $decision, $note); return; }
        Wallet::forUser($pdo, (int) $a0['earner_user_id']);
        $pdo->beginTransaction();
        try {
            Approvals::lockWallets($pdo, [(int) $a0['earner_user_id'], (int) $a0['advertiser_user_id']]);
            $c = Campaigns::lockRow($pdo, (int) $a0['campaign_id']);
            $s = Approvals::lockSubmission($pdo, (int) $a0['submission_id']);
            $a = self::lockAppeal($pdo, $appealId);
            if ((int) $a['admin_override'] === 1) throw new RuntimeException('An admin already made the final decision on this appeal.');
            $from = (string) $a['status'];
            if (!in_array($from, ['upheld', 'overturned'], true)) throw new RuntimeException('This appeal cannot be overridden.');
            $to = $decision === 'overturn' ? 'overturned' : 'upheld';
            if ($from === $to) throw new RuntimeException('The appeal is already ' . $to . '.');
            $set = ['st' => $to, 'id' => $appealId, 'u' => $adminId, 'n' => $note, 'of' => $from];
            if ($to === 'overturned') {
                if ((string) $s['status'] !== 'rejected') throw new RuntimeException('This submission is no longer rejected.');
                $f = self::payOverturn($pdo, $c, $s, $a, $adminId);
                $pdo->prepare('UPDATE submission_appeals SET escrow_kobo = :ek, wallet_kobo = :wk, platform_kobo = :pk, over_budget = :ob, funding_transaction_id = :ft, approve_transaction_id = :at WHERE id = :id')
                    ->execute(['ek' => $f['escrow'], 'wk' => $f['wallet'], 'pk' => $f['platform'], 'ob' => $f['over'] ? 1 : 0, 'ft' => $f['fund_tx'], 'at' => $f['approve_tx'], 'id' => $appealId]);
            } else {
                if ((string) $s['status'] !== 'approved') throw new RuntimeException('This submission is no longer approved.');
                $rt = self::reverseOverturn($pdo, $c, $s, $a, $adminId, $note);
                $pdo->prepare('UPDATE submission_appeals SET reverse_transaction_id = :rt WHERE id = :id')->execute(['rt' => $rt, 'id' => $appealId]);
            }
            $pdo->prepare('UPDATE submission_appeals SET status = :st, admin_override = 1, override_from_status = :of, overridden_by_user_id = :u, overridden_at = UTC_TIMESTAMP(), override_note = :n WHERE id = :id')->execute($set);
            ReviewerActions::record($pdo, $adminId, 'admin', 'appeal_override_' . $to, 'appeal', $appealId, (int) $s['id'], $note);
            Audit::record($pdo, $adminId, 'admin.appeal_override_' . $to, 'appeal', $appealId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        self::notifyDecided($pdo, $appealId, true);
    }

    /**
     * Pay an overturned rejection (inside the caller's transaction, wallets,
     * campaign, submission and appeal locked).
     * @return array{escrow:int,wallet:int,platform:int,over:bool,fund_tx:?int,approve_tx:int}
     */
    private static function payOverturn(PDO $pdo, array $c, array $s, array $a, int $actorId): array
    {
        $appealId = (int) $a['id'];
        $cid = (int) $c['id'];
        $price = (int) $s['unit_price_kobo'];
        $unspent = (int) $c['funded_kobo'] - (int) $c['spent_kobo'] - (int) $c['refunded_kobo'];
        $reserved = 0;
        if (in_array((string) $c['status'], ['live', 'paused'], true)) {
            $r = $pdo->prepare("SELECT COUNT(*) FROM task_reservations WHERE campaign_id = :c AND status = 'active' AND expires_at > UTC_TIMESTAMP()");
            $r->execute(['c' => $cid]);
            $reserved = (int) $r->fetchColumn();
        }
        $free = $unspent - ((int) $c['pending_count'] + $reserved) * $price;
        $fromEscrow = max(0, min($price, $free));
        $shortfall = $price - $fromEscrow;
        $fromWallet = 0;
        $fromPlatform = 0;
        $fundTx = null;
        if ($shortfall > 0) {
            $adv = Wallet::forUser($pdo, (int) $c['advertiser_user_id']);
            $fromWallet = max(0, min($shortfall, (int) $adv['available_kobo']));
            $fromPlatform = $shortfall - $fromWallet;
            $legs = [];
            if ($fromWallet > 0) $legs[] = [(int) $adv['available_account_id'], 'debit', $fromWallet];
            if ($fromPlatform > 0) $legs[] = [Ledger::systemAccountId($pdo, Ledger::ADVERTISER_RECEIVABLE), 'debit', $fromPlatform];
            $legs[] = [(int) $c['escrow_account_id'], 'credit', $shortfall];
            $tx = Ledger::post($pdo, 'appeal-fund:' . $appealId, 'appeal_funding', 'Appeal #' . $appealId . ' payout over campaign #' . $cid . ' budget', $legs, 'appeal', $appealId, $actorId);
            $fundTx = $tx['id'];
            $note = 'Appeal #' . $appealId . ' overturned after the budget was used: ' . ($fromWallet > 0 ? Money::format($fromWallet) . ' from the advertiser wallet' : '')
                . ($fromWallet > 0 && $fromPlatform > 0 ? ', ' : '') . ($fromPlatform > 0 ? Money::format($fromPlatform) . ' fronted by YOLOTASK (advertiser debt)' : '');
            $pdo->prepare("INSERT INTO campaign_funding_events (campaign_id, event, amount_kobo, ledger_transaction_id, note) VALUES (:c, 'appeal_topup', :a, :t, :n)")
                ->execute(['c' => $cid, 'a' => $shortfall, 't' => $fundTx, 'n' => mb_substr($note, 0, 255)]);
            $pdo->prepare('UPDATE campaigns SET funded_kobo = funded_kobo + :a WHERE id = :id')->execute(['a' => $shortfall, 'id' => $cid]);
            if ($fromPlatform > 0) AdvertiserDebts::open($pdo, (int) $c['advertiser_user_id'], $cid, $appealId, $fromPlatform, $fundTx);
            Logger::info('appeal.over_budget', ['appeal_id' => $appealId, 'campaign_id' => $cid, 'wallet' => $fromWallet, 'platform' => $fromPlatform]);
        }
        $pay = Approvals::postApproval($pdo, $c, $s, 'appeal-approve:' . $appealId, $actorId);
        Approvals::markApproved($pdo, (int) $s['id'], 'appeal', $actorId, $pay);
        $pdo->prepare('UPDATE campaigns SET approved_count = approved_count + 1, rejected_count = GREATEST(rejected_count, 1) - 1, spent_kobo = spent_kobo + :p, extra_approved_count = extra_approved_count + :x WHERE id = :id')
            ->execute(['p' => $price, 'x' => $shortfall > 0 ? 1 : 0, 'id' => $cid]);
        $c = Approvals::completeIfFull($pdo, $cid);
        Campaigns::settle($pdo, $c, 'appeal settled', $actorId);
        return ['escrow' => $fromEscrow, 'wallet' => $fromWallet, 'platform' => $fromPlatform, 'over' => $shortfall > 0, 'fund_tx' => $fundTx, 'approve_tx' => $pay['tx_id']];
    }

    /** Undo an overturn (admin override to uphold). Returns the reversal transaction id. */
    private static function reverseOverturn(PDO $pdo, array $c, array $s, array $a, int $adminId, string $note): int
    {
        $appealId = (int) $a['id'];
        $cid = (int) $c['id'];
        $price = (int) $s['unit_price_kobo'];
        $reward = (int) $s['unit_reward_kobo'];
        $margin = $price - $reward;
        $earner = Wallet::find($pdo, (int) $s['earner_user_id']);
        if ($earner === null) throw new RuntimeException('Earner wallet missing.');
        $held = (string) $s['reward_status'] === 'held';
        if (!$held && $earner['available_kobo'] < $reward) {
            throw new RuntimeException('The earner no longer has the ' . Money::format($reward) . ' reward in their wallet. Recover it with a wallet adjustment first, then override.');
        }
        $legs = [[$held ? Ledger::systemAccountId($pdo, Ledger::REWARD_HOLD) : $earner['available_account_id'], 'debit', $reward]];
        if ($margin > 0) $legs[] = [Ledger::systemAccountId($pdo, Ledger::REVENUE), 'debit', $margin];
        $legs[] = [(int) $c['escrow_account_id'], 'credit', $price];
        $tx = Ledger::post($pdo, 'appeal-reverse:' . $appealId, 'task_reward_reversal', 'Appeal #' . $appealId . ' overturn reversed by admin', $legs, 'appeal', $appealId, $adminId);
        $pdo->prepare("UPDATE submissions SET status = 'rejected', decision_source = 'appeal', decided_by_user_id = :a, decided_at = UTC_TIMESTAMP(), reject_reason = :r,
            reward_status = 'none', reward_release_at = NULL, reward_released_at = NULL, approve_transaction_id = NULL, release_transaction_id = NULL WHERE id = :id")
            ->execute(['a' => $adminId, 'r' => mb_substr($note, 0, 255), 'id' => $s['id']]);
        $pdo->prepare('UPDATE campaigns SET approved_count = approved_count - 1, rejected_count = rejected_count + 1, spent_kobo = spent_kobo - :p, extra_approved_count = GREATEST(extra_approved_count, :x) - :x2 WHERE id = :id')
            ->execute(['p' => $price, 'x' => (int) $a['over_budget'], 'x2' => (int) $a['over_budget'], 'id' => $cid]);

        // Return any top-up to where it came from.
        $w = (int) $a['wallet_kobo'];
        $d = (int) $a['platform_kobo'];
        if ($w + $d > 0) {
            $adv = Wallet::find($pdo, (int) $c['advertiser_user_id']);
            if ($adv === null) throw new RuntimeException('Advertiser wallet missing.');
            $legs = [[(int) $c['escrow_account_id'], 'debit', $w + $d]];
            $toAdvertiser = $w;
            if ($d > 0) {
                $debtTo = AdvertiserDebts::cancelForAppeal($pdo, $appealId, $adminId);
                if ($debtTo === 'receivable') $legs[] = [Ledger::systemAccountId($pdo, Ledger::ADVERTISER_RECEIVABLE), 'credit', $d];
                elseif ($debtTo === 'adjustments') $legs[] = [Ledger::systemAccountId($pdo, Ledger::ADJUSTMENTS), 'credit', $d];
                else $toAdvertiser += $d; // advertiser already paid the debt
            }
            if ($toAdvertiser > 0) $legs[] = [(int) $adv['available_account_id'], 'credit', $toAdvertiser];
            $ut = Ledger::post($pdo, 'appeal-unfund:' . $appealId, 'appeal_unfund', 'Appeal #' . $appealId . ' charge returned (overturn reversed)', $legs, 'appeal', $appealId, $adminId);
            $pdo->prepare('UPDATE campaigns SET refunded_kobo = refunded_kobo + :r WHERE id = :id')->execute(['r' => $w + $d, 'id' => $cid]);
            $pdo->prepare("INSERT INTO campaign_funding_events (campaign_id, event, amount_kobo, ledger_transaction_id, note) VALUES (:c, 'refund', :a, :t, :n)")
                ->execute(['c' => $cid, 'a' => $w + $d, 't' => $ut['id'], 'n' => 'Appeal #' . $appealId . ' overturn reversed: top-up returned']);
        }
        $c = Campaigns::lockRow($pdo, $cid);
        Campaigns::settle($pdo, $c, 'appeal overturn reversed', $adminId);
        return $tx['id'];
    }

    /** Emails after a decision or override (never fails the decision). */
    private static function notifyDecided(PDO $pdo, int $appealId, bool $override = false): void
    {
        try {
            $a = self::find($pdo, $appealId);
            if ($a === null) return;
            $url = yolotask_portal_url('earner') . '/submission.php?id=' . (int) $a['submission_id'];
            $note = (string) ($override && $a['override_note'] ? $a['override_note'] : $a['resolution_note']);
            if ((string) $a['status'] === 'overturned') {
                self::mail((string) $a['earner_email'], 'Your appeal was successful', 'Appeal overturned', [
                    ($override ? 'A YOLOTASK admin reviewed your appeal again. ' : '') . 'Your proof for "' . $a['campaign_title'] . '" has been approved on appeal.',
                    'Reviewer note: ' . $note,
                    (string) $a['reward_status'] === 'held' ? 'Your ' . Money::format((int) $a['unit_reward_kobo']) . ' reward is on a short hold and then reaches your wallet.' : 'Your ' . Money::format((int) $a['unit_reward_kobo']) . ' reward is in your wallet.',
                ], 'Open my submission', $url);
                if ($a['advertiser_email']) {
                    $lines = ['A YOLOTASK reviewer overturned your rejection of submission #' . (int) $a['submission_id'] . ' on "' . $a['campaign_title'] . '" after the earner appealed.',
                        'Your rejection reason: ' . (string) $a['rejection_reason'], 'Reviewer note: ' . $note];
                    if ((int) $a['wallet_kobo'] > 0) $lines[] = 'Your campaign budget was already used, so ' . Money::format((int) $a['wallet_kobo']) . ' was charged from your wallet for this task.';
                    if ((int) $a['platform_kobo'] > 0) $lines[] = 'Your wallet could not cover ' . Money::format((int) $a['platform_kobo']) . ', so YOLOTASK paid it for you. This amount is owed and will be settled from your wallet.';
                    $lines[] = 'Please check proofs carefully against your instructions before rejecting.';
                    self::mail((string) $a['advertiser_email'], 'A rejection was overturned on appeal', 'Rejection overturned', $lines, 'Open the campaign', yolotask_portal_url('advertiser') . '/campaign.php?id=' . (int) $a['campaign_id'] . '#appeals');
                }
            } else {
                self::mail((string) $a['earner_email'], 'Your appeal was reviewed', 'Rejection upheld', [
                    ($override ? 'A YOLOTASK admin reviewed your appeal again. ' : '') . 'After checking your proof for "' . $a['campaign_title'] . '" against the instructions, the rejection stands.',
                    'Reviewer note: ' . $note,
                    'This decision is final. There are many more tasks waiting in your feed.',
                ], 'Find tasks', yolotask_portal_url('earner') . '/tasks.php');
            }
        } catch (\Throwable $e) {
            Logger::error('appeal.notify_failed', ['appeal_id' => $appealId, 'exception' => get_class($e)]);
        }
    }

    private static function mail(string $to, string $subject, string $heading, array $lines, string $label, string $url): void
    {
        try {
            Mailer::send($to, $subject, $heading, $lines, ['label' => $label, 'url' => $url]);
        } catch (\Throwable $e) {
            Logger::error('appeal.mail_failed', ['exception' => get_class($e)]);
        }
    }

    private static function lockAppeal(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM submission_appeals WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new RuntimeException('Appeal not found.');
        return $row;
    }

    private static function cleanNote(string $note): string
    {
        $note = trim(str_replace(["\r\n", "\r"], "\n", $note));
        if (mb_strlen($note) < 5 || mb_strlen($note) > 500) throw new RuntimeException('Write a decision note of 5 to 500 characters; the earner sees it.');
        return $note;
    }

    private static function requireStaff(array $user): int
    {
        if (!in_array((string) $user['role'], ['reviewer', 'admin'], true)) throw new RuntimeException('Only reviewers and admins can decide appeals.');
        return (int) $user['id'];
    }

    public static function statusLabel(array $a): array
    {
        $label = match ((string) $a['status']) {
            'open' => ['Appeal open', 'badge-warning'],
            'upheld' => ['Rejection upheld', 'badge-danger'],
            'overturned' => ['Overturned on appeal', 'badge-success'],
            default => [ucfirst((string) $a['status']), 'badge-info'],
        };
        if ((int) ($a['admin_override'] ?? 0) === 1 && (string) ($a['override_from_status'] ?? '') !== 'open') $label[0] .= ' (admin override)';
        return $label;
    }
}
