<?php
declare(strict_types=1);
namespace YoloTask\Growth;

use PDO;
use PDOException;
use RuntimeException;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Messaging\Notifications;
use YoloTask\Money\Ledger;
use YoloTask\Money\Money;
use YoloTask\Money\Wallet;
use YoloTask\Security\Audit;
use YoloTask\Security\Crypto;

/**
 * Referrals (Phase 6).
 *
 * Every earner and advertiser has a code; ?ref=CODE on a register page
 * records the referrer once, at sign-up (never changed later).
 *
 * - Referred earner: the referrer earns each active milestone (N approved
 *   tasks = reward) once per referee; milestones stack. Ledger: Dr
 *   system:referral_bonus / Cr referrer available, key
 *   ref-milestone:<referralId>:<milestoneId>.
 * - Referred advertiser: the referrer earns referrals.commission_bps of every
 *   confirmed AfrStar deposit, for life. Ledger: Dr system:referral_commission
 *   / Cr referrer available, key ref-commission:<paymentOrderId>. Admin wallet
 *   credits and adjustments never earn commission.
 * - Abuse: no self-referral, no alias of an existing inbox (gmail dots,
 *   "+tag"). Shared networks are normal in Nigeria (family Wi-Fi, hostels,
 *   carrier-grade NAT), so each referrer has an allowance
 *   (referrals.ip_allowance, default 3) of referred sign-ups per IP address
 *   and per device: sign-ups 1..N from one IP (the referrer's own IP counts
 *   as that IP) or one device are recorded "active" and pay as usual; sign-up
 *   N+1 and later are "flagged". A sign-up on the referrer's own device (same
 *   device AND same network as one of the referrer's sessions in the last 30
 *   days) is always flagged as a likely self-referral. "Device" is the
 *   browser user-agent (hashed): the only device signal the web app has.
 *   Flagged referrals earn nothing until an admin approves them.
 *
 * Payouts run right after the approval / top-up commits (afterApproval,
 * afterTopupCredited) and a cron sweep catches up anything missed; both are
 * idempotent. Nothing here throws into the caller's money path.
 */
final class Referrals
{
    public const BONUS = 'system:referral_bonus';
    public const COMMISSION = 'system:referral_commission';
    public const MAX_BPS = 5000; // 50%
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function enabled(PDO $pdo): bool
    {
        return Settings::get($pdo, 'referrals.enabled', '1') === '1';
    }

    public const MAX_IP_ALLOWANCE = 100;

    /** Referred sign-ups per IP address / device (per referrer) accepted before review. */
    public static function ipAllowance(PDO $pdo): int
    {
        return max(1, min(self::MAX_IP_ALLOWANCE, Settings::int($pdo, 'referrals.ip_allowance')));
    }

    public static function commissionBps(PDO $pdo): int
    {
        return max(0, min(self::MAX_BPS, Settings::int($pdo, 'referrals.commission_bps')));
    }

    /** "5%" / "2.5%" for a basis-point rate. */
    public static function percent(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.') . '%';
    }

    /** Lower-case, "+tag" removed; Gmail/Googlemail also loses dots and becomes gmail.com. */
    public static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) return $email;
        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $plus = strpos($local, '+');
        if ($plus !== false) $local = substr($local, 0, $plus);
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }
        return $local . '@' . $domain;
    }

    public static function cleanCode(mixed $code): ?string
    {
        if (!is_string($code)) return null;
        $code = strtoupper(str_replace('-', '', trim($code))); // v1 codes looked like YOLO-18472E
        return preg_match('/^[A-Z0-9]{4,16}$/', $code) ? $code : null;
    }

    /** The user's referral code, created on first use. */
    public static function codeFor(PDO $pdo, int $userId): string
    {
        $stmt = $pdo->prepare('SELECT referral_code FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $code = $stmt->fetchColumn();
        if (is_string($code) && $code !== '') return $code;
        for ($i = 0; $i < 8; $i++) {
            $new = '';
            for ($j = 0; $j < 8; $j++) $new .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            try {
                $pdo->prepare('UPDATE users SET referral_code = :c WHERE id = :id AND referral_code IS NULL')->execute(['c' => $new, 'id' => $userId]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') continue; // taken, try another
                throw $e;
            }
            $stmt->execute(['id' => $userId]);
            $code = $stmt->fetchColumn();
            if (is_string($code) && $code !== '') return $code;
        }
        throw new RuntimeException('Could not create a referral code.');
    }

    /** Sign-up links for both portals (a referrer may invite earners and advertisers). */
    public static function links(PDO $pdo, int $userId): array
    {
        $code = self::codeFor($pdo, $userId);
        return [
            'code' => $code,
            'earner' => yolotask_portal_url('earner') . '/register.php?ref=' . rawurlencode($code),
            'advertiser' => yolotask_portal_url('advertiser') . '/register.php?ref=' . rawurlencode($code),
        ];
    }

    /** Active earner/advertiser who owns a code, or null. */
    public static function referrerByCode(PDO $pdo, ?string $code): ?array
    {
        $code = self::cleanCode($code);
        if ($code === null) return null;
        $stmt = $pdo->prepare("SELECT id, display_name, email, role FROM users WHERE referral_code = :c AND status = 'active' AND role IN ('earner', 'advertiser')");
        $stmt->execute(['c' => $code]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * After a successful registration: store the normalised email, give the
     * new user a code and, when a valid code came with the sign-up, record
     * the referral. Never throws. Returns the referral status, or null when
     * none was recorded.
     */
    public static function onRegistered(PDO $pdo, int $userId, string $role, string $email, ?string $code): ?string
    {
        try {
            $norm = self::normalizeEmail($email);
            $pdo->prepare('UPDATE users SET email_normalized = :n WHERE id = :id')->execute(['n' => $norm, 'id' => $userId]);
            self::codeFor($pdo, $userId);
            if ($code === null || !self::enabled($pdo) || !in_array($role, ['earner', 'advertiser'], true)) return null;
            $referrer = self::referrerByCode($pdo, $code);
            if ($referrer === null || (int) $referrer['id'] === $userId) return null;
            if (self::normalizeEmail((string) $referrer['email']) === $norm) {
                Logger::info('referral.self_blocked', ['user_id' => $userId]);
                return null;
            }
            // Accounts made outside sign-up (staff invites, imports) may have no stored normalised email yet: normalise those on the fly.
            $alias = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id <> :id AND (email_normalized = :n OR (email_normalized IS NULL AND (CASE
                    WHEN LOWER(SUBSTRING_INDEX(email, '@', -1)) IN ('gmail.com', 'googlemail.com')
                        THEN CONCAT(REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(LOWER(email), '@', 1), '+', 1), '.', ''), '@gmail.com')
                    ELSE CONCAT(SUBSTRING_INDEX(SUBSTRING_INDEX(LOWER(email), '@', 1), '+', 1), '@', LOWER(SUBSTRING_INDEX(email, '@', -1))) END) = :n2))");
            $alias->execute(['n' => $norm, 'n2' => $norm, 'id' => $userId]);
            if ((int) $alias->fetchColumn() > 0) {
                Logger::info('referral.alias_blocked', ['user_id' => $userId]);
                return null;
            }
            $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            $ipHash = Crypto::clientIp() !== '' ? Crypto::hmac(Crypto::clientIp()) : null;
            $agentHash = $ua !== '' ? Crypto::hmac($ua) : null;
            // Lock the referrer row so two sign-ups at the same moment cannot both take the last allowance slot.
            $pdo->beginTransaction();
            try {
                $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE')->execute(['id' => (int) $referrer['id']]);
                $flag = self::abuseFlag($pdo, (int) $referrer['id'], $ipHash, $agentHash);
                $pdo->prepare('INSERT INTO referrals (referrer_user_id, referee_user_id, referee_role, code_used, status, flag_reason, signup_ip_hash, signup_agent_hash, created_at)
                    VALUES (:r, :e, :role, :c, :s, :f, :ip, :ua, UTC_TIMESTAMP())')
                    ->execute(['r' => (int) $referrer['id'], 'e' => $userId, 'role' => $role, 'c' => self::cleanCode($code), 's' => $flag === null ? 'active' : 'flagged', 'f' => $flag, 'ip' => $ipHash, 'ua' => $agentHash]);
                $rid = (int) $pdo->lastInsertId();
                Audit::record($pdo, $userId, $flag === null ? 'referral.recorded' : 'referral.flagged', 'referral', $rid);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            return $flag === null ? 'active' : 'flagged';
        } catch (\Throwable $e) {
            Logger::error('referral.attach_failed', ['user_id' => $userId, 'exception' => get_class($e), 'message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Why a new sign-up must be reviewed, or null when it is accepted.
     * - Same device AND network as one of the referrer's recent sessions:
     *   always flagged (likely self-referral).
     * - IP: earlier referees of this referrer on the same IP, plus the
     *   referrer's own sessions on it, already fill the allowance -> flagged.
     *   (The referrer's own IP is "that IP": it gets the same allowance, it
     *   does not use up a slot.)
     * - Device: earlier referees of this referrer on the same device already
     *   fill the allowance -> flagged.
     * Every earlier referee counts whatever its status (flagged and rejected
     * sign-ups count too), for all time, so "4th and later" really means it.
     */
    private static function abuseFlag(PDO $pdo, int $referrerId, ?string $ipHash, ?string $agentHash): ?string
    {
        $limit = self::ipAllowance($pdo);
        if ($ipHash !== null && $agentHash !== null) {
            $own = $pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id = :u AND ip_hash = :ip AND user_agent_hash = :ua AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)');
            $own->execute(['u' => $referrerId, 'ip' => $ipHash, 'ua' => $agentHash]);
            if ((int) $own->fetchColumn() > 0) return 'Same device and network as the referrer (likely self-referral)';
        }
        if ($ipHash !== null) {
            $o = $pdo->prepare('SELECT COUNT(*) FROM referrals WHERE referrer_user_id = :u AND signup_ip_hash = :ip');
            $o->execute(['u' => $referrerId, 'ip' => $ipHash]);
            $earlier = (int) $o->fetchColumn();
            if ($earlier >= $limit) {
                $mine = $pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id = :u AND ip_hash = :ip AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)');
                $mine->execute(['u' => $referrerId, 'ip' => $ipHash]);
                $where = (int) $mine->fetchColumn() > 0 ? "the referrer's network" : 'one network';
                return 'Sign-up #' . ($earlier + 1) . " from {$where} (IP address); allowance is {$limit}";
            }
        }
        if ($agentHash !== null) {
            $d = $pdo->prepare('SELECT COUNT(*) FROM referrals WHERE referrer_user_id = :u AND signup_agent_hash = :ua');
            $d->execute(['u' => $referrerId, 'ua' => $agentHash]);
            $earlier = (int) $d->fetchColumn();
            if ($earlier >= $limit) return 'Sign-up #' . ($earlier + 1) . " from one device; allowance is {$limit}";
        }
        return null;
    }

    // ------------------------------------------------------------------ milestones

    /** @return list<array> milestones ordered by task count */
    public static function milestones(PDO $pdo, bool $activeOnly = false): array
    {
        $rows = $pdo->query('SELECT m.id, m.task_count, m.reward_kobo, m.is_active, (SELECT COUNT(*) FROM referral_payouts p WHERE p.milestone_id = m.id) payouts
            FROM referral_milestones m' . ($activeOnly ? ' WHERE m.is_active = 1' : '') . ' ORDER BY m.is_active DESC, m.task_count, m.id')->fetchAll() ?: [];
        foreach ($rows as &$r) foreach (['id', 'task_count', 'reward_kobo', 'is_active', 'payouts'] as $k) $r[$k] = (int) $r[$k];
        return $rows;
    }

    public static function saveMilestone(PDO $pdo, int $adminId, ?int $id, int $taskCount, int $rewardKobo, bool $active): int
    {
        if ($taskCount < 1 || $taskCount > 100000) throw new RuntimeException('Task count must be between 1 and 100,000 approved tasks.');
        if ($rewardKobo < 100 || $rewardKobo > 10000000) throw new RuntimeException('Reward must be between ₦1 and ₦100,000.');
        if ($active) {
            $dup = $pdo->prepare('SELECT COUNT(*) FROM referral_milestones WHERE is_active = 1 AND task_count = :t AND id <> :id');
            $dup->execute(['t' => $taskCount, 'id' => $id ?? 0]);
            if ((int) $dup->fetchColumn() > 0) throw new RuntimeException('An active milestone already pays at ' . number_format($taskCount) . ' approved tasks. Edit that one instead.');
        }
        if ($id === null) {
            $pdo->prepare('INSERT INTO referral_milestones (task_count, reward_kobo, is_active, updated_by_user_id) VALUES (:t, :r, :a, :u)')
                ->execute(['t' => $taskCount, 'r' => $rewardKobo, 'a' => $active ? 1 : 0, 'u' => $adminId]);
            $id = (int) $pdo->lastInsertId();
            Audit::record($pdo, $adminId, 'admin.referral_milestone_added', 'referral_milestone', $id);
        } else {
            $stmt = $pdo->prepare('UPDATE referral_milestones SET task_count = :t, reward_kobo = :r, is_active = :a, updated_by_user_id = :u WHERE id = :id');
            $stmt->execute(['t' => $taskCount, 'r' => $rewardKobo, 'a' => $active ? 1 : 0, 'u' => $adminId, 'id' => $id]);
            $chk = $pdo->prepare('SELECT COUNT(*) FROM referral_milestones WHERE id = :id');
            $chk->execute(['id' => $id]);
            if ((int) $chk->fetchColumn() === 0) throw new RuntimeException('Milestone not found.');
            Audit::record($pdo, $adminId, 'admin.referral_milestone_updated', 'referral_milestone', $id);
        }
        return $id;
    }

    public static function deleteMilestone(PDO $pdo, int $adminId, int $id): void
    {
        $used = $pdo->prepare('SELECT COUNT(*) FROM referral_payouts WHERE milestone_id = :id');
        $used->execute(['id' => $id]);
        if ((int) $used->fetchColumn() > 0) throw new RuntimeException('This milestone has already paid referrers, so it is kept for the record. Disable it instead.');
        $stmt = $pdo->prepare('DELETE FROM referral_milestones WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) throw new RuntimeException('Milestone not found.');
        Audit::record($pdo, $adminId, 'admin.referral_milestone_deleted', 'referral_milestone', $id);
    }

    /** Approved tasks of a referee since the referral was recorded. */
    public static function approvedSince(PDO $pdo, int $refereeId, string $since): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE earner_user_id = :u AND status = 'approved' AND decided_at >= :since");
        $stmt->execute(['u' => $refereeId, 'since' => $since]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Right after any approval of this earner's work commits (advertiser,
     * auto, admin, reviewer, appeal overturn). Never throws.
     */
    public static function afterApproval(PDO $pdo, int $earnerId): int
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM referrals WHERE referee_user_id = :u AND referee_role = 'earner' AND status = 'active'");
            $stmt->execute(['u' => $earnerId]);
            $r = $stmt->fetch();
            if (!is_array($r) || !self::enabled($pdo)) return 0;
            return self::payMilestones($pdo, $r);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('referral.after_approval_failed', ['earner_id' => $earnerId, 'exception' => get_class($e), 'message' => $e->getMessage()]);
            return 0;
        }
    }

    /** Pay every reached, unpaid, active milestone for one referral. */
    public static function payMilestones(PDO $pdo, array $referral): int
    {
        $count = self::approvedSince($pdo, (int) $referral['referee_user_id'], (string) $referral['created_at']);
        $n = 0;
        foreach (self::milestones($pdo, true) as $m) {
            if ($m['task_count'] > $count) continue;
            if (self::payMilestone($pdo, (int) $referral['id'], $m, $count)) $n++;
        }
        return $n;
    }

    private static function payMilestone(PDO $pdo, int $referralId, array $m, int $count): bool
    {
        $peek = $pdo->prepare('SELECT referrer_user_id FROM referrals WHERE id = :id');
        $peek->execute(['id' => $referralId]);
        $referrerId = (int) $peek->fetchColumn();
        if ($referrerId <= 0) return false;
        Wallet::forUser($pdo, $referrerId);
        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, $referrerId);
            $r = self::lockReferral($pdo, $referralId);
            if (!self::payable($pdo, $r)) { $pdo->commit(); return false; }
            $done = $pdo->prepare('SELECT id FROM referral_payouts WHERE referral_id = :r AND milestone_id = :m');
            $done->execute(['r' => $referralId, 'm' => $m['id']]);
            if ($done->fetchColumn() !== false) { $pdo->commit(); return false; }
            $amount = (int) $m['reward_kobo'];
            $tx = Ledger::post($pdo, 'ref-milestone:' . $referralId . ':' . $m['id'], 'referral_bonus',
                'Referral bonus: referee reached ' . number_format((int) $m['task_count']) . ' approved tasks (referral #' . $referralId . ')', [
                    [Ledger::systemAccountId($pdo, self::BONUS), 'debit', $amount],
                    [$wallet['available_account_id'], 'credit', $amount],
                ], 'referral', $referralId);
            $pdo->prepare("INSERT INTO referral_payouts (referral_id, referrer_user_id, referee_user_id, kind, milestone_id, milestone_task_count, base_kobo, amount_kobo, status, ledger_transaction_id, created_at)
                VALUES (:r, :rr, :re, 'milestone', :m, :tc, 0, :a, 'paid', :t, UTC_TIMESTAMP())")
                ->execute(['r' => $referralId, 'rr' => $referrerId, 're' => (int) $r['referee_user_id'], 'm' => $m['id'], 'tc' => (int) $m['task_count'], 'a' => $amount, 't' => $tx['id']]);
            $payoutId = (int) $pdo->lastInsertId();
            Audit::record($pdo, null, 'referral.milestone_paid', 'referral_payout', $payoutId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $name = self::maskName(self::displayName($pdo, (int) $r['referee_user_id']));
        Notifications::notify($pdo, $referrerId, 'rewards', 'Referral bonus: ' . Money::format($amount),
            "{$name} reached " . number_format((int) $m['task_count']) . ' approved tasks, so ' . Money::format($amount) . ' was added to your wallet.',
            'referrals.php', 'ref-payout:' . $payoutId, [
                'subject' => 'You earned a ' . Money::format($amount) . ' referral bonus', 'heading' => 'Referral bonus earned',
                'lines' => ["Someone you invited ({$name}) reached " . number_format((int) $m['task_count']) . ' approved tasks on YOLOTASK.', Money::format($amount) . ' has been added to your wallet balance.'],
                'button' => ['label' => 'See your referrals', 'url' => yolotask_portal_url(self::role($pdo, $referrerId)) . '/referrals.php'],
            ]);
        return true;
    }

    /**
     * After an admin reverses an approval (appeal override): no automatic
     * claw-back; milestone payouts the referee no longer qualifies for are
     * flagged for an admin to review. Never throws.
     */
    public static function afterApprovalReversed(PDO $pdo, int $earnerId): int
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM referrals WHERE referee_user_id = :u AND referee_role = 'earner'");
            $stmt->execute(['u' => $earnerId]);
            $r = $stmt->fetch();
            if (!is_array($r)) return 0;
            $count = self::approvedSince($pdo, $earnerId, (string) $r['created_at']);
            $upd = $pdo->prepare("UPDATE referral_payouts SET flag_note = :n, flagged_at = UTC_TIMESTAMP()
                WHERE referral_id = :r AND kind = 'milestone' AND status = 'paid' AND flagged_at IS NULL AND milestone_task_count > :c");
            $upd->execute(['n' => 'An approval was reversed: the referee now has ' . $count . ' approved tasks, below this milestone. Review and reverse if needed.', 'r' => (int) $r['id'], 'c' => $count]);
            if ($upd->rowCount() > 0) Audit::record($pdo, null, 'referral.payout_flagged', 'referral', (int) $r['id']);
            return $upd->rowCount();
        } catch (\Throwable $e) {
            Logger::error('referral.after_reversal_failed', ['earner_id' => $earnerId, 'exception' => get_class($e)]);
            return 0;
        }
    }

    // ------------------------------------------------------------------ commission

    /** Right after a top-up is credited (callback or admin review of an AfrStar payment). Never throws. */
    public static function afterTopupCredited(PDO $pdo, int $orderId): bool
    {
        try {
            if (!self::enabled($pdo)) return false;
            return self::payCommission($pdo, $orderId);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('referral.commission_failed', ['order_id' => $orderId, 'exception' => get_class($e), 'message' => $e->getMessage()]);
            return false;
        }
    }

    private static function payCommission(PDO $pdo, int $orderId): bool
    {
        $stmt = $pdo->prepare("SELECT o.id, o.order_sn, o.user_id, o.status, o.credited_kobo, r.id referral_id, r.referrer_user_id FROM payment_orders o
            JOIN referrals r ON r.referee_user_id = o.user_id AND r.referee_role = 'advertiser' AND r.status = 'active'
            WHERE o.id = :id AND o.status = 'paid' AND o.paid_at >= r.created_at");
        $stmt->execute(['id' => $orderId]);
        $o = $stmt->fetch();
        if (!is_array($o)) return false;
        $referrerId = (int) $o['referrer_user_id'];
        $referralId = (int) $o['referral_id'];
        Wallet::forUser($pdo, $referrerId);
        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, $referrerId);
            $r = self::lockReferral($pdo, $referralId);
            if (!self::payable($pdo, $r)) { $pdo->commit(); return false; }
            $done = $pdo->prepare('SELECT id FROM referral_payouts WHERE payment_order_id = :o');
            $done->execute(['o' => $orderId]);
            if ($done->fetchColumn() !== false) { $pdo->commit(); return false; }
            $base = (int) $o['credited_kobo'];
            $bps = self::commissionBps($pdo);
            $amount = intdiv($base * $bps, 10000);
            $txId = null;
            if ($amount > 0) {
                $tx = Ledger::post($pdo, 'ref-commission:' . $orderId, 'referral_commission',
                    'Referral commission ' . self::percent($bps) . ' of top-up ' . $o['order_sn'] . ' (referral #' . $referralId . ')', [
                        [Ledger::systemAccountId($pdo, self::COMMISSION), 'debit', $amount],
                        [$wallet['available_account_id'], 'credit', $amount],
                    ], 'referral', $referralId);
                $txId = $tx['id'];
            }
            $pdo->prepare("INSERT INTO referral_payouts (referral_id, referrer_user_id, referee_user_id, kind, payment_order_id, base_kobo, rate_bps, amount_kobo, status, ledger_transaction_id, created_at)
                VALUES (:r, :rr, :re, 'commission', :o, :b, :bps, :a, :s, :t, UTC_TIMESTAMP())")
                ->execute(['r' => $referralId, 'rr' => $referrerId, 're' => (int) $o['user_id'], 'o' => $orderId, 'b' => $base, 'bps' => $bps, 'a' => $amount, 's' => $amount > 0 ? 'paid' : 'skipped', 't' => $txId]);
            $payoutId = (int) $pdo->lastInsertId();
            if ($amount > 0) Audit::record($pdo, null, 'referral.commission_paid', 'referral_payout', $payoutId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if ($amount <= 0) return false;
        $name = self::maskName(self::displayName($pdo, (int) $o['user_id']));
        Notifications::notify($pdo, $referrerId, 'rewards', 'Referral commission: ' . Money::format($amount),
            "{$name} topped up " . Money::format($base) . ', so you earned ' . self::percent($bps) . ' (' . Money::format($amount) . ').',
            'referrals.php', 'ref-payout:' . $payoutId, [
                'subject' => 'You earned ' . Money::format($amount) . ' referral commission', 'heading' => 'Referral commission earned',
                'lines' => ["An advertiser you invited ({$name}) funded their wallet with " . Money::format($base) . '.', 'Your ' . self::percent($bps) . ' commission of ' . Money::format($amount) . ' has been added to your wallet balance.'],
                'button' => ['label' => 'See your referrals', 'url' => yolotask_portal_url(self::role($pdo, $referrerId)) . '/referrals.php'],
            ]);
        return true;
    }

    // ------------------------------------------------------------------ sweep, review, reversal

    /**
     * Cron catch-up: milestones reached and confirmed deposits by referred
     * advertisers that have no payout row yet (a crash after commit, a
     * referral approved by an admin, a milestone added later, or the
     * programme switched back on). Idempotent.
     * @return array{milestones:int,commissions:int}
     */
    public static function sweep(PDO $pdo, int $limit = 500): array
    {
        $out = ['milestones' => 0, 'commissions' => 0];
        if (!self::enabled($pdo)) return $out;
        $rows = $pdo->query("SELECT r.* FROM referrals r WHERE r.status = 'active' AND r.referee_role = 'earner' AND EXISTS (
                SELECT 1 FROM referral_milestones m WHERE m.is_active = 1
                AND NOT EXISTS (SELECT 1 FROM referral_payouts p WHERE p.referral_id = r.id AND p.milestone_id = m.id)
                AND m.task_count <= (SELECT COUNT(*) FROM submissions s WHERE s.earner_user_id = r.referee_user_id AND s.status = 'approved' AND s.decided_at >= r.created_at))
            ORDER BY r.id LIMIT " . max(1, $limit))->fetchAll() ?: [];
        foreach ($rows as $r) {
            try {
                $out['milestones'] += self::payMilestones($pdo, $r);
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                Logger::error('referral.sweep_milestone_failed', ['referral_id' => (int) $r['id'], 'exception' => get_class($e), 'message' => $e->getMessage()]);
            }
        }
        $orders = $pdo->query("SELECT o.id FROM payment_orders o JOIN referrals r ON r.referee_user_id = o.user_id AND r.referee_role = 'advertiser' AND r.status = 'active'
            WHERE o.status = 'paid' AND o.paid_at >= r.created_at AND NOT EXISTS (SELECT 1 FROM referral_payouts p WHERE p.payment_order_id = o.id) ORDER BY o.id LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($orders as $oid) {
            if (self::afterTopupCredited($pdo, (int) $oid)) $out['commissions']++;
        }
        return $out;
    }

    /** Admin decision on a flagged referral: approve (payouts start, backlog caught up) or reject (never pays). */
    public static function review(PDO $pdo, int $adminId, int $referralId, string $decision, string $note): void
    {
        $note = trim($note);
        if (!in_array($decision, ['approve', 'reject'], true)) throw new RuntimeException('Choose approve or reject.');
        if (mb_strlen($note) < 3 || mb_strlen($note) > 255) throw new RuntimeException('Add a note (3 to 255 characters).');
        $pdo->beginTransaction();
        try {
            $r = self::lockReferral($pdo, $referralId);
            if ((string) $r['status'] !== 'flagged') throw new RuntimeException('Only flagged referrals can be reviewed.');
            $pdo->prepare('UPDATE referrals SET status = :s, reviewed_by_user_id = :a, reviewed_at = UTC_TIMESTAMP(), review_note = :n WHERE id = :id')
                ->execute(['s' => $decision === 'approve' ? 'active' : 'rejected', 'a' => $adminId, 'n' => $note, 'id' => $referralId]);
            Audit::record($pdo, $adminId, $decision === 'approve' ? 'admin.referral_approved' : 'admin.referral_rejected', 'referral', $referralId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if ($decision === 'approve') {
            if ((string) $r['referee_role'] === 'earner') {
                self::afterApproval($pdo, (int) $r['referee_user_id']);
            } else {
                $o = $pdo->prepare("SELECT id FROM payment_orders WHERE user_id = :u AND status = 'paid' ORDER BY id");
                $o->execute(['u' => (int) $r['referee_user_id']]);
                foreach ($o->fetchAll(PDO::FETCH_COLUMN) ?: [] as $oid) self::afterTopupCredited($pdo, (int) $oid);
            }
        }
    }

    /** Admin reversal of a paid referral payout (ledger reversal, reason kept). */
    public static function reversePayout(PDO $pdo, int $adminId, int $payoutId, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) throw new RuntimeException('A reason of 5 to 255 characters is required.');
        $peek = $pdo->prepare('SELECT referrer_user_id FROM referral_payouts WHERE id = :id');
        $peek->execute(['id' => $payoutId]);
        $referrerId = (int) $peek->fetchColumn();
        if ($referrerId <= 0) throw new RuntimeException('Payout not found.');
        Wallet::forUser($pdo, $referrerId);
        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, $referrerId);
            $lock = $pdo->prepare('SELECT * FROM referral_payouts WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $payoutId]);
            $p = $lock->fetch();
            if ((string) $p['status'] !== 'paid') throw new RuntimeException('Only paid payouts can be reversed.');
            $amount = (int) $p['amount_kobo'];
            if ($wallet['available_kobo'] < $amount) throw new RuntimeException('The referrer no longer has ' . Money::format($amount) . ' available. Recover it with a wallet adjustment first, then reverse.');
            $expense = (string) $p['kind'] === 'milestone' ? self::BONUS : self::COMMISSION;
            $tx = Ledger::post($pdo, 'ref-reverse:' . $payoutId, 'referral_reversal', 'Referral payout #' . $payoutId . ' reversed: ' . $reason, [
                [$wallet['available_account_id'], 'debit', $amount],
                [Ledger::systemAccountId($pdo, $expense), 'credit', $amount],
            ], 'referral_payout', $payoutId, $adminId);
            $pdo->prepare("UPDATE referral_payouts SET status = 'reversed', reverse_transaction_id = :t, reversed_by_user_id = :a, reversed_at = UTC_TIMESTAMP(), reverse_reason = :r WHERE id = :id")
                ->execute(['t' => $tx['id'], 'a' => $adminId, 'r' => $reason, 'id' => $payoutId]);
            Audit::record($pdo, $adminId, 'admin.referral_payout_reversed', 'referral_payout', $payoutId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        Notifications::notify($pdo, $referrerId, 'rewards', 'Referral payout reversed', Money::format($amount) . ' was taken back from your wallet. Reason: ' . $reason,
            'referrals.php', 'ref-reverse:' . $payoutId, [
                'subject' => 'A YOLOTASK referral payout was reversed', 'heading' => 'Referral payout reversed',
                'lines' => ['A referral payout of ' . Money::format($amount) . ' was reversed and taken back from your wallet.', 'Reason: ' . $reason],
                'footnote' => 'Questions? Reply to YOLOTASK support from your account email.',
            ]);
    }

    // ------------------------------------------------------------------ views

    /** The referrer's page: referees (masked), progress and earnings. */
    public static function forReferrer(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare("SELECT r.id, r.referee_user_id, r.referee_role, r.status, r.created_at, u.display_name,
                COALESCE((SELECT SUM(p.amount_kobo) FROM referral_payouts p WHERE p.referral_id = r.id AND p.status = 'paid'), 0) earned,
                (SELECT COUNT(*) FROM submissions s WHERE s.earner_user_id = r.referee_user_id AND s.status = 'approved' AND s.decided_at >= r.created_at) approved,
                (SELECT COUNT(*) FROM payment_orders o WHERE o.user_id = r.referee_user_id AND o.status = 'paid' AND o.paid_at >= r.created_at) deposits
            FROM referrals r JOIN users u ON u.id = r.referee_user_id WHERE r.referrer_user_id = :u ORDER BY r.id DESC LIMIT 200");
        $stmt->execute(['u' => $userId]);
        $milestones = self::milestones($pdo, true);
        $paid = $pdo->prepare("SELECT referral_id, milestone_id FROM referral_payouts WHERE referrer_user_id = :u AND kind = 'milestone'");
        $paid->execute(['u' => $userId]);
        $paidSet = [];
        foreach ($paid->fetchAll() ?: [] as $p) $paidSet[(int) $p['referral_id'] . ':' . (int) $p['milestone_id']] = true;
        $rows = [];
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $next = null;
            if ((string) $r['referee_role'] === 'earner') {
                foreach ($milestones as $m) {
                    if (!isset($paidSet[(int) $r['id'] . ':' . $m['id']]) && $m['task_count'] > (int) $r['approved']) { $next = $m; break; }
                }
            }
            $rows[] = ['id' => (int) $r['id'], 'name' => self::maskName((string) $r['display_name']), 'role' => (string) $r['referee_role'], 'status' => (string) $r['status'],
                'joined' => (string) $r['created_at'], 'approved' => (int) $r['approved'], 'deposits' => (int) $r['deposits'], 'earned' => (int) $r['earned'], 'next' => $next];
        }
        $tot = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN kind = 'milestone' THEN amount_kobo END), 0) m, COALESCE(SUM(CASE WHEN kind = 'commission' THEN amount_kobo END), 0) c
            FROM referral_payouts WHERE referrer_user_id = :u AND status = 'paid'");
        $tot->execute(['u' => $userId]);
        $t = $tot->fetch();
        $recent = $pdo->prepare("SELECT p.id, p.kind, p.amount_kobo, p.status, p.milestone_task_count, p.base_kobo, p.rate_bps, p.created_at, u.display_name
            FROM referral_payouts p JOIN users u ON u.id = p.referee_user_id WHERE p.referrer_user_id = :u AND p.status IN ('paid', 'reversed') ORDER BY p.id DESC LIMIT 20");
        $recent->execute(['u' => $userId]);
        $payouts = $recent->fetchAll() ?: [];
        foreach ($payouts as &$p) $p['display_name'] = self::maskName((string) $p['display_name']);
        return ['referees' => $rows, 'milestone_total' => (int) $t['m'], 'commission_total' => (int) $t['c'], 'payouts' => $payouts, 'milestones' => $milestones];
    }

    public static function payouts(PDO $pdo, string $filter, int $page): array
    {
        $where = match ($filter) {
            'flagged' => "WHERE p.flagged_at IS NOT NULL AND p.status = 'paid'",
            'milestone' => "WHERE p.kind = 'milestone' AND p.status <> 'skipped'",
            'commission' => "WHERE p.kind = 'commission' AND p.status <> 'skipped'",
            'reversed' => "WHERE p.status = 'reversed'",
            default => "WHERE p.status <> 'skipped'",
        };
        return \YoloTask\Support\Pagination::query($pdo, "SELECT COUNT(*) FROM referral_payouts p {$where}",
            "SELECT p.*, ru.display_name referrer_name, ru.email referrer_email, ee.display_name referee_name, ee.email referee_email, o.order_sn
             FROM referral_payouts p JOIN users ru ON ru.id = p.referrer_user_id JOIN users ee ON ee.id = p.referee_user_id LEFT JOIN payment_orders o ON o.id = p.payment_order_id
             {$where} ORDER BY p.id DESC", [], $page, 25);
    }

    public static function flagged(PDO $pdo, int $limit = 50): array
    {
        return $pdo->query("SELECT r.*, ru.display_name referrer_name, ru.email referrer_email, ee.display_name referee_name, ee.email referee_email
            FROM referrals r JOIN users ru ON ru.id = r.referrer_user_id JOIN users ee ON ee.id = r.referee_user_id
            WHERE r.status = 'flagged' ORDER BY r.id LIMIT " . max(1, $limit))->fetchAll() ?: [];
    }

    public static function adminStats(PDO $pdo): array
    {
        $r = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(status = 'flagged'), 0) flagged, COALESCE(SUM(referee_role = 'earner'), 0) earners, COALESCE(SUM(referee_role = 'advertiser'), 0) advertisers FROM referrals")->fetch();
        $sys = [];
        foreach ($pdo->query("SELECT code, balance_kobo FROM ledger_accounts WHERE code IN ('system:referral_bonus', 'system:referral_commission')") as $row) $sys[(string) $row['code']] = (int) $row['balance_kobo'];
        $fp = (int) $pdo->query("SELECT COUNT(*) FROM referral_payouts WHERE flagged_at IS NOT NULL AND status = 'paid'")->fetchColumn();
        return ['referrals' => (int) $r['n'], 'flagged' => (int) $r['flagged'], 'earners' => (int) $r['earners'], 'advertisers' => (int) $r['advertisers'],
            'bonus' => $sys[self::BONUS] ?? 0, 'commission' => $sys[self::COMMISSION] ?? 0, 'flagged_payouts' => $fp];
    }

    /** "Ada Lovelace" -> "Ad*** L." (referrers never see referees' full names or emails). */
    public static function maskName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if ($parts === [] || $parts[0] === '') return 'Member';
        $first = mb_substr($parts[0], 0, 2) . '***';
        $last = count($parts) > 1 ? ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.' : '';
        return $first . $last;
    }

    /**
     * Reconciliation (daily cron / admin button): the referral expense
     * accounts equal the payouts still standing, and every payout row agrees
     * with its ledger transactions.
     * @return list<string>
     */
    public static function reconcile(PDO $pdo): array
    {
        if (!(bool) $pdo->query("SHOW TABLES LIKE 'referral_payouts'")->fetchColumn()) return [];
        $problems = [];
        foreach (['milestone' => self::BONUS, 'commission' => self::COMMISSION] as $kind => $code) {
            $bal = (int) $pdo->query("SELECT COALESCE(MAX(balance_kobo), 0) FROM ledger_accounts WHERE code = '{$code}'")->fetchColumn();
            $sum = (int) $pdo->query("SELECT COALESCE(SUM(amount_kobo), 0) FROM referral_payouts WHERE kind = '{$kind}' AND status = 'paid'")->fetchColumn();
            if ($bal !== $sum) $problems[] = "Account {$code} {$bal} != paid referral {$kind} payouts {$sum}";
        }
        $sql = "SELECT p.id FROM referral_payouts p LEFT JOIN ledger_transactions t ON t.id = p.ledger_transaction_id LEFT JOIN ledger_transactions rt ON rt.id = p.reverse_transaction_id
                WHERE (p.status IN ('paid', 'reversed') AND (t.id IS NULL OR t.amount_kobo <> p.amount_kobo OR p.amount_kobo <= 0))
                   OR (p.status = 'reversed' AND (rt.id IS NULL OR rt.amount_kobo <> p.amount_kobo))
                   OR (p.status = 'skipped' AND (p.ledger_transaction_id IS NOT NULL OR p.amount_kobo <> 0)) LIMIT 50";
        foreach ($pdo->query($sql) as $r) $problems[] = "Referral payout #{$r['id']} does not match its ledger transactions";
        $sql = "SELECT p.id FROM referral_payouts p JOIN payment_orders o ON o.id = p.payment_order_id WHERE p.kind = 'commission' AND (o.status <> 'paid' OR p.base_kobo <> o.credited_kobo) LIMIT 50";
        foreach ($pdo->query($sql) as $r) $problems[] = "Referral commission #{$r['id']} is not based on a credited top-up";
        return $problems;
    }

    private static function payable(PDO $pdo, array $r): bool
    {
        if ((string) $r['status'] !== 'active' || !self::enabled($pdo)) return false;
        $u = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id IN (:a, :b) AND status = 'active'");
        $u->execute(['a' => (int) $r['referrer_user_id'], 'b' => (int) $r['referee_user_id']]);
        return (int) $u->fetchColumn() === 2;
    }

    private static function lockReferral(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM referrals WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new RuntimeException('Referral not found.');
        return $row;
    }

    private static function displayName(PDO $pdo, int $userId): string
    {
        $stmt = $pdo->prepare('SELECT display_name FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return (string) $stmt->fetchColumn();
    }

    private static function role(PDO $pdo, int $userId): string
    {
        $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return (string) $stmt->fetchColumn();
    }
}
