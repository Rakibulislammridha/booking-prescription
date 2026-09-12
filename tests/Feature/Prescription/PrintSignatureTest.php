<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\Visit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BRIEF §5.A / PRESCRIPTION.md §7.1 — the doctor's signature on the printed sheet.
 *
 * The upload itself is covered by PadDesignerTest; what is pinned here is everything after it. A signature is
 * useless unless it (1) reaches the paper as bytes rather than as a URL — the pharmacy that prints the sheet has
 * no session on this panel and could never fetch one — (2) lands in the signature block above the doctor's name
 * rather than anywhere else on the page, and (3) is frozen at issue like the rest of the document, so removing it
 * from the live pad cannot alter a prescription already signed.
 */
final class PrintSignatureTest extends TestCase
{
    use PrescriptionTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        Storage::fake('uploads');
    }

    public function test_a_pad_with_no_signature_prints_the_blank_space_to_sign_in(): void
    {
        [$rx] = $this->issuedWithContent();

        $html = $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('<div class="sign-space"></div>', $html);
        $this->assertStringNotContainsString('<img class="sign-img"', $html);
    }

    public function test_an_uploaded_signature_prints_above_the_doctors_name_and_is_frozen_at_issue(): void
    {
        [$first, $doctor] = $this->issuedWithContent();

        $this->post('/panel/clinic/doctors/'.$doctor->public_id.'/pad/asset', [
            'kind' => 'signature',
            'file' => UploadedFile::fake()->image('sign.png', 300, 100),
        ])->assertRedirect();

        $this->assertStringStartsWith('tenants/', (string) $doctor->padSetting()->firstOrFail()->signature_path);

        $rx = $this->issuedFor($doctor);
        $html = $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent();
        $this->assertIsString($html);

        // Inlined, not linked: a print shop's browser holds no panel session and would render a broken image.
        $this->assertStringContainsString('<img class="sign-img" src="data:image/png;base64,', $html);
        $this->assertStringNotContainsString('<div class="sign-space"></div>', $html);
        $this->assertStringNotContainsString('/pad/asset/signature', $html);

        // Placement: inside the signature block, above the name line — not floating in the header or the band.
        $block = (int) strpos($html, '<div class="sign-block">');
        $image = (int) strpos($html, '<img class="sign-img"');
        $name = (int) strpos($html, '<div class="sign-line">');
        $this->assertTrue($block < $image && $image < $name, 'the signature must render between the block open and the name line');

        // Frozen (§7.5): clearing the live pad changes nothing about a prescription already issued…
        $this->delete('/panel/clinic/doctors/'.$doctor->public_id.'/pad/asset', ['kind' => 'signature'])->assertRedirect();
        $this->assertNull($doctor->padSetting()->firstOrFail()->signature_path);
        $this->assertStringContainsString('<img class="sign-img" src="data:image/png;base64,', (string) $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent());

        // …and the one issued before the upload never gains a signature it was not signed with.
        $this->assertStringContainsString('<div class="sign-space"></div>', (string) $this->get('/panel/prescriptions/'.$first->public_id.'/print')->assertOk()->getContent());
    }

    /** A second issued prescription for the same logged-in doctor, so an upload can sit between two issues. */
    private function issuedFor(Doctor $doctor): Prescription
    {
        $patient = Patient::factory()->create(['name' => 'Karim Uddin', 'dob' => now()->subYears(41)->toDateString()]);
        $visit = Visit::factory()->urti()->create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id]);
        $draft = $this->savedDraft(
            $this->draftFor($visit, $doctor),
            $this->itemsPayload([['slug' => 'paracetamol', 'shorthand' => '1+0+1 5d af', 'label' => '500 mg']]),
        );

        return $this->issued($draft);
    }
}
