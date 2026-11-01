<?php
declare(strict_types=1);
namespace YoloTask\Messaging;

use YoloTask\Core\Logger;

/**
 * Optional web-push driver (OneSignal). Disabled unless the private config
 * directory holds notifications.php returning
 *   ['onesignal' => ['enabled' => true, 'app_id' => '...', 'api_key' => '...']]
 * (see config/notifications.example.php). With no file, send() does nothing.
 * Users are addressed by OneSignal external id "yt-<userId>", which the
 * portal's OneSignal SDK login must set once push is switched on.
 */
final class Push
{
    public static function config(): ?array
    {
        static $cfg = false;
        if ($cfg !== false) return $cfg;
        $cfg = null;
        $dir = function_exists('yolotask_config') ? (string) (yolotask_config()['_config_dir'] ?? '') : '';
        $file = $dir !== '' ? $dir . '/notifications.php' : '';
        if ($file === '' || !is_file($file)) return null;
        $loaded = require $file;
        $os = is_array($loaded) && is_array($loaded['onesignal'] ?? null) ? $loaded['onesignal'] : [];
        if (!empty($os['enabled']) && is_string($os['app_id'] ?? null) && $os['app_id'] !== '' && is_string($os['api_key'] ?? null) && $os['api_key'] !== '') {
            $cfg = ['app_id' => $os['app_id'], 'api_key' => $os['api_key'], 'api_url' => (string) ($os['api_url'] ?? 'https://api.onesignal.com/notifications')];
        }
        return $cfg;
    }

    public static function isEnabled(): bool
    {
        return self::config() !== null;
    }

    public static function send(int $userId, string $title, string $body, ?string $url = null): bool
    {
        $cfg = self::config();
        if ($cfg === null || !function_exists('curl_init')) return false;
        $payload = ['app_id' => $cfg['app_id'], 'target_channel' => 'push', 'include_aliases' => ['external_id' => ['yt-' . $userId]],
            'headings' => ['en' => mb_substr($title, 0, 100)], 'contents' => ['en' => mb_substr($body, 0, 200)]];
        if ($url !== null) $payload['url'] = $url;
        $ch = curl_init($cfg['api_url']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Key ' . $cfg['api_key']],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($out === false || $code >= 300) {
            Logger::error('push.onesignal_failed', ['http' => $code]);
            return false;
        }
        return true;
    }
}
