<?php
declare(strict_types=1);
namespace YoloTask\Growth;

use PDO;
use RuntimeException;
use YoloTask\Core\Settings;
use YoloTask\Security\Audit;

/**
 * Earner levels (Phase 6). An earner holds the highest active level whose
 * approved-task count and approval rate they meet. Approval rate =
 * approved / (approved + rejected) decided submissions; with nothing decided
 * yet it counts as 100%. The level sets how many tasks the earner may hold at
 * once (overriding tasks.max_active_reservations), its withdrawal fee
 * override and its daily check-in reward. With levels switched off
 * (levels.enabled = 0) or no active level matching, the plain settings apply.
 * (earner_levels.premium_access is dormant since migration 0017.)
 */
final class Levels
{
    public static function enabled(PDO $pdo): bool
    {
        return Settings::get($pdo, 'levels.enabled', '1') === '1';
    }

    /** @return list<array> levels ordered by min_approved, then min rate */
    public static function all(PDO $pdo, bool $activeOnly = false): array
    {
        $where = ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY min_approved, min_approval_rate, id';
        try {
            $rows = $pdo->query('SELECT id, name, min_approved, min_approval_rate, max_active_reservations, is_active, withdrawal_fee_bps, checkin_reward_kobo FROM earner_levels' . $where)->fetchAll() ?: [];
        } catch (\PDOException) {
            try { // migration 0014 not applied yet: no level fee override, no check-in reward
                $rows = $pdo->query('SELECT id, name, min_approved, min_approval_rate, max_active_reservations, is_active, NULL withdrawal_fee_bps, 0 checkin_reward_kobo FROM earner_levels' . $where)->fetchAll() ?: [];
            } catch (\PDOException) {
                return []; // migration 0009 not applied yet
            }
        }
        foreach ($rows as &$r) {
            foreach (['id', 'min_approved', 'min_approval_rate', 'max_active_reservations', 'is_active', 'checkin_reward_kobo'] as $k) $r[$k] = (int) $r[$k];
            $r['withdrawal_fee_bps'] = $r['withdrawal_fee_bps'] === null ? null : (int) $r['withdrawal_fee_bps'];
        }
        return $rows;
    }

    /** Approved / rejected counts and approval rate (percent, 1 decimal). */
    public static function stats(PDO $pdo, int $earnerId): array
    {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(status = 'approved'), 0) a, COALESCE(SUM(status = 'rejected'), 0) r FROM submissions WHERE earner_user_id = :u");
        $stmt->execute(['u' => $earnerId]);
        $row = $stmt->fetch();
        $a = (int) $row['a'];
        $r = (int) $row['r'];
        return ['approved' => $a, 'rejected' => $r, 'rate' => $a + $r === 0 ? 100.0 : round($a * 100 / ($a + $r), 1)];
    }

    /**
     * The earner's current level, the next one and progress.
     * @return array{enabled:bool,level:?array,next:?array,approved:int,rejected:int,rate:float,max_active:int,progress:int}
     */
    public static function forEarner(PDO $pdo, int $earnerId): array
    {
        $s = self::stats($pdo, $earnerId);
        $default = max(1, Settings::int($pdo, 'tasks.max_active_reservations'));
        $out = ['enabled' => false, 'level' => null, 'next' => null, 'approved' => $s['approved'], 'rejected' => $s['rejected'], 'rate' => $s['rate'],
            'max_active' => $default, 'progress' => 0];
        if (!self::enabled($pdo)) return $out;
        $levels = self::all($pdo, true);
        if ($levels === []) return $out;
        $out['enabled'] = true;
        $idx = -1;
        foreach ($levels as $i => $l) {
            if ($s['approved'] >= $l['min_approved'] && $s['rate'] >= $l['min_approval_rate']) { $out['level'] = $l; $idx = $i; }
        }
        $out['next'] = $levels[$idx + 1] ?? null; // levels after the highest one met are all not met yet
        if ($out['level'] !== null) {
            $out['max_active'] = max(1, $out['level']['max_active_reservations']);
        }
        if ($out['next'] !== null) {
            $from = $out['level']['min_approved'] ?? 0;
            $span = max(1, $out['next']['min_approved'] - $from);
            $out['progress'] = (int) max(0, min(100, floor(($s['approved'] - $from) * 100 / $span)));
        } else {
            $out['progress'] = 100;
        }
        return $out;
    }

    public static function maxActive(PDO $pdo, int $earnerId): int
    {
        return self::forEarner($pdo, $earnerId)['max_active'];
    }

    public const MAX_CHECKIN_KOBO = 1000000; // ₦10,000 per day

    /**
     * Admin: add or edit a level. $withdrawalFeeBps = this level's withdrawal
     * fee (null = the base rate; never above the base). $checkinKobo = the
     * daily check-in reward (0 = no check-in reward for this level).
     */
    public static function save(PDO $pdo, int $adminId, ?int $id, string $name, int $minApproved, int $minRate, int $maxActive, bool $active, ?int $withdrawalFeeBps = null, int $checkinKobo = 0): int
    {
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 40) throw new RuntimeException('Give the level a name of 2 to 40 characters.');
        if ($minApproved < 0 || $minApproved > 1000000) throw new RuntimeException('Approved tasks must be between 0 and 1,000,000.');
        if ($minRate < 0 || $minRate > 100) throw new RuntimeException('Approval rate must be between 0 and 100%.');
        if ($maxActive < 1 || $maxActive > 50) throw new RuntimeException('Tasks held at once must be between 1 and 50.');
        $base = \YoloTask\Money\Fees::withdrawalBaseBps($pdo);
        if ($withdrawalFeeBps !== null && ($withdrawalFeeBps < 0 || $withdrawalFeeBps > $base)) {
            throw new RuntimeException('A level withdrawal fee must be between 0% and the base fee (' . \YoloTask\Money\Fees::percent($base) . '). Leave it empty to use the base fee.');
        }
        if ($checkinKobo < 0 || $checkinKobo > self::MAX_CHECKIN_KOBO) throw new RuntimeException('The daily check-in reward must be between ₦0 and ' . \YoloTask\Money\Money::format(self::MAX_CHECKIN_KOBO) . '.');
        if ($id === null) {
            $pdo->prepare('INSERT INTO earner_levels (name, min_approved, min_approval_rate, max_active_reservations, is_active, withdrawal_fee_bps, checkin_reward_kobo, updated_by_user_id) VALUES (:n, :a, :r, :m, :act, :f, :c, :u)')
                ->execute(['n' => $name, 'a' => $minApproved, 'r' => $minRate, 'm' => $maxActive, 'act' => $active ? 1 : 0, 'f' => $withdrawalFeeBps, 'c' => $checkinKobo, 'u' => $adminId]);
            $id = (int) $pdo->lastInsertId();
            Audit::record($pdo, $adminId, 'admin.level_added', 'earner_level', $id);
        } else {
            $chk = $pdo->prepare('SELECT withdrawal_fee_bps, checkin_reward_kobo FROM earner_levels WHERE id = :id');
            $chk->execute(['id' => $id]);
            $old = $chk->fetch();
            if (!is_array($old)) throw new RuntimeException('Level not found.');
            $pdo->prepare('UPDATE earner_levels SET name = :n, min_approved = :a, min_approval_rate = :r, max_active_reservations = :m, is_active = :act, withdrawal_fee_bps = :f, checkin_reward_kobo = :c, updated_by_user_id = :u WHERE id = :id')
                ->execute(['n' => $name, 'a' => $minApproved, 'r' => $minRate, 'm' => $maxActive, 'act' => $active ? 1 : 0, 'f' => $withdrawalFeeBps, 'c' => $checkinKobo, 'u' => $adminId, 'id' => $id]);
            Audit::record($pdo, $adminId, 'admin.level_updated', 'earner_level', $id);
            $oldFee = $old['withdrawal_fee_bps'] === null ? null : (int) $old['withdrawal_fee_bps'];
            if ($oldFee !== $withdrawalFeeBps) Audit::record($pdo, $adminId, 'admin.level_withdrawal_fee_changed', 'earner_level', $id);
            if ((int) $old['checkin_reward_kobo'] !== $checkinKobo) Audit::record($pdo, $adminId, 'admin.level_checkin_reward_changed', 'earner_level', $id);
        }
        return $id;
    }

    public static function delete(PDO $pdo, int $adminId, int $id): void
    {
        $stmt = $pdo->prepare('DELETE FROM earner_levels WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) throw new RuntimeException('Level not found.');
        Audit::record($pdo, $adminId, 'admin.level_deleted', 'earner_level', $id);
    }
}
