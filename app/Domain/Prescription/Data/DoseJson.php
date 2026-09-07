<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

use App\Domain\Prescription\Shorthand\NumberFormat;

/**
 * Server-written projections of a ParsedLine into the scalar prescription_items columns (PRESCRIPTION.md §2.11):
 * dose_schedule, duration_days, duration_text, quantity, quantity_unit, timing, is_continued. The server never trusts
 * client projections — it derives them here from its own parse.
 */
final class DoseJson
{
    public static function doseSchedule(ParsedLine $line): ?string
    {
        $s = $line->schedule;

        if ($s === null) {
            return null;
        }

        return match ($s['type']) {
            'slots' => implode('+', array_map(fn ($v) => NumberFormat::amount((float) $v), $s['slots'])),
            'frequency' => NumberFormat::amount((float) $s['amount']).' '.$s['code'],
            'interval' => NumberFormat::amount((float) $s['amount']).' q'.$s['every_hours'].'h',
            'stat' => ((float) $s['amount'] === 1.0 ? '' : NumberFormat::amount((float) $s['amount']).' ').'stat',
            'sos' => NumberFormat::amount((float) $s['amount']).' sos'.($s['max_per_day'] !== null ? ' max '.$s['max_per_day'] : ''),
            default => null,
        };
    }

    public static function durationDays(ParsedLine $line): ?int
    {
        return $line->effectiveDays();
    }

    /** "10 days" / "Continue" / "Till finish" in the pad language (bn digits when bn). */
    public static function durationText(ParsedLine $line, string $language = 'en'): ?string
    {
        $d = $line->duration;

        if ($d === null) {
            return null;
        }

        $bn = $language === 'bn';

        return match ($d['type']) {
            'days' => $bn ? NumberFormat::bnDigits((string) $d['days']).' দিন' : $d['days'].' '.($d['days'] === 1 ? 'day' : 'days'),
            'continuous' => $bn ? 'চলবে' : 'Continue',
            'till_finish' => $bn ? 'শেষ পর্যন্ত' : 'Till finish',
            default => null,
        };
    }

    public static function quantity(ParsedLine $line): ?float
    {
        return $line->quantity['value'] === null ? null : (float) $line->quantity['value'];
    }

    public static function quantityUnit(ParsedLine $line): ?string
    {
        return $line->quantity['value'] === null ? null : $line->quantity['unit'];
    }

    public static function isContinued(ParsedLine $line): bool
    {
        return ($line->duration['type'] ?? null) === 'continuous';
    }
}
