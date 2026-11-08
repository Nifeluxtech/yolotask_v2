<?php
declare(strict_types=1);
namespace YoloTask\Campaigns;

use PDO;
use RuntimeException;
use YoloTask\Money\Money;
use YoloTask\Security\Audit;

/**
 * The admin-managed catalog: platforms and task types with their prices.
 * Each task type has one price the advertiser pays per approved task and the
 * reward the earner receives (base_price_kobo / base_reward_kobo); the
 * difference is platform revenue. Campaigns snapshot the price and reward
 * when they are created, so later price edits never change a campaign that
 * already exists.
 *
 * The retired premium prices (task_types.premium_*) are dormant columns
 * since migration 0017 and are never read or written here.
 */
final class Pricing
{
    public const MAX_PRICE_KOBO = 10000000; // ₦100,000 per task

    public const CUSTOM_SLUG = 'custom';

    public static function isCustom(?array $row): bool
    {
        return $row !== null && (string) ($row['slug'] ?? '') === self::CUSTOM_SLUG;
    }

    /** Custom-task rules from settings. @return array{enabled:bool,min:int,percent:int} */
    public static function customRules(PDO $pdo): array
    {
        return [
            'enabled' => \YoloTask\Core\Settings::int($pdo, 'campaigns.custom_enabled') === 1,
            'min' => max(1, \YoloTask\Core\Settings::int($pdo, 'campaigns.custom_min_price_naira')) * 100,
            'percent' => min(99, max(1, \YoloTask\Core\Settings::int($pdo, 'campaigns.custom_reward_percent'))),
        ];
    }

    /** Earner reward for an advertiser-set custom price: percent of price, rounded down to the kobo. */
    public static function customReward(int $priceKobo, int $percent): int
    {
        return intdiv($priceKobo * $percent, 100);
    }

    public static function platforms(PDO $pdo, bool $activeOnly = true): array
    {
        $sql = 'SELECT id, slug, name, domains, is_active, sort_order FROM platforms' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name';
        return $pdo->query($sql)->fetchAll() ?: [];
    }

    public static function taskTypes(PDO $pdo, bool $activeOnly = true): array
    {
        $sql = 'SELECT id, slug, name, proof_hint, base_price_kobo, base_reward_kobo, is_active, sort_order FROM task_types'
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
        $stmt = $pdo->prepare('SELECT id, slug, name, proof_hint, base_price_kobo, base_reward_kobo, is_active FROM task_types WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * Price per task for a task type: what the advertiser pays, what the
     * earner gets and the platform margin.
     * @return array{price:int,reward:int,margin:int}
     */
    public static function unit(array $taskType): array
    {
        $price = (int) $taskType['base_price_kobo'];
        $reward = (int) $taskType['base_reward_kobo'];
        return ['price' => $price, 'reward' => $reward, 'margin' => $price - $reward];
    }

    /** Admin: update a task type's prices, hint and active flag. */
    public static function saveTaskType(PDO $pdo, int $adminId, int $id, array $in): void
    {
        $row = self::taskType($pdo, $id);
        if ($row === null) throw new RuntimeException('Task type not found.');
        if (self::isCustom($row)) throw new RuntimeException('The custom task is priced by each advertiser. Change its rules in Task settings.');
        $vals = [];
        foreach (['base_price_kobo', 'base_reward_kobo'] as $k) {
            $v = $in[$k] ?? null;
            if (!is_int($v) || $v < 100 || $v > self::MAX_PRICE_KOBO) throw new RuntimeException('Prices and rewards must be between ₦1 and ' . Money::format(self::MAX_PRICE_KOBO) . '.');
            $vals[$k] = $v;
        }
        if ($vals['base_reward_kobo'] >= $vals['base_price_kobo']) {
            throw new RuntimeException('The earner reward must be lower than the advertiser price, so the margin is positive.');
        }
        $name = trim((string) ($in['name'] ?? $row['name']));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) throw new RuntimeException('Give the task type a name of 2 to 60 characters.');
        $hint = trim((string) ($in['proof_hint'] ?? $row['proof_hint']));
        if (mb_strlen($hint) > 255) throw new RuntimeException('The proof hint is too long.');
        $pdo->prepare('UPDATE task_types SET name = :n, proof_hint = :h, base_price_kobo = :bp, base_reward_kobo = :br, is_active = :a, updated_by_user_id = :u WHERE id = :id')
            ->execute(['n' => $name, 'h' => $hint, 'bp' => $vals['base_price_kobo'], 'br' => $vals['base_reward_kobo'], 'a' => !empty($in['is_active']) ? 1 : 0, 'u' => $adminId, 'id' => $id]);
        Audit::record($pdo, $adminId, 'admin.task_type_updated', 'task_type', $id);
    }

    /** Admin: add a task type with its price and reward. */
    public static function addTaskType(PDO $pdo, int $adminId, string $name, int $basePrice, int $baseReward): int
    {
        $name = trim($name);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        if ($slug === '' || mb_strlen($name) > 60) throw new RuntimeException('Give the task type a name of 2 to 60 characters.');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM task_types WHERE slug = :s');
        $stmt->execute(['s' => $slug]);
        if ((int) $stmt->fetchColumn() > 0) throw new RuntimeException('A task type with that name already exists.');
        $pdo->prepare('INSERT INTO task_types (slug, name, base_price_kobo, base_reward_kobo, sort_order, is_active, updated_by_user_id) VALUES (:s, :n, 0, 0, 999, 0, :u)')
            ->execute(['s' => substr($slug, 0, 40), 'n' => $name, 'u' => $adminId]);
        $id = (int) $pdo->lastInsertId();
        try {
            self::saveTaskType($pdo, $adminId, $id, ['name' => $name, 'base_price_kobo' => $basePrice, 'base_reward_kobo' => $baseReward, 'is_active' => 1, 'proof_hint' => '']);
        } catch (RuntimeException $e) {
            $pdo->prepare('DELETE FROM task_types WHERE id = :id')->execute(['id' => $id]);
            throw $e;
        }
        return $id;
    }

    /** Admin: platform name, link domains and active flag. */
    public static function savePlatform(PDO $pdo, int $adminId, int $id, string $name, string $domains, bool $active): void
    {
        $existing = self::platform($pdo, $id);
        if ($existing === null) throw new RuntimeException('Platform not found.');
        if (self::isCustom($existing)) throw new RuntimeException('Other app / website accepts any link. Turn it on or off in Task settings.');
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
