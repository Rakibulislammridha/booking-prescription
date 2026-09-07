<?php

declare(strict_types=1);

namespace App\Domain\Reports\Support;

use App\Support\Clock;

/**
 * Every timestamp in the database is UTC (CONVENTIONS §3.2); every number a clinic owner reads is Asia/Dhaka.
 * A visit stamped 2026-03-01T17:30Z belongs to the clinic's 1 March, and one stamped 2026-03-01T18:30Z belongs
 * to 2 March — a report that groups on the raw UTC day silently moves the whole evening OPD into tomorrow.
 *
 * These helpers emit the `AT TIME ZONE` expressions the grouping queries use. The zone is interpolated (a bound
 * parameter cannot appear in a GROUP BY expression Postgres has to match syntactically against the SELECT), so
 * it is validated against the IANA name grammar first and falls back to Asia/Dhaka — the same guard Billing's
 * CollectionReportQuery uses.
 *
 * Columns that already hold a clinic-local calendar day (`session_instances.session_date`, `visits.follow_up_on`)
 * are `date` columns and must NOT be passed through here: they are local by construction.
 */
final class LocalTime
{
    public static function zone(): string
    {
        $tz = Clock::timezone();

        return preg_match('#^[A-Za-z][A-Za-z0-9_+/-]{1,63}$#', $tz) === 1 ? $tz : Clock::DEFAULT_TIMEZONE;
    }

    /** The local wall-clock timestamp of a UTC column, as a SQL expression. */
    public static function at(string $column): string
    {
        return "({$column} at time zone '".self::zone()."')";
    }

    /** `YYYY-MM-DD` of the local day. */
    public static function day(string $column): string
    {
        return 'to_char('.self::at($column).", 'YYYY-MM-DD')";
    }

    /** `YYYY-MM` of the local month. */
    public static function month(string $column): string
    {
        return 'to_char('.self::at($column).", 'YYYY-MM')";
    }

    /** Monday-anchored ISO week, labelled by its first day. */
    public static function week(string $column): string
    {
        return "to_char(date_trunc('week', ".self::at($column)."), 'YYYY-MM-DD')";
    }

    /** 0 = Sunday … 6 = Saturday, matching `doctor_schedules.weekday`. */
    public static function weekday(string $column): string
    {
        return 'extract(dow from '.self::at($column).')::int';
    }

    /** Local hour of day, 0–23. */
    public static function hour(string $column): string
    {
        return 'extract(hour from '.self::at($column).')::int';
    }

    /** Bucket a UTC column by the granularity of the range. */
    public static function bucket(string $column, string $granularity): string
    {
        return match ($granularity) {
            'month' => self::month($column),
            'week' => self::week($column),
            default => self::day($column),
        };
    }

    /** The same buckets for a `date` column, which is already clinic-local. */
    public static function dateBucket(string $column, string $granularity): string
    {
        return match ($granularity) {
            'month' => "to_char({$column}, 'YYYY-MM')",
            'week' => "to_char(date_trunc('week', {$column}), 'YYYY-MM-DD')",
            default => "to_char({$column}, 'YYYY-MM-DD')",
        };
    }
}
