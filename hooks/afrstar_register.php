<?php
declare(strict_types=1);

/**
 * AfrStar hub: order registration for other sites on the same merchant.
 * Public URL: https://yolotask.com.ng/hooks/afrstar/register.php
 *
 * POST, JSON body {"order_sn": "...", "callback_url": "https://..."}
 * Headers:
 *   X-Hub-Client:    client name from config-private/afrstar-forward.php
 *   X-Hub-Timestamp: Unix seconds (must be within 5 minutes of now)
 *   X-Hub-Signature: hex HMAC-SHA256 of "<timestamp>.<raw body>" with the client's secret
 * Answers JSON: 201 registered, 200 updated, 401 bad auth, 409 taken by
 * another client, 422 invalid input. See docs/callbacks-and-reconciliation.md.
 */

use YoloTask\Core\Logger;
use YoloTask\Payments\CallbackForwarder;

if (!defined('YOLOTASK_APP_ROLE')) define('YOLOTASK_APP_ROLE', 'hook');
if (!defined('YOLOTASK_APP_ROOT')) define('YOLOTASK_APP_ROOT', dirname(__DIR__));
if (!defined('YOLOTASK_NO_SESSION')) define('YOLOTASK_NO_SESSION', true);
ini_set('display_errors', '0');
require_once YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$reply = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    $reply(405, ['ok' => false, 'error' => 'method_not_allowed']);
}
$declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$raw = $declared > 4096 ? '' : (string) file_get_contents('php://input', false, null, 0, 4097);
if ($declared > 4096 || strlen($raw) > 4096) $reply(413, ['ok' => false, 'error' => 'payload_too_large']);

$clients = CallbackForwarder::config()['clients'] ?? [];
$name = (string) ($_SERVER['HTTP_X_HUB_CLIENT'] ?? '');
$ts = (string) ($_SERVER['HTTP_X_HUB_TIMESTAMP'] ?? '');
$sig = strtolower(trim((string) ($_SERVER['HTTP_X_HUB_SIGNATURE'] ?? '')));
$client = $clients[$name] ?? null;
if ($client === null || !ctype_digit($ts) || abs(time() - (int) $ts) > 300
    || !hash_equals(hash_hmac('sha256', $ts . '.' . $raw, $client['secret']), $sig)) {
    Logger::error('payments.register_unauthorised', ['client' => substr(preg_replace('/[^a-z0-9_-]/', '', $name) ?? '', 0, 40)]);
    $reply(401, ['ok' => false, 'error' => 'unauthorised']);
}

$body = json_decode($raw, true);
if (!is_array($body)) $reply(422, ['ok' => false, 'error' => 'bad_json']);
$orderSn = is_string($body['order_sn'] ?? null) ? trim($body['order_sn']) : '';
$url = is_string($body['callback_url'] ?? null) ? trim($body['callback_url']) : '';

try {
    [$status, $out] = CallbackForwarder::register($client, $orderSn, $url);
} catch (Throwable $e) {
    Logger::error('payments.register_failed', ['exception' => get_class($e)]);
    $reply(500, ['ok' => false, 'error' => 'server_error']);
}
$reply($status, $out);
