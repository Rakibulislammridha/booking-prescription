<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Prescription\Render\PadGeometry;
use App\Domain\Prescription\Services\SnapshotBuilder;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorPadSetting;
use App\Models\Tenant\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * BRIEF §5.A pad designer + PRESCRIPTION.md §7.2. The claim under test is the one the whole screen rests on: what
 * the designer saves is what the print pipeline renders. Every geometry assertion below is made against the REAL
 * renderer output (the test-print route), not against the designer's own idea of the numbers.
 */
final class PadDesignerTest extends TestCase
{
    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        Storage::fake('uploads');
        $this->actingAsStaff(Role::HospitalAdmin);
        $this->doctor = Doctor::factory()->complete()->create(['name' => 'Dr. Rahman', 'code' => 'RAH', 'slug' => 'dr-rahman']);
    }

    public function test_the_designer_renders_with_the_renderers_own_clamps(): void
    {
        $this->get('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Clinic/Doctors/Pad')
            ->where('doctor.code', 'RAH')
            ->where('pad.paper_size', 'A5')
            ->where('pad.margins.top', 20)
            ->has('pad.layout.sections', 10)
            ->where('defaults.header_height_mm', 35)
            ->has('options.paper_sizes', 2)
            ->has('options.sections', 10)
            // PadGeometry clamps the footer band to 80mm even though the column allows 120 — the designer must
            // refuse what the renderer would silently pull back.
            ->where('limits.footer_height_mm.max', 80)
            ->where('limits.header_height_mm.max', 120)
            ->where('limits.margin_mm.max', 60)
            ->where('limits.font_size_pt.max', 18)
            ->where('limits.rx_font_size_pt.max', 20)
            ->where('assets.logo_url', null));
    }

    public function test_pad_settings_round_trip_and_preprinted_mode_blanks_the_letterhead(): void
    {
        $this->put('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad', [
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'preprinted_mode' => true,
            'letterhead_enabled' => true,          // the action forces this off in preprinted mode
            'header_height_mm' => 42,
            'footer_height_mm' => 18,
            'margins' => ['top' => 25, 'right' => 10, 'bottom' => 15, 'left' => 20],
            'font_size_pt' => 11.5,
            'default_language' => 'bn',
            'show_qr' => false,
            'layout' => [
                'columns' => 2,
                'rx_font_size_pt' => 12,
                'flags' => ['icd_codes' => false, 'investigation_prices' => true, 'generic_names' => true],
                'sections' => [['key' => 'rx', 'visible' => true], ['key' => 'vitals', 'visible' => false]],
            ],
        ])->assertRedirect('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad');

        $pad = DoctorPadSetting::query()->where('doctor_id', $this->doctor->id)->firstOrFail();
        $this->assertSame('A4', $pad->paper_size->value);
        $this->assertTrue($pad->preprinted_mode);
        $this->assertFalse($pad->letterhead_enabled);
        $this->assertSame(42, $pad->header_height_mm);
        $this->assertEqualsCanonicalizing(['top' => 25, 'right' => 10, 'bottom' => 15, 'left' => 20], $pad->margins);
        $this->assertSame('bn', $pad->default_language);

        // The designer's page reads the same row back, with the full ten sections restored around the two stored.
        $this->get('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('pad.preprinted_mode', true)
            ->where('pad.letterhead_enabled', false)
            ->where('pad.layout.columns', 2)
            ->where('pad.layout.flags.icd_codes', false)
            ->where('pad.layout.sections.0.key', 'rx')
            ->where('pad.layout.sections.1.key', 'vitals')
            ->where('pad.layout.sections.1.visible', false)
            ->has('pad.layout.sections', 10));

        // …and `pad_snapshot` — what is frozen onto every prescription at issue — carries exactly these numbers.
        $frozen = app(SnapshotBuilder::class)->padArray($pad);
        $this->assertSame(42, $frozen['header_height_mm']);
        $this->assertSame('A4', $frozen['paper_size']);
        $this->assertFalse($frozen['letterhead_enabled']);
    }

    public function test_the_print_pipeline_renders_exactly_what_the_designer_saved(): void
    {
        $this->put('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad', [
            'paper_size' => 'A4', 'orientation' => 'portrait', 'preprinted_mode' => true,
            'header_height_mm' => 42, 'footer_height_mm' => 18,
            'margins' => ['top' => 25, 'right' => 10, 'bottom' => 15, 'left' => 20],
            'font_size_pt' => 11.5,
        ])->assertRedirect();

        $html = $this->get('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad/test-print')->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('@page { size: A4 portrait; margin: 25mm 10mm 15mm 20mm; }', $html);
        $this->assertStringContainsString('font-size: 11.5pt;', $html);
        $this->assertStringContainsString('.header-spacer { height: 42mm; }', $html);
        $this->assertStringContainsString('data-preprinted-header="42mm"', $html);
        // Preprinted mode reserves the footer band too (PadGeometry::reservedFooterMm).
        $this->assertStringContainsString('padding-bottom: 18mm;', $html);
        // A test page must never be mistakable for a prescription.
        $this->assertStringContainsString('DRAFT', $html);
        // The letterhead is suppressed: the doctor's own pad already carries it.
        $this->assertStringNotContainsString('class="letterhead"', $html);
    }

    public function test_switching_off_preprinted_mode_prints_the_letterhead_again(): void
    {
        $this->put('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad', [
            'paper_size' => 'A5', 'preprinted_mode' => false, 'letterhead_enabled' => true,
        ])->assertRedirect();

        $html = (string) $this->get('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad/test-print')->assertOk()->getContent();

        $this->assertStringContainsString('@page { size: A5 portrait;', $html);
        $this->assertStringContainsString('class="letterhead"', $html);
        $this->assertStringNotContainsString('data-preprinted-header', $html);
    }

    public function test_logo_and_signature_uploads_land_under_the_tenant_prefix(): void
    {
        $this->post('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad/asset', [
            'kind' => 'logo',
            'file' => UploadedFile::fake()->image('logo.png', 200, 80),
        ])->assertRedirect();

        $pad = DoctorPadSetting::query()->where('doctor_id', $this->doctor->id)->firstOrFail();
        $this->assertNotNull($pad->logo_path);
        $this->assertStringStartsWith('tenants/9001/doctors/'.$this->doctor->public_id.'/pad/logo-', (string) $pad->logo_path);
        Storage::disk('uploads')->assertExists((string) $pad->logo_path);

        $this->post('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad/asset', [
            'kind' => 'signature',
            'file' => UploadedFile::fake()->image('sign.png', 300, 100),
        ])->assertRedirect();

        $pad->refresh();
        $this->assertStringStartsWith('tenants/9001/doctors/'.$this->doctor->public_id.'/pad/signature-', (string) $pad->signature_path);

        // Both are served back through the panel session, never as a public URL.
        $this->get('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('assets.logo_url', route('panel.clinic.doctors.pad.asset.show', ['doctor' => $this->doctor->public_id, 'kind' => 'logo']))
            ->where('assets.signature_url', route('panel.clinic.doctors.pad.asset.show', ['doctor' => $this->doctor->public_id, 'kind' => 'signature'])));

        $this->get('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad/asset/logo')->assertOk();

        $this->delete('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad/asset', ['kind' => 'logo'])->assertRedirect();
        $this->assertNull($pad->fresh()?->logo_path);
    }

    public function test_a_doctor_photo_lands_under_the_tenant_prefix_too(): void
    {
        $this->post('/panel/clinic/doctors/'.$this->doctor->public_id.'/photo', [
            'photo' => UploadedFile::fake()->image('doctor.jpg', 400, 400),
        ])->assertRedirect();

        $path = (string) $this->doctor->fresh()?->photo_path;
        $this->assertStringStartsWith('tenants/9001/doctors/'.$this->doctor->public_id.'/photo-', $path);
        Storage::disk('uploads')->assertExists($path);
        $this->get('/panel/clinic/doctors/'.$this->doctor->public_id.'/photo')->assertOk();
    }

    public function test_the_designer_rejects_values_the_renderer_would_clamp(): void
    {
        $this->put('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad', ['margins' => ['top' => 90, 'right' => 15, 'bottom' => 20, 'left' => 15]])
            ->assertSessionHasErrors('margins.top');

        $this->put('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad', ['header_height_mm' => 500])
            ->assertSessionHasErrors('header_height_mm');

        $this->put('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad', ['paper_size' => 'A3'])
            ->assertSessionHasErrors('paper_size');

        // The three the request used to wave through: the column is wider than PadGeometry honours, so a
        // non-UI caller could store a number that changed itself the first time the pad printed.
        $this->put('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad', ['footer_height_mm' => 100])
            ->assertSessionHasErrors('footer_height_mm');

        $this->put('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad', ['font_size_pt' => 22])
            ->assertSessionHasErrors('font_size_pt');

        $this->put('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad', ['layout' => ['rx_font_size_pt' => 22]])
            ->assertSessionHasErrors('layout.rx_font_size_pt');

        // …and the bounds are the renderer's own, not a copy: the maxima still round-trip.
        $this->put('/panel/clinic/doctors/'.$this->doctor->public_id.'/pad', [
            'footer_height_mm' => PadGeometry::LIMITS['footer_height_mm']['max'],
            'font_size_pt' => PadGeometry::LIMITS['font_size_pt']['max'],
            'layout' => ['rx_font_size_pt' => PadGeometry::LIMITS['rx_font_size_pt']['max']],
        ])->assertSessionHasNoErrors();
    }

    public function test_a_doctor_designs_only_their_own_pad(): void
    {
        $other = Doctor::factory()->complete()->create(['code' => 'OTH', 'slug' => 'dr-other']);
        $doctorUser = $this->actingAsDoctor();
        /** @var User $doctorUser */
        $own = $doctorUser->doctor()->firstOrFail();

        $this->get('/panel/clinic/doctors/'.$own->public_id.'/pad')->assertOk();
        $this->get('/panel/clinic/doctors/'.$other->public_id.'/pad')->assertForbidden();
        $this->put('/panel/clinic/doctors/'.$other->public_id.'/pad', ['paper_size' => 'A4'])->assertForbidden();
    }
}
