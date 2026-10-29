<?php
declare(strict_types=1);
namespace YoloTask\Payments;

use YoloTask\Money\Money;

/**
 * AfrStar callback authenticity checks (constant-time comparisons).
 *
 * Collection: md5(ak + platform_osn + status + ORIGINAL stored order amount + sk)
 * Payout:     md5(ak + platform_osn + status + amount + sk), amount = stored payout amount
 */
final class CallbackVerifier
{
    /** Read a callback body: JSON first, form-encoded as a fallback. */
    public static function parse(string $raw, array $post = []): array
    {
        $json = json_decode($raw, true);
        if (is_array($json)) return $json;
        if ($post !== []) return $post;
        $form = [];
        parse_str($raw, $form);
        return is_array($form) ? $form : [];
    }

    public static function merchantMatches(array $payload, array $config): bool
    {
        return $config['merchant_sn'] !== '' && hash_equals($config['merchant_sn'], (string) ($payload['merchant_sn'] ?? ''));
    }

    public static function collectionSignature(array $config, string $platformOsn, string $status, int $orderAmountKobo): string
    {
        return AfrstarClient::sign([$config['merchant_ak'], $platformOsn, $status, Money::decimal($orderAmountKobo)], $config['merchant_sk']);
    }

    public static function payoutSignature(array $config, string $platformOsn, string $status, int $payoutAmountKobo): string
    {
        return AfrstarClient::sign([$config['merchant_ak'], $platformOsn, $status, Money::decimal($payoutAmountKobo)], $config['merchant_sk']);
    }

    public static function verifyCollection(array $payload, array $config, int $orderAmountKobo): bool
    {
        if (!self::merchantMatches($payload, $config)) return false;
        $expected = self::collectionSignature($config, (string) ($payload['platform_osn'] ?? ''), (string) ($payload['status'] ?? ''), $orderAmountKobo);
        return hash_equals($expected, strtolower((string) ($payload['sign'] ?? '')));
    }

    public static function verifyPayout(array $payload, array $config, int $payoutAmountKobo): bool
    {
        if (!self::merchantMatches($payload, $config)) return false;
        $expected = self::payoutSignature($config, (string) ($payload['platform_osn'] ?? ''), (string) ($payload['status'] ?? ''), $payoutAmountKobo);
        return hash_equals($expected, strtolower((string) ($payload['sign'] ?? '')));
    }

    /** Provider amount string to kobo, or null when malformed. */
    public static function amount(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) return null;
        $text = is_float($value) ? number_format($value, 2, '.', '') : (string) $value;
        try {
            return Money::toKobo($text);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
