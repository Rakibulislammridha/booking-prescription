<?php

declare(strict_types=1);

namespace App\Domain\Patients\Contracts;

use App\Domain\Patients\Exceptions\OcrEngineUnavailable;
use App\Domain\Patients\Exceptions\OcrFailed;

/**
 * Reads text out of a document the local text-layer path cannot — a photographed report (PRESCRIPTION.md §8).
 * It is separate from DocumentNamer because the naming policy is ours and the recognition is a vendor's: with
 * nothing configured the null engine is bound and uploads keep being named by type + upload date, exactly as
 * they are today, and no request ever leaves the clinic.
 */
interface OcrEngine
{
    /** False until a tenant or the platform actually configured a provider — the unconfigured default. */
    public function isConfigured(): bool;

    /** Whether this engine can read that content type at all (Vision's images:annotate takes images, not PDFs). */
    public function supports(string $mimeType): bool;

    /**
     * The document's text, or null when the engine read nothing out of it.
     *
     * @param  string  $bytes  the raw file
     *
     * @throws OcrFailed the provider refused the document — permanent, the caller marks it `failed`
     * @throws OcrEngineUnavailable the provider could not be reached — transient, the caller retries
     */
    public function text(string $bytes, string $mimeType): ?string;
}
