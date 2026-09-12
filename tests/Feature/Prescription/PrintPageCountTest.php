<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Prescription\Data\PrescriptionSnapshot;
use App\Domain\Prescription\Render\PdfRenderer;
use App\Domain\Prescription\Render\RenderOptions;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * "A one-page prescription must be one page."
 *
 * The sheet used to come out of the printer as two: everything on page one, and page two carrying nothing but the
 * signature line and the QR code. Three separate things caused it and all three are asserted here against the REAL
 * PDF, because this is not a property of the markup — it is a property of what Chrome does with the markup.
 *
 *   · the flex column claimed the printable height in exact ISO millimetres while Chrome lays the page box out from
 *     a rounded inch figure, so the sheet could overflow by a sub-pixel (PadGeometry::sheetMinHeightMm);
 *   · the footer's three blocks did not fit one row across a 118 mm A5 measure and wrapped, doubling its height;
 *   · the body carried four lines of drug URL, a duplicated weight and a four-line identity strip it did not need.
 *
 * Group `browsershot` (§9.1): skipped when Chrome is absent, like every other test that renders a real PDF.
 */
#[Group('browsershot')]
final class PrintPageCountTest extends TestCase
{
    use PrescriptionTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(PdfRenderer::class)->available()) {
            $this->markTestSkipped('Chrome not available at '.PdfRenderer::chromePath().' — set CHROME_PATH.');
        }

        $this->asTenant('a');
    }

    /**
     * The case the product owner photographed off the printer: two drugs, a full set of vitals and a follow-up on
     * the pad's own default A5. It came out as two pages, the second holding only the signature and the QR.
     */
    public function test_a_two_drug_prescription_is_one_page_on_the_pad_default_a5(): void
    {
        $rx = $this->twoDrugPrescription();

        $this->assertSame('A5', (string) $rx->snapshot?->get('pad.paper_size'));
        $this->assertSame(1, $this->pageCount($rx), 'a two-drug prescription spilled onto a second page');
    }

    /** The same two drugs with the whole clinical narrative on them — complaints, findings, a coded diagnosis. */
    public function test_a_full_clinical_sheet_with_two_drugs_is_one_page_on_a4(): void
    {
        $rx = $this->twoDrugPrescription(clinical: true);

        $this->assertSame(1, $this->pageCount($rx, ['paper' => 'A4']));
    }

    /**
     * The property underneath the bug: a prescription long enough to need two pages may have two, but the last one
     * must never be a lonely signature and QR. Asserted by rasterising it and looking for ink ABOVE the footer band
     * — on the broken sheet the whole top two thirds of page two were blank paper.
     */
    public function test_a_prescription_that_really_needs_two_pages_never_ends_on_a_footer_only_page(): void
    {
        // Sixteen Rx lines, grown from a real issued snapshot rather than issued for real: the renderer's only
        // input is the frozen document (I6), and sixteen genuine drugs would be a pharmacology exercise in picking
        // a combination the safety pipeline is willing to let through.
        $snapshot = $this->twoDrugPrescription()->snapshot;
        $this->assertNotNull($snapshot);

        $document = $snapshot->toArray();
        $items = [];

        for ($i = 0; $i < 8; $i++) {
            foreach ($snapshot->items() as $item) {
                $item['brand_name'] = ($item['brand_name'] ?? 'Drug').' '.($i + 1);
                $items[] = $item;
            }
        }

        $document['items'] = $items;
        $long = new PrescriptionSnapshot($document);
        $pages = $this->pageCount($long);

        $this->assertGreaterThan(1, $pages, 'sixteen Rx lines should not fit one A5 page — the fixture no longer tests anything');
        $this->assertGreaterThan(0, $this->inkAboveTheFooter($long, $pages), 'the last page carries nothing but the signature and QR block');
    }

    private function twoDrugPrescription(bool $clinical = false): Prescription
    {
        return $this->issuedFrom([
            ['slug' => 'esomeprazole', 'shorthand' => '1+0+0 30d bf', 'form' => 'cap'],
            ['slug' => 'paracetamol', 'shorthand' => '1+1+1 5d af', 'label' => '500 mg'],
        ], $clinical);
    }

    /** @param  list<array{slug: string, shorthand: string, form?: string, label?: string}>  $lines */
    private function issuedFrom(array $lines, bool $clinical = false): Prescription
    {
        $user = $this->actingAsDoctor();
        $doctor = $user->doctor()->firstOrFail();
        $patient = Patient::factory()->create(['name' => 'Rehana Begum', 'gender' => 'female', 'dob' => now()->subYears(48)->toDateString()]);
        $factory = Visit::factory();
        $visit = ($clinical ? $factory->urti() : $factory)->create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id]);

        Vital::factory()->for($visit)->create(['weight_kg' => 71, 'height_cm' => 155, 'bp_systolic' => 148, 'bp_diastolic' => 92, 'pulse_bpm' => 84, 'spo2_percent' => 97]);

        $draft = $this->draftFor($visit, $doctor);
        $this->savedDraft($draft, $this->itemsPayload($lines), ['follow_up_days' => 14, 'create_booking' => false]);

        return $this->issued($draft->fresh() ?? $draft);
    }

    /** @param  array<string, mixed>  $override */
    private function pageCount(Prescription|PrescriptionSnapshot $rx, array $override = []): int
    {
        $file = $this->pdf($rx, $override);

        try {
            $process = new Process(['pdfinfo', $file]);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->markTestSkipped('pdfinfo not available for the page-count check');
            }

            preg_match('/^Pages:\s+(\d+)$/m', $process->getOutput(), $matches);
            $this->assertArrayHasKey(1, $matches, 'pdfinfo reported no page count');

            return (int) $matches[1];
        } finally {
            @unlink($file);
        }
    }

    /** Non-white pixels in the top 55 % of the last page — the band the footer can never reach. */
    private function inkAboveTheFooter(Prescription|PrescriptionSnapshot $rx, int $page): int
    {
        $file = $this->pdf($rx);
        $prefix = tempnam(sys_get_temp_dir(), 'rxpage');

        try {
            $process = new Process(['pdftoppm', '-png', '-gray', '-r', '72', '-f', (string) $page, '-l', (string) $page, $file, $prefix]);
            $process->run();
            $rendered = $prefix.'-'.$page.'.png';

            if (! $process->isSuccessful() || ! is_file($rendered)) {
                $this->markTestSkipped('pdftoppm not available for the rasterisation check');
            }

            $image = imagecreatefrompng($rendered);
            $this->assertNotFalse($image);
            $height = (int) floor(imagesy($image) * 0.55);
            $ink = 0;

            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < imagesx($image); $x++) {
                    if ((imagecolorat($image, $x, $y) & 0xFF) < 200) {
                        $ink++;
                    }
                }
            }

            imagedestroy($image);
            @unlink($rendered);

            return $ink;
        } finally {
            @unlink($file);
            @unlink($prefix);
        }
    }

    /** @param  array<string, mixed>  $override */
    private function pdf(Prescription|PrescriptionSnapshot $rx, array $override = []): string
    {
        $snapshot = $rx instanceof PrescriptionSnapshot ? $rx : $rx->snapshot;
        $this->assertNotNull($snapshot);

        $options = RenderOptions::fromPad($snapshot->pad(), purpose: 'pdf')->override($override);
        $file = tempnam(sys_get_temp_dir(), 'rxpages').'.pdf';
        file_put_contents($file, app(PdfRenderer::class)->pdf($snapshot, $options));

        return $file;
    }
}
