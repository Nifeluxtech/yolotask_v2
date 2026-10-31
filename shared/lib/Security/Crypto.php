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

    /** Legacy format: libsodium secretbox. Read-only; needs ext-sodium. */
    public const SEAL_PREFIX_SODIUM = 'enc1:';
    /** Current format: OpenSSL AES-256-GCM. Written by seal(). */
    public const SEAL_PREFIX_GCM = 'enc2:';
    /** Every prefix that marks a sealed value (for SQL "NOT LIKE" checks). */
    public const SEAL_PREFIXES = [self::SEAL_PREFIX_SODIUM, self::SEAL_PREFIX_GCM];

    private const GCM_CIPHER = 'aes-256-gcm';
    private const GCM_IV_BYTES = 12;
    private const GCM_TAG_BYTES = 16;
    private const SECRETBOX_NONCE_BYTES = 24; // SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
    private const SECRETBOX_KEY_BYTES = 32;   // SODIUM_CRYPTO_SECRETBOX_KEYBYTES

    /**
     * Encrypt a sensitive field (bank account numbers) at rest, keyed from the
     * private security key.
     *
     * Always writes "enc2:" + base64(iv[12] || tag[16] || ciphertext) using
     * OpenSSL AES-256-GCM, even when libsodium is loaded. ext-openssl is on
     * every host we deploy to (cPanel ea-php84 ships without ext-sodium), so a
     * single write format keeps values portable between hosts. Older "enc1:"
     * (libsodium secretbox) values stay readable wherever ext-sodium exists.
     *
     * Changing security_key makes sealed values unreadable, so keep that key
     * stable and backed up.
     */
    public static function seal(string $plain): string
    {
        if (!self::gcmAvailable()) throw new RuntimeException('The PHP openssl extension with aes-256-gcm is required to encrypt sensitive fields.');
        $iv = random_bytes(self::GCM_IV_BYTES);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::GCM_CIPHER, self::gcmKey(), OPENSSL_RAW_DATA, $iv, $tag, self::SEAL_PREFIX_GCM, self::GCM_TAG_BYTES);
        if ($cipher === false || strlen($tag) !== self::GCM_TAG_BYTES) throw new RuntimeException('Encrypting the sensitive field failed.');
        return self::SEAL_PREFIX_GCM . base64_encode($iv . $tag . $cipher);
    }

    /** Decrypt a sealed field (enc1 or enc2); legacy plaintext (no prefix) is returned unchanged. */
    public static function reveal(?string $value): string
    {
        $value = (string) $value;
        if (str_starts_with($value, self::SEAL_PREFIX_GCM)) return self::revealGcm(substr($value, strlen(self::SEAL_PREFIX_GCM)));
        if (str_starts_with($value, self::SEAL_PREFIX_SODIUM)) return self::revealSodium(substr($value, strlen(self::SEAL_PREFIX_SODIUM)));
        return $value;
    }

    /**
     * reveal() for display-only paths (lists, emails): returns $fallback
     * instead of throwing when a value cannot be decrypted on this host, e.g.
     * a legacy enc1: value where ext-sodium is not loaded. Never use the
     * result to move money; paths that send an account number use reveal().
     */
    public static function revealOr(?string $value, string $fallback): string
    {
        try {
            return self::reveal($value);
        } catch (RuntimeException) {
            return $fallback;
        }
    }

    public static function isSealed(?string $value): bool
    {
        $value = (string) $value;
        foreach (self::SEAL_PREFIXES as $prefix) if (str_starts_with($value, $prefix)) return true;
        return false;
    }

    /** Whether this PHP can write sealed values (enc2 / AES-256-GCM). */
    public static function gcmAvailable(): bool
    {
        return function_exists('openssl_encrypt') && in_array(self::GCM_CIPHER, openssl_get_cipher_methods(), true);
    }

    /** Whether this PHP can read legacy enc1 (libsodium) values. Optional. */
    public static function sodiumAvailable(): bool
    {
        return function_exists('sodium_crypto_secretbox_open');
    }

    private static function revealGcm(string $b64): string
    {
        if (!self::gcmAvailable()) throw new RuntimeException('The PHP openssl extension with aes-256-gcm is required to decrypt enc2 values.');
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < self::GCM_IV_BYTES + self::GCM_TAG_BYTES) throw new RuntimeException('Sealed value is corrupt.');
        $iv = substr($raw, 0, self::GCM_IV_BYTES);
        $tag = substr($raw, self::GCM_IV_BYTES, self::GCM_TAG_BYTES);
        $plain = openssl_decrypt(substr($raw, self::GCM_IV_BYTES + self::GCM_TAG_BYTES), self::GCM_CIPHER, self::gcmKey(), OPENSSL_RAW_DATA, $iv, $tag, self::SEAL_PREFIX_GCM);
        if ($plain === false) throw new RuntimeException('Sealed value cannot be decrypted with the current security key.');
        return $plain;
    }

    private static function revealSodium(string $b64): string
    {
        if (!self::sodiumAvailable()) throw new RuntimeException('This value was sealed with libsodium (enc1:) but the PHP sodium extension is not loaded; decrypt it on a host with ext-sodium or re-seal it there.');
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) <= self::SECRETBOX_NONCE_BYTES) throw new RuntimeException('Sealed value is corrupt.');
        $plain = sodium_crypto_secretbox_open(substr($raw, self::SECRETBOX_NONCE_BYTES), substr($raw, 0, self::SECRETBOX_NONCE_BYTES), self::sealKey());
        if ($plain === false) throw new RuntimeException('Sealed value cannot be decrypted with the current security key.');
        return $plain;
    }

    /** enc1 (libsodium) key. Unchanged so existing enc1 values keep opening. */
    private static function sealKey(): string
    {
        return hash_hkdf('sha256', self::key(), self::SECRETBOX_KEY_BYTES, 'yolotask-sensitive-fields-v1');
    }

    /** enc2 (AES-256-GCM) key: HKDF with its own context, distinct from sealKey() and hmac(). */
    private static function gcmKey(): string
    {
        return hash_hkdf('sha256', self::key(), 32, 'yolotask-sensitive-fields-v2-aes-256-gcm');
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
