<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Safety;

use App\Domain\Prescription\Data\ParsedLine;
use App\Domain\Prescription\Shorthand\Keywords;

/**
 * PRESCRIPTION.md §5.3: daily mg from a ParsedLine — counted units × strength_mg; liquids daily_ml × per_ml (mg/ml);
 * puff/spray/drop × strength_mg per actuation when known; sos with max is max-based; stat is per-dose only.
 */
final class DailyDoseCalculator
{
    public static function dailyMg(?ParsedLine $line, ?float $strengthMg, ?float $perMl, ?string $formCode): ?float
    {
        if ($line === null || $line->schedule === null || $line->hasErrors()) {
            return null;
        }

        $daily = $line->dailyTotal;

        if ($daily === null) {
            return null;                                              // stat, sos without max
        }

        $mgPerUnit = self::mgPerUnit($line->unit, $strengthMg, $perMl);

        return $mgPerUnit === null ? null : round($daily * $mgPerUnit, 3);
    }

    public static function perDoseMg(?ParsedLine $line, ?float $strengthMg, ?float $perMl, ?string $formCode): ?float
    {
        if ($line === null || $line->schedule === null || $line->hasErrors()) {
            return null;
        }

        $mgPerUnit = self::mgPerUnit($line->unit, $strengthMg, $perMl);

        if ($mgPerUnit === null) {
            return null;
        }

        $s = $line->schedule;
        $amount = $s['type'] === 'slots' ? max(array_map(fn ($v) => (float) $v, $s['slots'])) : (float) $s['amount'];

        return round($amount * $mgPerUnit, 3);
    }

    /** mg contained in one `unit` of the presentation. */
    public static function mgPerUnit(string $unit, ?float $strengthMg, ?float $perMl): ?float
    {
        $family = Keywords::familyOf($unit);

        if ($family === 'liquid') {
            return $perMl === null ? null : Keywords::liquidMl()[$unit] * $perMl;
        }

        if ($family === 'insulin') {
            return null;                                              // units, not mg
        }

        return $strengthMg;                                           // counted, puff, spray, drop, app: per actuation when known
    }
}
