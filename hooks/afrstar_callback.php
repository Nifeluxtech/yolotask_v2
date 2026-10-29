<?php
declare(strict_types=1);

/**
 * Shared runner for the AfrStar callback endpoints. Included by
 * hooks/afrstar_collection.php and hooks/afrstar_payout.php, which the
 * public files under apps/main/public/hooks/afrstar/ include.
 * No session, no CSRF: authenticity comes from the AfrStar signature.
 */

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Payments\CallbackProcessor;

if (!defined('YOLOTASK_APP_ROLE')) define('YOLOTASK_APP_ROLE', 'hook');
if (!defined('YOLOTASK_APP_ROOT')) define('YOLOTASK_APP_ROOT', dirname(__DIR__));
if (!defined('YOLOTASK_NO_SESSION')) define('YOLOTASK_NO_SESSION', true);
ini_set('display_errors', '0');
require_once YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

function yolotask_afrstar_callback(string $kind): never
{
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo 'METHOD_NOT_ALLOWED';
        exit;
    }
    $raw = (string) file_get_contents('php://input', false, null, 0, 65536);
    $post = is_array($_POST) ? $_POST : [];
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    try {
        $pdo = Database::connection();
        $result = $kind === 'payout'
            ? CallbackProcessor::payout($pdo, $raw, $post, $ip)
            : CallbackProcessor::collection($pdo, $raw, $post, $ip);
    } catch (Throwable $e) {
        Logger::error('payments.callback_crashed', ['kind' => $kind, 'exception' => get_class($e)]);
        $result = ['status' => 500, 'body' => 'ERROR', 'after' => []];
    }
    http_response_code($result['status']);
    header('Content-Length: ' . strlen($result['body']));
    echo $result['body'];
    // Answer AfrStar first (5 s timeout), then send any emails.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        flush();
    }
    foreach ($result['after'] as $job) {
        $job();
    }
    exit;
}
