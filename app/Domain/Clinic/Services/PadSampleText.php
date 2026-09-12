<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Services;

use App\Domain\Patients\Exceptions\OcrEngineUnavailable;
use App\Domain\Patients\Exceptions\OcrFailed;
use App\Domain\Patients\Services\OcrEngineResolver;
use App\Domain\Patients\Services\PdfTextLayerExtractor;
use Illuminate\Support\Facades\Storage;

/**
 * "Read text from the sample" in the pad designer (BRIEF §5.A) — and nothing more than that.
 *
 * The uploaded pad photo is a TRACING GUIDE. This class does the one automatic thing that is honest: it pulls the
 * text out, offers the lines, and lets the doctor accept the ones they want. It does not attempt to read a
 * design — colours, positions and sizes are the doctor's to set, because guessing them and being wrong is worse
 * than not guessing.
 *
 * It reuses the Patients module's OCR seam (PRESCRIPTION.md §8) rather than growing a second one, which also
 * means it inherits that seam's honesty: with no cloud driver configured the engine is the null engine, this
 * class reports `not_configured`, and the designer disables the button instead of offering a dead one. A PDF
 * sample needs no driver at all — `pdftotext` reads a pad exported from the printer's own artwork offline.
 */
final class PadSampleText
{
    /** Nobody transcribes a 40-line pad; past this the list stops being a prefill and starts being a wall. */
    private const MAX_LINES = 20;

    public function __construct(
        private readonly OcrEngineResolver $engines,
        private readonly PdfTextLayerExtractor $pdf,
    ) {}

    /** Whether the button can do anything at all for THIS sample, on THIS tenant's configuration. */
    public function isAvailable(?string $path): bool
    {
        return $this->reason($path) === null;
    }

    /** Why not, in a key the designer turns into one honest sentence: null when the action is live. */
    public function reason(?string $path): ?string
    {
        if ($path === null) {
            return 'no_sample';
        }

        if ($this->isPdf($path)) {
            return $this->pdf->isAvailable() ? null : 'no_pdf_reader';
        }

        $engine = $this->engines->resolve();

        return $engine->isConfigured() && $engine->supports($this->mimeType($path)) ? null : 'not_configured';
    }

    /**
     * The sample's text as candidate lines, longest-lasting first — i.e. in the order they appear on the pad, so
     * accepting them top to bottom rebuilds the header in the right order.
     *
     * @return list<string>
     *
     * @throws OcrFailed the provider refused the image
     * @throws OcrEngineUnavailable the provider could not be reached
     */
    public function lines(string $disk, string $path): array
    {
        $text = $this->isPdf($path)
            ? $this->pdf->extract($disk, $path)
            : $this->readWithEngine($disk, $path);

        if (! is_string($text) || trim($text) === '') {
            return [];
        }

        $lines = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $clean = trim((string) preg_replace('/\s+/u', ' ', $line));

            // One character is a stray mark, not a line; duplicates are the same line read twice off a fold.
            if (mb_strlen($clean) < 2 || in_array($clean, $lines, true)) {
                continue;
            }

            $lines[] = mb_substr($clean, 0, 160);

            if (count($lines) >= self::MAX_LINES) {
                break;
            }
        }

        return $lines;
    }

    private function readWithEngine(string $disk, string $path): ?string
    {
        $bytes = Storage::disk($disk)->get($path);

        return is_string($bytes) ? $this->engines->resolve()->text($bytes, $this->mimeType($path)) : null;
    }

    private function isPdf(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
    }

    private function mimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'image/jpeg',
        };
    }
}
