<?php
declare(strict_types=1);
namespace YoloTask\Core;

final class Validator
{
    public static function requiredString(mixed $value, int $maxLength): bool
    {
        if (!is_string($value)) return false;
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        return $value !== '' && $length <= $maxLength;
    }
}
