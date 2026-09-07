<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Queries\TopDiagnosesQuery;
use App\Domain\Reports\Queries\TopDrugsQuery;
use App\Models\Tenant\DoctorSpecialty;
use App\Models\Tenant\Specialty;
use App\Support\Clock;
use Tests\Feature\Reports\Concerns\ReportFixture;
use Tests\TestCase;

/**
 * Diagnoses in the fixture: E11 on V1, V2 and V4 (twice on V4 — one visit, one count); I10 on V1 and V5;
 * an uncoded "Viral fever"/"viral fever" on V3 and V6.
 * Drug lines on issued prescriptions: Paracetamol ×4 (Napa ×3, Ace ×1) and Metformin ×1 with no brand.
 */
final class ClinicalReportTest extends TestCase
{
    use ReportFixture;

    private TopDiagnosesQuery $diagnoses;

    private TopDrugsQuery $drugs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->seedReportFixture();
        $this->diagnoses = app(TopDiagnosesQuery::class);
        $this->drugs = app(TopDrugsQuery::class);
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    private function march(): ReportFilters
    {
        return ReportFilters::forDays('2026-03-01', '2026-03-10');
    }

    public function test_a_diagnosis_listed_twice_on_one_visit_is_counted_once(): void
    {
        $rows = $this->rows($this->diagnoses->rows($this->march()))->keyBy('key');

        // V4 carries E11 as both provisional and final; that is one visit with that diagnosis.
        $this->assertSame(3, $rows['E11']['visits']);
        $this->assertSame(3, $rows['E11']['patients']);
        $this->assertSame(2, $rows['I10']['visits']);
        $this->assertSame(6, $this->diagnoses->visitCount($this->march()));
    }

    public function test_the_displayed_title_is_the_commonest_spelling_seen_under_the_code(): void
    {
        $rows = $this->rows($this->diagnoses->rows($this->march()))->keyBy('key');

        // "Type 2 diabetes" appears three times against "Type 2 diabetes mellitus" once.
        $this->assertSame('Type 2 diabetes', $rows['E11']['title']);
        $this->assertTrue($rows['E11']['coded']);
    }

    public function test_an_uncoded_diagnosis_is_still_counted_grouped_by_its_text(): void
    {
        $rows = $this->rows($this->diagnoses->rows($this->march()))->keyBy('key');

        // "Viral fever" and "viral fever" are the same clinical reality; the key lower-cases the text.
        $this->assertArrayHasKey('~viral fever', $rows);
        $this->assertSame(2, $rows['~viral fever']['visits']);
        $this->assertFalse($rows['~viral fever']['coded']);
        $this->assertNull($rows['~viral fever']['icd10_code']);
    }

    public function test_the_diagnosis_trend_reports_the_leading_keys_per_period(): void
    {
        $summary = $this->diagnoses->summary($this->march());
        $trend = $this->rows($summary['trend']);

        $this->assertSame(3, $trend->where('key', 'E11')->sum('visits'));
        $this->assertSame(2, $trend->where('period', '2026-03-02')->sum('visits'));   // I10 on V5, viral fever on V6
        $this->assertContains('E11', $summary['trend_keys']);
    }

    public function test_only_issued_prescriptions_are_counted(): void
    {
        $totals = $this->drugs->totals($this->march());

        // Rx1 (2 lines) + Rx2 + Rx4 + Rx5 = 5 lines over 4 prescriptions. The amended v1 and the draft are out.
        $this->assertSame(5, $totals['items']);
        $this->assertSame(4, $totals['prescriptions']);
        // The Metformin line names no brand.
        $this->assertSame(1, $totals['generic_only']);
        $this->assertSame(20.0, $totals['generic_only_rate']);
    }

    public function test_top_drugs_by_generic_and_by_brand_use_the_prescription_snapshot(): void
    {
        $generics = $this->rows($this->drugs->byGeneric($this->march()))->keyBy('key');
        $this->assertSame(4, $generics['paracetamol']['items']);
        $this->assertSame(4, $generics['paracetamol']['prescriptions']);
        $this->assertSame(4, $generics['paracetamol']['patients']);
        $this->assertSame('Paracetamol', $generics['paracetamol']['name']);
        $this->assertSame(1, $generics['metformin']['items']);

        $brands = $this->rows($this->drugs->byBrand($this->march()))->keyBy('key');
        $this->assertSame(3, $brands['napa']['items']);
        $this->assertSame(1, $brands['ace']['items']);
        $this->assertSame('Paracetamol', $brands['napa']['generic_name']);
        // A line prescribed by molecule has no brand row at all rather than an empty one.
        $this->assertCount(2, $brands);
    }

    public function test_the_specialty_filter_narrows_to_that_specialty_s_doctors(): void
    {
        $cardiology = Specialty::factory()->create(['slug' => 'cardio-fx', 'name' => 'Cardiology']);
        DoctorSpecialty::query()->create(['doctor_id' => $this->rahman->id, 'specialty_id' => $cardiology->id, 'is_primary' => true]);

        $filters = new ReportFilters(
            from: $this->march()->from,
            to: $this->march()->to,
            specialtyId: $cardiology->id,
        );

        // Only Dr Rahman's four March visits carry a cardiology-tagged doctor.
        $this->assertSame(4, $this->diagnoses->visitCount($filters));
        $this->assertSame(4, $this->drugs->totals($filters)['items']);

        $rows = $this->rows($this->diagnoses->rows($filters))->keyBy('key');
        $this->assertSame(3, $rows['E11']['visits']);
        // I10 is on V1 (Rahman) and V5 (Sultana); the filter leaves only Rahman's.
        $this->assertSame(1, $rows['I10']['visits']);
    }
}
