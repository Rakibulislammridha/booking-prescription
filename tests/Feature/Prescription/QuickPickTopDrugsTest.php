<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Prescription\Services\DoctorLearningCache;
use App\Models\Tenant\DoctorFavourite;
use App\Models\Tenant\Prescription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRESCRIPTION.md §3.5 — the quick-pick row a doctor clicks once.
 *
 * The row carries the presentation, not just the ids: `form_code`, `default_unit`, `strength_mg`, `per_ml`,
 * `pack_size`/`pack_unit`. Without them the client builds its ParseContext from tablet defaults, so a one-click
 * syrup line is parsed as tablets until the server echo replaces it a round trip later — the visible flicker on
 * every quick-pick insert. With them, the first render of the line is already the right one.
 */
final class QuickPickTopDrugsTest extends TestCase
{
    use PrescriptionTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_a_top_drug_row_carries_the_presentation_the_client_parser_needs(): void
    {
        [, $doctor] = $this->doctorWithOpenVisit();
        $this->favourite($doctor->id, $this->presentation('paracetamol', 'syr'), 'Napa Syrup 120 mg/5 ml', '2+0+2 7d af');

        $row = app(DoctorLearningCache::class)->top50($doctor->id)[0] ?? [];
        $drug = (array) ($row['drug'] ?? []);

        $this->assertSame('syr', $drug['form_code']);
        $this->assertSame('ml', $drug['default_unit']);                          // a syrup is measured in ml, not tabs
        $this->assertSame(120.0, $drug['strength_mg']);
        $this->assertSame(24.0, $drug['per_ml']);
        $this->assertSame(100.0, $drug['pack_size']);
        $this->assertSame('ml', $drug['pack_unit']);
        $this->assertSame('Paracetamol', $drug['generic_name']);
        $this->assertNotNull($drug['brand_name']);
        $this->assertSame('120 mg/5 ml', $drug['strength']);
        $this->assertSame('presentation', $drug['kind']);
        $this->assertSame('s'.$drug['strength_id'], $drug['presentation_key']);
        // The dose he last prescribed for it — what the insert pre-fills into the line (§3.5).
        $this->assertSame('2+0+2 7d af', $row['default_dose']['shorthand']);
    }

    public function test_a_reference_that_no_longer_resolves_still_produces_a_usable_row(): void
    {
        [, $doctor] = $this->doctorWithOpenVisit();
        DoctorFavourite::factory()->create(['doctor_id' => $doctor->id, 'generic_id' => 987654321, 'label' => 'Gone 10 mg Tab']);

        $drug = (array) (app(DoctorLearningCache::class)->top50($doctor->id)[0]['drug'] ?? []);

        // The keys are always present so a cached row is recognisable; the values are null and the label carries on.
        $this->assertArrayHasKey('form_code', $drug);
        $this->assertNull($drug['form_code']);
        $this->assertSame('Gone 10 mg Tab', $drug['generic_name']);
    }

    public function test_a_cache_written_before_the_row_carried_the_presentation_is_rebuilt_not_served(): void
    {
        [, $doctor] = $this->doctorWithOpenVisit();
        $this->favourite($doctor->id, $this->presentation('paracetamol', 'tab', '500 mg'), 'Napa 500 mg Tab', '1+0+1 5d af');
        $learning = app(DoctorLearningCache::class);

        // Exactly what a pre-upgrade Redis key holds: ids, label, dose — and no presentation at all.
        Cache::store(config('prescription.cache_store'))->put($learning->key($doctor->id, 'top50'), [[
            'id' => 1, 'icd10_code' => null, 'drug' => ['kind' => 'presentation', 'generic_id' => 1, 'presentation_key' => 's1'],
            'label' => 'Stale', 'default_dose' => [], 'use_count' => 1, 'is_pinned' => false, 'rank' => 0, 'last_used_at' => null,
        ]], 60);

        $rows = $learning->top50($doctor->id);

        $this->assertSame('Napa 500 mg Tab', $rows[0]['label']);
        $this->assertSame('tab', $rows[0]['drug']['form_code']);
        $this->assertSame('tab', $rows[0]['drug']['default_unit']);
    }

    public function test_the_top_drugs_endpoint_serves_the_same_rows(): void
    {
        [, $doctor] = $this->doctorWithOpenVisit();
        $this->favourite($doctor->id, $this->presentation('paracetamol', 'tab', '500 mg'), 'Napa 500 mg Tab', '1+0+1 5d af');

        $this->getJson('/panel/doctors/me/top-drugs')->assertOk()
            ->assertJsonPath('data.0.drug.form_code', 'tab')
            ->assertJsonPath('data.0.drug.strength_mg', 500)
            ->assertJsonPath('data.0.default_dose.shorthand', '1+0+1 5d af');
    }

    public function test_the_writer_payload_carries_the_presentation_and_stays_inside_its_query_budget(): void
    {
        [, $doctor, $visit] = $this->doctorWithOpenVisit();
        $this->favourite($doctor->id, $this->presentation('paracetamol', 'tab', '500 mg'), 'Napa 500 mg Tab', '1+0+1 5d af');
        $this->get('/panel/visits/'.$visit->public_id.'/prescribe')->assertOk();

        DB::connection('pgsql')->enableQueryLog();
        $this->get('/panel/visits/'.$visit->public_id.'/prescribe?format=json')->assertOk()
            ->assertJsonPath('quick_pick.top_drugs.0.drug.form_code', 'tab')
            ->assertJsonPath('quick_pick.top_drugs.0.drug.default_unit', 'tab');
        $count = count(DB::connection('pgsql')->getQueryLog());
        DB::connection('pgsql')->disableQueryLog();

        $this->assertSame(1, Prescription::query()->where('visit_id', $visit->id)->count());
        // The same budget WriterPageTest holds: resolving the quick-pick rows is cached per doctor, not per open.
        $this->assertLessThan(45, $count, "writer open ran {$count} queries");
    }

    /** @param  array{generic_id: int, brand_id: int, strength_id: int}  $presentation */
    private function favourite(int $doctorId, array $presentation, string $label, string $shorthand): DoctorFavourite
    {
        return DoctorFavourite::factory()->create($presentation + [
            'doctor_id' => $doctorId,
            'label' => $label,
            'default_dose' => ['dose_schedule' => null, 'duration_days' => null, 'timing' => 'after', 'instruction' => null, 'shorthand' => $shorthand],
        ]);
    }
}
