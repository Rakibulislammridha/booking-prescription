<?php

declare(strict_types=1);

namespace Tests\Unit\Patients;

use App\Domain\Patients\Services\DocumentTitleDeriver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The naming rules of PRESCRIPTION.md §8, argued with directly: no container, no disk, no clock of its own. */
final class DocumentTitleDeriverTest extends TestCase
{
    private const FALLBACK = 'Lab report – 08 Sep 2026';

    private DocumentTitleDeriver $deriver;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deriver = new DocumentTitleDeriver;
        $this->now = CarbonImmutable::parse('2026-09-08');
    }

    public function test_an_english_lab_report_is_named_from_its_heading_and_date(): void
    {
        $text = <<<'TXT'
            City Diagnostic Centre, Dhaka
            COMPLETE BLOOD COUNT (CBC)
            Patient: Rahima Begum        Date: 06 Sep 2026
            Haemoglobin  12.4 g/dL     WBC  7,200 /cmm
            TXT;

        $derived = $this->deriver->derive($text, self::FALLBACK, now: $this->now);

        $this->assertSame('CBC – 06 Sep 2026', $derived->title);
        $this->assertSame('2026-09-06', $derived->documentDate?->toDateString());
        $this->assertTrue($derived->matchedHeading);
        $this->assertStringContainsString('COMPLETE BLOOD COUNT', $derived->ocrText);
    }

    public function test_a_bangla_report_is_recognised_in_its_own_script(): void
    {
        $text = "ঢাকা ডায়াগনস্টিক সেন্টার\nএক্স-রে চেস্ট পি/এ ভিউ\nতারিখঃ 06/09/2026";

        $derived = $this->deriver->derive($text, self::FALLBACK, now: $this->now);

        $this->assertSame('X-ray – 06 Sep 2026', $derived->title);
        $this->assertSame('2026-09-06', $derived->documentDate?->toDateString());
    }

    public function test_bangla_digits_and_a_bangla_month_name_are_read_as_a_date(): void
    {
        $text = "রক্তে শর্করা পরীক্ষা\nতারিখ: ০৬ সেপ্টেম্বর ২০২৬\nফলাফল: ৫.৪ mmol/L";

        $derived = $this->deriver->derive($text, self::FALLBACK, now: $this->now);

        $this->assertSame('Blood sugar – 06 Sep 2026', $derived->title);
        $this->assertSame('2026-09-06', $derived->documentDate?->toDateString());
    }

    public function test_bangla_digits_in_a_numeric_date_are_normalised_before_parsing(): void
    {
        $derived = $this->deriver->derive('লিপিড প্রোফাইল  ০৬/০৯/২০২৬', self::FALLBACK, now: $this->now);

        $this->assertSame('Lipid profile – 06 Sep 2026', $derived->title);
        $this->assertSame('2026-09-06', $derived->documentDate?->toDateString());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function headings(): array
    {
        return [
            'usg' => ['USG of whole abdomen', 'Ultrasonogram'],
            'ecg bangla' => ['ইসিজি রিপোর্ট', 'ECG'],
            'fbs' => ['FBS  6.1 mmol/L', 'Blood sugar'],
            'urine' => ['Urine R/E', 'Urine R/E'],
            'creatinine' => ['Serum creatinine  1.1 mg/dL', 'Creatinine'],
            'lft' => ['LFT with SGPT', 'Liver function'],
            'thyroid' => ['TSH  2.4 mIU/L', 'Thyroid'],
            'prescription bangla' => ['ব্যবস্থাপত্র', 'Prescription'],
        ];
    }

    #[DataProvider('headings')]
    public function test_the_keyword_table_covers_the_common_bd_reports(string $text, string $label): void
    {
        $this->assertSame($label, $this->deriver->derive($text, self::FALLBACK, now: $this->now)->title);
    }

    public function test_the_earliest_heading_on_the_page_wins(): void
    {
        $text = 'Lipid profile — fasting. Bring this report with your next prescription.';

        $this->assertSame('Lipid profile', $this->deriver->derive($text, self::FALLBACK, now: $this->now)->title);
    }

    public function test_an_unrecognised_page_keeps_the_fallback_title(): void
    {
        $derived = $this->deriver->derive("Scanned page\nno headings here at all", self::FALLBACK, now: $this->now);

        $this->assertSame(self::FALLBACK, $derived->title);
        $this->assertNull($derived->documentDate);
        $this->assertFalse($derived->matchedHeading);
        $this->assertSame("Scanned page\nno headings here at all", $derived->ocrText);
    }

    public function test_a_recognised_heading_without_a_date_falls_back_to_the_upload_date(): void
    {
        $derived = $this->deriver->derive('Complete Blood Count', self::FALLBACK, CarbonImmutable::parse('2026-09-08'), $this->now);

        $this->assertSame('CBC – 08 Sep 2026', $derived->title);
        $this->assertNull($derived->documentDate);
    }

    /** @return array<string, array{0: string}> */
    public static function implausibleDates(): array
    {
        return [
            'far future' => ['CBC report dated 06 Sep 2031'],
            'before 1990' => ['CBC report dated 06/09/1972'],
            'not a calendar date' => ['CBC report dated 31/02/2026'],
            'a reference number' => ['CBC  Lab no. 2026-45-99'],
        ];
    }

    #[DataProvider('implausibleDates')]
    public function test_implausible_dates_are_refused_rather_than_stored(string $text): void
    {
        $derived = $this->deriver->derive($text, self::FALLBACK, now: $this->now);

        $this->assertNull($derived->documentDate);
        $this->assertSame('CBC', $derived->title);
    }

    public function test_tomorrow_is_still_accepted_because_a_clinic_clock_may_drift(): void
    {
        $derived = $this->deriver->derive('CBC 09 Sep 2026', self::FALLBACK, now: $this->now);

        $this->assertSame('2026-09-09', $derived->documentDate?->toDateString());
    }

    public function test_a_long_page_is_capped_before_it_reaches_the_encrypted_column(): void
    {
        $derived = $this->deriver->derive('CBC 06 Sep 2026 '.str_repeat('a', 40_000), self::FALLBACK, now: $this->now);

        $this->assertSame(DocumentTitleDeriver::MAX_OCR_TEXT, mb_strlen($derived->ocrText));
        $this->assertSame('CBC – 06 Sep 2026', $derived->title);
    }

    public function test_the_title_never_exceeds_the_column_and_is_never_empty(): void
    {
        $long = str_repeat('Discharge summary of a very long stay ', 20);
        $title = $this->deriver->derive('nothing here', $long, now: $this->now)->title;

        $this->assertLessThanOrEqual(DocumentTitleDeriver::MAX_TITLE, mb_strlen($title));
        $this->assertGreaterThan(DocumentTitleDeriver::MAX_TITLE - 20, mb_strlen($title));
        $this->assertStringStartsWith('Discharge summary', $title);
        $this->assertSame(self::FALLBACK, $this->deriver->derive('   ', self::FALLBACK, now: $this->now)->title);
    }
}
