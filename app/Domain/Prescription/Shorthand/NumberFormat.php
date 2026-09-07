<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Shorthand;

/** Amount spelling shared by normalized / dose_schedule (fractions as 1/2, 1 1/2; else trimmed decimals). */
final class NumberFormat
{
    private const BN = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    public static function amount(float $value): string
    {
        $whole = (int) floor($value + 1e-9);
        $frac = $value - $whole;
        $fraction = match (true) {
            abs($frac) < 1e-9 => '',
            abs($frac - 0.5) < 1e-9 => '1/2',
            abs($frac - 0.25) < 1e-9 => '1/4',
            abs($frac - 0.75) < 1e-9 => '3/4',
            default => null,
        };

        if ($fraction === null) {
            return self::decimal($value);
        }

        if ($fraction === '') {
            return (string) $whole;
        }

        return $whole === 0 ? $fraction : "{$whole} {$fraction}";
    }

    /** Trimmed decimal: 7.5 → "7.5", 20.0 → "20". */
    public static function decimal(float $value): string
    {
        $s = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }

    public static function bnDigits(string $text): string
    {
        return strtr($text, array_combine(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], self::BN));
    }

    public static function enDigits(string $text): string
    {
        return strtr($text, array_combine(self::BN, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9']));
    }

    /** Parses "1", "1/2", "1 1/2", "0.5" (ASCII digits) to a float. */
    public static function parse(string $text): float
    {
        $text = trim($text);

        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $text, $m)) {
            return (int) $m[1] + ((int) $m[3] === 0 ? 0 : (int) $m[2] / (int) $m[3]);
        }

        if (preg_match('/^(\d+)\/(\d+)$/', $text, $m)) {
            return (int) $m[2] === 0 ? 0.0 : (int) $m[1] / (int) $m[2];
        }

        return (float) $text;
    }
}
