<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

/**
 * Integer-only money arithmetic. Nothing in Billing ever multiplies or divides a float: a `numeric(10,2)` column
 * arrives from Postgres as a decimal STRING and is parsed digit-by-digit, percentages become basis points, and
 * every division rounds half-up on integers. Overflow is not a concern: the largest intermediate is
 * `paisa * 10000`, and paisa is bounded by bigint.
 */
final class Paisa
{
    /** Two decimal places, as Postgres `numeric(10,2)` stores them. */
    public const SCALE = 2;

    private const HUNDREDTHS = 100;

    private const BASIS_POINTS = 10000;

    /**
     * "1250.75" (taka) → 125075 paisa. Accepts an int/float too (tests and factories), always exactly.
     * Pure string arithmetic for strings, so 0.1 + 0.2 can never appear.
     */
    public static function fromDecimal(string|int|float $value): int
    {
        if (is_int($value)) {
            return $value * self::HUNDREDTHS;
        }

        $text = is_float($value) ? number_format($value, self::SCALE, '.', '') : trim($value);

        if ($text === '') {
            return 0;
        }

        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '+-');
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = substr(str_pad($fraction, self::SCALE, '0'), 0, self::SCALE);

        $paisa = ((int) $whole) * self::HUNDREDTHS + (int) $fraction;

        return $negative ? -$paisa : $paisa;
    }

    /** "7.50" (percent) → 750 basis points. A percentage is never applied as a float. */
    public static function percentToBasisPoints(string|int|float $percent): int
    {
        return self::fromDecimal($percent);
    }

    /**
     * `amount * basisPoints / 10000`, rounded half-up, never negative.
     * 7.5% of ৳500.00 (50000 paisa) = 50000 * 750 / 10000 = 3750 paisa exactly; ties go up.
     */
    public static function applyBasisPoints(int $amountPaisa, int $basisPoints): int
    {
        if ($amountPaisa <= 0 || $basisPoints <= 0) {
            return 0;
        }

        return intdiv($amountPaisa * $basisPoints + intdiv(self::BASIS_POINTS, 2), self::BASIS_POINTS);
    }

    /** Clamp to [0, $max] — the shape every discount, coupon and share resolution ends with. */
    public static function clamp(int $paisa, int $max): int
    {
        return max(0, min($paisa, max(0, $max)));
    }

    /** Format a `numeric(10,2)` column back for display without touching a float. */
    public static function toDecimal(int $paisa): string
    {
        $sign = $paisa < 0 ? '-' : '';
        $abs = abs($paisa);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, self::HUNDREDTHS), $abs % self::HUNDREDTHS);
    }
}
