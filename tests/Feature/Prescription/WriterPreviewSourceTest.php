<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Audit\Enums\AuditAction;
use App\Models\Tenant\AdviceSnippet;
use App\Models\Tenant\InvestigationCatalogItem;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\Vital;
use Tests\TestCase;

/**
 * PRESCRIPTION.md §1.1 / §7.1 — the writer's live pad preview has NO template of its own. It loads
 * `panel.prescription.print` for the draft, which is the route "Issue & Print" opens and which renders the
 * transient DRAFT snapshot through PrescriptionRenderer and resources/views/print/prescription/**.
 *
 * That is the property these tests hold, because it is the one that can silently rot: the sheet the doctor watches
 * fill up while he writes must be the sheet that comes out of the printer. So the preview of a draft and the print
 * of the prescription issued FROM that draft are compared body-for-body — letterhead, patient bar, vitals,
 * clinical, Rx lines, advice, follow-up — and must be byte-identical. Fork the layout for the preview, render one
 * of them through a different partial, give the preview its own geometry, and this fails.
 *
 * The one place they may differ is deliberate and asserted here too: a preview must never look like a document.
 * It carries the DRAFT watermark, no verification code and no QR (partials/qr.blade.php).
 */
final class WriterPreviewSourceTest extends TestCase
{
    use PrescriptionTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_the_preview_of_a_draft_and_the_print_of_the_issued_sheet_are_the_same_markup(): void
    {
        $this->freezeTime();
        $draft = $this->populatedDraft();

        $preview = $this->get('/panel/prescriptions/'.$draft->public_id.'/print')->assertOk()->getContent();
        $print = $this->get('/panel/prescriptions/'.$this->issued($draft->fresh())->public_id.'/print')->assertOk()->getContent();

        $body = self::sheetBody((string) $preview);

        // The comparison is only worth anything if the thing compared is the whole sheet: check the body actually
        // carries what a prescription carries before asserting the two are equal.
        $this->assertStringContainsString('Napa', $body);                        // the Rx line
        $this->assertStringContainsString('প্রচুর পানি পান করুন', $body);          // Bangla advice
        $this->assertStringContainsString('Rehana Begum', $body);                // the patient bar
        $this->assertStringContainsString('120/80', $body);                      // vitals
        $this->assertSame($body, self::sheetBody((string) $print), 'the preview and the print must render the same sheet body');
    }

    public function test_the_preview_is_watermarked_draft_and_carries_no_verification_code_or_qr(): void
    {
        $draft = $this->populatedDraft();

        $html = (string) $this->get('/panel/prescriptions/'.$draft->public_id.'/print')->assertOk()->getContent();

        $this->assertStringContainsString('class="watermark en"', $html);
        $this->assertStringContainsString('>DRAFT<', $html);
        $this->assertStringNotContainsString('data:image/svg+xml', $html);       // the QR is withheld until issue
        $this->assertStringNotContainsString('/rx/DRAFT', $html);
    }

    public function test_the_preview_is_authorised_by_the_same_policy_as_the_print_and_is_never_cached(): void
    {
        $draft = $this->populatedDraft();

        $this->get('/panel/prescriptions/'.$draft->public_id.'/print')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')                 // never reusable for another doctor
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        // Same route, same PrescriptionPolicy: another doctor cannot preview the draft he is not writing.
        $this->actingAsDoctor();
        $this->get('/panel/prescriptions/'.$draft->public_id.'/print')->assertForbidden();
    }

    public function test_previewing_while_writing_is_not_a_print(): void
    {
        $draft = $this->populatedDraft();

        $this->get('/panel/prescriptions/'.$draft->public_id.'/print')->assertOk();
        $this->get('/panel/prescriptions/'.$draft->public_id.'/print')->assertOk();

        $this->assertSame(0, $draft->fresh()->printed_count);
        $this->assertNotAudited(AuditAction::Print, $draft);
    }

    /** The writer's draft after a normal save: vitals, a diagnosis, one Rx line, an investigation, advice, follow-up. */
    private function populatedDraft(): Prescription
    {
        [, $doctor, $visit] = $this->doctorWithOpenVisit(['name' => 'Rehana Begum']);
        Vital::factory()->for($visit)->create(['weight_kg' => 58, 'height_cm' => 160, 'bp_systolic' => 120, 'bp_diastolic' => 80]);
        $snippet = AdviceSnippet::factory()->create(['text' => 'Drink plenty of water', 'text_bn' => 'প্রচুর পানি পান করুন']);
        $test = InvestigationCatalogItem::factory()->create(['name' => 'CBC with ESR #'.$visit->id, 'price_paisa' => 40000]);
        $draft = $this->draftFor($visit, $doctor);

        $this->patchJson('/panel/prescriptions/'.$draft->public_id.'/draft', [
            'language' => 'both',
            'items' => $this->itemsPayload([['slug' => 'paracetamol', 'shorthand' => '1+0+1 5d af', 'label' => '500 mg']]),
            'investigations' => [['key' => 'x1', 'investigation_catalog_id' => $test->id, 'is_urgent' => false, 'sort_order' => 0]],
            'advice' => [['key' => 'a1', 'advice_snippet_id' => $snippet->id, 'sort_order' => 0]],
            'referrals' => [],
            'follow_up_days' => 7,
        ])->assertOk();

        return $draft->fresh();
    }

    /** Everything between `.sheet-body` and `.sheet-foot` — the part of the page that IS the prescription. */
    private static function sheetBody(string $html): string
    {
        $start = strpos($html, '<div class="sheet-body">');
        $end = strpos($html, '<div class="sheet-foot">');
        self::assertIsInt($start, 'the sheet body is missing from the rendered page');
        self::assertIsInt($end, 'the sheet foot is missing from the rendered page');

        return trim((string) preg_replace('~\s+~', ' ', substr($html, $start, $end - $start)));
    }
}
