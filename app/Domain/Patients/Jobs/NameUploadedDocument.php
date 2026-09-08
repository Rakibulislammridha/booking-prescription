<?php

declare(strict_types=1);

namespace App\Domain\Patients\Jobs;

use App\Domain\Patients\Contracts\DocumentNamer;
use App\Domain\Patients\Enums\OcrStatus;
use App\Domain\Patients\Services\NullDocumentNamer;
use App\Models\Tenant\PatientDocument;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs the DocumentNamer after upload (PRESCRIPTION.md §8): fills ocr_text / ocr_status and, unless the uploader
 * typed a title, the suggested title and document date. Idempotent: a document already `done`/`skipped` is left alone.
 */
final class NameUploadedDocument implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $documentId, public readonly bool $keepTitle = false)
    {
        $this->onQueue('default');

        if (Tenancy::check()) {
            $this->forTenant((int) Tenancy::id());
        }
    }

    public function handle(DocumentNamer $namer): void
    {
        $document = PatientDocument::query()->find($this->documentId);

        if ($document === null || $document->ocr_status->value !== 'pending') {
            return;
        }

        $naming = $namer->suggest($document);

        $document->forceFill(array_filter([
            'title' => $this->keepTitle ? null : $naming->title,
            'document_date' => $document->document_date ?? $naming->documentDate,
            'ocr_text' => $naming->ocrText,
            'ocr_status' => $naming->ocrStatus,
        ], fn ($v) => $v !== null))->save();
    }

    /**
     * The last attempt is spent (an OCR provider that stayed unreachable through all three). The document must not
     * be left `pending` forever with the placeholder title the upload wrote: settle it on `failed` and give it the
     * name it would have had with no OCR at all. The file itself was never at risk — only its name.
     */
    public function failed(?Throwable $e): void
    {
        if (! Tenancy::check()) {
            return;
        }

        $document = PatientDocument::query()->find($this->documentId);

        if ($document === null || $document->ocr_status !== OcrStatus::Pending) {
            return;
        }

        $naming = app(NullDocumentNamer::class)->suggest($document);

        $document->forceFill(array_filter([
            'title' => $this->keepTitle ? null : $naming->title,
            'document_date' => $document->document_date ?? $naming->documentDate,
            'ocr_status' => OcrStatus::Failed,
        ], fn ($v) => $v !== null))->save();
    }
}
