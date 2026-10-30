<?php
declare(strict_types=1);
namespace YoloTask\Campaigns;

use PDO;
use RuntimeException;

/**
 * Versioned campaign instructions. The campaign row carries the current
 * text; every version (including the first) is kept in
 * campaign_instructions, and each submission records the version the earner
 * worked from, so a later edit never moves the goalposts on earlier work.
 */
final class Instructions
{
    /** @return array{instructions:string,proof:string} cleaned text */
    public static function clean(string $instructions, string $proof): array
    {
        $instructions = trim(str_replace("\r\n", "\n", $instructions));
        $proof = trim(str_replace(["\r\n", "\n"], ' ', $proof));
        if (mb_strlen($instructions) < 10 || mb_strlen($instructions) > 2000) throw new RuntimeException('Write instructions of 10 to 2,000 characters so earners know exactly what to do.');
        if (mb_strlen($proof) < 5 || mb_strlen($proof) > 500) throw new RuntimeException('Describe the proof you need (5 to 500 characters), for example "Screenshot showing you follow the page".');
        return ['instructions' => $instructions, 'proof' => $proof];
    }

    /** Record a version (inside the caller's transaction). */
    public static function record(PDO $pdo, int $campaignId, int $version, string $instructions, string $proof, int $userId): void
    {
        $pdo->prepare('INSERT INTO campaign_instructions (campaign_id, version, instructions, proof_requirements, created_by_user_id) VALUES (:c, :v, :i, :p, :u)')
            ->execute(['c' => $campaignId, 'v' => $version, 'i' => $instructions, 'p' => $proof, 'u' => $userId]);
    }

    /** Replace the current text of a draft (no new version: nobody has seen it). */
    public static function replaceDraft(PDO $pdo, int $campaignId, string $instructions, string $proof): void
    {
        $pdo->prepare('UPDATE campaign_instructions SET instructions = :i, proof_requirements = :p WHERE campaign_id = :c AND version = 1')
            ->execute(['i' => $instructions, 'p' => $proof, 'c' => $campaignId]);
    }

    public static function history(PDO $pdo, int $campaignId): array
    {
        $stmt = $pdo->prepare('SELECT version, instructions, proof_requirements, created_at FROM campaign_instructions WHERE campaign_id = :c ORDER BY version DESC');
        $stmt->execute(['c' => $campaignId]);
        return $stmt->fetchAll() ?: [];
    }

    public static function version(PDO $pdo, int $campaignId, int $version): ?array
    {
        $stmt = $pdo->prepare('SELECT version, instructions, proof_requirements, created_at FROM campaign_instructions WHERE campaign_id = :c AND version = :v');
        $stmt->execute(['c' => $campaignId, 'v' => $version]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }
}
