<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Marketplace\Orders;
use YoloTask\Marketplace\Products;

/*
 * Main site: product file download /download.php?o=<order_sn>&t=<token>
 * (migration 0022). Only for a paid product order whose product delivers a
 * file. Files live in private runtime storage; this is the only way to read
 * one. Every other case is the same 404.
 */
if (yolotask_role() !== 'main') yolotask_error_page(404);
$deny = static function (): never {
    http_response_code(404);
    header('Cache-Control: no-store, private');
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
};
$sn = is_string($_GET['o'] ?? null) ? substr($_GET['o'], 0, 64) : '';
$token = is_string($_GET['t'] ?? null) ? substr($_GET['t'], 0, 64) : '';
try {
    $pdo = Database::connection();
    $order = $sn !== '' ? Orders::findForBuyer($pdo, $sn, $token) : null;
    if ($order === null || (string) $order['status'] !== 'paid' || (string) $order['kind'] !== 'product') $deny();
    $product = Products::find($pdo, (int) $order['product_id']);
    if ($product === null) $deny();
    $source = Products::deliverySource($pdo, $product);
    $path = $source['delivery_type'] === 'file' ? Products::privatePath('files', $source['delivery_path']) : null;
    if ($path === null) {
        Logger::error('marketplace.file_missing', ['product_id' => (int) $source['id']]);
        $deny();
    }
    $pdo->prepare('UPDATE mkt_orders SET download_count = download_count + 1 WHERE id = :id')->execute(['id' => (int) $order['id']]);
} catch (Throwable $e) {
    Logger::error('marketplace.download_failed', ['exception' => get_class($e)]);
    $deny();
}
$name = Products::cleanName((string) ($source['delivery_name'] ?? 'download'));
header('Content-Type: application/octet-stream');
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex');
while (ob_get_level() > 0) ob_end_clean();
readfile($path);
exit;
