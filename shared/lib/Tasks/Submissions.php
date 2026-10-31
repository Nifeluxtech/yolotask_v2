<?php
declare(strict_types=1);
namespace YoloTask\Tasks;

use PDO;
use RuntimeException;
use YoloTask\Campaigns\Campaigns;
use YoloTask\Core\Settings;
use YoloTask\Security\Audit;
use YoloTask\Security\Throttle;
use YoloTask\Support\Pagination;
use YoloTask\Support\Uploads;

/**
 * Earner submissions: one per earner per campaign. Submitting needs an
 * active reservation (the earner's slot) and a proof screenshot. After a
 * rejection the earner may resubmit up to tasks.resubmission_cap times, as
 * long as the campaign is live and a slot is free, unless they appealed
 * the rejection instead (Phase 5: one path or the other, never both).
 */
final class Submissions
{
    public const PAGE_SIZE = 20;

    /** First submission for a campaign. Returns the submission id. */
    public static function submit(PDO $pdo, array $user, int $campaignId, mixed $file, string $comment): int
    {
        $uid = self::requireEarner($user);
        $comment = self::cleanComment($comment);
        if (Throttle::tooMany($pdo, 'task_submit', (string) $uid, 20, 600)) throw new RuntimeException('You are submitting too quickly. Wait a few minutes.');
        $img = Uploads::image($file, Settings::int($pdo, 'tasks.proof_max_bytes'));
        FakeProofGuard::assertFresh($pdo, $img['upload_sha256']);

        $pdo->beginTransaction();
        try {
            $c = Campaigns::lockRow($pdo, $campaignId);
            if (!in_array((string) $c['status'], ['live', 'paused'], true)) throw new RuntimeException('This task is no longer accepting proofs.');
            $existing = $pdo->prepare('SELECT id, status FROM submissions WHERE campaign_id = :c AND earner_user_id = :u FOR UPDATE');
            $existing->execute(['c' => $campaignId, 'u' => $uid]);
            if ($existing->fetch() !== false) throw new RuntimeException('You have already submitted this task.');
            $res = Tasks::lockReservation($pdo, $campaignId, $uid);
            if ($res === null || (string) $res['status'] !== 'active' || strtotime((string) $res['expires_at'] . ' UTC') <= time()) {
                throw new RuntimeException('Your time for this task ran out. Start it again if slots are still free.');
            }
            $hours = (int) $c['auto_approve_hours'];
            $pdo->prepare("INSERT INTO submissions (campaign_id, earner_user_id, status, comment, instructions_version, unit_price_kobo, unit_reward_kobo, submitted_at, auto_approve_at)
                VALUES (:c, :u, 'pending', :cm, :v, :p, :r, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL :h HOUR))")
                ->execute(['c' => $campaignId, 'u' => $uid, 'cm' => $comment, 'v' => $c['instructions_version'], 'p' => $c['unit_price_kobo'], 'r' => $c['unit_reward_kobo'], 'h' => $hours]);
            $id = (int) $pdo->lastInsertId();
            Proofs::store($pdo, $id, 1, $img);
            $pdo->prepare("UPDATE task_reservations SET status = 'submitted' WHERE id = :id")->execute(['id' => $res['id']]);
            $pdo->prepare('UPDATE campaigns SET pending_count = pending_count + 1 WHERE id = :id')->execute(['id' => $campaignId]);
            Audit::record($pdo, $uid, 'task.submitted', 'submission', $id);
            $pdo->commit();
            Proofs::forgetWritten();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Proofs::discardWritten();
            throw $e;
        }
        Throttle::hit($pdo, 'task_submit', (string) $uid);
        return $id;
    }

    /** Resubmit after a rejection (needs a free slot on a live campaign). */
    public static function resubmit(PDO $pdo, array $user, int $submissionId, mixed $file, string $comment): void
    {
        $uid = self::requireEarner($user);
        $comment = self::cleanComment($comment);
        $cap = Settings::int($pdo, 'tasks.resubmission_cap');
        $own = self::findForEarner($pdo, $submissionId, $uid);
        if ($own === null) throw new RuntimeException('Submission not found.');
        if (Throttle::tooMany($pdo, 'task_submit', (string) $uid, 20, 600)) throw new RuntimeException('You are submitting too quickly. Wait a few minutes.');
        $img = Uploads::image($file, Settings::int($pdo, 'tasks.proof_max_bytes'));
        FakeProofGuard::assertFresh($pdo, $img['upload_sha256'], $submissionId);

        $pdo->beginTransaction();
        try {
            $c = Campaigns::lockRow($pdo, (int) $own['campaign_id']);
            $stmt = $pdo->prepare('SELECT * FROM submissions WHERE id = :id AND earner_user_id = :u FOR UPDATE');
            $stmt->execute(['id' => $submissionId, 'u' => $uid]);
            $s = $stmt->fetch();
            if (!is_array($s) || (string) $s['status'] !== 'rejected') throw new RuntimeException('Only rejected submissions can be resubmitted.');
            $appealed = $pdo->prepare('SELECT COUNT(*) FROM submission_appeals WHERE submission_id = :id');
            $appealed->execute(['id' => $submissionId]);
            if ((int) $appealed->fetchColumn() > 0) throw new RuntimeException('You appealed this decision, so it cannot be resubmitted. A reviewer will decide your appeal.');
            if ((int) $s['attempts'] > $cap) throw new RuntimeException($cap === 0 ? 'Resubmissions are not allowed.' : 'You have used all your resubmissions for this task.');
            if ((string) $c['status'] !== 'live') throw new RuntimeException('This campaign is not accepting new proofs right now.');
            $res = Tasks::lockReservation($pdo, (int) $c['id'], $uid);
            $held = $pdo->prepare("SELECT COUNT(*) FROM task_reservations WHERE campaign_id = :c AND status = 'active' AND expires_at > UTC_TIMESTAMP() AND earner_user_id <> :u");
            $held->execute(['c' => $c['id'], 'u' => $uid]);
            if ((int) $c['approved_count'] + (int) $c['pending_count'] + (int) $held->fetchColumn() >= Campaigns::capacity($c)) throw new RuntimeException('All slots for this task are taken right now. Try again later.');
            $attempt = (int) $s['attempts'] + 1;
            $pdo->prepare("UPDATE submissions SET status = 'pending', attempts = :a, comment = :cm, instructions_version = :v, reject_reason = NULL, decision_source = NULL, decided_by_user_id = NULL, decided_at = NULL,
                submitted_at = UTC_TIMESTAMP(), auto_approve_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :h HOUR) WHERE id = :id")
                ->execute(['a' => $attempt, 'cm' => $comment, 'v' => $c['instructions_version'], 'h' => (int) $c['auto_approve_hours'], 'id' => $submissionId]);
            Proofs::store($pdo, $submissionId, $attempt, $img);
            if ($res !== null) $pdo->prepare("UPDATE task_reservations SET status = 'submitted' WHERE id = :id")->execute(['id' => $res['id']]);
            $pdo->prepare('UPDATE campaigns SET pending_count = pending_count + 1, rejected_count = GREATEST(rejected_count, 1) - 1 WHERE id = :id')->execute(['id' => $c['id']]);
            Audit::record($pdo, $uid, 'task.resubmitted', 'submission', $submissionId);
            $pdo->commit();
            Proofs::forgetWritten();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Proofs::discardWritten();
            throw $e;
        }
        Throttle::hit($pdo, 'task_submit', (string) $uid);
    }

    public static function canResubmit(PDO $pdo, array $s): bool
    {
        return (string) $s['status'] === 'rejected' && (int) $s['attempts'] <= Settings::int($pdo, 'tasks.resubmission_cap') && (string) ($s['campaign_status'] ?? '') === 'live'
            && empty($s['appeal_id']);
    }

    private const SELECT = 'SELECT s.*, c.title campaign_title, c.status campaign_status, c.advertiser_user_id, c.link_url, c.quantity, p.name platform_name, t.name task_type_name,
        u.display_name earner_name, u.email earner_email,
        (SELECT pr.id FROM submission_proofs pr WHERE pr.submission_id = s.id AND pr.is_appeal = 0 ORDER BY pr.attempt DESC, pr.id DESC LIMIT 1) latest_proof_id,
        (SELECT ap.id FROM submission_appeals ap WHERE ap.submission_id = s.id) appeal_id,
        (SELECT ap.status FROM submission_appeals ap WHERE ap.submission_id = s.id) appeal_status
        FROM submissions s JOIN campaigns c ON c.id = s.campaign_id JOIN platforms p ON p.id = c.platform_id JOIN task_types t ON t.id = c.task_type_id JOIN users u ON u.id = s.earner_user_id';

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(self::SELECT . ' WHERE s.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function findForEarner(PDO $pdo, int $id, int $earnerId): ?array
    {
        $s = self::find($pdo, $id);
        return $s !== null && (int) $s['earner_user_id'] === $earnerId ? $s : null;
    }

    public static function forEarnerCampaign(PDO $pdo, int $campaignId, int $earnerId): ?array
    {
        $stmt = $pdo->prepare(self::SELECT . ' WHERE s.campaign_id = :c AND s.earner_user_id = :u');
        $stmt->execute(['c' => $campaignId, 'u' => $earnerId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function listForEarner(PDO $pdo, int $uid, string $status, int $page): array
    {
        $where = 'WHERE s.earner_user_id = :u';
        $args = ['u' => $uid];
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) { $where .= ' AND s.status = :st'; $args['st'] = $status; }
        return Pagination::query($pdo, "SELECT COUNT(*) FROM submissions s {$where}", self::SELECT . " {$where} ORDER BY s.submitted_at DESC, s.id DESC", $args, $page, self::PAGE_SIZE);
    }

    public static function listForCampaign(PDO $pdo, int $campaignId, string $status, int $page): array
    {
        $where = 'WHERE s.campaign_id = :c';
        $args = ['c' => $campaignId];
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) { $where .= ' AND s.status = :st'; $args['st'] = $status; }
        $order = $status === 'pending' ? 's.submitted_at ASC' : 's.submitted_at DESC';
        return Pagination::query($pdo, "SELECT COUNT(*) FROM submissions s {$where}", self::SELECT . " {$where} ORDER BY {$order}, s.id", $args, $page, self::PAGE_SIZE);
    }

    /** Admin list; status 'stale' = pending longer than tasks.stale_hours. */
    public static function listForAdmin(PDO $pdo, string $status, string $q, int $page): array
    {
        $where = 'WHERE 1 = 1';
        $args = [];
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) { $where .= ' AND s.status = :st'; $args['st'] = $status; }
        if ($status === 'stale') { $where .= " AND s.status = 'pending' AND s.submitted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :h HOUR)"; $args['h'] = StaleQueue::hours($pdo); }
        if ($q !== '') { $where .= ' AND (u.email LIKE :q OR c.title LIKE :q2)'; $like = '%' . addcslashes($q, '%_\\') . '%'; $args += ['q' => $like, 'q2' => $like]; }
        $order = in_array($status, ['pending', 'stale'], true) ? 's.submitted_at ASC' : 's.id DESC';
        return Pagination::query($pdo, "SELECT COUNT(*) FROM submissions s JOIN campaigns c ON c.id = s.campaign_id JOIN users u ON u.id = s.earner_user_id {$where}", self::SELECT . " {$where} ORDER BY {$order}", $args, $page, self::PAGE_SIZE);
    }

    public static function statusLabel(array $s): array
    {
        return match ((string) $s['status']) {
            'pending' => ['Waiting for review', 'badge-warning'],
            'approved' => [match ((string) ($s['decision_source'] ?? '')) { 'auto' => 'Approved (auto)', 'appeal' => 'Approved on appeal', default => 'Approved' }, 'badge-success'],
            'rejected' => [(string) ($s['appeal_status'] ?? '') === 'open' ? 'Rejected · appeal open' : ((string) ($s['appeal_status'] ?? '') === 'upheld' ? 'Rejected · appeal upheld' : 'Rejected'), 'badge-danger'],
            default => [ucfirst((string) $s['status']), 'badge-info'],
        };
    }

    private static function cleanComment(string $comment): ?string
    {
        $comment = trim(str_replace(["\r\n", "\r"], "\n", $comment));
        if (mb_strlen($comment) > 500) throw new RuntimeException('Keep your note under 500 characters.');
        return $comment === '' ? null : $comment;
    }

    private static function requireEarner(array $user): int
    {
        if ((string) $user['role'] !== 'earner') throw new RuntimeException('Only earner accounts can submit tasks.');
        if (empty($user['email_verified_at'])) throw new RuntimeException('Confirm your email to start earning.');
        return (int) $user['id'];
    }
}
