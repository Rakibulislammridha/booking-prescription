<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Support;

use App\Domain\Clinic\Enums\Locale;
use App\Support\Clock;
use Carbon\CarbonInterface;

/**
 * Dates, times and numbers as a Bangladeshi patient reads them on a feature phone. Bangla digits are a display
 * concern only (CONVENTIONS §7.5 "never store Bangla digits") — nothing here is written to a column, it is
 * interpolated into a message body at render time.
 *
 * Everything is rendered in the tenant's timezone (Asia/Dhaka by default): a reminder that says "10:00" must mean
 * ten in the morning at the clinic, not ten UTC.
 */
final class Localised
{
    private const BN_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    private const BN_MONTHS = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];

    public static function digits(string $text, Locale $locale): string
    {
        return $locale === Locale::Bn ? str_replace(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], self::BN_DIGITS, $text) : $text;
    }

    public static function number(int|float $value, Locale $locale): string
    {
        return self::digits((string) $value, $locale);
    }

    public static function date(?CarbonInterface $at, Locale $locale): string
    {
        if ($at === null) {
            return '';
        }

        $local = $at->copy()->setTimezone(Clock::timezone());

        return $locale === Locale::Bn
            ? self::digits((string) $local->day, $locale).' '.self::BN_MONTHS[$local->month - 1]
            : $local->format('j M');
    }

    public static function time(?CarbonInterface $at, Locale $locale): string
    {
        if ($at === null) {
            return '';
        }

        $local = $at->copy()->setTimezone(Clock::timezone());

        if ($locale !== Locale::Bn) {
            return $local->format('g:i a');
        }

        $period = match (true) {
            $local->hour < 6 => 'ভোর',
            $local->hour < 12 => 'সকাল',
            $local->hour < 16 => 'দুপুর',
            $local->hour < 19 => 'বিকাল',
            default => 'রাত',
        };

        return $period.' '.self::digits($local->format('g:i'), $locale);
    }
}
