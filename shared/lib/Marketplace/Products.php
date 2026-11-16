<?php
declare(strict_types=1);
namespace YoloTask\Marketplace;

use PDO;
use RuntimeException;
use YoloTask\Core\Logger;
use YoloTask\Money\Money;
use YoloTask\Security\Audit;
use YoloTask\Support\Storage;
use YoloTask\Support\Uploads;

/**
 * Marketplace products and resale listings (migration 0022).
 *
 * Sellers (advertisers) create products: title, cover image, description,
 * normal price and optional bonus (promo) price, who bears the platform fee,
 * affiliate commission % (0 = off, max 90), optional resell rights and their
 * price, and the delivery: a file (stored in private runtime storage,
 * runtime/marketplace/files) or a private link/text revealed after purchase.
 *
 * A resale listing (parent_product_id set) belongs to someone who bought the
 * resell rights. It has its own price, fee bearer and affiliate %, an optional
 * own title / description / cover, and delivers the original product's
 * content (source_product_id). It cannot offer resell rights itself.
 *
 * Every new product or listing, and every edit to its title, description,
 * cover or delivery, goes to admin review (pending_review) before it is live.
 * Price, fee and affiliate changes apply at once. Owners can pause and resume.
 */
final class Products
{
    public const COVER_MAX_BYTES = 3145728;   // 3 MB
    public const FILE_MAX_BYTES = 52428800;   // 50 MB (the host's upload_max_filesize may be lower)
    public const FILE_EXTENSIONS = ['pdf', 'zip', 'epub', 'mobi', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'mp3', 'm4a', 'wav', 'mp4', 'mov', 'jpg', 'jpeg', 'png', 'webp', 'psd', 'ai', 'svg'];
    public const STATUSES = ['pending_review', 'live', 'paused', 'rejected'];

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT p.*, u.display_name owner_name, u.email owner_email, u.status owner_status, u.role owner_role,
                (SELECT 1 FROM mkt_frozen_sellers f WHERE f.user_id = p.owner_user_id) owner_frozen
            FROM mkt_products p JOIN users u ON u.id = p.owner_user_id WHERE p.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? self::cast($row) : null;
    }

    public static function forOwner(PDO $pdo, int $ownerId, int $id): ?array
    {
        $p = self::find($pdo, $id);
        return $p !== null && $p['owner_user_id'] === $ownerId ? $p : null;
    }

    /** Can the public buy it right now (live, owner active and not frozen)? */
    public static function isBuyable(array $p): bool
    {
        return $p['status'] === 'live' && (string) ($p['owner_status'] ?? 'active') === 'active' && empty($p['owner_frozen']);
    }

    /** The product that holds the delivery content (itself, or the original of a resale listing). */
    public static function deliverySource(PDO $pdo, array $p): array
    {
        if ($p['source_product_id'] === null) return $p;
        $src = self::find($pdo, (int) $p['source_product_id']);
        if ($src === null) throw new RuntimeException('The original product is missing.');
        return $src;
    }

    /** Effective cover row (a resale listing without its own cover shows the original's). */
    public static function coverOwner(PDO $pdo, array $p): ?array
    {
        if ($p['cover_path'] !== null) return $p;
        if ($p['source_product_id'] !== null) {
            $src = self::find($pdo, (int) $p['source_product_id']);
            if ($src !== null && $src['cover_path'] !== null) return $src;
        }
        return null;
    }

    /** Products owned by a user (their products and resale listings), newest first. */
    public static function listForOwner(PDO $pdo, int $ownerId): array
    {
        $stmt = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM mkt_orders o WHERE o.product_id = p.id AND o.kind = 'product' AND o.status = 'paid') paid_orders,
                (SELECT COALESCE(SUM(o.seller_kobo), 0) FROM mkt_orders o WHERE o.product_id = p.id AND o.kind = 'product' AND o.status = 'paid') seller_total
            FROM mkt_products p WHERE p.owner_user_id = :u ORDER BY p.id DESC LIMIT 200");
        $stmt->execute(['u' => $ownerId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll() ?: []);
    }

    /** Live products for the marketplace tab (owner active and not frozen), newest first. */
    public static function liveList(PDO $pdo, string $q, int $page, int $perPage = 20): array
    {
        $where = "p.status = 'live' AND u.status = 'active' AND NOT EXISTS (SELECT 1 FROM mkt_frozen_sellers f WHERE f.user_id = p.owner_user_id)";
        $args = [];
        if ($q !== '') { $where .= ' AND p.title LIKE :q'; $args['q'] = '%' . addcslashes($q, '%_\\') . '%'; }
        $res = \YoloTask\Support\Pagination::query($pdo, "SELECT COUNT(*) FROM mkt_products p JOIN users u ON u.id = p.owner_user_id WHERE {$where}",
            "SELECT p.*, u.display_name owner_name FROM mkt_products p JOIN users u ON u.id = p.owner_user_id WHERE {$where} ORDER BY p.sales_count DESC, p.id DESC", $args, $page, $perPage);
        $res['rows'] = array_map([self::class, 'cast'], $res['rows']);
        return $res;
    }

    /**
     * Create or edit a product (not a resale listing).
     * @param array<string,mixed> $in raw POST
     * @param array<string,mixed> $files raw $_FILES
     */
    public static function save(PDO $pdo, array $user, ?int $id, array $in, array $files): int
    {
        self::assertSeller($pdo, $user);
        $ownerId = (int) $user['id'];
        $existing = $id !== null ? self::forOwner($pdo, $ownerId, $id) : null;
        if ($id !== null && $existing === null) throw new RuntimeException('That product does not exist.');
        if ($existing !== null && $existing['parent_product_id'] !== null) return self::saveResale($pdo, $user, $existing, $in, $files);
        if ($existing === null && (string) $user['role'] !== 'advertiser') throw new RuntimeException('Only advertiser accounts can create products. Buy resell rights to list a product as your own.');

        $f = self::commonFields($in, $existing);
        $resell = !empty($in['resell_allowed']);
        $resellPrice = null;
        if ($resell) {
            $resellPrice = self::naira((string) ($in['resell_price'] ?? ''), 'resell rights price');
            if ($resellPrice < Pricing::MIN_PRICE || $resellPrice > Pricing::MAX_PRICE) throw new RuntimeException('The resell rights price must be between ' . Money::format(Pricing::MIN_PRICE) . ' and ' . Money::format(Pricing::MAX_PRICE) . '.');
        }
        $type = (string) ($in['delivery_type'] ?? 'link') === 'file' ? 'file' : 'link';
        $text = trim(str_replace("\r\n", "\n", (string) ($in['delivery_text'] ?? '')));
        if ($type === 'link') {
            if (mb_strlen($text) < 5 || mb_strlen($text) > 5000) throw new RuntimeException('Enter the private link or text buyers get after paying (5 to 5,000 characters).');
        } elseif (mb_strlen($text) > 5000) {
            throw new RuntimeException('Keep the note for buyers to 5,000 characters or fewer.');
        }
        $deliveryFile = self::hasUpload($files['delivery_file'] ?? null) ? self::storeFile($files['delivery_file']) : null;
        if ($type === 'file' && $deliveryFile === null && ($existing === null || $existing['delivery_path'] === null)) {
            throw new RuntimeException('Upload the file buyers will download.');
        }
        $cover = self::hasUpload($files['cover'] ?? null) ? self::storeCover($files['cover']) : null;

        try {
            if ($existing === null) {
                $pdo->prepare("INSERT INTO mkt_products (owner_user_id, title, description, cover_path, cover_mime, price_kobo, bonus_price_kobo, fee_bearer, affiliate_percent, resell_allowed, resell_price_kobo,
                        delivery_type, delivery_path, delivery_name, delivery_bytes, delivery_text, status)
                    VALUES (:o, :t, :d, :cp, :cm, :p, :b, :fb, :ap, :ra, :rp, :dt, :dp, :dn, :db, :dx, 'pending_review')")
                    ->execute(['o' => $ownerId, 't' => $f['title'], 'd' => $f['description'], 'cp' => $cover['path'] ?? null, 'cm' => $cover['mime'] ?? null,
                        'p' => $f['price'], 'b' => $f['bonus'], 'fb' => $f['fee_bearer'], 'ap' => $f['affiliate'], 'ra' => $resell ? 1 : 0, 'rp' => $resellPrice,
                        'dt' => $type, 'dp' => $deliveryFile['path'] ?? null, 'dn' => $deliveryFile['name'] ?? null, 'db' => $deliveryFile['bytes'] ?? null, 'dx' => $text !== '' ? $text : null]);
                $newId = (int) $pdo->lastInsertId();
                Audit::record($pdo, $ownerId, 'marketplace.product_created', 'mkt_product', $newId);
                return $newId;
            }
            $contentChanged = $f['title'] !== $existing['title'] || $f['description'] !== $existing['description'] || $cover !== null || $deliveryFile !== null
                || $type !== $existing['delivery_type'] || $text !== (string) ($existing['delivery_text'] ?? '');
            $status = ($contentChanged || $existing['status'] === 'rejected') ? 'pending_review' : $existing['status'];
            $pdo->prepare('UPDATE mkt_products SET title = :t, description = :d, cover_path = COALESCE(:cp, cover_path), cover_mime = COALESCE(:cm, cover_mime), price_kobo = :p, bonus_price_kobo = :b,
                    fee_bearer = :fb, affiliate_percent = :ap, resell_allowed = :ra, resell_price_kobo = :rp, delivery_type = :dt, delivery_path = COALESCE(:dp, delivery_path),
                    delivery_name = COALESCE(:dn, delivery_name), delivery_bytes = COALESCE(:db, delivery_bytes), delivery_text = :dx, status = :s,
                    reject_reason = IF(:s2 = \'pending_review\', NULL, reject_reason) WHERE id = :id')
                ->execute(['t' => $f['title'], 'd' => $f['description'], 'cp' => $cover['path'] ?? null, 'cm' => $cover['mime'] ?? null, 'p' => $f['price'], 'b' => $f['bonus'],
                    'fb' => $f['fee_bearer'], 'ap' => $f['affiliate'], 'ra' => $resell ? 1 : 0, 'rp' => $resellPrice, 'dt' => $type, 'dp' => $deliveryFile['path'] ?? null,
                    'dn' => $deliveryFile['name'] ?? null, 'db' => $deliveryFile['bytes'] ?? null, 'dx' => $text !== '' ? $text : null, 's' => $status, 's2' => $status, 'id' => $existing['id']]);
            if ($cover !== null && $existing['cover_path'] !== null) self::unlinkPrivate('covers', (string) $existing['cover_path']);
            if ($deliveryFile !== null && $existing['delivery_path'] !== null) self::unlinkPrivate('files', (string) $existing['delivery_path']);
            Audit::record($pdo, $ownerId, 'marketplace.product_updated', 'mkt_product', $existing['id']);
            return $existing['id'];
        } catch (\Throwable $e) {
            if ($cover !== null) self::unlinkPrivate('covers', $cover['path']);
            if ($deliveryFile !== null) self::unlinkPrivate('files', $deliveryFile['path']);
            throw $e;
        }
    }

    /** Create a resale listing from resell rights the user owns. */
    public static function createResale(PDO $pdo, array $user, int $parentId, array $in, array $files): int
    {
        self::assertSeller($pdo, $user);
        $uid = (int) $user['id'];
        $rights = $pdo->prepare('SELECT * FROM mkt_resell_rights WHERE product_id = :p AND user_id = :u');
        $rights->execute(['p' => $parentId, 'u' => $uid]);
        $r = $rights->fetch();
        if (!is_array($r)) throw new RuntimeException('Buy the resell rights for this product first.');
        if ($r['listing_product_id'] !== null) throw new RuntimeException('You already have a listing for this product. Edit it in My Products.');
        $parent = self::find($pdo, $parentId);
        if ($parent === null) throw new RuntimeException('That product does not exist.');
        $source = self::deliverySource($pdo, $parent);
        $in['title'] = trim((string) ($in['title'] ?? '')) !== '' ? $in['title'] : $parent['title'];
        $in['description'] = trim((string) ($in['description'] ?? '')) !== '' ? $in['description'] : $parent['description'];
        $f = self::commonFields($in, null);
        $cover = self::hasUpload($files['cover'] ?? null) ? self::storeCover($files['cover']) : null;
        try {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO mkt_products (owner_user_id, parent_product_id, source_product_id, title, description, cover_path, cover_mime, price_kobo, bonus_price_kobo, fee_bearer,
                    affiliate_percent, resell_allowed, delivery_type, status) VALUES (:o, :pp, :sp, :t, :d, :cp, :cm, :p, :b, :fb, :ap, 0, :dt, 'pending_review')")
                ->execute(['o' => $uid, 'pp' => $parentId, 'sp' => $source['id'], 't' => $f['title'], 'd' => $f['description'], 'cp' => $cover['path'] ?? null, 'cm' => $cover['mime'] ?? null,
                    'p' => $f['price'], 'b' => $f['bonus'], 'fb' => $f['fee_bearer'], 'ap' => $f['affiliate'], 'dt' => $source['delivery_type']]);
            $id = (int) $pdo->lastInsertId();
            $upd = $pdo->prepare('UPDATE mkt_resell_rights SET listing_product_id = :l WHERE id = :id AND listing_product_id IS NULL');
            $upd->execute(['l' => $id, 'id' => (int) $r['id']]);
            if ($upd->rowCount() !== 1) throw new RuntimeException('You already have a listing for this product.');
            Audit::record($pdo, $uid, 'marketplace.resale_created', 'mkt_product', $id);
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($cover !== null) self::unlinkPrivate('covers', $cover['path']);
            throw $e;
        }
    }

    private static function saveResale(PDO $pdo, array $user, array $existing, array $in, array $files): int
    {
        $f = self::commonFields($in, $existing);
        $cover = self::hasUpload($files['cover'] ?? null) ? self::storeCover($files['cover']) : null;
        $contentChanged = $f['title'] !== $existing['title'] || $f['description'] !== $existing['description'] || $cover !== null;
        $status = ($contentChanged || $existing['status'] === 'rejected') ? 'pending_review' : $existing['status'];
        try {
            $pdo->prepare('UPDATE mkt_products SET title = :t, description = :d, cover_path = COALESCE(:cp, cover_path), cover_mime = COALESCE(:cm, cover_mime), price_kobo = :p, bonus_price_kobo = :b,
                    fee_bearer = :fb, affiliate_percent = :ap, status = :s, reject_reason = IF(:s2 = \'pending_review\', NULL, reject_reason) WHERE id = :id')
                ->execute(['t' => $f['title'], 'd' => $f['description'], 'cp' => $cover['path'] ?? null, 'cm' => $cover['mime'] ?? null, 'p' => $f['price'], 'b' => $f['bonus'],
                    'fb' => $f['fee_bearer'], 'ap' => $f['affiliate'], 's' => $status, 's2' => $status, 'id' => $existing['id']]);
        } catch (\Throwable $e) {
            if ($cover !== null) self::unlinkPrivate('covers', $cover['path']);
            throw $e;
        }
        if ($cover !== null && $existing['cover_path'] !== null) self::unlinkPrivate('covers', (string) $existing['cover_path']);
        Audit::record($pdo, (int) $user['id'], 'marketplace.resale_updated', 'mkt_product', $existing['id']);
        return $existing['id'];
    }

    /** Owner: pause a live product, or resume a paused one. */
    public static function setPaused(PDO $pdo, array $user, int $id, bool $pause): void
    {
        $p = self::forOwner($pdo, (int) $user['id'], $id);
        if ($p === null) throw new RuntimeException('That product does not exist.');
        if ($pause && $p['status'] !== 'live') throw new RuntimeException('Only a live product can be paused.');
        if (!$pause && $p['status'] !== 'paused') throw new RuntimeException('Only a paused product can be resumed.');
        $pdo->prepare('UPDATE mkt_products SET status = :s WHERE id = :id AND status = :was')->execute(['s' => $pause ? 'paused' : 'live', 'was' => $pause ? 'live' : 'paused', 'id' => $id]);
        Audit::record($pdo, (int) $user['id'], $pause ? 'marketplace.product_paused' : 'marketplace.product_resumed', 'mkt_product', $id);
    }

    /** Admin: approve (live) or reject a product waiting for review. */
    public static function review(PDO $pdo, int $adminId, int $id, bool $approve, string $reason = ''): array
    {
        $reason = trim($reason);
        if (!$approve && (mb_strlen($reason) < 3 || mb_strlen($reason) > 255)) throw new RuntimeException('Give the seller a reason (3 to 255 characters).');
        $stmt = $pdo->prepare("UPDATE mkt_products SET status = :s, reject_reason = :r, reviewed_by_user_id = :a, reviewed_at = UTC_TIMESTAMP() WHERE id = :id AND status = 'pending_review'");
        $stmt->execute(['s' => $approve ? 'live' : 'rejected', 'r' => $approve ? null : $reason, 'a' => $adminId, 'id' => $id]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('This product is no longer waiting for review.');
        Audit::record($pdo, $adminId, $approve ? 'admin.marketplace_product_approved' : 'admin.marketplace_product_rejected', 'mkt_product', $id);
        $p = self::find($pdo, $id);
        if ($p !== null) {
            $url = 'my-products.php';
            \YoloTask\Messaging\Notifications::notify($pdo, $p['owner_user_id'], 'marketplace', $approve ? 'Product approved: ' . $p['title'] : 'Product not approved: ' . $p['title'],
                $approve ? 'Your product is live on the YOLOTASK marketplace.' : 'Reason: ' . $reason . '. Edit it and it goes back for review.', $url,
                'mkt-review:' . $id . ':' . ($approve ? 'ok' : 'no') . ':' . time(), [
                    'subject' => $approve ? 'Your product is live on YOLOTASK' : 'Your product was not approved',
                    'heading' => $approve ? 'Product approved' : 'Product not approved',
                    'lines' => $approve ? ['"' . $p['title'] . '" is now live. Share your product link or let affiliates promote it.'] : ['"' . $p['title'] . '" was not approved.', 'Reason: ' . $reason, 'Edit the product and it goes back for review.'],
                    'button' => ['label' => 'My Products', 'url' => yolotask_portal_url((string) $p['owner_role']) . '/my-products.php'],
                ]);
        }
        return $p ?? [];
    }

    public static function pendingCount(PDO $pdo): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM mkt_products WHERE status = 'pending_review'")->fetchColumn();
    }

    public static function adminList(PDO $pdo, string $status, string $q, int $page, int $perPage = 25): array
    {
        $where = ['1 = 1'];
        $args = [];
        if (in_array($status, self::STATUSES, true)) { $where[] = 'p.status = :s'; $args['s'] = $status; }
        if ($q !== '') { $where[] = '(p.title LIKE :q OR u.email LIKE :q2)'; $args += ['q' => '%' . addcslashes($q, '%_\\') . '%', 'q2' => '%' . addcslashes($q, '%_\\') . '%']; }
        $w = implode(' AND ', $where);
        $res = \YoloTask\Support\Pagination::query($pdo, "SELECT COUNT(*) FROM mkt_products p JOIN users u ON u.id = p.owner_user_id WHERE {$w}",
            "SELECT p.*, u.display_name owner_name, u.email owner_email, u.role owner_role, (SELECT 1 FROM mkt_frozen_sellers f WHERE f.user_id = p.owner_user_id) owner_frozen,
                pp.title parent_title FROM mkt_products p JOIN users u ON u.id = p.owner_user_id LEFT JOIN mkt_products pp ON pp.id = p.parent_product_id
             WHERE {$w} ORDER BY p.status = 'pending_review' DESC, p.id DESC", $args, $page, $perPage);
        $res['rows'] = array_map([self::class, 'cast'], $res['rows']);
        return $res;
    }

    /** Absolute path of a stored private file, or null when it is missing. */
    public static function privatePath(string $kind, ?string $rel): ?string
    {
        if ($rel === null || !preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.[a-z0-9]{1,5}$#', $rel)) return null;
        $dir = Storage::root() . '/marketplace/' . ($kind === 'covers' ? 'covers' : 'files');
        $path = $dir . '/' . $rel;
        return is_file($path) ? $path : null;
    }

    /** @return array{title:string,description:string,price:int,bonus:?int,fee_bearer:string,affiliate:int} */
    private static function commonFields(array $in, ?array $existing): array
    {
        $title = trim((string) preg_replace('/\s+/', ' ', (string) ($in['title'] ?? '')));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 120) throw new RuntimeException('Give the product a title of 3 to 120 characters.');
        $description = trim(str_replace("\r\n", "\n", (string) ($in['description'] ?? '')));
        if (mb_strlen($description) < 10 || mb_strlen($description) > 5000) throw new RuntimeException('Describe the product in 10 to 5,000 characters.');
        $price = self::naira((string) ($in['price'] ?? ''), 'normal price');
        if ($price < Pricing::MIN_PRICE || $price > Pricing::MAX_PRICE) throw new RuntimeException('The normal price must be between ' . Money::format(Pricing::MIN_PRICE) . ' and ' . Money::format(Pricing::MAX_PRICE) . '.');
        $bonusRaw = trim((string) ($in['bonus_price'] ?? ''));
        $bonus = null;
        if ($bonusRaw !== '') {
            $bonus = self::naira($bonusRaw, 'bonus price');
            if ($bonus >= $price) throw new RuntimeException('The bonus price must be lower than the normal price. Leave it empty for no bonus price.');
            if ($bonus < Pricing::MIN_PRICE) throw new RuntimeException('The bonus price must be at least ' . Money::format(Pricing::MIN_PRICE) . '.');
        }
        $feeBearer = (string) ($in['fee_bearer'] ?? 'customer') === 'seller' ? 'seller' : 'customer';
        $aff = trim((string) ($in['affiliate_percent'] ?? '0'));
        if (!preg_match('/^\d{1,2}$/', $aff === '' ? '0' : $aff) || (int) $aff > Pricing::MAX_AFFILIATE_PERCENT) throw new RuntimeException('Affiliate commission must be a whole percent from 0 to ' . Pricing::MAX_AFFILIATE_PERCENT . '.');
        return ['title' => $title, 'description' => $description, 'price' => $price, 'bonus' => $bonus, 'fee_bearer' => $feeBearer, 'affiliate' => (int) $aff];
    }

    private static function naira(string $raw, string $what): int
    {
        try {
            return Money::toKobo($raw);
        } catch (\InvalidArgumentException) {
            throw new RuntimeException('Enter the ' . $what . ' in naira, for example 2500.');
        }
    }

    private static function assertSeller(PDO $pdo, array $user): void
    {
        if (!in_array((string) $user['role'], ['advertiser', 'earner'], true)) throw new RuntimeException('Only earner and advertiser accounts can sell.');
        if (empty($user['email_verified_at'])) throw new RuntimeException('Confirm your email before selling.');
        if (Marketplace::isFrozen($pdo, (int) $user['id'])) throw new RuntimeException('Your marketplace selling is on hold. Contact support.');
    }

    public static function hasUpload(mixed $file): bool
    {
        return is_array($file) && isset($file['error']) && !is_array($file['error']) && (int) $file['error'] !== UPLOAD_ERR_NO_FILE;
    }

    /** @return array{path:string,mime:string} */
    private static function storeCover(array $file): array
    {
        try {
            $img = Uploads::image($file, self::COVER_MAX_BYTES);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Cover image: ' . str_replace(['screenshot of your proof', 'screenshot'], ['image', 'image'], $e->getMessage()));
        }
        return ['path' => self::writePrivate('covers', $img['bytes'], $img['ext']), 'mime' => $img['mime']];
    }

    /** @return array{path:string,name:string,bytes:int} */
    private static function storeFile(array $file): array
    {
        $err = (int) $file['error'];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('The product file is too large for upload. Use a private link instead.');
        if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('The file upload did not finish. Please try again.');
        $tmp = (string) $file['tmp_name'];
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) throw new RuntimeException('The file upload did not finish. Please try again.');
        $size = (int) @filesize($tmp);
        if ($size <= 0) throw new RuntimeException('The product file is empty.');
        if ($size > self::FILE_MAX_BYTES) throw new RuntimeException('The product file is larger than ' . (self::FILE_MAX_BYTES / 1048576) . ' MB. Use a private link instead.');
        $name = self::cleanName((string) ($file['name'] ?? 'file'));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::FILE_EXTENSIONS, true)) throw new RuntimeException('Upload a ' . implode(', ', self::FILE_EXTENSIONS) . ' file, or use a private link.');
        $bytes = (string) file_get_contents($tmp);
        return ['path' => self::writePrivate('files', $bytes, $ext), 'name' => $name, 'bytes' => strlen($bytes)];
    }

    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^A-Za-z0-9._ \-]/', '_', $name);
        $name = trim($name, ' .');
        return mb_substr($name !== '' ? $name : 'file', 0, 150);
    }

    private static function writePrivate(string $kind, string $bytes, string $ext): string
    {
        $sub = gmdate('Y/m');
        $dir = Storage::dir('marketplace/' . $kind . '/' . $sub);
        if ($dir === null) {
            Logger::error('marketplace.mkdir_failed', ['kind' => $kind]);
            throw new RuntimeException('Uploads are not available right now. Please try again later.');
        }
        $rel = $sub . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        if (@file_put_contents($dir . '/' . basename($rel), $bytes, LOCK_EX) !== strlen($bytes)) {
            Logger::error('marketplace.write_failed', ['kind' => $kind]);
            throw new RuntimeException('Uploads are not available right now. Please try again later.');
        }
        @chmod($dir . '/' . basename($rel), 0600);
        return $rel;
    }

    private static function unlinkPrivate(string $kind, string $rel): void
    {
        $path = self::privatePath($kind, $rel);
        if ($path !== null) @unlink($path);
    }

    public static function cast(array $row): array
    {
        foreach (['id', 'owner_user_id', 'price_kobo', 'affiliate_percent', 'resell_allowed', 'sales_count'] as $k) if (isset($row[$k])) $row[$k] = (int) $row[$k];
        foreach (['parent_product_id', 'source_product_id', 'bonus_price_kobo', 'resell_price_kobo', 'delivery_bytes'] as $k) if (array_key_exists($k, $row)) $row[$k] = $row[$k] === null ? null : (int) $row[$k];
        return $row;
    }
}
