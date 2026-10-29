<?php
declare(strict_types=1);
namespace YoloTask\Money;

use InvalidArgumentException;

/**
 * Money helpers. Amounts are integer kobo everywhere in the application and
 * the database; strings like "500.00" exist only at the edges (forms, AfrStar).
 */
final class Money
{
    public static function normalize(string $amount): string
    {
        $amount=trim($amount);
        if (!preg_match('/^(0|[1-9][0-9]*)(?:\\.([0-9]{1,2}))?$/',$amount,$m)) throw new InvalidArgumentException('Money must be a non-negative decimal string with at most two decimal places.');
        return $m[1] . '.' . str_pad($m[2] ?? '',2,'0');
    }

    /** Parse user or provider input ("1,000", "₦250.5", "500.00") into kobo. */
    public static function toKobo(string $amount): int
    {
        $clean = str_replace([',', ' ', '₦', 'NGN', 'N'], '', trim($amount));
        $normal = self::normalize($clean);
        [$naira, $kobo] = explode('.', $normal);
        if (strlen($naira) > 13) throw new InvalidArgumentException('Amount is too large.');
        return ((int) $naira) * 100 + (int) $kobo;
    }

    /** Kobo to a plain two-decimal string ("500.00"), as AfrStar expects. */
    public static function decimal(int $kobo): string
    {
        $sign = $kobo < 0 ? '-' : '';
        $kobo = abs($kobo);
        return $sign . intdiv($kobo, 100) . '.' . str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Kobo for display: "₦1,250.00". */
    public static function format(int $kobo): string
    {
        $sign = $kobo < 0 ? '−' : '';
        $kobo = abs($kobo);
        return $sign . '₦' . number_format(intdiv($kobo, 100)) . '.' . str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);
    }
}
