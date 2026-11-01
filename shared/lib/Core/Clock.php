<?php
declare(strict_types=1);
namespace YoloTask\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Current time and the Africa/Lagos calendar day. Code that depends on the
 * Lagos day (daily check-in) asks here so a CLI test can pin the clock with
 * Clock::freeze(); nothing in a web request freezes it.
 */
final class Clock
{
    public const LAGOS = 'Africa/Lagos';
    private static ?DateTimeImmutable $frozen = null;

    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** Tests (CLI) only: pin "now". Null releases it. */
    public static function freeze(?DateTimeImmutable $at): void
    {
        self::$frozen = $at?->setTimezone(new DateTimeZone('UTC'));
    }

    /** Lagos calendar date (Y-m-d) of a moment, default now. */
    public static function lagosDate(?DateTimeImmutable $at = null): string
    {
        return ($at ?? self::now())->setTimezone(new DateTimeZone(self::LAGOS))->format('Y-m-d');
    }

    /** The Lagos date $days before/after a Y-m-d date. */
    public static function shiftDate(string $ymd, int $days): string
    {
        return (new DateTimeImmutable($ymd . ' 12:00:00', new DateTimeZone(self::LAGOS)))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** UTC Y-m-d H:i:s of now, for SQL. */
    public static function utcSql(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }
}
