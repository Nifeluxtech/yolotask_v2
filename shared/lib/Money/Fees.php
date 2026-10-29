<?php
declare(strict_types=1);
namespace YoloTask\Money;

/**
 * Fee policy. There are no fees today; callers always go through here so a
 * future fee lands in one place and is posted to its own ledger account.
 */
final class Fees
{
    public static function topup(int $amountKobo): int
    {
        return 0;
    }

    public static function withdrawal(int $amountKobo): int
    {
        return 0;
    }
}
