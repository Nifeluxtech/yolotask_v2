<?php
declare(strict_types=1);
namespace YoloTask\Growth;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use YoloTask\Messaging\Notifications;
use YoloTask\Money\Money;
use YoloTask\Security\Audit;

/**
 * Leaderboard (Phase 7, port of V1 get_leaderboard / reward_leader).
 *
 * Two boards over two periods, all in Lagos time (WAT, UTC+1):
 *  - "workers": earners ranked by tasks approved in the period (by the
 *    submission's approval time, submissions.decided_at), with the rewards of
 *    those tasks (unit_reward_kobo).
 *  - "referrers": earners and advertisers ranked by the people they referred
 *    who joined in the period (referrals.created_at; 'flagged' and 'rejected'
 *    referrals never count; imported v1 referrals count like any other), with
 *    the referral earnings paid to them in the period (milestone bonuses +
 *    deposit commissions, status 'paid'; reversed/skipped never count).
 * "week" runs from Monday 00:00 WAT to now; "month" from the 1st 00:00 WAT;
 * "all" is all time.
 * Staff (reviewers, admins) and suspended or closed accounts are left out.
 * Ties go to whoever got there first (earliest latest-qualifying event), then
 * the lower user id. Earners see masked names (first name + last initial).
 *
 * No query cache exists in the codebase, so results are computed per request;
 * migration 0012 adds the (status, time) indexes the range scans use.
 *
 * Admins can reward any ranked user: the money goes through the admin bonus
 * path (Dr system:bonuses / Cr wallet, idempotency key bonus:<admin>:<form>,
 * audited, notified as "Leadership bonus") and a leaderboard_rewards row
 * keeps the reward history.
 */
final class Leaderboard
{
    public const LIMIT = 50;
    public const BOARDS = ['workers', 'referrers'];
    public const PERIODS = ['week', 'month', 'all'];
    public const REWARD_MIN_KOBO = 100;          // ₦1
    public const REWARD_MAX_KOBO = 10000000;     // ₦100,000
    public const NOTE_MAX = 140;
    public const LABEL_MAX = 40;

    public static function tz(): DateTimeZone
    {
        return new DateTimeZone('Africa/Lagos');
    }

    public static function board(?string $b): string
    {
        return in_array($b, self::BOARDS, true) ? (string) $b : 'workers';
    }

    public static function period(?string $p): string
    {
        return in_array($p, self::PERIODS, true) ? (string) $p : 'week';
    }

    /**
     * Period window: start (WAT midnight) and end (now), both as UTC
     * 'Y-m-d H:i:s' strings for the database, plus the WAT start and a label.
     * @return array{from:string,to:string,start_wat:DateTimeImmutable,label:string}
     */
    public static function bounds(string $period, ?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(self::tz());
        if (self::period($period) === 'all') {
            $start = new DateTimeImmutable('2020-01-01 00:00:00', self::tz());
            $label = 'All time';
        } elseif (self::period($period) === 'month') {
            $start = $now->setDate((int) $now->format('Y'), (int) $now->format('n'), 1)->setTime(0, 0, 0);
            $label = $start->format('F Y');
        } else {
            $dow = (int) $now->format('N'); // 1 = Monday
            $start = $now->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);
            $label = 'Week of ' . $start->format('Y-m-d');
        }
        $utc = new DateTimeZone('UTC');
        return [
            'from' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'to' => $now->setTimezone($utc)->format('Y-m-d H:i:s'),
            'start_wat' => $start,
            'label' => $label,
        ];
    }

    /** Public name: first name + last initial ("Ada O."); one-word names stay as they are. */
    public static function maskName(string $displayName): string
    {
        $parts = preg_split('/\s+/u', trim($displayName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) return 'Member';
        $first = mb_substr($parts[0], 0, 20);
        $first = mb_strtoupper(mb_substr($first, 0, 1)) . mb_substr($first, 1);
        if (count($parts) === 1) return $first;
        $last = (string) end($parts);
        return $first . ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.';
    }

    /** Two-letter avatar initials from a (masked or full) name. */
    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) return '?';
        $out = mb_strtoupper(mb_substr($parts[0], 0, 1));
        if (count($parts) > 1) $out .= mb_strtoupper(mb_substr((string) end($parts), 0, 1));
        return $out;
    }

    /**
     * The aggregate for one board as SQL (one row per user: uid, cnt, total,
     * last_at) with :from / :to parameters. Only active earners (workers) or
     * active earners/advertisers (referrers) appear.
     */
    private static function aggregateSql(string $board): string
    {
        if ($board === 'referrers') {
            // Ranked by referrals made in the period; earnings are shown beside the count.
            return "SELECT r.referrer_user_id uid, COUNT(*) cnt,
                    COALESCE((SELECT SUM(p.amount_kobo) FROM referral_payouts p WHERE p.referrer_user_id = r.referrer_user_id
                        AND p.status = 'paid' AND p.amount_kobo > 0 AND p.created_at >= :pfrom AND p.created_at <= :pto), 0) total,
                    MAX(r.created_at) last_at
                FROM referrals r JOIN users u ON u.id = r.referrer_user_id
                WHERE r.status = 'active' AND r.created_at >= :from AND r.created_at <= :to
                  AND u.role IN ('earner', 'advertiser') AND u.status = 'active'
                GROUP BY r.referrer_user_id";
        }
        return "SELECT s.earner_user_id uid, COUNT(*) cnt, COALESCE(SUM(s.unit_reward_kobo), 0) total, MAX(s.decided_at) last_at
            FROM submissions s JOIN users u ON u.id = s.earner_user_id
            WHERE s.status = 'approved' AND s.decided_at >= :from AND s.decided_at <= :to
              AND u.role = 'earner' AND u.status = 'active'
            GROUP BY s.earner_user_id";
    }

    /**
     * Ranked board.
     * @return array{board:string,period:string,label:string,since:string,from:string,to:string,rows:list<array>,viewer:?array}
     *   rows: rank, user_id, name (full), public_name, initials, count, total_kobo, last_at, is_viewer
     *   viewer: the viewer's own row when they are outside the top LIMIT (rank null when they have nothing in the period)
     */
    public static function get(PDO $pdo, string $board, string $period, ?int $viewerId = null, ?DateTimeImmutable $now = null, int $limit = self::LIMIT): array
    {
        $board = self::board($board);
        $period = self::period($period);
        $b = self::bounds($period, $now);
        $agg = self::aggregateSql($board);
        $stmt = $pdo->prepare("SELECT a.uid, a.cnt, a.total, a.last_at, u.display_name FROM ($agg) a JOIN users u ON u.id = a.uid
            ORDER BY a.cnt DESC, a.last_at ASC, a.uid ASC LIMIT " . max(1, min(500, $limit)));
        $stmt->execute(self::params($board, $b));
        $rows = [];
        $viewerIn = false;
        foreach ($stmt->fetchAll() ?: [] as $i => $r) {
            $rows[] = self::row($i + 1, $r, $viewerId);
            if ($viewerId !== null && (int) $r['uid'] === $viewerId) $viewerIn = true;
        }
        $viewer = null;
        if ($viewerId !== null && !$viewerIn) {
            $mine = $pdo->prepare("SELECT a.uid, a.cnt, a.total, a.last_at FROM ($agg) a WHERE a.uid = :me");
            $mine->execute(self::params($board, $b) + ['me' => $viewerId]);
            $m = $mine->fetch();
            $name = $pdo->prepare('SELECT display_name FROM users WHERE id = :id');
            $name->execute(['id' => $viewerId]);
            $display = (string) ($name->fetchColumn() ?: '');
            if (is_array($m)) {
                $ahead = $pdo->prepare("SELECT COUNT(*) FROM ($agg) a WHERE a.cnt > :c OR (a.cnt = :c2 AND (a.last_at < :t OR (a.last_at = :t2 AND a.uid < :me)))");
                $ahead->execute(self::params($board, $b) + ['c' => (int) $m['cnt'], 'c2' => (int) $m['cnt'], 't' => $m['last_at'], 't2' => $m['last_at'], 'me' => $viewerId]);
                $viewer = self::row((int) $ahead->fetchColumn() + 1, $m + ['display_name' => $display], $viewerId);
            } else {
                $viewer = self::row(0, ['uid' => $viewerId, 'cnt' => 0, 'total' => 0, 'last_at' => null, 'display_name' => $display], $viewerId);
                $viewer['rank'] = null;
            }
        }
        return ['board' => $board, 'period' => $period, 'label' => $b['label'], 'since' => $b['start_wat']->format('D j M Y'), 'from' => $b['from'], 'to' => $b['to'], 'rows' => $rows, 'viewer' => $viewer];
    }

    /** Query parameters for a board's aggregate (the referrers board binds the window twice). */
    private static function params(string $board, array $b): array
    {
        $p = ['from' => $b['from'], 'to' => $b['to']];
        return $board === 'referrers' ? $p + ['pfrom' => $b['from'], 'pto' => $b['to']] : $p;
    }

    private static function row(int $rank, array $r, ?int $viewerId): array
    {
        $full = (string) ($r['display_name'] ?? '');
        $public = self::maskName($full);
        return [
            'rank' => $rank,
            'user_id' => (int) $r['uid'],
            'name' => $full,
            'public_name' => $public,
            'initials' => self::initials($public),
            'count' => (int) $r['cnt'],
            'total_kobo' => (int) $r['total'],
            'last_at' => $r['last_at'] !== null ? (string) $r['last_at'] : null,
            'is_viewer' => $viewerId !== null && (int) $r['uid'] === $viewerId,
        ];
    }

    /**
     * Admin: pay a leadership reward through the bonus path and record it.
     * Returns the ledger transaction id; a repeated form (same idem key) is a
     * no-op that returns the same transaction.
     */
    public static function reward(PDO $pdo, int $adminId, int $userId, int $amountKobo, string $note, string $periodLabel, string $board, string $period, ?int $rank, string $idemKey): int
    {
        $note = trim(preg_replace('/\s+/u', ' ', $note) ?? '');
        if ($note === '') $note = 'Leadership bonus';
        $periodLabel = trim(preg_replace('/\s+/u', ' ', $periodLabel) ?? '');
        if ($amountKobo < self::REWARD_MIN_KOBO || $amountKobo > self::REWARD_MAX_KOBO) throw new RuntimeException('Reward must be between ₦1 and ₦100,000.');
        if (mb_strlen($note) > self::NOTE_MAX) throw new RuntimeException('Keep the note to ' . self::NOTE_MAX . ' characters.');
        if ($periodLabel === '' || mb_strlen($periodLabel) > self::LABEL_MAX) throw new RuntimeException('Give the period a label of up to ' . self::LABEL_MAX . ' characters.');
        if (!preg_match('/^[a-f0-9]{32}$/', $idemKey)) throw new RuntimeException('Your form expired. Please try again.');
        $board = self::board($board);
        $period = self::period($period);
        $tx = Bonuses::pay($pdo, $adminId, $userId, $amountKobo, 'Leadership bonus (' . $periodLabel . '): ' . $note, $idemKey,
            static function (PDO $pdo, array $tx, array $wallet) use ($adminId, $userId, $board, $period, $periodLabel, $rank, $amountKobo, $note): void {
                $pdo->prepare('INSERT INTO leaderboard_rewards (user_id, admin_user_id, board, period, period_label, rank_at_reward, amount_kobo, note, ledger_transaction_id)
                    VALUES (:u, :a, :b, :p, :l, :r, :amt, :n, :tx)')
                    ->execute(['u' => $userId, 'a' => $adminId, 'b' => $board, 'p' => $period, 'l' => $periodLabel, 'r' => $rank !== null && $rank > 0 ? $rank : null,
                        'amt' => $amountKobo, 'n' => $note, 'tx' => $tx['id']]);
                Audit::record($pdo, $adminId, 'admin.leader_rewarded', 'user', $userId);
            });
        if ($tx['created']) {
            $amount = Money::format($amountKobo);
            Notifications::notify($pdo, $userId, 'rewards', 'Leadership bonus: ' . $amount,
                'You received ' . $amount . ' for outstanding performance on the leaderboard (' . $periodLabel . '). ' . $note . ' Keep shining!',
                'wallet.php', 'leader:' . $tx['id'], [
                    'subject' => 'Leadership bonus: ' . $amount, 'heading' => 'Leadership bonus',
                    'lines' => ['YOLOTASK added a leadership bonus of ' . $amount . ' to your wallet for your place on the leaderboard (' . $periodLabel . ').', 'Note: ' . $note],
                ]);
        }
        return $tx['id'];
    }

    /** Admin: the last $limit leadership rewards, newest first. */
    public static function history(PDO $pdo, int $limit = 50): array
    {
        try {
            $rows = $pdo->query('SELECT r.id, r.user_id, r.board, r.period, r.period_label, r.rank_at_reward, r.amount_kobo, r.note, r.created_at,
                    u.display_name user_name, a.display_name admin_name
                FROM leaderboard_rewards r JOIN users u ON u.id = r.user_id LEFT JOIN users a ON a.id = r.admin_user_id
                ORDER BY r.id DESC LIMIT ' . max(1, min(200, $limit)))->fetchAll() ?: [];
        } catch (\PDOException) {
            return []; // migration 0012 not applied yet
        }
        return $rows;
    }
}
