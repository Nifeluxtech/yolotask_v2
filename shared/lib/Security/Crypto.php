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

    private const SEAL_PREFIX = 'enc1:';

    /**
     * Encrypt a sensitive field (bank account numbers) at rest with
     * libsodium secretbox, keyed from the private security key. Output:
     * "enc1:" + base64(nonce || ciphertext). Changing security_key makes
     * sealed values unreadable, so keep that key stable and backed up.
     */
    public static function seal(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::SEAL_PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::sealKey()));
    }

    /** Decrypt a sealed field; legacy plaintext (no prefix) is returned unchanged. */
    public static function reveal(?string $value): string
    {
        $value = (string) $value;
        if (!str_starts_with($value, self::SEAL_PREFIX)) return $value;
        $raw = base64_decode(substr($value, strlen(self::SEAL_PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('Sealed value is corrupt.');
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::sealKey());
        if ($plain === false) throw new RuntimeException('Sealed value cannot be decrypted with the current security key.');
        return $plain;
    }

    public static function isSealed(?string $value): bool
    {
        return str_starts_with((string) $value, self::SEAL_PREFIX);
    }

    private static function sealKey(): string
    {
        return hash_hkdf('sha256', self::key(), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'yolotask-sensitive-fields-v1');
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
