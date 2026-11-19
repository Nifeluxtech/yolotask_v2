<?php
declare(strict_types=1);
namespace YoloTask\Jobs;

use PDO;
use RuntimeException;
use YoloTask\Campaigns\Campaigns;
use YoloTask\Core\Settings;
use YoloTask\Money\Ledger;
use YoloTask\Money\Money;
use YoloTask\Money\Wallet;
use YoloTask\Security\Audit;
use YoloTask\Support\Pagination;

/**
 * Jobs: multi-step tasks written entirely by the advertiser (migration 0023).
 *
 * Lifecycle: live <-> paused (advertiser; an admin pause cannot be resumed by
 * the advertiser), then closed (advertiser), removed (admin) or completed
 * (every funded slot approved). No draft and no admin approval: publishing
 * locks pay x slots into system:jobs_escrow and the job is live.
 *
 * Slots: `slots` is how many earners the job is currently funded for.
 * active_count = attempts in progress, submitted or sent back; a slot is free
 * when slots - approved_count - active_count > 0. A rejected, expired,
 * abandoned or cancelled attempt gives its slot back. On a closed / removed
 * job every slot not approved and not active is refunded at once (settle());
 * each active attempt that later ends without approval refunds its slot.
 *
 * Money invariant per job: funded - spent - refunded = pay x (slots - approved).
 *
 * YOLOTASK jobs (migration 0024, is_system = 1): posted by an admin, owned by
 * the closed system account "YOLOTASK" (platform_tasks.owner_user_id), like
 * YOLOTASK tasks. No wallet and no escrow: publishing moves no money,
 * funded / spent / refunded stay 0, slots is only a cap and closing refunds
 * nothing. The earner gets the full pay (earner_percent 100, fee 0), paid by
 * the platform from system:platform_job_payouts on approval (Reviews). Pay may
 * be as low as ₦1 (jobs.min_pay_kobo does not apply). Admins review them in
 * Admin -> Jobs and reviewers in their "YOLOTASK jobs" queue (SystemReviews).
 */
final class Jobs
{
    public const PAGE_SIZE = 20;
    public const MAX_SLOTS = 10000;
    public const MAX_PAY_KOBO = 100000000;      // ₦1,000,000 per earner
    public const OPEN = ['live', 'paused'];
    public const CLOSED = ['closed', 'completed', 'removed'];
    public const PROOF_KINDS = ['image' => 'Screenshot / image', 'text' => 'Text answer', 'link' => 'Link', 'file' => 'File'];
    public const SYSTEM_MIN_PAY_KOBO = 100;     // YOLOTASK jobs: at least ₦1

    // ---------------------------------------------------------------- settings

    public static function enabled(PDO $pdo): bool
    {
        return Settings::int($pdo, 'jobs.enabled') === 1;
    }

    public static function minPay(PDO $pdo): int
    {
        return max(100, Settings::int($pdo, 'jobs.min_pay_kobo'));
    }

    public static function earnerPercent(PDO $pdo): int
    {
        return max(50, min(100, Settings::int($pdo, 'jobs.earner_percent')));
    }

    public static function maxSteps(PDO $pdo): int
    {
        return max(2, min(50, Settings::int($pdo, 'jobs.max_steps')));
    }

    /** @return array{min:int,max:int} hours */
    public static function timeLimitBounds(PDO $pdo): array
    {
        $min = max(1, Settings::int($pdo, 'jobs.time_limit_min_hours'));
        $max = max($min, min(8760, Settings::int($pdo, 'jobs.time_limit_max_hours')));
        return ['min' => $min, 'max' => $max];
    }

    public static function sendBackMaxRounds(PDO $pdo): int
    {
        return max(0, min(5, Settings::int($pdo, 'jobs.send_back_max_rounds')));
    }

    public static function fixHours(PDO $pdo): int
    {
        return max(1, min(336, Settings::int($pdo, 'jobs.send_back_fix_hours')));
    }

    public static function maxActivePerEarner(PDO $pdo): int
    {
        return max(1, min(10, Settings::int($pdo, 'jobs.max_active_per_earner')));
    }

    /** Is this a YOLOTASK job (migration 0024)? */
    public static function isSystem(array $job): bool
    {
        return (int) ($job['is_system'] ?? 0) === 1;
    }

    /** The closed system account "YOLOTASK" that owns YOLOTASK tasks and jobs. */
    public static function systemOwnerId(PDO $pdo): int
    {
        return \YoloTask\PlatformTasks\PlatformTasks::ownerUserId($pdo);
    }

    /** "YOLOTASK" for a YOLOTASK job, "the advertiser" otherwise (for messages to earners). */
    public static function reviewerLabel(array $job): string
    {
        return self::isSystem($job) ? 'YOLOTASK' : 'the advertiser';
    }

    /** Earner share (rounded down to the kobo) and platform share of a pay. */
    public static function split(int $payKobo, int $earnerPercent): array
    {
        $earner = intdiv($payKobo * max(0, min(100, $earnerPercent)), 100);
        return ['earner' => $earner, 'fee' => $payKobo - $earner];
    }

    /** @return list<string> */
    public static function kinds(string $csv): array
    {
        return array_values(array_filter(explode(',', $csv), static fn (string $k): bool => isset(self::PROOF_KINDS[$k])));
    }

    // ---------------------------------------------------------------- create

    /**
     * Validate the advertiser's form.
     * $in: title, instructions, link_url, mode, location, pay (naira), slots,
     * time_limit (number), time_unit (hours|days), review_hours,
     * steps: list of [title, instructions, kinds: list<string>].
     * $system: a YOLOTASK job (minimum pay ₦1 instead of jobs.min_pay_kobo).
     */
    public static function validate(PDO $pdo, array $in, bool $system = false): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 120) throw new RuntimeException('Give the job a name of 3 to 120 characters.');
        $instructions = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['instructions'] ?? '')));
        if (mb_strlen($instructions) < 10 || mb_strlen($instructions) > 5000) throw new RuntimeException('Write the overall instructions (10 to 5,000 characters).');
        $link = trim((string) ($in['link_url'] ?? ''));
        if ($link !== '') {
            $parts = parse_url($link);
            if (strlen($link) > 500 || !is_array($parts) || empty($parts['host']) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $link)) {
                throw new RuntimeException('The link must be a full http(s):// address, or leave it empty.');
            }
        }
        $mode = (string) ($in['mode'] ?? 'online') === 'offline' ? 'offline' : 'online';
        $location = trim((string) ($in['location'] ?? ''));
        if ($mode === 'offline') {
            if (mb_strlen($location) < 3 || mb_strlen($location) > 160) throw new RuntimeException('Offline jobs need a location earners can see: state, city or address area (3 to 160 characters).');
        } else {
            $location = '';
        }
        try {
            $pay = Money::toKobo((string) ($in['pay'] ?? ''));
        } catch (\InvalidArgumentException) {
            throw new RuntimeException('Enter the pay per earner in naira, for example 500.');
        }
        $min = $system ? self::SYSTEM_MIN_PAY_KOBO : self::minPay($pdo);
        if ($pay < $min) throw new RuntimeException('Pay per earner must be at least ' . Money::format($min) . '.');
        if ($pay > self::MAX_PAY_KOBO) throw new RuntimeException('Pay per earner can be at most ' . Money::format(self::MAX_PAY_KOBO) . '.');
        $slots = self::wholeNumber($in['slots'] ?? '', 'Enter how many earners (slots) you need.');
        if ($slots < 1 || $slots > self::MAX_SLOTS) throw new RuntimeException('Slots must be between 1 and ' . number_format(self::MAX_SLOTS) . '.');
        $limit = self::wholeNumber($in['time_limit'] ?? '', 'Enter how long an earner has to finish once they start.');
        $hours = (string) ($in['time_unit'] ?? 'hours') === 'days' ? $limit * 24 : $limit;
        $b = self::timeLimitBounds($pdo);
        if ($hours < $b['min'] || $hours > $b['max']) throw new RuntimeException('The time limit must be between ' . self::hoursLabel($b['min']) . ' and ' . self::hoursLabel($b['max']) . '.');
        $rb = Campaigns::autoApproveBounds($pdo);
        $review = self::wholeNumber($in['review_hours'] ?? '', 'Choose your review window in hours.');
        if ($review < $rb['min'] || $review > $rb['max']) throw new RuntimeException("Your review window must be between {$rb['min']} and {$rb['max']} hours.");

        $raw = is_array($in['steps'] ?? null) ? array_values($in['steps']) : [];
        $steps = [];
        foreach ($raw as $s) {
            if (!is_array($s)) continue;
            $t = trim((string) ($s['title'] ?? ''));
            $i = trim(str_replace(["\r\n", "\r"], "\n", (string) ($s['instructions'] ?? '')));
            $k = is_array($s['kinds'] ?? null) ? array_values(array_unique(array_filter(array_map('strval', $s['kinds']), static fn (string $x): bool => isset(self::PROOF_KINDS[$x])))) : [];
            if ($t === '' && $i === '' && $k === []) continue; // an untouched empty step block
            $n = count($steps) + 1;
            if (mb_strlen($t) < 2 || mb_strlen($t) > 120) throw new RuntimeException("Step {$n}: give it a title of 2 to 120 characters.");
            if (mb_strlen($i) < 5 || mb_strlen($i) > 3000) throw new RuntimeException("Step {$n}: write its instructions (5 to 3,000 characters).");
            if ($k === []) throw new RuntimeException("Step {$n}: choose at least one kind of proof.");
            $ordered = array_values(array_intersect(array_keys(self::PROOF_KINDS), $k));
            $steps[] = ['title' => $t, 'instructions' => $i, 'kinds' => $ordered];
        }
        $max = self::maxSteps($pdo);
        if (count($steps) < 2) throw new RuntimeException('A job needs at least 2 steps.');
        if (count($steps) > $max) throw new RuntimeException("A job can have at most {$max} steps.");
        return ['title' => $title, 'instructions' => $instructions, 'link_url' => $link === '' ? null : $link, 'mode' => $mode, 'location' => $location === '' ? null : $location,
            'pay' => $pay, 'slots' => $slots, 'time_limit_hours' => $hours, 'review_hours' => $review, 'steps' => $steps];
    }

    /** Publish: validate, lock pay x slots from the wallet into system:jobs_escrow, go live. Returns the job id. */
    public static function publish(PDO $pdo, array $user, array $in): int
    {
        if ((string) $user['role'] !== 'advertiser') throw new RuntimeException('Only advertiser accounts can post jobs.');
        if (!self::enabled($pdo)) throw new RuntimeException('Jobs are switched off right now.');
        if (empty($user['email_verified_at'])) throw new RuntimeException('Confirm your email before posting jobs.');
        $f = self::validate($pdo, $in);
        $uid = (int) $user['id'];
        $percent = self::earnerPercent($pdo);
        $split = self::split($f['pay'], $percent);
        $total = $f['pay'] * $f['slots'];
        Wallet::forUser($pdo, $uid);
        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, $uid);
            if ((string) $wallet['status'] !== 'active') throw new RuntimeException('Your wallet is on hold. Contact support.');
            if ($wallet['available_kobo'] < $total) {
                throw new RuntimeException('This job costs ' . Money::format($total) . ' (' . Money::format($f['pay']) . ' x ' . number_format($f['slots']) . ') and your wallet has ' . Money::format($wallet['available_kobo']) . '. Top up ' . Money::format($total - $wallet['available_kobo']) . ' to publish it.');
            }
            $pdo->prepare("INSERT INTO jobs (advertiser_user_id, title, instructions, link_url, mode, location, step_count, pay_kobo, earner_percent, earner_kobo, fee_kobo, slots, time_limit_hours, review_hours, status, published_at)
                VALUES (:a, :t, :i, :l, :m, :loc, :sc, :p, :ep, :ek, :fk, 0, :tl, :rh, 'live', UTC_TIMESTAMP())")
                ->execute(['a' => $uid, 't' => $f['title'], 'i' => $f['instructions'], 'l' => $f['link_url'], 'm' => $f['mode'], 'loc' => $f['location'], 'sc' => count($f['steps']),
                    'p' => $f['pay'], 'ep' => $percent, 'ek' => $split['earner'], 'fk' => $split['fee'], 'tl' => $f['time_limit_hours'], 'rh' => $f['review_hours']]);
            $id = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO job_steps (job_id, position, title, instructions, proof_kinds) VALUES (:j, :p, :t, :i, :k)');
            foreach ($f['steps'] as $n => $s) $ins->execute(['j' => $id, 'p' => $n + 1, 't' => $s['title'], 'i' => $s['instructions'], 'k' => implode(',', $s['kinds'])]);
            $job = self::lockRow($pdo, $id);
            self::fund($pdo, $job, $wallet, $f['slots'], 'Job published', $uid);
            Audit::record($pdo, $uid, 'job.published', 'job', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    /**
     * Publish a YOLOTASK job (admin or script): owned by the system account,
     * earner gets the full pay, slots is a cap, no money moves. Returns the job id.
     */
    public static function publishSystem(PDO $pdo, ?int $adminId, array $in): int
    {
        $f = self::validate($pdo, $in, true);
        $owner = self::systemOwnerId($pdo);
        Wallet::forUser($pdo, $owner); // decisions lock the owner's wallet row like any job's
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO jobs (advertiser_user_id, is_system, title, instructions, link_url, mode, location, step_count, pay_kobo, earner_percent, earner_kobo, fee_kobo, slots, time_limit_hours, review_hours, status, published_at)
                VALUES (:a, 1, :t, :i, :l, :m, :loc, :sc, :p, 100, :ek, 0, :s, :tl, :rh, 'live', UTC_TIMESTAMP())")
                ->execute(['a' => $owner, 't' => $f['title'], 'i' => $f['instructions'], 'l' => $f['link_url'], 'm' => $f['mode'], 'loc' => $f['location'], 'sc' => count($f['steps']),
                    'p' => $f['pay'], 'ek' => $f['pay'], 's' => $f['slots'], 'tl' => $f['time_limit_hours'], 'rh' => $f['review_hours']]);
            $id = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO job_steps (job_id, position, title, instructions, proof_kinds) VALUES (:j, :p, :t, :i, :k)');
            foreach ($f['steps'] as $n => $s) $ins->execute(['j' => $id, 'p' => $n + 1, 't' => $s['title'], 'i' => $s['instructions'], 'k' => implode(',', $s['kinds'])]);
            Audit::record($pdo, $adminId, 'admin.system_job_published', 'job', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    /** A YOLOTASK job by id (refuses advertisers' jobs). */
    public static function systemJob(PDO $pdo, int $id): array
    {
        $job = self::find($pdo, $id);
        if ($job === null || !self::isSystem($job)) throw new RuntimeException('YOLOTASK job not found.');
        return $job;
    }

    /** Admin pauses or resumes a YOLOTASK job (no reason needed: there is no advertiser to tell). */
    public static function systemSetPaused(PDO $pdo, int $adminId, int $id, bool $pause): void
    {
        self::systemJob($pdo, $id);
        $pdo->beginTransaction();
        try {
            $job = self::lockRow($pdo, $id);
            if ((string) $job['status'] !== ($pause ? 'live' : 'paused')) throw new RuntimeException($pause ? 'Only live jobs can be paused.' : 'Only paused jobs can be resumed.');
            $pdo->prepare('UPDATE jobs SET status = :s, admin_paused = 0, status_reason = NULL WHERE id = :id')->execute(['s' => $pause ? 'paused' : 'live', 'id' => $id]);
            Audit::record($pdo, $adminId, $pause ? 'admin.system_job_paused' : 'admin.system_job_resumed', 'job', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Admin closes a YOLOTASK job: no new starts; work already started can still be submitted and decided. Nothing is refunded. */
    public static function systemClose(PDO $pdo, int $adminId, int $id): void
    {
        self::systemJob($pdo, $id);
        self::closeWith($pdo, $adminId, $id, 'closed', 'Closed by YOLOTASK', 'admin.system_job_closed');
    }

    /** Lock more pay into escrow (inside the caller's transaction; wallet and job locked). */
    private static function fund(PDO $pdo, array $job, array $wallet, int $slots, string $note, int $actorId): int
    {
        $id = (int) $job['id'];
        $amount = (int) $job['pay_kobo'] * $slots;
        $seq = (int) $job['fund_seq'] + 1;
        $tx = Ledger::post($pdo, 'job-fund:' . $id . ':' . $seq, 'job_fund', 'Job #' . $id . ' funded: ' . number_format($slots) . ' slot' . ($slots === 1 ? '' : 's'), [
            [(int) $wallet['available_account_id'], 'debit', $amount],
            [Ledger::systemAccountId($pdo, Ledger::JOBS_ESCROW), 'credit', $amount],
        ], 'job', $id, $actorId);
        $pdo->prepare('UPDATE jobs SET funded_kobo = funded_kobo + :a, slots = slots + :s, fund_seq = :q WHERE id = :id')->execute(['a' => $amount, 's' => $slots, 'q' => $seq, 'id' => $id]);
        $pdo->prepare("INSERT INTO job_funding_events (job_id, event, slots, amount_kobo, ledger_transaction_id, note) VALUES (:j, 'lock', :s, :a, :t, :n)")
            ->execute(['j' => $id, 's' => $slots, 'a' => $amount, 't' => $tx['id'], 'n' => $note]);
        return $amount;
    }

    /** Advertiser adds slots to a live or paused job (locks pay x n more). */
    public static function addSlots(PDO $pdo, array $user, int $id, int $n): int
    {
        $uid = (int) $user['id'];
        self::ownedBy($pdo, $id, $uid);
        if ($n < 1 || $n > self::MAX_SLOTS) throw new RuntimeException('Add between 1 and ' . number_format(self::MAX_SLOTS) . ' slots.');
        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, $uid);
            $job = self::lockRow($pdo, $id);
            if (!in_array((string) $job['status'], self::OPEN, true)) throw new RuntimeException('Only live or paused jobs can take more slots.');
            if ((int) $job['slots'] + $n > self::MAX_SLOTS) throw new RuntimeException('A job can have at most ' . number_format(self::MAX_SLOTS) . ' slots.');
            $amount = (int) $job['pay_kobo'] * $n;
            if ($wallet['available_kobo'] < $amount) throw new RuntimeException(number_format($n) . ' more slot' . ($n === 1 ? '' : 's') . ' cost ' . Money::format($amount) . ' and your wallet has ' . Money::format($wallet['available_kobo']) . '.');
            self::fund($pdo, $job, $wallet, $n, 'Slots added', $uid);
            Audit::record($pdo, $uid, 'job.slots_added', 'job', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $amount;
    }

    public static function pause(PDO $pdo, array $user, int $id): void
    {
        self::transition($pdo, (int) $user['id'], $id, 'live', 'paused', 'job.paused');
    }

    public static function resume(PDO $pdo, array $user, int $id): void
    {
        self::transition($pdo, (int) $user['id'], $id, 'paused', 'live', 'job.resumed');
    }

    private static function transition(PDO $pdo, int $uid, int $id, string $from, string $to, string $event): void
    {
        self::ownedBy($pdo, $id, $uid);
        $pdo->beginTransaction();
        try {
            $job = self::lockRow($pdo, $id);
            if ((string) $job['status'] !== $from) throw new RuntimeException($to === 'paused' ? 'Only live jobs can be paused.' : 'Only paused jobs can be resumed.');
            if ($to === 'live' && (int) $job['admin_paused'] === 1) throw new RuntimeException('YOLOTASK paused this job' . ($job['status_reason'] ? ': ' . $job['status_reason'] : '') . '. Contact support to resume it.');
            if ($to === 'live' && !self::enabled($pdo)) throw new RuntimeException('Jobs are switched off right now.');
            $pdo->prepare('UPDATE jobs SET status = :s WHERE id = :id')->execute(['s' => $to, 'id' => $id]);
            Audit::record($pdo, $uid, $event, 'job', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Advertiser closes a job: unused slots are refunded now; work in progress or under review stays reserved until resolved. */
    public static function close(PDO $pdo, array $user, int $id): int
    {
        $uid = (int) $user['id'];
        self::ownedBy($pdo, $id, $uid);
        return self::closeWith($pdo, $uid, $id, 'closed', 'Closed by the advertiser', 'job.closed');
    }

    /** Admin pause (advertiser cannot resume) or lift it. */
    public static function adminPause(PDO $pdo, int $adminId, int $id, bool $pause, string $reason = ''): void
    {
        $reason = trim($reason);
        if ($pause && (mb_strlen($reason) < 3 || mb_strlen($reason) > 255)) throw new RuntimeException('Give a reason (3 to 255 characters); the advertiser sees it.');
        $pdo->beginTransaction();
        try {
            $job = self::lockRow($pdo, $id);
            if (!in_array((string) $job['status'], self::OPEN, true)) throw new RuntimeException('Only live or paused jobs can be paused.');
            if ($pause) {
                $pdo->prepare("UPDATE jobs SET status = 'paused', admin_paused = 1, status_reason = :r WHERE id = :id")->execute(['r' => $reason, 'id' => $id]);
            } else {
                $pdo->prepare("UPDATE jobs SET status = 'live', admin_paused = 0, status_reason = NULL WHERE id = :id")->execute(['id' => $id]);
            }
            Audit::record($pdo, $adminId, $pause ? 'admin.job_paused' : 'admin.job_unpaused', 'job', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Admin removes a job: in-progress attempts (not yet submitted) are
     * cancelled, unused escrow goes back to the advertiser; submitted and
     * sent-back attempts stay until they are decided or expire.
     */
    public static function adminRemove(PDO $pdo, int $adminId, int $id, string $reason): int
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) throw new RuntimeException('Give a reason (3 to 255 characters); the advertiser sees it.');
        $job = self::find($pdo, $id);
        if ($job === null) throw new RuntimeException('Job not found.');
        $pdo->beginTransaction();
        try {
            Wallet::lock($pdo, (int) $job['advertiser_user_id']);
            $row = self::lockRow($pdo, $id);
            if (!in_array((string) $row['status'], self::OPEN, true)) throw new RuntimeException('This job is already closed.');
            $stmt = $pdo->prepare("SELECT id, earner_user_id FROM job_attempts WHERE job_id = :j AND status = 'in_progress' FOR UPDATE");
            $stmt->execute(['j' => $id]);
            $cancelled = $stmt->fetchAll() ?: [];
            foreach ($cancelled as $a) {
                $pdo->prepare("UPDATE job_attempts SET status = 'cancelled', ended_reason = :r WHERE id = :id")->execute(['r' => 'Job removed by YOLOTASK', 'id' => $a['id']]);
            }
            if ($cancelled !== []) $pdo->prepare('UPDATE jobs SET active_count = active_count - :n WHERE id = :id')->execute(['n' => count($cancelled), 'id' => $id]);
            $pdo->prepare("UPDATE jobs SET status = 'removed', status_reason = :r, admin_paused = 0, closed_at = UTC_TIMESTAMP() WHERE id = :id")->execute(['r' => $reason, 'id' => $id]);
            $refund = self::settle($pdo, self::lockRow($pdo, $id), 'job removed by YOLOTASK', $adminId);
            Audit::record($pdo, $adminId, 'admin.job_removed', 'job', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        foreach ($cancelled as $a) {
            \YoloTask\Messaging\Notifications::notify($pdo, (int) $a['earner_user_id'], 'jobs', 'Job removed: ' . mb_substr((string) $job['title'], 0, 90),
                'YOLOTASK removed this job, so your attempt was cancelled. Nothing was charged to you.', 'jobs.php?tab=mine', 'job-cancel:' . (int) $a['id']);
        }
        return $refund;
    }

    private static function closeWith(PDO $pdo, int $actorId, int $id, string $to, string $reason, string $event): int
    {
        $job = self::find($pdo, $id);
        if ($job === null) throw new RuntimeException('Job not found.');
        $pdo->beginTransaction();
        try {
            Wallet::lock($pdo, (int) $job['advertiser_user_id']);
            $row = self::lockRow($pdo, $id);
            if (!in_array((string) $row['status'], self::OPEN, true)) throw new RuntimeException('This job is already closed.');
            $pdo->prepare('UPDATE jobs SET status = :s, status_reason = :r, admin_paused = 0, closed_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['s' => $to, 'r' => $reason, 'id' => $id]);
            $refund = self::settle($pdo, self::lockRow($pdo, $id), mb_strtolower($reason), $actorId);
            Audit::record($pdo, $actorId, $event, 'job', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $refund;
    }

    /**
     * Refund every slot a closed job no longer needs (neither approved nor
     * active). Caller holds the advertiser wallet lock and the job row lock
     * ($job is that row). Returns the amount refunded. A YOLOTASK job has no
     * escrow: its cap just shrinks to what approved and active work holds.
     */
    public static function settle(PDO $pdo, array $job, string $note, ?int $actorId = null): int
    {
        if (!in_array((string) $job['status'], self::CLOSED, true)) return 0;
        $id = (int) $job['id'];
        $keep = (int) $job['approved_count'] + (int) $job['active_count'];
        $excess = (int) $job['slots'] - $keep;
        if ($excess <= 0) return 0;
        if (self::isSystem($job)) {
            $pdo->prepare('UPDATE jobs SET slots = :k WHERE id = :id')->execute(['k' => $keep, 'id' => $id]);
            return 0;
        }
        $refund = $excess * (int) $job['pay_kobo'];
        $wallet = Wallet::find($pdo, (int) $job['advertiser_user_id']);
        if ($wallet === null) throw new RuntimeException('Advertiser wallet missing.');
        $seq = (int) $job['refund_seq'] + 1;
        $tx = Ledger::post($pdo, 'job-refund:' . $id . ':' . $seq, 'job_refund', 'Job #' . $id . ' refund: ' . $note, [
            [Ledger::systemAccountId($pdo, Ledger::JOBS_ESCROW), 'debit', $refund],
            [(int) $wallet['available_account_id'], 'credit', $refund],
        ], 'job', $id, $actorId);
        $pdo->prepare('UPDATE jobs SET refunded_kobo = refunded_kobo + :r, refund_seq = :s, slots = :k WHERE id = :id')->execute(['r' => $refund, 's' => $seq, 'k' => $keep, 'id' => $id]);
        $pdo->prepare("INSERT INTO job_funding_events (job_id, event, slots, amount_kobo, ledger_transaction_id, note) VALUES (:j, 'refund', :s, :a, :t, :n)")
            ->execute(['j' => $id, 's' => -$excess, 'a' => $refund, 't' => $tx['id'], 'n' => mb_substr(ucfirst($note), 0, 255)]);
        return $refund;
    }

    /** Mark an open job completed once every funded slot is approved and nothing is active. Returns the fresh row. */
    public static function completeIfFull(PDO $pdo, int $id): array
    {
        $job = self::lockRow($pdo, $id);
        if (in_array((string) $job['status'], self::OPEN, true) && (int) $job['approved_count'] >= (int) $job['slots'] && (int) $job['active_count'] === 0) {
            $pdo->prepare("UPDATE jobs SET status = 'completed', status_reason = 'Every slot approved', closed_at = UTC_TIMESTAMP() WHERE id = :id")->execute(['id' => $id]);
            Audit::record($pdo, null, 'job.completed', 'job', $id);
            $job = self::lockRow($pdo, $id);
        }
        return $job;
    }

    // ---------------------------------------------------------------- reads

    private const SELECT = 'SELECT j.*, u.display_name advertiser_name, u.email advertiser_email FROM jobs j JOIN users u ON u.id = j.advertiser_user_id';

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(self::SELECT . ' WHERE j.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function ownedBy(PDO $pdo, int $id, int $advertiserId): array
    {
        $job = self::find($pdo, $id);
        if ($job === null || (int) $job['advertiser_user_id'] !== $advertiserId) throw new RuntimeException('Job not found.');
        return $job;
    }

    public static function lockRow(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM jobs WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new RuntimeException('Job not found.');
        return $row;
    }

    public static function freeSlots(array $job): int
    {
        if (!in_array((string) $job['status'], self::OPEN, true)) return 0;
        return max(0, (int) $job['slots'] - (int) $job['approved_count'] - (int) $job['active_count']);
    }

    /** @return list<array> steps in order */
    public static function steps(PDO $pdo, int $jobId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM job_steps WHERE job_id = :j ORDER BY position');
        $stmt->execute(['j' => $jobId]);
        return $stmt->fetchAll() ?: [];
    }

    /** Earner Jobs tab: live jobs with a free slot that this earner has not tried yet. */
    public static function listOpen(PDO $pdo, int $earnerId, int $page): array
    {
        $where = "WHERE j.status = 'live' AND j.slots > j.approved_count + j.active_count AND j.advertiser_user_id <> :u
            AND NOT EXISTS (SELECT 1 FROM job_attempts a WHERE a.job_id = j.id AND a.earner_user_id = :u2)";
        $args = ['u' => $earnerId, 'u2' => $earnerId];
        return Pagination::query($pdo, "SELECT COUNT(*) FROM jobs j {$where}", self::SELECT . " {$where} ORDER BY j.id DESC", $args, $page, self::PAGE_SIZE);
    }

    public static function openCount(PDO $pdo, int $earnerId): int
    {
        return (int) self::listOpen($pdo, $earnerId, 1)['total'];
    }

    public static function listForAdvertiser(PDO $pdo, int $uid, string $status, int $page): array
    {
        $where = 'WHERE j.advertiser_user_id = :u';
        $args = ['u' => $uid];
        if ($status === 'open') $where .= " AND j.status IN ('live', 'paused')";
        elseif ($status === 'ended') $where .= " AND j.status IN ('closed', 'completed', 'removed')";
        $sql = "SELECT j.*, (SELECT COUNT(*) FROM job_attempts a WHERE a.job_id = j.id AND a.status = 'submitted') to_review FROM jobs j {$where} ORDER BY j.id DESC";
        return Pagination::query($pdo, "SELECT COUNT(*) FROM jobs j {$where}", $sql, $args, $page, self::PAGE_SIZE);
    }

    /** $kind: '' every job, 'system' YOLOTASK jobs only (migration 0024). */
    public static function listForAdmin(PDO $pdo, string $status, string $q, int $page, string $kind = ''): array
    {
        $where = 'WHERE 1 = 1';
        $args = [];
        if ($kind === 'system') $where .= ' AND j.is_system = 1';
        if (in_array($status, ['live', 'paused', 'closed', 'completed', 'removed'], true)) { $where .= ' AND j.status = :s'; $args['s'] = $status; }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (j.title LIKE :q OR u.email LIKE :q2' . (ctype_digit($q) ? ' OR j.id = :qi' : '') . ')';
            $args += ['q' => $like, 'q2' => $like];
            if (ctype_digit($q)) $args['qi'] = (int) $q;
        }
        $sql = self::SELECT . " {$where} ORDER BY j.id DESC";
        $sql = str_replace('SELECT j.*,', "SELECT j.*, (SELECT COUNT(*) FROM job_attempts a WHERE a.job_id = j.id AND a.status = 'submitted') to_review,", $sql);
        return Pagination::query($pdo, "SELECT COUNT(*) FROM jobs j JOIN users u ON u.id = j.advertiser_user_id {$where}", $sql, $args, $page, self::PAGE_SIZE);
    }

    public static function advertiserStats(PDO $pdo, int $uid): array
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(status IN ('live', 'paused')), 0) open, COALESCE(SUM(funded_kobo - spent_kobo - refunded_kobo), 0) escrow, COALESCE(SUM(spent_kobo), 0) spent FROM jobs WHERE advertiser_user_id = :u");
        $stmt->execute(['u' => $uid]);
        $r = $stmt->fetch() ?: [];
        $rev = $pdo->prepare("SELECT COUNT(*) FROM job_attempts WHERE advertiser_user_id = :u AND status = 'submitted'");
        $rev->execute(['u' => $uid]);
        return ['jobs' => (int) ($r['n'] ?? 0), 'open' => (int) ($r['open'] ?? 0), 'escrow' => (int) ($r['escrow'] ?? 0), 'spent' => (int) ($r['spent'] ?? 0), 'to_review' => (int) $rev->fetchColumn()];
    }

    public static function adminStats(PDO $pdo): array
    {
        $r = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(status = 'live'), 0) live FROM jobs")->fetch() ?: [];
        $bal = static function (string $code) use ($pdo): int {
            $s = $pdo->prepare('SELECT COALESCE((SELECT balance_kobo FROM ledger_accounts WHERE code = :c), 0)');
            $s->execute(['c' => $code]);
            return (int) $s->fetchColumn();
        };
        return [
            'jobs' => (int) ($r['n'] ?? 0), 'live' => (int) ($r['live'] ?? 0),
            'escrow' => $bal(Ledger::JOBS_ESCROW), 'revenue' => $bal(Ledger::JOBS_REVENUE), 'platform_appeals' => $bal(Ledger::JOBS_APPEAL_PAYOUTS),
            'to_review' => (int) $pdo->query("SELECT COUNT(*) FROM job_attempts a JOIN jobs j ON j.id = a.job_id WHERE a.status = 'submitted' AND j.is_system = 0")->fetchColumn(),
            'system_to_review' => SystemReviews::count($pdo),
            'system_paid' => $bal(Ledger::PLATFORM_JOB_PAYOUTS),
            'appeals' => JobAppeals::openCount($pdo),
        ];
    }

    public static function fundingEvents(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM job_funding_events WHERE job_id = :j ORDER BY id DESC LIMIT 50');
        $stmt->execute(['j' => $id]);
        return $stmt->fetchAll() ?: [];
    }

    public static function statusLabel(string $status, bool $adminPaused = false): array
    {
        return match ($status) {
            'live' => ['Live', 'badge-success'],
            'paused' => [$adminPaused ? 'Paused by YOLOTASK' : 'Paused', 'badge-warning'],
            'closed' => ['Closed', 'badge-info'],
            'completed' => ['Completed', 'badge-success'],
            'removed' => ['Removed', 'badge-danger'],
            default => [ucfirst($status), 'badge-info'],
        };
    }

    public static function hoursLabel(int $hours): string
    {
        if ($hours % 24 === 0 && $hours >= 24) { $d = intdiv($hours, 24); return $d . ' day' . ($d === 1 ? '' : 's'); }
        return $hours . ' hour' . ($hours === 1 ? '' : 's');
    }

    private static function wholeNumber(mixed $v, string $error): int
    {
        $v = trim((string) $v);
        if (!preg_match('/^\d{1,7}$/', $v)) throw new RuntimeException($error);
        return (int) $v;
    }

    // ---------------------------------------------------------------- reconciliation

    /**
     * Ledger checks (part of Money\Ledger::reconcile):
     *  - every job: funded - spent - refunded = pay x (slots - approved), spent = pay x approved;
     *  - counters match the attempts (approved, active) and closed jobs keep only what active work needs;
     *  - system:jobs_escrow = total unspent job budgets (escrow == locked unpaid);
     *  - system:jobs_revenue = fees of approved attempts;
     *  - system:jobs_appeal_payouts = platform share of overturned appeals;
     *  - every approved attempt has its approval posting; released rewards have their release posting;
     *  - YOLOTASK jobs (migration 0024) skip the escrow checks, never hold money
     *    (funded = spent = refunded = fee = 0) and system:platform_job_payouts =
     *    pay of approved attempts on YOLOTASK jobs.
     * @return list<string>
     */
    public static function reconcile(PDO $pdo): array
    {
        if (!(bool) $pdo->query("SHOW TABLES LIKE 'job_attempts'")->fetchColumn()) return [];
        $problems = [];
        $hasSystem = (bool) $pdo->query("SHOW COLUMNS FROM jobs LIKE 'is_system'")->fetchColumn();
        $adv = $hasSystem ? 'is_system = 0' : '1 = 1';
        $bal = static function (string $code) use ($pdo): int {
            $s = $pdo->prepare('SELECT COALESCE((SELECT balance_kobo FROM ledger_accounts WHERE code = :c), 0)');
            $s->execute(['c' => $code]);
            return (int) $s->fetchColumn();
        };
        foreach ($pdo->query("SELECT id, funded_kobo - spent_kobo - refunded_kobo unspent, pay_kobo * (CAST(slots AS SIGNED) - CAST(approved_count AS SIGNED)) needed, spent_kobo, pay_kobo * approved_count approved_value
                FROM jobs WHERE {$adv} AND (funded_kobo - spent_kobo - refunded_kobo <> pay_kobo * (CAST(slots AS SIGNED) - CAST(approved_count AS SIGNED)) OR spent_kobo <> pay_kobo * approved_count) LIMIT 50") as $r) {
            $problems[] = "Job #{$r['id']} escrow {$r['unspent']} != locked unpaid slots {$r['needed']} (spent {$r['spent_kobo']}, approved value {$r['approved_value']})";
        }
        foreach ($pdo->query("SELECT j.id, j.approved_count, j.active_count, COALESCE(SUM(a.status = 'approved'), 0) ap, COALESCE(SUM(a.status IN ('in_progress', 'submitted', 'sent_back')), 0) ac
                FROM jobs j LEFT JOIN job_attempts a ON a.job_id = j.id GROUP BY j.id, j.approved_count, j.active_count HAVING ap <> j.approved_count OR ac <> j.active_count LIMIT 50") as $r) {
            $problems[] = "Job #{$r['id']} counters (approved {$r['approved_count']}, active {$r['active_count']}) do not match its attempts ({$r['ap']}, {$r['ac']})";
        }
        foreach ($pdo->query("SELECT id, slots, approved_count, active_count FROM jobs WHERE status IN ('closed', 'completed', 'removed') AND slots <> approved_count + active_count LIMIT 50") as $r) {
            $problems[] = "Closed job #{$r['id']} still holds {$r['slots']} slots (approved {$r['approved_count']} + active {$r['active_count']})";
        }
        $escrow = $bal(Ledger::JOBS_ESCROW);
        $unspent = (int) $pdo->query("SELECT COALESCE(SUM(funded_kobo - spent_kobo - refunded_kobo), 0) FROM jobs WHERE {$adv}")->fetchColumn();
        if ($escrow !== $unspent) $problems[] = "Jobs escrow {$escrow} != total locked unpaid job budgets {$unspent}";
        if ($hasSystem) {
            foreach ($pdo->query('SELECT id FROM jobs WHERE is_system = 1 AND (funded_kobo <> 0 OR spent_kobo <> 0 OR refunded_kobo <> 0 OR fee_kobo <> 0 OR earner_kobo <> pay_kobo) LIMIT 50') as $r) {
                $problems[] = "YOLOTASK job #{$r['id']} holds money columns (it must have no escrow and pay the earner in full)";
            }
            foreach ($pdo->query('SELECT a.id FROM job_attempts a JOIN jobs j ON j.id = a.job_id WHERE j.is_system = 1 AND (a.fee_kobo <> 0 OR a.earner_kobo <> a.pay_kobo) LIMIT 50') as $r) {
                $problems[] = "YOLOTASK job attempt #{$r['id']} does not pay the earner in full";
            }
            $paid = $bal(Ledger::PLATFORM_JOB_PAYOUTS);
            $owed = (int) $pdo->query("SELECT COALESCE(SUM(a.pay_kobo), 0) FROM job_attempts a JOIN jobs j ON j.id = a.job_id WHERE j.is_system = 1 AND a.status = 'approved'")->fetchColumn();
            if ($paid !== $owed) $problems[] = "YOLOTASK job payouts {$paid} != pay of approved YOLOTASK job attempts {$owed}";
        }
        $rev = $bal(Ledger::JOBS_REVENUE);
        $fees = (int) $pdo->query("SELECT COALESCE(SUM(fee_kobo), 0) FROM job_attempts WHERE status = 'approved'")->fetchColumn();
        if ($rev !== $fees) $problems[] = "Jobs revenue {$rev} != platform share of approved jobs {$fees}";
        $plat = $bal(Ledger::JOBS_APPEAL_PAYOUTS);
        $platAppeals = (int) $pdo->query("SELECT COALESCE(SUM(platform_kobo), 0) FROM job_appeals WHERE status = 'overturned'")->fetchColumn();
        if ($plat !== $platAppeals) $problems[] = "Job appeal payouts {$plat} != platform share of overturned job appeals {$platAppeals}";
        foreach ($pdo->query("SELECT a.id FROM job_attempts a LEFT JOIN ledger_transactions t ON t.id = a.approve_transaction_id
                WHERE (a.status = 'approved' AND (t.id IS NULL OR t.amount_kobo <> a.pay_kobo OR t.type <> 'job_reward')) OR (a.status <> 'approved' AND a.approve_transaction_id IS NOT NULL)
                   OR (a.reward_status = 'released' AND a.status = 'approved' AND a.release_transaction_id IS NULL AND a.reward_release_at IS NOT NULL) LIMIT 50") as $r) {
            $problems[] = "Job attempt #{$r['id']} does not match its ledger posting";
        }
        return $problems;
    }
}
