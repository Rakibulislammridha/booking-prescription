<?php

declare(strict_types=1);

namespace App\Domain\Patients\Services;

use App\Domain\Patients\Exceptions\OcrFailed;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The one text path that needs no configuration and no network (PRESCRIPTION.md §8): most reports a Bangladeshi
 * lab emails or a clinic re-uploads are PDFs printed from the lab's own software, so the text is already IN the
 * file and `pdftotext -layout` reads it in milliseconds. Only a photograph — or a scanner's image-only PDF —
 * needs a cloud engine.
 *
 * `-f 1 -l 3` because the heading and the date are on the first page; the rest is numbers we do not name from,
 * and a 60-page discharge summary should not be held in memory. The file is streamed from its disk to a temp
 * file (poppler wants a seekable path, and the disk may be S3) and deleted in a `finally`.
 *
 * Exit code 1 is poppler's "this is not a PDF I can open", which is an OUTCOME, not a failure: an image-only PDF
 * exits 0 with empty output and a damaged one exits 1, and both simply mean "no text layer — try the engine".
 * Anything worse (a missing shared library, a signal, a timeout) is a real failure and marks the document.
 */
final class PdfTextLayerExtractor
{
    public function __construct(
        private readonly string $binary = '/usr/bin/pdftotext',
        private readonly float $timeout = 10.0,
    ) {}

    /** Whether poppler is actually on this host — a caller that offers "read the text" must not offer a no-op. */
    public function isAvailable(): bool
    {
        return $this->binary !== '' && is_executable($this->binary);
    }

    /** The PDF's text layer, or null when there is none (or no poppler on this host). */
    public function extract(string $disk, string $path): ?string
    {
        if ($this->binary === '' || ! is_executable($this->binary)) {
            return null;
        }

        $temp = tempnam(sys_get_temp_dir(), 'ocr-pdf-');

        if ($temp === false) {
            return null;
        }

        try {
            if (! $this->copyToTemp($disk, $path, $temp)) {
                return null;
            }

            $process = new Process([$this->binary, '-layout', '-q', '-f', '1', '-l', '3', $temp, '-'], null, null, null, $this->timeout);
            $process->run();

            if ($process->getExitCode() === 1) {
                return null;                                          // not a PDF poppler can open — fall through to the engine
            }

            if (! $process->isSuccessful()) {
                throw new OcrFailed('pdftotext exited '.($process->getExitCode() ?? -1));
            }

            $text = $process->getOutput();

            return trim($text) === '' ? null : $text;
        } catch (ProcessTimedOutException) {
            throw new OcrFailed('pdftotext timed out after '.$this->timeout.'s');
        } finally {
            is_file($temp) && unlink($temp);
        }
    }

    private function copyToTemp(string $disk, string $path, string $temp): bool
    {
        $source = Storage::disk($disk)->readStream($path);

        if (! is_resource($source)) {
            return false;                                             // the object is gone: nothing to read, name by type
        }

        $target = fopen($temp, 'wb');

        if ($target === false) {
            fclose($source);

            return false;
        }

        stream_copy_to_stream($source, $target);
        fclose($target);
        fclose($source);

        return true;
    }
}
