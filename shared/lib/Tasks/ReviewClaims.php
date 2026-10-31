<?php
declare(strict_types=1);
namespace YoloTask\Tasks;

use PDO;
use RuntimeException;
use YoloTask\Core\Settings;
use YoloTask\Security\Audit;

/**
 * Reviewer claims (locks) on queue items, so two reviewers never work the
 * same appeal or stale submission. A claim lasts reviews.claim_minutes
 * (default 30); an expired claim can be taken by anyone and
 * cron/every-five-minutes.php deletes expired rows. Deciding an item needs
 * the reviewer's own unexpired claim. Admins never need a claim.
 */
final class ReviewClaims
{
    public const TYPES = ['appeal', 'submission'];

    public static function minutes(PDO $pdo): int
    {
        return max(5, Settings::int($pdo, 'reviews.claim_minutes'));
    }

    /**
     * Claim an item (inside the caller's transaction, with the item row
     * already locked so concurrent claims serialize). Re-claiming your own
     * item extends it. Returns the expiry (UTC).
     */
    public static function claimLocked(PDO $pdo, string $type, int $itemId, int $reviewerId): string
    {
        self::type($type);
        $cur = self::lockRow($pdo, $type, $itemId);
        if ($cur !== null && (int) $cur['reviewer_user_id'] !== $reviewerId && !self::expired($cur)) {
            throw new RuntimeException('Another reviewer is working on this item until ' . yolotask_wat((string) $cur['expires_at'], 'g:i A') . '.');
        }
        $m = self::minutes($pdo);
        $pdo->prepare('INSERT INTO review_claims (item_type, item_id, reviewer_user_id, claimed_at, expires_at) VALUES (:t, :i, :r, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL :m MINUTE))
            ON DUPLICATE KEY UPDATE reviewer_user_id = VALUES(reviewer_user_id), claimed_at = VALUES(claimed_at), expires_at = VALUES(expires_at)')
            ->execute(['t' => $type, 'i' => $itemId, 'r' => $reviewerId, 'm' => $m]);
        Audit::record($pdo, $reviewerId, 'reviewer.claimed_' . $type, $type, $itemId);
        return (string) self::lockRow($pdo, $type, $itemId)['expires_at'];
    }

    /** Release your own claim (no-op when you do not hold it). */
    public static function release(PDO $pdo, string $type, int $itemId, int $reviewerId): bool
    {
        self::type($type);
        $stmt = $pdo->prepare('DELETE FROM review_claims WHERE item_type = :t AND item_id = :i AND reviewer_user_id = :r');
        $stmt->execute(['t' => $type, 'i' => $itemId, 'r' => $reviewerId]);
        if ($stmt->rowCount() > 0) Audit::record($pdo, $reviewerId, 'reviewer.released_' . $type, $type, $itemId);
        return $stmt->rowCount() > 0;
    }

    /** Inside a transaction: refuse unless this reviewer holds an unexpired claim. */
    public static function assertHeld(PDO $pdo, string $type, int $itemId, int $reviewerId): void
    {
        $cur = self::lockRow($pdo, $type, $itemId);
        if ($cur === null || (int) $cur['reviewer_user_id'] !== $reviewerId) throw new RuntimeException('Claim this item before deciding it, so no other reviewer works on it at the same time.');
        if (self::expired($cur)) throw new RuntimeException('Your claim on this item expired. Claim it again to decide it.');
    }

    /** Remove any claim on an item once it is decided (inside the caller's transaction). */
    public static function clear(PDO $pdo, string $type, int $itemId): void
    {
        $pdo->prepare('DELETE FROM review_claims WHERE item_type = :t AND item_id = :i')->execute(['t' => $type, 'i' => $itemId]);
    }

    /** Current claim (or null), with an "active" flag. */
    public static function current(PDO $pdo, string $type, int $itemId): ?array
    {
        $stmt = $pdo->prepare('SELECT rc.*, u.display_name reviewer_name, rc.expires_at > UTC_TIMESTAMP() AS active FROM review_claims rc JOIN users u ON u.id = rc.reviewer_user_id WHERE rc.item_type = :t AND rc.item_id = :i');
        $stmt->execute(['t' => $type, 'i' => $itemId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** Cron: delete expired claims. */
    public static function expire(PDO $pdo): int
    {
        return (int) $pdo->exec('DELETE FROM review_claims WHERE expires_at <= UTC_TIMESTAMP()');
    }

    private static function lockRow(PDO $pdo, string $type, int $itemId): ?array
    {
        $stmt = $pdo->prepare('SELECT item_type, item_id, reviewer_user_id, claimed_at, expires_at FROM review_claims WHERE item_type = :t AND item_id = :i FOR UPDATE');
        $stmt->execute(['t' => $type, 'i' => $itemId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private static function expired(array $claim): bool
    {
        return strtotime((string) $claim['expires_at'] . ' UTC') <= time();
    }

    private static function type(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) throw new RuntimeException('Unknown review item.');
    }
}
