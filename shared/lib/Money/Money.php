<?php
declare(strict_types=1);
namespace YoloTask\Money;

use InvalidArgumentException;

final class Money
{
    public static function normalize(string $amount): string
    {
        $amount=trim($amount);
        if (!preg_match('/^(0|[1-9][0-9]*)(?:\\.([0-9]{1,2}))?$/',$amount,$m)) throw new InvalidArgumentException('Money must be a non-negative decimal string with at most two decimal places.');
        return $m[1] . '.' . str_pad($m[2] ?? '',2,'0');
    }
}
