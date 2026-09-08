<?php

declare(strict_types=1);

namespace App\Domain\Patients\Services;

use App\Domain\Patients\Data\DerivedTitle;
use Carbon\CarbonImmutable;

/**
 * Turns a page of recognised text into the title PRESCRIPTION.md §8 asks for — "CBC – 06 Sep 2026" — and nothing
 * else: no I/O, no container, no clock of its own, so the naming rules can be argued with in a unit test.
 *
 * Both scripts matter. A Dhaka lab prints its heading in English and its date in Bangla digits as often as not,
 * and a patient photographs whichever page has the numbers, so every heading carries its Bangla spellings and
 * ০-৯ is normalised to 0-9 before any date is parsed. The label stored is always the ENGLISH canonical one:
 * the title is a filing label the whole clinic reads, and the UI translates elsewhere.
 *
 * When nothing is recognised the caller's fallback title is returned untouched — a report that could not be read
 * is still named by type and upload date, exactly as NullDocumentNamer names it today.
 */
final class DocumentTitleDeriver
{
    /** `patient_documents.title` is varchar(200) (SCHEMA §3.2). */
    public const MAX_TITLE = 200;

    /** `ocr_text` is an encrypted `text` column: a 60-page scan is not worth carrying in every row we read. */
    public const MAX_OCR_TEXT = 20_000;

    /**
     * English canonical label => the strings that name it in either script, lower-cased. First occurrence in the
     * page wins (headings sit at the top), so an incidental "prescription" further down cannot rename a CBC.
     *
     * @var array<string, array<int, string>>
     */
    private const HEADINGS = [
        'CBC' => ['cbc', 'c.b.c', 'complete blood count', 'full blood count', 'সিবিসি', 'রক্তের সম্পূর্ণ পরীক্ষা', 'রক্তের পূর্ণাঙ্গ পরীক্ষা'],
        'X-ray' => ['x-ray', 'x ray', 'xray', 'radiograph', 'এক্স-রে', 'এক্স রে', 'এক্সরে'],
        'Ultrasonogram' => ['ultrasonogram', 'ultrasonography', 'ultrasound', 'usg', 'আল্ট্রাসনোগ্রাম', 'আল্ট্রাসনোগ্রাফি', 'আলট্রাসনোগ্রাম'],
        'ECG' => ['ecg', 'e.c.g', 'electrocardiogram', 'ইসিজি', 'ইলেক্ট্রোকার্ডিওগ্রাম'],
        'Blood sugar' => ['blood sugar', 'fbs', 'rbs', 'fasting blood sugar', 'random blood sugar', 'blood glucose', 'রক্তে শর্করা', 'রক্তের শর্করা', 'রক্তে গ্লুকোজ'],
        'Lipid profile' => ['lipid profile', 'fasting lipid profile', 'লিপিড প্রোফাইল'],
        'Urine R/E' => ['urine r/e', 'urine r.e', 'urine routine', 'urine routine examination', 'urine analysis', 'প্রস্রাব পরীক্ষা', 'প্রস্রাবের পরীক্ষা'],
        'Creatinine' => ['creatinine', 's. creatinine', 'serum creatinine', 'ক্রিয়েটিনিন'],
        'Liver function' => ['liver function', 'liver function test', 'lft', 'sgpt', 'sgot', 'লিভার ফাংশন', 'যকৃতের কার্যকারিতা'],
        'Thyroid' => ['thyroid', 'thyroid profile', 'tsh', 'ft4', 'থাইরয়েড', 'টিএসএইচ'],
        'Prescription' => ['prescription', 'ব্যবস্থাপত্র', 'প্রেসক্রিপশন'],
    ];

    /** Month token => month number, both scripts; the alternation is built longest-first. */
    private const MONTHS = [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6,
        'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8,
        'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        'জানুয়ারি' => 1, 'ফেব্রুয়ারি' => 2, 'মার্চ' => 3, 'এপ্রিল' => 4, 'মে' => 5, 'জুন' => 6,
        'জুলাই' => 7, 'আগস্ট' => 8, 'সেপ্টেম্বর' => 9, 'অক্টোবর' => 10, 'নভেম্বর' => 11, 'ডিসেম্বর' => 12,
    ];

    private const BANGLA_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    /** No clinic scans a report older than this; anything earlier is a reference number that looks like a year. */
    private const EARLIEST_YEAR = 1990;

    /**
     * @param  string  $text  what the text layer or the OCR engine returned
     * @param  string  $fallbackTitle  NullDocumentNamer's "{type} – {upload date}", used when no heading is recognised
     * @param  CarbonImmutable|null  $uploadedOn  dates the title when the report itself carries no date
     * @param  CarbonImmutable|null  $now  clinic-local today; a report dated after tomorrow is a misread
     */
    public function derive(string $text, string $fallbackTitle, ?CarbonImmutable $uploadedOn = null, ?CarbonImmutable $now = null): DerivedTitle
    {
        $haystack = $this->normalise($text);
        $label = $this->heading($haystack);
        $date = $this->date($haystack, $now ?? CarbonImmutable::now());
        $shown = $date ?? $uploadedOn;

        $title = match (true) {
            $label === null => $fallbackTitle,
            $shown !== null => $label.' – '.$shown->format('d M Y'),
            default => $label,
        };

        return new DerivedTitle(
            title: $this->clamp($title, self::MAX_TITLE, $fallbackTitle),
            documentDate: $date,
            ocrText: $this->storableText($text),
            matchedHeading: $label !== null,
        );
    }

    /** The recognised text as it is stored: normalised line endings, no NUL bytes, capped. */
    public function storableText(string $text): string
    {
        $clean = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
        $clean = trim((string) preg_replace('/[ \t]+/u', ' ', $clean));

        return mb_strlen($clean) > self::MAX_OCR_TEXT ? mb_substr($clean, 0, self::MAX_OCR_TEXT) : $clean;
    }

    /** Lower-cased, Bangla digits as 0-9, dashes and whitespace regular — everything below matches against this. */
    private function normalise(string $text): string
    {
        $text = str_replace(self::BANGLA_DIGITS, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $text);
        $text = str_replace(['–', '—', '−', "\u{00a0}"], ['-', '-', '-', ' '], $text);

        return (string) preg_replace('/\s+/u', ' ', mb_strtolower($text));
    }

    /** The canonical label whose keyword appears earliest in the page, or null. */
    private function heading(string $haystack): ?string
    {
        $best = null;
        $bestAt = PHP_INT_MAX;

        foreach (self::HEADINGS as $label => $keywords) {
            foreach ($keywords as $keyword) {
                $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($keyword, '/').'(?![\p{L}\p{N}])/u';

                if (preg_match($pattern, $haystack, $m, PREG_OFFSET_CAPTURE) === 1 && (int) $m[0][1] < $bestAt) {
                    $bestAt = (int) $m[0][1];
                    $best = $label;
                }
            }
        }

        return $best;
    }

    /** The earliest plausible date on the page: 2026-09-06, 06/09/2026 (BD reads day first), 06 Sep 2026, ০৬ সেপ্টেম্বর ২০২৬. */
    private function date(string $haystack, CarbonImmutable $now): ?CarbonImmutable
    {
        $months = $this->monthAlternation();

        /** @var array<int, array{0: string, 1: array{0: int, 1: int, 2: int}}> $patterns  regex => [year, month, day] group numbers */
        $patterns = [
            ['/(?<!\d)(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)/u', [1, 2, 3]],
            ['/(?<!\d)(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})(?!\d)/u', [3, 2, 1]],
            ['/(?<![\p{L}\p{N}])(\d{1,2})(?:st|nd|rd|th)?[\s.,-]*('.$months.')[\s.,-]*(\d{4})(?!\d)/u', [3, 2, 1]],
            ['/(?<![\p{L}\p{N}])('.$months.')[\s.,-]*(\d{1,2})(?:st|nd|rd|th)?[\s.,-]*(\d{4})(?!\d)/u', [3, 1, 2]],
        ];

        $earliest = null;
        $earliestAt = PHP_INT_MAX;

        foreach ($patterns as [$pattern, $groups]) {
            if (preg_match_all($pattern, $haystack, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
                continue;
            }

            /** @var array<int, array<int, array{0: string, 1: int}>> $matches */
            foreach ($matches as $match) {
                $at = (int) $match[0][1];

                if ($at >= $earliestAt) {
                    continue;
                }

                $date = $this->toDate((string) $match[$groups[0]][0], (string) $match[$groups[1]][0], (string) $match[$groups[2]][0], $now);

                if ($date !== null) {
                    $earliest = $date;
                    $earliestAt = $at;
                }
            }
        }

        return $earliest;
    }

    /** A real calendar date, this century-ish, and not in the future — or null. */
    private function toDate(string $year, string $month, string $day, CarbonImmutable $now): ?CarbonImmutable
    {
        $m = is_numeric($month) ? (int) $month : (self::MONTHS[$month] ?? 0);
        $y = (int) $year;
        $d = (int) $day;

        if ($m === 0 || $y < self::EARLIEST_YEAR || ! checkdate($m, $d, $y)) {
            return null;
        }

        $date = CarbonImmutable::parse(sprintf('%04d-%02d-%02d', $y, $m, $d))->startOfDay();

        return $date->greaterThan($now->addDay()->endOfDay()) ? null : $date;
    }

    private function monthAlternation(): string
    {
        $tokens = array_keys(self::MONTHS);
        usort($tokens, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return implode('|', array_map(fn (string $token) => preg_quote($token, '/'), $tokens));
    }

    /** Never returns an empty title: the column is NOT NULL and an empty name helps nobody find their report. */
    private function clamp(string $title, int $max, string $fallback): string
    {
        $title = trim($title) !== '' ? trim($title) : trim($fallback);

        return mb_strlen($title) > $max ? rtrim(mb_substr($title, 0, $max)) : $title;
    }
}
