<?php

declare(strict_types=1);

namespace App\Domain\Reports\Support;

/**
 * Aggregate SQL comes back as `stdClass`, and `sum()` comes back as a numeric STRING in the pgsql driver.
 * These readers keep the casts in one place instead of scattering `(int) $row->x` with different null handling
 * in every query object — a report's job is to be right, and "null became 0 here but '' there" is how it stops
 * being right.
 */
final class Row
{
    public static function int(mixed $row, string $key, int $default = 0): int
    {
        $value = self::raw($row, $key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function float(mixed $row, string $key, float $default = 0.0): float
    {
        $value = self::raw($row, $key);

        return is_numeric($value) ? (float) $value : $default;
    }

    /** Null stays null: "no samples" is not "zero seconds". */
    public static function nullableFloat(mixed $row, string $key): ?float
    {
        $value = self::raw($row, $key);

        return is_numeric($value) ? (float) $value : null;
    }

    public static function nullableInt(mixed $row, string $key): ?int
    {
        $value = self::raw($row, $key);

        return is_numeric($value) ? (int) $value : null;
    }

    public static function string(mixed $row, string $key, string $default = ''): string
    {
        $value = self::raw($row, $key);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function nullableString(mixed $row, string $key): ?string
    {
        $value = self::raw($row, $key);

        return is_scalar($value) ? (string) $value : null;
    }

    /** Seconds → whole minutes, rounded, keeping null as null. */
    public static function minutes(mixed $row, string $key): ?float
    {
        $seconds = self::nullableFloat($row, $key);

        return $seconds === null ? null : round($seconds / 60, 1);
    }

    private static function raw(mixed $row, string $key): mixed
    {
        if (is_object($row)) {
            return $row->{$key} ?? null;
        }

        return is_array($row) ? ($row[$key] ?? null) : null;
    }
}
