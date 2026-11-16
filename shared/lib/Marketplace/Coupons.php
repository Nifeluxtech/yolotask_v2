<?php
declare(strict_types=1);
namespace YoloTask\Marketplace;

use PDO;
use RuntimeException;
use YoloTask\Core\Clock;
use YoloTask\Money\Money;
use YoloTask\Security\Audit;

/**
 * Seller coupons (migration 0022). A product owner (seller, or a reseller for
 * their own listings) creates codes: auto-generated or custom (4-32 letters,
 * digits, - or _; unique per owner, case-insensitive), percent (1-90%) or a
 * fixed naira amount off, for one of their products or all of them, with an
 * optional expiry (Lagos time) and maximum uses.
 *
 * The buyer enters the code at checkout; Orders validates it server-side
 * (Pricing::couponProblem: owner, scope, active, not expired, uses left) and
 * applies it to the charged price before fees. `uses` grows only when an
 * order with the coupon is confirmed paid (Orders::settlePaid).
 */
final class Coupons
{
    public static function forOwner(PDO $pdo, int $ownerId): array
    {
        $stmt = $pdo->prepare('SELECT c.*, p.title product_title FROM mkt_coupons c LEFT JOIN mkt_products p ON p.id = c.product_id WHERE c.owner_user_id = :u ORDER BY c.id DESC LIMIT 200');
        $stmt->execute(['u' => $ownerId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll() ?: []);
    }

    public static function findByCode(PDO $pdo, int $ownerId, string $code): ?array
    {
        $code = self::clean($code);
        if ($code === null) return null;
        $stmt = $pdo->prepare('SELECT * FROM mkt_coupons WHERE owner_user_id = :u AND code = :c');
        $stmt->execute(['u' => $ownerId, 'c' => $code]);
        $row = $stmt->fetch();
        return is_array($row) ? self::cast($row) : null;
    }

    /** Valid coupon for a product, or an exception with the reason the buyer sees. */
    public static function validFor(PDO $pdo, array $product, string $code): array
    {
        $c = self::findByCode($pdo, (int) $product['owner_user_id'], $code);
        if ($c === null) throw new RuntimeException('That coupon code is not valid for this product.');
        $problem = Pricing::couponProblem($c, (int) $product['id'], (int) $product['owner_user_id'], Clock::now()->getTimestamp());
        if ($problem !== null) throw new RuntimeException($problem);
        return $c;
    }

    /** Create a coupon. $in: code, kind, value (percent or naira), product_id, expires (Lagos datetime-local), max_uses. */
    public static function create(PDO $pdo, array $user, array $in): int
    {
        $ownerId = (int) $user['id'];
        if (Marketplace::isFrozen($pdo, $ownerId)) throw new RuntimeException('Your marketplace selling is on hold. Contact support.');
        $raw = trim((string) ($in['code'] ?? ''));
        $code = $raw === '' ? self::generate() : self::clean($raw);
        if ($code === null) throw new RuntimeException('Use 4 to 32 letters, digits, - or _ for the code, or leave it empty to generate one.');
        $kind = (string) ($in['kind'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
        $valueRaw = trim((string) ($in['value'] ?? ''));
        if ($kind === 'percent') {
            if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $valueRaw)) throw new RuntimeException('Enter the discount as a percent from 1 to 90.');
            $value = (int) round((float) $valueRaw * 100);
            if ($value < 100 || $value > Pricing::MAX_COUPON_BPS) throw new RuntimeException('Enter the discount as a percent from 1 to 90.');
        } else {
            try { $value = Money::toKobo($valueRaw); } catch (\InvalidArgumentException) { throw new RuntimeException('Enter the discount in naira, for example 500.'); }
            if ($value < 100 || $value > Pricing::MAX_PRICE) throw new RuntimeException('Enter a naira discount of at least ₦1.');
        }
        $productId = (int) ($in['product_id'] ?? 0);
        if ($productId > 0) {
            if (Products::forOwner($pdo, $ownerId, $productId) === null) throw new RuntimeException('Choose one of your own products.');
        } else {
            $productId = null;
        }
        $expires = null;
        $expRaw = trim((string) ($in['expires'] ?? ''));
        if ($expRaw !== '') {
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $expRaw, new \DateTimeZone('Africa/Lagos')) ?: \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $expRaw, new \DateTimeZone('Africa/Lagos'));
            if (!$dt) throw new RuntimeException('Enter the expiry as a date and time, or leave it empty.');
            if ($dt <= Clock::now()) throw new RuntimeException('The expiry must be in the future.');
            $expires = $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $maxRaw = trim((string) ($in['max_uses'] ?? ''));
        $max = null;
        if ($maxRaw !== '') {
            if (!preg_match('/^\d{1,7}$/', $maxRaw) || (int) $maxRaw < 1) throw new RuntimeException('Maximum uses must be a whole number of 1 or more, or empty for no limit.');
            $max = (int) $maxRaw;
        }
        try {
            $pdo->prepare('INSERT INTO mkt_coupons (owner_user_id, product_id, code, kind, value, expires_at, max_uses) VALUES (:o, :p, :c, :k, :v, :e, :m)')
                ->execute(['o' => $ownerId, 'p' => $productId, 'c' => $code, 'k' => $kind, 'v' => $value, 'e' => $expires, 'm' => $max]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') throw new RuntimeException('You already have a coupon with the code ' . $code . '.');
            throw $e;
        }
        $id = (int) $pdo->lastInsertId();
        Audit::record($pdo, $ownerId, 'marketplace.coupon_created', 'mkt_coupon', $id);
        return $id;
    }

    public static function setActive(PDO $pdo, array $user, int $id, bool $active): void
    {
        $stmt = $pdo->prepare('UPDATE mkt_coupons SET status = :s WHERE id = :id AND owner_user_id = :u');
        $stmt->execute(['s' => $active ? 'active' : 'disabled', 'id' => $id, 'u' => (int) $user['id']]);
        Audit::record($pdo, (int) $user['id'], $active ? 'marketplace.coupon_enabled' : 'marketplace.coupon_disabled', 'mkt_coupon', $id);
    }

    /** "20% off" / "₦500 off". */
    public static function label(array $c): string
    {
        return $c['kind'] === 'percent' ? \YoloTask\Money\Fees::percent((int) $c['value']) . ' off' : Money::format((int) $c['value']) . ' off';
    }

    public static function clean(string $code): ?string
    {
        $code = strtoupper(trim($code));
        return preg_match('/^[A-Z0-9_-]{4,32}$/', $code) ? $code : null;
    }

    public static function generate(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 8; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        return $out;
    }

    private static function cast(array $row): array
    {
        foreach (['id', 'owner_user_id', 'value', 'uses'] as $k) $row[$k] = (int) $row[$k];
        foreach (['product_id', 'max_uses'] as $k) $row[$k] = $row[$k] === null ? null : (int) $row[$k];
        return $row;
    }
}
