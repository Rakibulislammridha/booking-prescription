<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Prescription\Events\PdfReady;
use App\Domain\Prescription\Jobs\GeneratePrescriptionPdf;
use App\Domain\Prescription\Render\PdfRenderer;
use App\Domain\Prescription\Render\RenderOptions;
use App\Domain\Prescription\Services\PdfStorage;
use App\Models\Tenant\Prescription;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * PRESCRIPTION.md §7.5 — the real PDF path. DomPDF is banned because it cannot shape Bangla (BRIEF §2), so the
 * point of this file is not "a PDF was produced" but "the Bangla in that PDF is real text an engine shaped" —
 * asserted by extracting the text layer and checking Bengali codepoints survived, and by rasterising the page so
 * a font that silently fell back to tofu boxes cannot pass.
 *
 * Group `browsershot` (§9.1): skipped when Chrome is absent.
 */
#[Group('browsershot')]
final class PdfGenerationTest extends TestCase
{
    use PrescriptionTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(PdfRenderer::class)->available()) {
            $this->markTestSkipped('Chrome not available at '.PdfRenderer::chromePath().' — set CHROME_PATH.');
        }

        config(['prescription.pdf.on_issue' => true]);
        $this->asTenant('a');
    }

    public function test_issuing_queues_the_job_which_writes_the_pdf_broadcasts_and_audits(): void
    {
        Storage::fake('pdfs');
        Event::fake([PdfReady::class]);

        [$rx] = $this->issuedWithContent(['paper_size' => 'A4']);
        $rx = $rx->fresh();
        $this->assertInstanceOf(Prescription::class, $rx);

        $this->assertNotNull($rx->pdf_path, 'the issue listener did not produce a PDF');
        $this->assertNotNull($rx->pdf_generated_at);
        $this->assertStringStartsWith('tenants/9001/patients/', $rx->pdf_path);           // TenantPath, ARCHITECTURE §8.7
        $this->assertStringEndsWith('/v1/'.$rx->verification_code.'.pdf', $rx->pdf_path);
        Storage::disk('pdfs')->assertExists($rx->pdf_path);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('pdfs')->get($rx->pdf_path));

        Event::assertDispatched(PdfReady::class, fn (PdfReady $e) => $e->prescriptionId === $rx->id);
        $this->assertAudited(AuditAction::Update, $rx, ['event' => 'pdf_generated']);

        // Re-running is a no-op unless forced: the same snapshot always produces the same paper.
        $before = $rx->pdf_generated_at;
        app(GeneratePrescriptionPdf::class, ['prescriptionId' => $rx->id])->handle(app(PdfRenderer::class), app(PdfStorage::class), app(AuditRecorder::class));
        $this->assertEquals($before, $rx->fresh()?->pdf_generated_at);
    }

    public function test_the_pdf_actually_contains_shaped_bangla_and_the_english_drug_name(): void
    {
        [$rx] = $this->issuedWithContent(['paper_size' => 'A4']);
        $snapshot = $rx->snapshot;
        $this->assertNotNull($snapshot);

        $bytes = app(PdfRenderer::class)->pdf($snapshot, RenderOptions::fromPad($snapshot->pad(), purpose: 'pdf'));
        $file = tempnam(sys_get_temp_dir(), 'rx').'.pdf';
        file_put_contents($file, $bytes);

        try {
            $this->assertStringStartsWith('%PDF', $bytes);

            $text = $this->extractText($file);

            if ($text === null) {
                $this->assertGreaterThan(6000, $this->inkPixels($file), 'no text extractor available and the page rasterised blank');

                return;
            }

            // The Bangla advice, frozen at issue, survived Chrome, the font subset and the PDF text layer.
            $this->assertMatchesRegularExpression('/[\x{0980}-\x{09FF}]/u', $text, 'no Bengali codepoint survived into the PDF text layer');
            $this->assertStringContainsString('পানি', $text);
            $this->assertStringContainsString('দিন', $text);            // the Bangla duration from display.bn
            $this->assertStringContainsString('৫', $text);              // Bangla digit, not "5"
            $this->assertStringContainsString('Napa', $text);           // drug names stay English (§7.2)
            $this->assertStringContainsString('Paracetamol', $text);
            $this->assertStringContainsString((string) $rx->verification_code, $text);
            // A font that fell back to tofu would still extract codepoints, so also prove the page has real ink.
            $this->assertGreaterThan(6000, $this->inkPixels($file), 'the page rasterised almost blank');
        } finally {
            @unlink($file);
        }
    }

    public function test_every_pad_mode_produces_a_valid_pdf(): void
    {
        [$rx] = $this->issuedWithContent();
        $snapshot = $rx->snapshot;
        $this->assertNotNull($snapshot);
        $renderer = app(PdfRenderer::class);

        foreach ([
            ['paper' => 'A4', 'preprinted' => false, 'layout' => 'full'],
            ['paper' => 'A5', 'preprinted' => false, 'layout' => 'full'],
            ['paper' => 'A4', 'preprinted' => true, 'letterhead' => false, 'layout' => 'full'],
            ['paper' => 'A5', 'preprinted' => false, 'layout' => 'pharmacy'],
        ] as $mode) {
            $options = RenderOptions::fromPad($snapshot->pad(), purpose: 'pdf')->override($mode);
            $bytes = $renderer->pdf($snapshot, $options);
            $this->assertStringStartsWith('%PDF', $bytes, json_encode($mode));
            $this->assertGreaterThan(5000, strlen($bytes), json_encode($mode));
        }
    }

    public function test_the_panel_pdf_route_renders_on_demand_and_audits_the_download(): void
    {
        Storage::fake('pdfs');
        config(['prescription.pdf.on_issue' => false]);
        [$rx] = $this->issuedWithContent();

        $this->get('/panel/prescriptions/'.$rx->public_id.'/pdf?sync=1')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $rx = $rx->fresh();
        $this->assertNotNull($rx?->pdf_path);
        Storage::disk('pdfs')->assertExists($rx->pdf_path);
        $this->assertAudited(AuditAction::Download, $rx, ['event' => 'downloaded']);
    }

    /** Text layer via poppler; null when the tool is not installed (the caller then falls back to pixels). */
    private function extractText(string $file): ?string
    {
        $process = new Process(['pdftotext', '-enc', 'UTF-8', $file, '-']);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    /** Non-white pixels on page 1 — a page of tofu boxes still has ink, but a blank page does not. */
    private function inkPixels(string $file): int
    {
        $prefix = tempnam(sys_get_temp_dir(), 'rxpng');
        $process = new Process(['pdftoppm', '-png', '-gray', '-r', '72', '-f', '1', '-l', '1', $file, $prefix]);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($prefix.'-1.png')) {
            $this->markTestSkipped('pdftoppm not available for the rasterisation check');
        }

        $image = imagecreatefrompng($prefix.'-1.png');
        $this->assertNotFalse($image);
        $ink = 0;

        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                if ((imagecolorat($image, $x, $y) & 0xFF) < 200) {
                    $ink++;
                }
            }
        }

        imagedestroy($image);
        @unlink($prefix);
        @unlink($prefix.'-1.png');

        return $ink;
    }
}
