<?php
declare(strict_types=1);

/**
 * Korapay webhook (charges and payouts), migration 0021.
 * Public URL: https://yolotask.com.ng/hooks/korapay/webhook.php
 * (served by apps/main/public/hooks/korapay/webhook.php). Set it in the
 * Korapay dashboard (Settings → API Configuration → Webhook URL); charges
 * also send it as notification_url.
 * No session, no CSRF: authenticity comes from x-korapay-signature, and the
 * status/amount are always re-read from Korapay's verify endpoints.
 */

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Payments\KorapayProcessor;

if (!defined('YOLOTASK_APP_ROLE')) define('YOLOTASK_APP_ROLE', 'hook');
if (!defined('YOLOTASK_APP_ROOT')) define('YOLOTASK_APP_ROOT', dirname(__DIR__));
if (!defined('YOLOTASK_NO_SESSION')) define('YOLOTASK_NO_SESSION', true);
ini_set('display_errors', '0');
require_once YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'METHOD_NOT_ALLOWED';
    exit;
}
$max = 65536;
$declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$raw = $declared > $max ? '' : (string) file_get_contents('php://input', false, null, 0, $max + 1);
if ($declared > $max || strlen($raw) > $max) {
    http_response_code(413);
    echo 'PAYLOAD_TOO_LARGE';
    exit;
}
$signature = (string) ($_SERVER['HTTP_X_KORAPAY_SIGNATURE'] ?? '');
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
try {
    $pdo = Database::connection();
    $result = KorapayProcessor::webhook($pdo, $raw, $signature, $ip);
} catch (Throwable $e) {
    Logger::error('korapay.webhook_crashed', ['exception' => get_class($e)]);
    $result = ['status' => 500, 'body' => 'ERROR', 'after' => []];
}
http_response_code($result['status']);
header('Content-Length: ' . strlen($result['body']));
echo $result['body'];
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_end_flush();
    flush();
}
foreach ($result['after'] as $job) {
    try {
        $job();
    } catch (Throwable $e) {
        Logger::error('korapay.webhook_after_failed', ['exception' => get_class($e)]);
    }
}
exit;
