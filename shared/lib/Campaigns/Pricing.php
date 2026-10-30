<?php
declare(strict_types=1);
namespace YoloTask\Campaigns;

use PDO;
use RuntimeException;
use YoloTask\Money\Money;
use YoloTask\Security\Audit;

/**
 * The admin-managed catalog: platforms and task types with their prices.
 * Each task type has a base (untargeted) and a premium (targeted) price the
 * advertiser pays per approved task, and the reward the earner receives.
 * The difference is platform revenue. Campaigns snapshot the price and
 * reward when they are created, so later price edits never change a
 * campaign that already exists.
 */
final class Pricing
{
    public const MAX_PRICE_KOBO = 10000000; // ₦100,000 per task

    public static function platforms(PDO $pdo, bool $activeOnly = true): array
    {
        $sql = 'SELECT id, slug, name, domains, is_active, sort_order FROM platforms' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name';
        return $pdo->query($sql)->fetchAll() ?: [];
    }

    public static function taskTypes(PDO $pdo, bool $activeOnly = true): array
    {
        $sql = 'SELECT id, slug, name, proof_hint, base_price_kobo, base_reward_kobo, premium_price_kobo, premium_reward_kobo, is_active, sort_order FROM task_types'
            . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name';
        return $pdo->query($sql)->fetchAll() ?: [];
    }

    public static function platform(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT id, slug, name, domains, is_active FROM platforms WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function taskType(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT id, slug, name, proof_hint, base_price_kobo, base_reward_kobo, premium_price_kobo, premium_reward_kobo, is_active FROM task_types WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * Price per task for a type and tier.
     * @return array{price:int,reward:int,margin:int}
     */
    public static function unit(array $taskType, string $tier): array
    {
        $premium = $tier === 'targeted';
        $price = (int) $taskType[$premium ? 'premium_price_kobo' : 'base_price_kobo'];
        $reward = (int) $taskType[$premium ? 'premium_reward_kobo' : 'base_reward_kobo'];
        return ['price' => $price, 'reward' => $reward, 'margin' => $price - $reward];
    }

    /** Premium default from a base amount: x1.5, rounded half-up to whole naira. */
    public static function premiumFrom(int $baseKobo): int
    {
        return intdiv($baseKobo * 3 + 100, 200) * 100;
    }

    /** Admin: update a task type's prices, hint and active flag. */
    public static function saveTaskType(PDO $pdo, int $adminId, int $id, array $in): void
    {
        $row = self::taskType($pdo, $id);
        if ($row === null) throw new RuntimeException('Task type not found.');
        $vals = [];
        foreach (['base_price_kobo', 'base_reward_kobo', 'premium_price_kobo', 'premium_reward_kobo'] as $k) {
            $v = $in[$k] ?? null;
            if (!is_int($v) || $v < 100 || $v > self::MAX_PRICE_KOBO) throw new RuntimeException('Prices and rewards must be between ₦1 and ' . Money::format(self::MAX_PRICE_KOBO) . '.');
            $vals[$k] = $v;
        }
        if ($vals['base_reward_kobo'] >= $vals['base_price_kobo'] || $vals['premium_reward_kobo'] >= $vals['premium_price_kobo']) {
            throw new RuntimeException('The earner reward must be lower than the advertiser price, so the margin is positive.');
        }
        $name = trim((string) ($in['name'] ?? $row['name']));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) throw new RuntimeException('Give the task type a name of 2 to 60 characters.');
        $hint = trim((string) ($in['proof_hint'] ?? $row['proof_hint']));
        if (mb_strlen($hint) > 255) throw new RuntimeException('The proof hint is too long.');
        $pdo->prepare('UPDATE task_types SET name = :n, proof_hint = :h, base_price_kobo = :bp, base_reward_kobo = :br, premium_price_kobo = :pp, premium_reward_kobo = :pr, is_active = :a, updated_by_user_id = :u WHERE id = :id')
            ->execute(['n' => $name, 'h' => $hint, 'bp' => $vals['base_price_kobo'], 'br' => $vals['base_reward_kobo'], 'pp' => $vals['premium_price_kobo'], 'pr' => $vals['premium_reward_kobo'], 'a' => !empty($in['is_active']) ? 1 : 0, 'u' => $adminId, 'id' => $id]);
        Audit::record($pdo, $adminId, 'admin.task_type_updated', 'task_type', $id);
    }

    /** Admin: add a task type; premium defaults to base x1.5 when not given. */
    public static function addTaskType(PDO $pdo, int $adminId, string $name, int $basePrice, int $baseReward): int
    {
        $name = trim($name);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        if ($slug === '' || mb_strlen($name) > 60) throw new RuntimeException('Give the task type a name of 2 to 60 characters.');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM task_types WHERE slug = :s');
        $stmt->execute(['s' => $slug]);
        if ((int) $stmt->fetchColumn() > 0) throw new RuntimeException('A task type with that name already exists.');
        $pdo->prepare('INSERT INTO task_types (slug, name, base_price_kobo, base_reward_kobo, premium_price_kobo, premium_reward_kobo, sort_order, is_active, updated_by_user_id) VALUES (:s, :n, 0, 0, 0, 0, 999, 0, :u)')
            ->execute(['s' => substr($slug, 0, 40), 'n' => $name, 'u' => $adminId]);
        $id = (int) $pdo->lastInsertId();
        try {
            self::saveTaskType($pdo, $adminId, $id, ['name' => $name, 'base_price_kobo' => $basePrice, 'base_reward_kobo' => $baseReward,
                'premium_price_kobo' => self::premiumFrom($basePrice), 'premium_reward_kobo' => self::premiumFrom($baseReward), 'is_active' => 1, 'proof_hint' => '']);
        } catch (RuntimeException $e) {
            $pdo->prepare('DELETE FROM task_types WHERE id = :id')->execute(['id' => $id]);
            throw $e;
        }
        return $id;
    }

    /** Admin: platform name, link domains and active flag. */
    public static function savePlatform(PDO $pdo, int $adminId, int $id, string $name, string $domains, bool $active): void
    {
        if (self::platform($pdo, $id) === null) throw new RuntimeException('Platform not found.');
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) throw new RuntimeException('Give the platform a name of 2 to 60 characters.');
        $list = CampaignLinks::parseDomains($domains);
        if ($list === []) throw new RuntimeException('List at least one link domain, for example instagram.com.');
        $pdo->prepare('UPDATE platforms SET name = :n, domains = :d, is_active = :a WHERE id = :id')
            ->execute(['n' => $name, 'd' => implode(',', $list), 'a' => $active ? 1 : 0, 'id' => $id]);
        Audit::record($pdo, $adminId, 'admin.platform_updated', 'platform', $id);
    }

    public static function addPlatform(PDO $pdo, int $adminId, string $name, string $domains): int
    {
        $name = trim($name);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        if ($slug === '' || mb_strlen($name) > 60) throw new RuntimeException('Give the platform a name of 2 to 60 characters.');
        $list = CampaignLinks::parseDomains($domains);
        if ($list === []) throw new RuntimeException('List at least one link domain, for example instagram.com.');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM platforms WHERE slug = :s');
        $stmt->execute(['s' => $slug]);
        if ((int) $stmt->fetchColumn() > 0) throw new RuntimeException('A platform with that name already exists.');
        $pdo->prepare('INSERT INTO platforms (slug, name, domains, sort_order) VALUES (:s, :n, :d, 999)')->execute(['s' => substr($slug, 0, 40), 'n' => $name, 'd' => implode(',', $list)]);
        $id = (int) $pdo->lastInsertId();
        Audit::record($pdo, $adminId, 'admin.platform_added', 'platform', $id);
        return $id;
    }
}
