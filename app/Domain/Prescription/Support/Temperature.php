<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Support;

use App\Domain\Prescription\Shorthand\NumberFormat;

/**
 * The one temperature conversion (BRIEF §5.G.2 vitals). The database keeps CELSIUS — `vitals.temperature_c`
 * (SCHEMA §3.4, numeric(4,1), CHECK 30–45) is the clinical canonical unit, the safety pipeline and the seeders
 * reason in °C and a stored unit never depends on a UI preference — while FAHRENHEIT is what a compounder types
 * and what a doctor, a patient and a printed sheet read. Every human boundary converts here, once, in this class
 * (its TypeScript twin is `resources/js/shared/format/temperature.ts`, same rounding), so a value entered at the
 * desk in °F can never be read back by the doctor as °C.
 *
 * Both directions round to ONE decimal — the column's precision — so 98.6 °F → 37.0 °C → 98.6 °F is stable.
 */
final class Temperature
{
    /** SCHEMA §3.4 `vitals_temperature_c_check`. */
    public const MIN_C = 30.0;

    public const MAX_C = 45.0;

    /** The same bounds in the entry unit: 30 °C = 86 °F, 45 °C = 113 °F. */
    public const MIN_F = 86.0;

    public const MAX_F = 113.0;

    public const UNIT_F = ['en' => '°F', 'bn' => '°ফা'];

    /** 98.6 → 37.0; 100.4 → 38.0. Rounds to the 0.1 °C the column stores. */
    public static function fToC(float $fahrenheit): float
    {
        return round(($fahrenheit - 32) * 5 / 9, 1);
    }

    /** 37.0 → 98.6; 38.0 → 100.4. */
    public static function cToF(float $celsius): float
    {
        return round($celsius * 9 / 5 + 32, 1);
    }

    /**
     * The reading for a person: "98.6°F" (`en` / `both`) or "৯৮.৬°ফা" (`bn`). Always one decimal — "100°F" reads
     * like an estimate on a clinical sheet, "100.0°F" like a measurement. `$separator` is what sits between the
     * number and the unit (the printed sheet uses none, screens a thin space).
     */
    public static function formatF(float|int|string|null $celsius, string $language = 'en', string $separator = ''): ?string
    {
        if ($celsius === null || $celsius === '') {
            return null;
        }

        $number = number_format(self::cToF((float) $celsius), 1, '.', '');

        if ($language === 'bn') {
            return NumberFormat::bnDigits($number).$separator.self::UNIT_F['bn'];
        }

        return $number.$separator.self::UNIT_F['en'];
    }
}
