<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Patients\Services\OcrSettings;
use App\Domain\Prescription\Data\Letterhead;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorPadSetting;
use App\Models\Tenant\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * BRIEF §5.A — the letterhead a doctor actually designs, and the sample pad they trace it against.
 *
 * The claim under test is that the letterhead is CONTENT, not markup: a line is text plus a colour, a weight, a
 * size and an alignment, it round-trips through the column unchanged (Bangla included), and nothing that is not
 * text can be stored in it. The sample pad is tested for what it is — a private tracing guide that is uploaded,
 * audited, served through the session and never printed.
 */
final class PadLetterheadTest extends TestCase
{
    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        Storage::fake('uploads');
        $this->actingAsStaff(Role::HospitalAdmin);
        $this->doctor = Doctor::factory()->complete()->create(['name' => 'Dr. Rahman', 'code' => 'RAH', 'slug' => 'dr-rahman']);
        $this->doctor->profile?->fill(['degrees' => 'MBBS (DMC), FCPS (Medicine)', 'bmdc_reg_no' => 'A-12345', 'designation' => 'Associate Professor'])->save();
    }

    private function url(string $suffix = ''): string
    {
        return '/panel/clinic/doctors/'.$this->doctor->public_id.'/pad'.$suffix;
    }

    /** @return array<string, mixed> */
    private function line(string $text, string $color = 'text', string $weight = 'normal', float $size = 1.0, string $transform = 'none', ?string $textBn = null, ?string $align = null): array
    {
        return ['text' => $text, 'text_bn' => $textBn, 'color' => $color, 'weight' => $weight, 'size' => $size, 'transform' => $transform, 'align' => $align];
    }

    /**
     * @param  array<int, array<string, mixed>>  $header
     * @param  array<int, array<string, mixed>>|null  $columns
     * @return array<string, mixed>
     */
    private function letterhead(array $header, ?array $columns = null): array
    {
        return [
            'accent_color' => '#B03A2E',
            'text_color' => '#1A1A1A',
            'muted_color' => '#666666',
            'header' => ['align' => 'center', 'lines' => $header, 'rule' => true],
            'footer' => ['columns' => $columns ?? [['align' => 'left', 'logo' => true, 'lines' => []]], 'rule' => true],
        ];
    }

    public function test_the_designer_ships_the_letterhead_and_a_default_built_from_the_doctors_profile(): void
    {
        $this->get($this->url())->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Clinic/Doctors/Pad')
            // A pad row that has never been designed still arrives in the full contract shape.
            ->where('pad.letterhead.accent_color', Letterhead::DEFAULT_ACCENT)
            ->where('pad.letterhead.header.align', 'left')
            ->has('pad.letterhead.header.lines', 0)
            ->where('pad.sample_path', null)
            // …and "reset to my profile" is `Letterhead::defaults()` — the doctor's own name, degrees, designation
            // and BMDC number on the left, the clinic on the right, which is exactly what an undesigned pad prints.
            ->where('letterhead_defaults.header.align', 'split')
            ->where('letterhead_defaults.header.lines.0.text', 'Dr. Rahman')
            ->where('letterhead_defaults.header.lines.0.color', 'accent')
            ->where('letterhead_defaults.header.lines.0.weight', 'bold')
            ->where('letterhead_defaults.header.lines.1.text', 'MBBS (DMC), FCPS (Medicine)')
            ->where('letterhead_defaults.header.lines.2.text', 'Associate Professor')
            ->where('assets.sample_url', null)
            ->where('assets.sample_kind', null)
            // No OCR driver is configured in the suite, so the read action is offered as unavailable with a reason.
            ->where('ocr.available', false)
            ->where('ocr.reason', 'no_sample')
            ->has('sample_lines', 0));
    }

    public function test_a_full_letterhead_round_trips_with_its_bangla_lines_and_per_line_styling(): void
    {
        $letterhead = $this->letterhead(
            [
                $this->line('PROF. DR. A RAHMAN', 'accent', 'bold', 1.45, 'uppercase', 'প্রফেসর ডা. এ রহমান'),
                $this->line('MBBS (DMC), FCPS (Medicine)', 'text', 'normal', 0.95),
                $this->line('Associate Professor, Medicine', 'muted', 'normal', 0.88, 'none', null, 'right'),
            ],
            [
                ['align' => 'left', 'logo' => true, 'lines' => [$this->line('Seba Hospital', 'text', 'bold', 0.95, 'none', 'সেবা হাসপাতাল')]],
                ['align' => 'center', 'logo' => false, 'lines' => [$this->line('Chamber', 'accent', 'bold', 0.9, 'uppercase', 'চেম্বার')]],
                ['align' => 'right', 'logo' => false, 'lines' => [$this->line('01711-000000', 'accent', 'bold', 1.0, 'none', '০১৭১১-০০০০০০')]],
            ],
        );
        $letterhead['accent_color'] = '#004080';

        $this->put($this->url(), ['letterhead' => $letterhead])->assertSessionHasNoErrors()->assertRedirect($this->url());

        $pad = DoctorPadSetting::query()->where('doctor_id', $this->doctor->id)->firstOrFail();
        $this->assertSame('#004080', $pad->letterhead['accent_color']);
        $this->assertSame('প্রফেসর ডা. এ রহমান', $pad->letterhead['header']['lines'][0]['text_bn']);
        $this->assertSame(1.45, (float) $pad->letterhead['header']['lines'][0]['size']);
        $this->assertSame('uppercase', $pad->letterhead['header']['lines'][0]['transform']);
        $this->assertSame('right', $pad->letterhead['header']['lines'][2]['align']);
        $this->assertCount(3, $pad->letterhead['footer']['columns']);
        $this->assertSame('০১৭১১-০০০০০০', $pad->letterhead['footer']['columns'][2]['lines'][0]['text_bn']);

        $this->get($this->url())->assertInertia(fn (AssertableInertia $p) => $p
            ->where('pad.letterhead.accent_color', '#004080')
            ->has('pad.letterhead.header.lines', 3)
            ->where('pad.letterhead.header.lines.0.text_bn', 'প্রফেসর ডা. এ রহমান')
            ->where('pad.letterhead.header.lines.0.weight', 'bold')
            ->where('pad.letterhead.header.lines.2.align', 'right')
            ->has('pad.letterhead.footer.columns', 3)
            ->where('pad.letterhead.footer.columns.1.align', 'center')
            ->where('pad.letterhead.footer.columns.0.logo', true));
    }

    public function test_saving_the_defaults_the_designer_was_handed_is_a_no_op_round_trip(): void
    {
        $defaults = (array) $this->get($this->url())->viewData('page')['props']['letterhead_defaults'];

        $this->put($this->url(), ['letterhead' => $defaults])->assertSessionHasNoErrors();

        $pad = DoctorPadSetting::query()->where('doctor_id', $this->doctor->id)->firstOrFail();
        $this->assertSame('Dr. Rahman', $pad->letterhead['header']['lines'][0]['text']);
        $this->assertSame('split', $pad->letterhead['header']['align']);
        $this->assertContains('BMDC A-12345', array_column($pad->letterhead['header']['lines'], 'text'));
    }

    public function test_the_letterhead_refuses_what_the_print_partials_could_not_draw(): void
    {
        foreach (['maroon', 'rgb(1,2,3)', '#B03A2', 'B03A2E'] as $notAHex) {
            $bad = $this->letterhead([$this->line('Name')]);
            $bad['accent_color'] = $notAHex;
            $this->put($this->url(), ['letterhead' => $bad])->assertSessionHasErrors('letterhead.accent_color');
        }

        $this->put($this->url(), ['letterhead' => $this->letterhead([$this->line('Too big', 'text', 'normal', 4.0)])])
            ->assertSessionHasErrors('letterhead.header.lines.0.size');
        $this->put($this->url(), ['letterhead' => $this->letterhead([$this->line('Too small', 'text', 'normal', 0.2)])])
            ->assertSessionHasErrors('letterhead.header.lines.0.size');

        $this->put($this->url(), ['letterhead' => $this->letterhead([$this->line('Name', 'brand')])])
            ->assertSessionHasErrors('letterhead.header.lines.0.color');

        // 21 header lines, and a fourth footer column: both are more than a sheet of paper holds.
        $twentyOne = array_map(fn (int $i) => $this->line("Line {$i}"), range(1, Letterhead::MAX_LINES + 1));
        $this->put($this->url(), ['letterhead' => $this->letterhead($twentyOne)])->assertSessionHasErrors('letterhead.header.lines');

        $fourColumns = array_map(fn (int $i) => ['align' => 'left', 'logo' => false, 'lines' => [$this->line("Column {$i}")]], range(1, Letterhead::MAX_COLUMNS + 1));
        $this->put($this->url(), ['letterhead' => $this->letterhead([$this->line('Name')], $fourColumns)])->assertSessionHasErrors('letterhead.footer.columns');

        // …and the twenty-line, three-column maxima still save.
        $twenty = array_map(fn (int $i) => $this->line("Line {$i}"), range(1, Letterhead::MAX_LINES));
        $three = array_map(fn (int $i) => ['align' => 'left', 'logo' => false, 'lines' => [$this->line("Column {$i}")]], range(1, Letterhead::MAX_COLUMNS));
        $this->put($this->url(), ['letterhead' => $this->letterhead($twenty, $three)])->assertSessionHasNoErrors();
    }

    public function test_markup_pasted_into_a_line_is_stored_as_text_or_not_at_all(): void
    {
        $this->put($this->url(), ['letterhead' => $this->letterhead([
            $this->line('<b>Dr. Rahman</b><script>alert(1)</script>', 'accent', 'bold', 1.4, 'uppercase', '<i>ডা. রহমান</i>'),
        ])])->assertSessionHasNoErrors();

        $pad = DoctorPadSetting::query()->where('doctor_id', $this->doctor->id)->firstOrFail();
        $stored = $pad->letterhead['header']['lines'][0];
        $this->assertSame('Dr. Rahmanalert(1)', $stored['text']);
        $this->assertSame('ডা. রহমান', $stored['text_bn']);
        $this->assertStringNotContainsString('<', (string) $stored['text']);

        // A line that was ONLY markup has no text left, and an empty line is not a line the validator accepts.
        $this->put($this->url(), ['letterhead' => $this->letterhead([$this->line('<img src=x onerror=alert(1)>')])])
            ->assertSessionHasErrors('letterhead.header.lines.0.text');
    }

    public function test_the_sample_pad_lands_under_the_tenant_prefix_and_is_audited(): void
    {
        $this->post($this->url('/sample'), ['file' => UploadedFile::fake()->image('my-pad.jpg', 1200, 1700)])->assertRedirect();

        $pad = DoctorPadSetting::query()->where('doctor_id', $this->doctor->id)->firstOrFail();
        $path = (string) $pad->sample_path;
        $this->assertStringStartsWith('tenants/9001/doctors/'.$this->doctor->public_id.'/pad/sample-', $path);
        Storage::disk('uploads')->assertExists($path);

        $this->assertTrue(AuditLog::query()
            ->where('auditable_type', $pad->getMorphClass())
            ->where('action', AuditAction::Update)
            ->exists());

        // The designer serves it back through the panel session, and tells the page how to draw it.
        $this->get($this->url())->assertInertia(fn (AssertableInertia $p) => $p
            ->where('assets.sample_url', route('panel.clinic.doctors.pad.asset.show', ['doctor' => $this->doctor->public_id, 'kind' => 'sample']))
            ->where('assets.sample_kind', 'image')
            // With no OCR driver configured, the read action is not offered — the reason is now the driver.
            ->where('ocr.available', false)
            ->where('ocr.reason', 'not_configured'));

        $this->get($this->url('/asset/sample'))->assertOk();

        // Reading it anyway is refused rather than silently doing nothing.
        $this->post($this->url('/sample/read'))->assertRedirect();
        $this->assertNotNull(session('flash.error'));

        $this->delete($this->url('/sample'))->assertRedirect();
        $this->assertNull($pad->fresh()?->sample_path);
        Storage::disk('uploads')->assertMissing($path);
    }

    public function test_a_pdf_sample_is_accepted_and_an_executable_is_not(): void
    {
        $this->post($this->url('/sample'), ['file' => UploadedFile::fake()->create('pad.pdf', 64, 'application/pdf')])->assertRedirect();
        $this->assertStringEndsWith('.pdf', (string) DoctorPadSetting::query()->where('doctor_id', $this->doctor->id)->firstOrFail()->sample_path);
        $this->get($this->url())->assertInertia(fn (AssertableInertia $p) => $p->where('assets.sample_kind', 'pdf'));

        $this->post($this->url('/sample'), ['file' => UploadedFile::fake()->create('pad.svg', 12, 'image/svg+xml')])->assertSessionHasErrors('file');
        $this->post($this->url('/sample'), ['file' => UploadedFile::fake()->create('pad.exe', 12, 'application/x-msdownload')])->assertSessionHasErrors('file');
        // 8 MB is the ceiling: a phone photo fits, a scanned book does not.
        $this->post($this->url('/sample'), ['file' => UploadedFile::fake()->create('huge.pdf', 9000, 'application/pdf')])->assertSessionHasErrors('file');
    }

    /**
     * The OCR seam, both ways round. Unconfigured (the suite's default, and every clinic that has not paid for a
     * cloud engine) the designer is TOLD the action is off and why — it never renders a button that does nothing.
     * Configured, the same action reads the sample and offers its lines back, one at a time, as a prefill.
     */
    public function test_reading_text_from_the_sample_is_offered_only_when_an_engine_is_configured(): void
    {
        Http::fake(['vision.googleapis.com/*' => Http::response(['responses' => [['fullTextAnnotation' => ['text' => "PROF. DR. A RAHMAN\nMBBS (DMC), FCPS\nMBBS (DMC), FCPS\n.\nChamber: 6pm - 9pm"]]]])]);
        $this->post($this->url('/sample'), ['file' => UploadedFile::fake()->image('my-pad.jpg', 1200, 1700)])->assertRedirect();

        $this->get($this->url())->assertInertia(fn (AssertableInertia $p) => $p->where('ocr.available', false)->where('ocr.reason', 'not_configured'));
        $this->post($this->url('/sample/read'))->assertRedirect();
        Http::assertNothingSent();

        config(['patients.ocr.driver' => 'google', 'patients.ocr.key' => 'platform-fallback-key']);
        app(OcrSettings::class)->storeApiKey('tenant-key');

        $this->get($this->url())->assertInertia(fn (AssertableInertia $p) => $p->where('ocr.available', true)->where('ocr.reason', null));

        $this->post($this->url('/sample/read'))->assertRedirect();

        // Offered, not applied: the lines arrive as a page prop for the doctor to accept one by one, deduplicated,
        // with the stray marks a photographed pad always produces dropped.
        $this->get($this->url())->assertInertia(fn (AssertableInertia $p) => $p
            ->where('sample_lines.0', 'PROF. DR. A RAHMAN')
            ->where('sample_lines.1', 'MBBS (DMC), FCPS')
            ->where('sample_lines.2', 'Chamber: 6pm - 9pm')
            ->has('sample_lines', 3));

        // …and the letterhead itself is untouched until the doctor saves one.
        $this->assertSame([], DoctorPadSetting::query()->where('doctor_id', $this->doctor->id)->firstOrFail()->letterhead['header']['lines'] ?? []);
    }

    public function test_the_permission_matrix_holds_for_the_letterhead_and_the_sample(): void
    {
        $other = Doctor::factory()->complete()->create(['code' => 'OTH', 'slug' => 'dr-other']);
        $otherUrl = '/panel/clinic/doctors/'.$other->public_id.'/pad';

        /** @var User $doctorUser */
        $doctorUser = $this->actingAsDoctor();
        $own = $doctorUser->doctor()->firstOrFail();
        $ownUrl = '/panel/clinic/doctors/'.$own->public_id.'/pad';

        // A doctor designs their own pad, letterhead and sample included…
        $this->put($ownUrl, ['letterhead' => $this->letterhead([$this->line('My own pad')])])->assertSessionHasNoErrors()->assertRedirect();
        $this->post($ownUrl.'/sample', ['file' => UploadedFile::fake()->image('mine.png', 400, 560)])->assertRedirect();
        $this->post($ownUrl.'/sample/read')->assertRedirect();
        $this->delete($ownUrl.'/sample')->assertRedirect();

        // …and nobody else's.
        $this->put($otherUrl, ['letterhead' => $this->letterhead([$this->line('Not mine')])])->assertForbidden();
        $this->post($otherUrl.'/sample', ['file' => UploadedFile::fake()->image('theirs.png', 400, 560)])->assertForbidden();
        $this->post($otherUrl.'/sample/read')->assertForbidden();
        $this->delete($otherUrl.'/sample')->assertForbidden();
        $this->get($otherUrl.'/asset/sample')->assertForbidden();

        // A receptionist designs no pad at all.
        $this->actingAsStaff(Role::Receptionist);
        $this->get($ownUrl)->assertForbidden();
        $this->put($ownUrl, ['letterhead' => $this->letterhead([$this->line('Nope')])])->assertForbidden();
        $this->post($ownUrl.'/sample', ['file' => UploadedFile::fake()->image('nope.png', 400, 560)])->assertForbidden();

        // A hospital admin designs any doctor's.
        $this->actingAsStaff(Role::HospitalAdmin);
        $this->put($otherUrl, ['letterhead' => $this->letterhead([$this->line('Admin set this')])])->assertSessionHasNoErrors()->assertRedirect();
    }
}
