<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Prescription\Actions\AmendPrescription;
use App\Domain\Prescription\Jobs\GeneratePrescriptionPdf;
use App\Domain\Shared\Actor;
use App\Models\Tenant\DoctorPadSetting;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PRESCRIPTION.md §7.1–§7.3, §7.6 — the panel print surface: geometry driven by the frozen pad, the preprinted
 * blank band, the pharmacy variant, the language variants, auth, and the audit row every print writes.
 */
final class PrintRouteTest extends TestCase
{
    use PrescriptionTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_print_renders_the_frozen_snapshot_with_pad_geometry_and_audits_the_print(): void
    {
        [$rx] = $this->issuedWithContent(['paper_size' => 'A4', 'margins' => ['top' => 22, 'right' => 14, 'bottom' => 18, 'left' => 16], 'font_size_pt' => 11.5]);

        $html = $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->getContent();

        // Geometry comes from pad_snapshot, not from the live row (§7.2).
        $this->assertStringContainsString('@page { size: A4 portrait; margin: 22mm 14mm 18mm 16mm; }', $html);
        $this->assertStringContainsString('font-size: 11.5pt;', $html);
        $this->assertStringContainsString('Napa', $html);                       // brand, English, always
        $this->assertStringContainsString('প্রচুর পানি পান করুন', $html);          // Bangla advice survives to the markup
        $this->assertStringContainsString('window.print()', $html);             // §7.6 one click
        $this->assertStringContainsString((string) $rx->verification_code, $html);

        $rx->refresh();
        $this->assertSame(1, $rx->printed_count);
        $this->assertNotNull($rx->last_printed_at);
        $this->assertAudited(AuditAction::Print, $rx, ['event' => 'printed', 'paper' => 'A4', 'preprinted' => false]);
    }

    public function test_query_parameters_override_the_pad_per_print(): void
    {
        [$rx] = $this->issuedWithContent(['paper_size' => 'A4']);

        $html = $this->get('/panel/prescriptions/'.$rx->public_id.'/print?paper=A5&orientation=landscape&letterhead=0&lang=en')->assertOk()->getContent();

        $this->assertStringContainsString('@page { size: A5 landscape;', $html);
        $this->assertStringNotContainsString('class="letterhead"', $html);
        $this->assertStringContainsString('5 days', $html);                     // en interpretation
        $this->assertStringNotContainsString('৫ দিন', $html);                    // bn suppressed by lang=en
    }

    public function test_preprinted_mode_reserves_the_header_band_and_prints_nothing_in_it(): void
    {
        [$rx] = $this->issuedWithContent(['preprinted_mode' => true, 'letterhead_enabled' => false, 'header_height_mm' => 42, 'paper_size' => 'A4']);

        $html = $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent();

        $this->assertStringContainsString('.header-spacer { height: 42mm; }', $html);
        $this->assertStringContainsString('data-preprinted-header="42mm"', $html);
        // The doctor's own letterhead is already on the paper: nothing of ours may land in that band.
        $this->assertStringNotContainsString('class="letterhead"', $html);
        $this->assertStringNotContainsString('letterhead-html', $html);
        $this->assertMatchesRegularExpression('~<div class="header-spacer"[^>]*></div>~', $html);
        $this->assertAudited(AuditAction::Print, $rx, ['event' => 'printed', 'preprinted' => true]);
    }

    public function test_pharmacy_view_shows_drugs_and_quantity_but_no_clinical_detail(): void
    {
        [$rx] = $this->issuedWithContent();

        $html = $this->get('/panel/prescriptions/'.$rx->public_id.'/pharmacy')->assertOk()->getContent();

        $this->assertStringContainsString('Napa', $html);
        $this->assertStringContainsString('10 tab', $html);
        $this->assertStringContainsString('@page { size: A5 portrait;', $html);            // §7.3 A5 by default
        $this->assertStringNotContainsString('Acute upper respiratory infection', $html);  // no diagnosis
        $this->assertStringNotContainsString('<div class="section" data-section="vitals"', $html);   // no vitals
        $this->assertStringNotContainsString('Drink plenty of water', $html);              // no advice
        $this->assertStringContainsString('data-section="pharmacy-items"', $html);
        $this->assertAudited(AuditAction::Export, $rx, ['event' => 'exported', 'layout' => 'pharmacy']);
    }

    public function test_language_variants_render_the_frozen_display_strings(): void
    {
        [$rx] = $this->issuedWithContent();

        $both = $this->get('/panel/prescriptions/'.$rx->public_id.'/print?lang=both')->assertOk()->getContent();
        $this->assertStringContainsString('৫ দিন', $both);
        $this->assertStringContainsString('5 days', $both);

        $bn = $this->get('/panel/prescriptions/'.$rx->public_id.'/print?lang=bn')->assertOk()->getContent();
        $this->assertStringContainsString('৫ দিন', $bn);
        $this->assertStringNotContainsString('5 days', $bn);
        $this->assertStringContainsString('Napa', $bn);                          // drug names are never translated
    }

    public function test_a_draft_prints_a_transient_preview_with_a_draft_watermark(): void
    {
        [, $doctor, $visit] = $this->doctorWithOpenVisit();
        $draft = $this->draftFor($visit, $doctor);
        $this->savedDraft($draft, $this->itemsPayload([['slug' => 'paracetamol', 'shorthand' => '1+0+1 5d af', 'label' => '500 mg']]));

        $html = $this->get('/panel/prescriptions/'.$draft->public_id.'/print?draft=1')->assertOk()->getContent();

        $this->assertStringContainsString('class="watermark en" aria-hidden="true">DRAFT', $html);
        $this->assertStringContainsString('Napa', $html);
        // No scannable QR and no verification URL: nothing on a preview may look like a verifiable prescription.
        $this->assertStringNotContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringNotContainsString('/rx/DRAFT', $html);
        $this->assertNotAudited(AuditAction::Print, $draft);                     // a preview is not a print
        $this->assertSame(0, $draft->fresh()->printed_count);
    }

    public function test_an_amended_version_still_prints_forever_with_a_copy_watermark_and_its_own_chain_line(): void
    {
        [$rx, $doctor] = $this->issuedWithContent();
        $amended = app(AmendPrescription::class)->handle($rx, 'Wrong strength', Actor::user((int) auth('web')->id()));
        $this->issued($amended->fresh());
        unset($doctor);

        $html = $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent();

        $this->assertSame('amended', $rx->fresh()->status->value);
        $this->assertStringContainsString('>COPY', $html);
        $this->assertStringContainsString('Napa', $html);
    }

    public function test_the_qr_follows_the_pad_flag_but_the_code_and_hash_always_print(): void
    {
        [$withQr] = $this->issuedWithContent(['show_qr' => true]);
        $html = $this->get('/panel/prescriptions/'.$withQr->public_id.'/print')->assertOk()->getContent();
        $this->assertStringContainsString('src="data:image/svg+xml;base64,', $html);       // frozen at issue, no library call
        $this->assertStringContainsString((string) $withQr->snapshot?->get('prescription.verify_url'), $html);
        $this->assertStringContainsString(substr((string) $withQr->snapshot_sha256, 0, 8), $html);

        [$withoutQr] = $this->issuedWithContent(['show_qr' => false]);
        $plain = $this->get('/panel/prescriptions/'.$withoutQr->public_id.'/print')->assertOk()->getContent();
        $this->assertStringNotContainsString('data:image/svg+xml;base64,', $plain);
        // A pharmacy that cannot scan still has to be able to phone the clinic and quote something.
        $this->assertStringContainsString((string) $withoutQr->verification_code, $plain);
        $this->assertStringContainsString(substr((string) $withoutQr->snapshot_sha256, 0, 8), $plain);
    }

    public function test_the_pdf_endpoint_answers_202_while_the_render_is_queued_and_regenerate_asks_for_a_new_one(): void
    {
        Queue::fake();
        [$rx] = $this->issuedWithContent();

        $this->getJson('/panel/prescriptions/'.$rx->public_id.'/pdf')->assertStatus(202)->assertJsonPath('status', 'pending');
        Queue::assertPushed(GeneratePrescriptionPdf::class, fn (GeneratePrescriptionPdf $job) => $job->prescriptionId === $rx->id && $job->force === false);

        $this->postJson('/panel/prescriptions/'.$rx->public_id.'/pdf/regenerate')->assertStatus(202)->assertJsonPath('status', 'pending');
        Queue::assertPushed(GeneratePrescriptionPdf::class, fn (GeneratePrescriptionPdf $job) => $job->force === true);

        // A draft has no frozen document to render, so there is nothing to download.
        [, $doctor, $visit] = $this->doctorWithOpenVisit();
        $draft = $this->draftFor($visit, $doctor);
        $this->getJson('/panel/prescriptions/'.$draft->public_id.'/pdf')->assertNotFound();
    }

    public function test_another_clinics_staff_cannot_print_and_a_guest_is_redirected(): void
    {
        [$rx] = $this->issuedWithContent();

        $this->app['auth']->forgetGuards();
        $this->post('/panel/logout');
        $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertRedirect();

        $this->actingAsStaff(Role::Accountant);
        $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertForbidden();
        $this->get('/panel/prescriptions/'.$rx->public_id.'/pharmacy')->assertForbidden();
    }

    public function test_pad_layout_flags_hide_icd_codes_prices_and_generic_names(): void
    {
        [$rx] = $this->issuedWithContent(['layout' => DoctorPadSetting::defaults()['layout']]);

        $withFlags = $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent();
        $this->assertStringContainsString('Paracetamol', $withFlags);            // generic under the brand

        // The pad copy is frozen at issue, so flipping the live row must NOT change an issued print.
        $rx->doctor->padSetting()->update(['layout' => ['sections' => [], 'columns' => 1, 'rx_font_size_pt' => null, 'flags' => ['icd_codes' => false, 'investigation_prices' => false, 'generic_names' => false]]]);
        $afterChange = $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent();
        $this->assertStringContainsString('Paracetamol', $afterChange);
    }
}
