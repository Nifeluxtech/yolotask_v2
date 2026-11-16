<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Marketplace\Products;

/*
 * Main site: product cover image /cover.php?id=<product> (migration 0022).
 * Covers are public (they are on the product page) but stored in private
 * runtime storage, re-encoded on upload. A resale listing without its own
 * cover shows the original product's cover.
 */
if (yolotask_role() !== 'main') yolotask_error_page(404);
$deny = static function (): never {
    http_response_code(404);
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
};
try {
    $pdo = Database::connection();
    $p = Products::find($pdo, (int) ($_GET['id'] ?? 0));
    $owner = $p !== null ? Products::coverOwner($pdo, $p) : null;
    $path = $owner !== null ? Products::privatePath('covers', $owner['cover_path']) : null;
} catch (Throwable $e) {
    Logger::error('marketplace.cover_failed', ['exception' => get_class($e)]);
    $deny();
}
if ($path === null) $deny();
$mime = in_array((string) $owner['cover_mime'], ['image/jpeg', 'image/png', 'image/webp'], true) ? (string) $owner['cover_mime'] : 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Cross-Origin-Resource-Policy: cross-origin');
readfile($path);
exit;
