<?php

declare(strict_types=1);

namespace App\Support;

use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;

/**
 * Clinic-local calendar dates (CONVENTIONS §3.2): the tenant timezone (default Asia/Dhaka) decides "today";
 * every stored timestamp stays UTC. Test helpers freeze time with Clock::freeze('2026-03-01 09:00').
 */
final class Clock
{
    public const DEFAULT_TIMEZONE = 'Asia/Dhaka';

    public static function timezone(): string
    {
        return Tenancy::current()->timezone ?? self::DEFAULT_TIMEZONE;
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /** Freeze the clock at a clinic-local wall-clock time (tests only). */
    public static function freeze(string $localDateTime): CarbonImmutable
    {
        $instant = CarbonImmutable::parse($localDateTime, self::timezone());
        CarbonImmutable::setTestNow($instant);

        return $instant;
    }

    public static function unfreeze(): void
    {
        CarbonImmutable::setTestNow();
    }
}
