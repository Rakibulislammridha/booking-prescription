<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Prescription\Data\PrescriptionSnapshot;
use App\Domain\Prescription\Render\PrescriptionRenderer;
use App\Domain\Prescription\Render\RenderOptions;
use App\Models\Tenant\Vital;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * "Make the temperature F not C all over the site where vitals can be entered." The database keeps °C
 * (`vitals.temperature_c`, SCHEMA §3.4 — the clinical canonical unit); every human boundary is °F: the entry
 * fields validate °F and `VitalsData` converts once, every JSON shape carries `temperature_f` next to the stored
 * `temperature_c`, and the printed sheet, the PDF and /rx/{code} convert at render time from the snapshot's °C —
 * which is what keeps a prescription issued before this change printing correctly.
 */
final class VitalsTemperatureTest extends TestCase
{
    use PrescriptionTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    /**
     * A whole-number float leaves json_encode as `38`, not `38.0`; the contract is the number, not its spelling.
     *
     * @param  TestResponse<Response>  $response
     * @return TestResponse<Response>
     */
    private function assertJsonNumber(TestResponse $response, string $path, float $expected): TestResponse
    {
        $this->assertEqualsWithDelta($expected, (float) $response->json($path), 0.0001, "{$path} should be {$expected}");

        return $response;
    }

    public function test_the_entry_endpoint_validates_in_fahrenheit_and_stores_celsius(): void
    {
        [, , $visit] = $this->doctorWithOpenVisit();
        $url = '/panel/visits/'.$visit->public_id.'/vitals';

        // 98.6 °F is a normal reading: accepted, stored as 37.0 °C, answered in both units.
        $created = $this->postJson($url, ['temperature_f' => 98.6, 'pulse_bpm' => 76])->assertCreated();
        $this->assertJsonNumber($created, 'vitals.temperature_c', 37.0);
        $this->assertJsonNumber($created, 'vitals.temperature_f', 98.6);

        $vital = Vital::query()->where('visit_id', $visit->id)->firstOrFail();
        $this->assertSame(37.0, $vital->temperature_c, 'RecordVitals stores the °C the column is defined in');

        // 37 is a thermometer read in °C typed into the °F field: refused with a message that says what the field wants
        // — the °F bounds and the °C they correspond to, in the user's own language (the fixture doctor works in Bangla).
        $message = (string) $this->postJson($url, ['temperature_f' => 37])->assertUnprocessable()
            ->assertJsonValidationErrors(['temperature_f'])->json('errors.temperature_f.0');
        $this->assertMatchesRegularExpression('/86.*113.*°(F|ফা).*30.*45.*°(C|সে)/u', $message);

        // 105 °F is a high fever, not a typo.
        $this->assertJsonNumber($this->postJson($url, ['temperature_f' => 105])->assertCreated(), 'vitals.temperature_c', 40.6);

        // 120 °F is outside anything the column (30–45 °C) accepts.
        $this->postJson($url, ['temperature_f' => 120])->assertUnprocessable()->assertJsonValidationErrors(['temperature_f']);
        $this->postJson($url, ['temperature_f' => 'hot'])->assertUnprocessable()->assertJsonValidationErrors(['temperature_f' => '113']);

        // A stale client still sending the old °C key is told so rather than having its reading dropped on the floor.
        $this->postJson($url, ['temperature_c' => 37.2])->assertUnprocessable()->assertJsonValidationErrors(['temperature_c' => 'temperature_f']);

        $this->assertSame(2, Vital::query()->where('visit_id', $visit->id)->count());
    }

    public function test_the_doctor_edit_converts_the_same_way_and_can_clear_a_reading(): void
    {
        [, , $visit] = $this->doctorWithOpenVisit();
        $vital = Vital::factory()->for($visit)->create(['temperature_c' => 37.0]);

        $edited = $this->patchJson('/panel/vitals/'.$vital->id, ['temperature_f' => 100.4])->assertOk()->assertJsonPath('vitals.edited_by_doctor', true);
        $this->assertJsonNumber($edited, 'vitals.temperature_c', 38.0);
        $this->assertJsonNumber($edited, 'vitals.temperature_f', 100.4);
        $this->assertSame(38.0, $vital->refresh()->temperature_c);

        $this->patchJson('/panel/vitals/'.$vital->id, ['temperature_f' => null])->assertOk()
            ->assertJsonPath('vitals.temperature_c', null)
            ->assertJsonPath('vitals.temperature_f', null);
        $this->assertNull($vital->refresh()->temperature_c);
    }

    public function test_every_json_shape_carries_both_units_and_the_timeline_reads_fahrenheit(): void
    {
        [, , $visit] = $this->doctorWithOpenVisit();
        Vital::factory()->for($visit)->create(['temperature_c' => 38.0]);
        $patient = $visit->patient;

        $show = $this->getJson('/panel/visits/'.$visit->public_id)->assertOk();
        $this->assertJsonNumber($show, 'vitals.temperature_c', 38.0);
        $this->assertJsonNumber($show, 'vitals.temperature_f', 100.4);

        $index = $this->getJson('/panel/visits/'.$visit->public_id.'/vitals')->assertOk();
        $this->assertJsonNumber($index, 'data.0.temperature_c', 38.0);
        $this->assertJsonNumber($index, 'data.0.temperature_f', 100.4);

        $trend = $this->getJson('/panel/patients/'.$patient->public_id.'/vitals-trend')->assertOk();
        $this->assertJsonNumber($trend, 'data.0.temperature_c', 38.0);
        $this->assertJsonNumber($trend, 'data.0.temperature_f', 100.4);

        $timeline = $this->getJson('/panel/patients/'.$patient->public_id.'/timeline?kinds=vital')->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringContainsString('T 100.4°F', (string) $timeline->json('data.0.subtitle'));
        $this->assertStringNotContainsString('°C', (string) $timeline->json('data.0.subtitle'));
        $this->assertJsonNumber($timeline, 'data.0.meta.temperature_f', 100.4);

        $near = fn (float $expected) => fn (mixed $actual) => abs((float) $actual - $expected) < 0.0001;
        $this->get('/panel/patients/'.$patient->public_id)->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('vitals_trend.0.temperature_c', $near(38.0))->where('vitals_trend.0.temperature_f', $near(100.4)));
    }

    /**
     * The snapshot is the only render source (I6) and it keeps °C — nothing about a UI unit belongs in a frozen
     * clinical document — so the sheet converts when it renders. That is also what makes every prescription issued
     * BEFORE this change (a `visit.vitals` with `temperature_c` and nothing else) print in °F today.
     */
    public function test_the_print_pdf_and_verification_page_render_fahrenheit_from_a_celsius_snapshot(): void
    {
        [$rx] = $this->issuedWithContent();
        // issuedWithContent() leaves the factory's random temperature on the row; the snapshot froze it. Read it back.
        $storedC = $rx->snapshot?->get('visit.vitals.temperature_c');
        $this->assertIsFloat($storedC);
        $this->assertNull($rx->snapshot?->get('visit.vitals.temperature_f'), 'the frozen document stores the canonical unit only');
        $expectedF = number_format($storedC * 9 / 5 + 32, 1, '.', '');

        $html = $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent();
        $this->assertStringContainsString('<div class="section" data-section="vitals"', $html);
        $this->assertStringContainsString($expectedF.'°F', $html);
        $this->assertStringNotContainsString('°C', $html);

        $bn = $this->get('/panel/prescriptions/'.$rx->public_id.'/print?lang=bn')->assertOk()->getContent();
        $this->assertStringContainsString(strtr($expectedF, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']).'°ফা', $bn);

        $this->app['auth']->forgetGuards();
        $verify = $this->get('/rx/'.$rx->verification_code)->assertOk()->getContent();
        $this->assertStringContainsString($expectedF.'°F', $verify);
        $this->assertStringNotContainsString('°C', $verify);
    }

    public function test_a_legacy_snapshot_that_stored_only_celsius_prints_in_fahrenheit(): void
    {
        [$rx] = $this->issuedWithContent();
        $document = $rx->snapshot?->toArray() ?? [];
        // The shape every pre-°F snapshot has: the row's columns, temperature in °C, no `temperature_f` anywhere.
        $document['visit']['vitals'] = ['bp_systolic' => 120, 'bp_diastolic' => 80, 'pulse_bpm' => 96, 'temperature_c' => 39.4, 'spo2_percent' => 97, 'weight_kg' => 58.0, 'height_cm' => 160.0, 'bmi' => 22.7, 'recorded_at' => '2026-01-05T10:00:00+06:00'];
        $legacy = new PrescriptionSnapshot($document);

        $renderer = app(PrescriptionRenderer::class);
        // Vitals print as a labelled grid (§7.1), so the caption and the value are separate cells of one block.
        $en = $renderer->render($legacy, RenderOptions::fromPad($legacy->pad(), purpose: 'pdf', language: 'en'));
        $this->assertStringContainsString('>Temp</span>', $en);
        $this->assertStringContainsString('>102.9°F</span>', $en);
        $this->assertStringNotContainsString('39.4', $en);
        $this->assertStringContainsString('>120/80<span class="vital-u"> mmHg</span>', $en);

        $both = $renderer->render($legacy, RenderOptions::fromPad($legacy->pad(), purpose: 'print', language: 'both'));
        $this->assertStringContainsString('102.9°F', $both);

        $bn = $renderer->render($legacy, RenderOptions::fromPad($legacy->pad(), purpose: 'pdf', language: 'bn'));
        $this->assertStringContainsString('>তাপমাত্রা</span>', $bn);
        $this->assertStringContainsString('>১০২.৯°ফা</span>', $bn);
        $this->assertStringNotContainsString('°C', $bn);

        // A snapshot from the pad designer's sample document stores the value as a string — still °F on paper.
        $document['visit']['vitals']['temperature_c'] = '38.2';
        $this->assertStringContainsString('100.8°F', $renderer->render(new PrescriptionSnapshot($document), RenderOptions::fromPad($legacy->pad(), language: 'en')));
    }
}
