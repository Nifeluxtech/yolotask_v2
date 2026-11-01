<?php
declare(strict_types=1);
namespace YoloTask\Campaigns;

use PDO;
use RuntimeException;
use YoloTask\Security\Audit;

/**
 * Interest catalog and premium-task targeting (migration 0009).
 *
 * - Admin keeps the list: add, rename, reorder, disable (never deleted, so
 *   old selections survive a disable and come back on re-enable).
 * - Earners pick MIN..MAX interests; every new campaign picks MIN..MAX.
 * - Premium (tier "targeted") tasks are shown to and reservable by an earner
 *   only when the earner has at least one active interest AND either the
 *   campaign shares one active interest with them or the campaign has no
 *   active interest at all (campaigns made before interests existed, or
 *   whose interests were all disabled since: they match every earner who
 *   has chosen interests). Standard tasks ignore interests.
 * - Disabled interests are ignored on both sides and cannot be newly chosen.
 */
final class Interests
{
    public const MIN = 1;
    public const MAX = 3;

    /** @return list<array{id:int,name:string,sort_order:int,is_active:int,earners:int,campaigns:int}> */
    public static function all(PDO $pdo, bool $activeOnly = false): array
    {
        $rows = $pdo->query('SELECT i.id, i.name, i.sort_order, i.is_active,
                (SELECT COUNT(*) FROM user_interests ui WHERE ui.interest_id = i.id) earners,
                (SELECT COUNT(*) FROM campaign_interests ci WHERE ci.interest_id = i.id) campaigns
            FROM interests i' . ($activeOnly ? ' WHERE i.is_active = 1' : '') . ' ORDER BY i.is_active DESC, i.sort_order, i.name, i.id')->fetchAll() ?: [];
        foreach ($rows as &$r) foreach (['id', 'sort_order', 'is_active', 'earners', 'campaigns'] as $k) $r[$k] = (int) $r[$k];
        return $rows;
    }

    /** Active interests as id => name, in display order. */
    public static function options(PDO $pdo): array
    {
        $out = [];
        foreach (self::all($pdo, true) as $r) $out[$r['id']] = (string) $r['name'];
        return $out;
    }

    // ---------------------------------------------------------------- admin

    private static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 40) throw new RuntimeException('Interest names are 2 to 40 characters.');
        if (preg_match('/[<>"]/', $name)) throw new RuntimeException('Interest names cannot contain < > or ".');
        return $name;
    }

    private static function nameTaken(PDO $pdo, string $name, int $exceptId): bool
    {
        $s = $pdo->prepare('SELECT COUNT(*) FROM interests WHERE name = :n AND id <> :id');
        $s->execute(['n' => $name, 'id' => $exceptId]);
        return (int) $s->fetchColumn() > 0;
    }

    public static function add(PDO $pdo, int $adminId, string $name): int
    {
        $name = self::cleanName($name);
        if (self::nameTaken($pdo, $name, 0)) throw new RuntimeException('That interest already exists.');
        $next = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM interests')->fetchColumn();
        $pdo->prepare('INSERT INTO interests (name, sort_order, is_active, updated_by_user_id) VALUES (:n, :s, 1, :u)')->execute(['n' => $name, 's' => $next, 'u' => $adminId]);
        $id = (int) $pdo->lastInsertId();
        Audit::record($pdo, $adminId, 'admin.interest_added', 'interest', $id);
        return $id;
    }

    /** Rename and/or enable/disable. */
    public static function update(PDO $pdo, int $adminId, int $id, string $name, bool $active): void
    {
        $row = self::find($pdo, $id);
        if ($row === null) throw new RuntimeException('Interest not found.');
        $name = self::cleanName($name);
        if (self::nameTaken($pdo, $name, $id)) throw new RuntimeException('Another interest already has that name.');
        if (!$active && (int) $row['is_active'] === 1) {
            $left = (int) $pdo->query('SELECT COUNT(*) FROM interests WHERE is_active = 1')->fetchColumn();
            if ($left - 1 < self::MAX) throw new RuntimeException('Keep at least ' . self::MAX . ' interests active so earners and advertisers have a real choice.');
        }
        $pdo->prepare('UPDATE interests SET name = :n, is_active = :a, updated_by_user_id = :u WHERE id = :id')->execute(['n' => $name, 'a' => $active ? 1 : 0, 'u' => $adminId, 'id' => $id]);
        $event = 'admin.interest_updated';
        if ((int) $row['is_active'] === 1 && !$active) $event = 'admin.interest_disabled';
        if ((int) $row['is_active'] === 0 && $active) $event = 'admin.interest_enabled';
        Audit::record($pdo, $adminId, $event, 'interest', $id);
    }

    /** Move one place up or down among the active interests (renumbers 10, 20, 30 ...). */
    public static function move(PDO $pdo, int $adminId, int $id, string $dir): void
    {
        if (!in_array($dir, ['up', 'down'], true)) throw new RuntimeException('Choose up or down.');
        $ids = array_map(static fn (array $r): int => $r['id'], self::all($pdo));
        $pos = array_search($id, $ids, true);
        if ($pos === false) throw new RuntimeException('Interest not found.');
        $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
        if ($swap < 0 || $swap >= count($ids)) return;
        [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
        $pdo->beginTransaction();
        try {
            $u = $pdo->prepare('UPDATE interests SET sort_order = :s WHERE id = :id');
            foreach ($ids as $i => $iid) $u->execute(['s' => ($i + 1) * 10, 'id' => $iid]);
            Audit::record($pdo, $adminId, 'admin.interest_reordered', 'interest', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        $s = $pdo->prepare('SELECT id, name, sort_order, is_active FROM interests WHERE id = :id');
        $s->execute(['id' => $id]);
        $r = $s->fetch();
        return is_array($r) ? $r : null;
    }

    // ---------------------------------------------------------------- selections

    /**
     * Validate a posted selection (array of ids). Every id must be an active
     * interest; between MIN and MAX distinct ids.
     * @return list<int>
     */
    public static function parseSelection(PDO $pdo, mixed $input): array
    {
        if ($input === null || $input === '' || $input === []) throw new RuntimeException('Choose at least ' . self::MIN . ' interest.');
        if (!is_array($input)) $input = [$input];
        $ids = [];
        foreach ($input as $v) {
            if (!is_string($v) && !is_int($v)) throw new RuntimeException('Choose interests from the list.');
            if (!preg_match('/^\d{1,18}$/', (string) $v)) throw new RuntimeException('Choose interests from the list.');
            $ids[(int) $v] = true;
        }
        $ids = array_keys($ids);
        if (count($ids) < self::MIN) throw new RuntimeException('Choose at least ' . self::MIN . ' interest.');
        if (count($ids) > self::MAX) throw new RuntimeException('Choose up to ' . self::MAX . ' interests.');
        $active = self::options($pdo);
        foreach ($ids as $id) {
            if (!isset($active[$id])) throw new RuntimeException('One of the interests you chose is no longer available. Pick again.');
        }
        return $ids;
    }

    /** Active interests of a user, in display order: id => name. */
    public static function forUser(PDO $pdo, int $userId): array
    {
        $s = $pdo->prepare('SELECT i.id, i.name FROM user_interests ui JOIN interests i ON i.id = ui.interest_id WHERE ui.user_id = :u AND i.is_active = 1 ORDER BY i.sort_order, i.name');
        $s->execute(['u' => $userId]);
        $out = [];
        foreach ($s->fetchAll() ?: [] as $r) $out[(int) $r['id']] = (string) $r['name'];
        return $out;
    }

    /** Active interests of a campaign, in display order: id => name. */
    public static function forCampaign(PDO $pdo, int $campaignId): array
    {
        $s = $pdo->prepare('SELECT i.id, i.name FROM campaign_interests ci JOIN interests i ON i.id = ci.interest_id WHERE ci.campaign_id = :c AND i.is_active = 1 ORDER BY i.sort_order, i.name');
        $s->execute(['c' => $campaignId]);
        $out = [];
        foreach ($s->fetchAll() ?: [] as $r) $out[(int) $r['id']] = (string) $r['name'];
        return $out;
    }

    /** Active interest names for many campaigns at once: campaign id => list of names. */
    public static function namesForCampaigns(PDO $pdo, array $campaignIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $campaignIds)));
        if ($ids === []) return [];
        $in = implode(',', $ids);
        $out = [];
        foreach ($pdo->query("SELECT ci.campaign_id, i.name FROM campaign_interests ci JOIN interests i ON i.id = ci.interest_id WHERE i.is_active = 1 AND ci.campaign_id IN ({$in}) ORDER BY i.sort_order, i.name") ?: [] as $r) {
            $out[(int) $r['campaign_id']][] = (string) $r['name'];
        }
        return $out;
    }

    /** Earner settings: replace the earner's interests (1..3 active). */
    public static function saveForEarner(PDO $pdo, array $user, mixed $input): array
    {
        if ((string) $user['role'] !== 'earner') throw new RuntimeException('Only earner accounts choose interests.');
        $ids = self::parseSelection($pdo, $input);
        $uid = (int) $user['id'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM user_interests WHERE user_id = :u')->execute(['u' => $uid]);
            $ins = $pdo->prepare('INSERT INTO user_interests (user_id, interest_id) VALUES (:u, :i)');
            foreach ($ids as $id) $ins->execute(['u' => $uid, 'i' => $id]);
            Audit::record($pdo, $uid, 'account.interests_updated', 'user', $uid);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $ids;
    }

    /** Replace a campaign's interests. Caller validated the ids and holds any transaction it needs. */
    public static function setForCampaign(PDO $pdo, int $campaignId, array $ids): void
    {
        $pdo->prepare('DELETE FROM campaign_interests WHERE campaign_id = :c')->execute(['c' => $campaignId]);
        $ins = $pdo->prepare('INSERT INTO campaign_interests (campaign_id, interest_id) VALUES (:c, :i)');
        foreach ($ids as $id) $ins->execute(['c' => $campaignId, 'i' => (int) $id]);
    }

    // ---------------------------------------------------------------- targeting

    /**
     * SQL condition (on campaigns alias c) that keeps premium campaigns this
     * earner matches. Bind :ix_me to the earner id when the returned array's
     * second item is true.
     * @return array{0:string,1:bool}
     */
    public static function feedCondition(PDO $pdo, int $earnerId): array
    {
        if (self::forUser($pdo, $earnerId) === []) return ["c.tier <> 'targeted'", false];
        return ["(c.tier <> 'targeted'
            OR NOT EXISTS (SELECT 1 FROM campaign_interests xci JOIN interests xi ON xi.id = xci.interest_id AND xi.is_active = 1 WHERE xci.campaign_id = c.id)
            OR EXISTS (SELECT 1 FROM campaign_interests yci JOIN interests yi ON yi.id = yci.interest_id AND yi.is_active = 1
                JOIN user_interests yui ON yui.interest_id = yci.interest_id AND yui.user_id = :ix_me WHERE yci.campaign_id = c.id))", true];
    }

    /**
     * Why this earner cannot take this premium campaign, or null when they
     * can (always null for standard campaigns).
     */
    public static function premiumBlock(PDO $pdo, int $earnerId, array $campaign): ?string
    {
        if ((string) ($campaign['tier'] ?? '') !== 'targeted') return null;
        $mine = self::forUser($pdo, $earnerId);
        if ($mine === []) return 'Premium tasks are matched to your interests. Choose your interests to see them.';
        $theirs = self::forCampaign($pdo, (int) $campaign['id']);
        if ($theirs === [] || array_intersect_key($mine, $theirs) !== []) return null;
        return 'This premium task is for earners interested in ' . implode(', ', $theirs) . '. Update your interests to take tasks like it.';
    }
}
