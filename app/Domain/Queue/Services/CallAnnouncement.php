<?php

declare(strict_types=1);

namespace App\Domain\Queue\Services;

/**
 * Ready-made speech lines for the waiting-room display (REALTIME.md §9.2): the server spells the numbers so the TV
 * never needs a number-to-words library — "সিরিয়াল এ বিয়াল্লিশ, রুম তিন" / "Serial A forty-two, room three".
 * Pure: no container, no DB, no tenancy.
 */
final class CallAnnouncement
{
    /** Session-code letters as they are read out in Bangla (SERIAL_ENGINE §5.2 allows A–Z). */
    private const BN_LETTERS = [
        'A' => 'এ', 'B' => 'বি', 'C' => 'সি', 'D' => 'ডি', 'E' => 'ই', 'F' => 'এফ', 'G' => 'জি', 'H' => 'এইচ',
        'I' => 'আই', 'J' => 'জে', 'K' => 'কে', 'L' => 'এল', 'M' => 'এম', 'N' => 'এন', 'O' => 'ও', 'P' => 'পি',
        'Q' => 'কিউ', 'R' => 'আর', 'S' => 'এস', 'T' => 'টি', 'U' => 'ইউ', 'V' => 'ভি', 'W' => 'ডব্লিউ',
        'X' => 'এক্স', 'Y' => 'ওয়াই', 'Z' => 'জেড',
    ];

    /** 0–99 in Bangla words; hundreds are composed as "<n> শ <rest>". */
    private const BN_NUMBERS = [
        'শূন্য', 'এক', 'দুই', 'তিন', 'চার', 'পাঁচ', 'ছয়', 'সাত', 'আট', 'নয়',
        'দশ', 'এগারো', 'বারো', 'তেরো', 'চৌদ্দ', 'পনেরো', 'ষোলো', 'সতেরো', 'আঠারো', 'উনিশ',
        'বিশ', 'একুশ', 'বাইশ', 'তেইশ', 'চব্বিশ', 'পঁচিশ', 'ছাব্বিশ', 'সাতাশ', 'আটাশ', 'ঊনত্রিশ',
        'ত্রিশ', 'একত্রিশ', 'বত্রিশ', 'তেত্রিশ', 'চৌত্রিশ', 'পঁয়ত্রিশ', 'ছত্রিশ', 'সাঁইত্রিশ', 'আটত্রিশ', 'ঊনচল্লিশ',
        'চল্লিশ', 'একচল্লিশ', 'বিয়াল্লিশ', 'তেতাল্লিশ', 'চুয়াল্লিশ', 'পঁয়তাল্লিশ', 'ছেচল্লিশ', 'সাতচল্লিশ', 'আটচল্লিশ', 'ঊনপঞ্চাশ',
        'পঞ্চাশ', 'একান্ন', 'বায়ান্ন', 'তিপ্পান্ন', 'চুয়ান্ন', 'পঞ্চান্ন', 'ছাপ্পান্ন', 'সাতান্ন', 'আটান্ন', 'ঊনষাট',
        'ষাট', 'একষট্টি', 'বাষট্টি', 'তেষট্টি', 'চৌষট্টি', 'পঁয়ষট্টি', 'ছেষট্টি', 'সাতষট্টি', 'আটষট্টি', 'ঊনসত্তর',
        'সত্তর', 'একাত্তর', 'বাহাত্তর', 'তিয়াত্তর', 'চুয়াত্তর', 'পঁচাত্তর', 'ছিয়াত্তর', 'সাতাত্তর', 'আটাত্তর', 'ঊনআশি',
        'আশি', 'একাশি', 'বিরাশি', 'তিরাশি', 'চুরাশি', 'পঁচাশি', 'ছিয়াশি', 'সাতাশি', 'আটাশি', 'ঊননব্বই',
        'নব্বই', 'একানব্বই', 'বিরানব্বই', 'তিরানব্বই', 'চুরানব্বই', 'পঁচানব্বই', 'ছিয়ানব্বই', 'সাতানব্বই', 'আটানব্বই', 'নিরানব্বই',
    ];

    private const EN_ONES = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
        'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];

    private const EN_TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    /**
     * @return array{bn: string, en: string}
     */
    public static function speak(string $sessionCode, int $number, ?string $room): array
    {
        $letter = strtoupper(mb_substr($sessionCode, 0, 1));

        $bn = 'সিরিয়াল '.(self::BN_LETTERS[$letter] ?? $letter).' '.self::bnNumber($number);
        $en = 'Serial '.$letter.' '.self::enNumber($number);

        if ($room !== null && $room !== '') {
            $bn .= ', রুম '.self::roomBn($room);
            $en .= ', room '.self::roomEn($room);
        }

        return ['bn' => $bn, 'en' => $en];
    }

    public static function bnNumber(int $n): string
    {
        if ($n < 0) {
            return (string) $n;
        }

        if ($n < 100) {
            return self::BN_NUMBERS[$n];
        }

        if ($n < 1000) {
            $hundreds = intdiv($n, 100);
            $rest = $n % 100;

            return self::BN_NUMBERS[$hundreds].' শ'.($rest === 0 ? '' : ' '.self::BN_NUMBERS[$rest]);
        }

        return implode(' ', array_map(fn (string $d) => self::BN_NUMBERS[(int) $d], str_split((string) $n)));
    }

    public static function enNumber(int $n): string
    {
        if ($n < 0) {
            return (string) $n;
        }

        if ($n < 20) {
            return self::EN_ONES[$n];
        }

        if ($n < 100) {
            $tens = self::EN_TENS[intdiv($n, 10)];
            $ones = $n % 10;

            return $ones === 0 ? $tens : $tens.'-'.self::EN_ONES[$ones];
        }

        if ($n < 1000) {
            $rest = $n % 100;

            return self::EN_ONES[intdiv($n, 100)].' hundred'.($rest === 0 ? '' : ' '.self::enNumber($rest));
        }

        return implode(' ', array_map(fn (string $d) => self::EN_ONES[(int) $d], str_split((string) $n)));
    }

    /** "Room 3" / "3" / "3A" — digits are spelled, the rest is read as written. */
    private static function roomBn(string $room): string
    {
        return self::squeeze((string) preg_replace_callback('/\d+/', fn (array $m) => ' '.self::bnNumber((int) $m[0]).' ', self::stripRoomWord($room)));
    }

    private static function roomEn(string $room): string
    {
        return self::squeeze((string) preg_replace_callback('/\d+/', fn (array $m) => ' '.self::enNumber((int) $m[0]).' ', self::stripRoomWord($room)));
    }

    private static function squeeze(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function stripRoomWord(string $room): string
    {
        return trim((string) preg_replace('/^\s*(room|রুম|কক্ষ)\s*/iu', '', trim($room)));
    }
}
