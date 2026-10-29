<?php
declare(strict_types=1);
namespace YoloTask\Security;

use RuntimeException;

/** Token, code and keyed-hash helpers shared by every security feature. */
final class Crypto
{
    public static function key(): string
    {
        $config = function_exists('yolotask_config') ? yolotask_config() : [];
        $key = (string) (($config['security_key'] ?? null) ?: (getenv('YOLOTASK_SECURITY_KEY') ?: ''));
        if (strlen($key) < 32) throw new RuntimeException('Set a private YOLOTASK_SECURITY_KEY of at least 32 characters.');
        return $key;
    }

    public static function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, self::key());
    }

    /** URL-safe random token (43 chars for 32 bytes). */
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Zero-padded numeric code, e.g. 6 digits. */
    public static function code(int $digits = 6): string
    {
        return str_pad((string) random_int(0, (10 ** $digits) - 1), $digits, '0', STR_PAD_LEFT);
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
