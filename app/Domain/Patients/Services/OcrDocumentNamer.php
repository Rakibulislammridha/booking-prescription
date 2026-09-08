<?php

declare(strict_types=1);

namespace App\Domain\Patients\Services;

use App\Domain\Patients\Contracts\DocumentNamer;
use App\Domain\Patients\Contracts\OcrEngine;
use App\Domain\Patients\Data\DocumentNaming;
use App\Domain\Patients\Enums\OcrStatus;
use App\Domain\Patients\Exceptions\OcrFailed;
use App\Models\Tenant\PatientDocument;
use App\Support\Clock;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The real DocumentNamer of PRESCRIPTION.md §8: extract text, derive a title, record the OCR outcome. It is the
 * default binding, and it is deliberately built so that a clinic which has configured NOTHING keeps exactly
 * today's behaviour — "{type} – {upload date}" and `ocr_status = skipped` — while a PDF that already carries a
 * text layer (what a lab's own software prints) is named properly with no configuration, no cost and no request
 * leaving the building. Only a photographed report needs the cloud engine, and only if a clinic turns it on.
 *
 * The four outcomes, in the order they are decided:
 *   text found            → `done`, the title from DocumentTitleDeriver, the text on the row (encrypted column)
 *   nothing readable      → `skipped`, NullDocumentNamer's title — the unconfigured default
 *   permanently refused   → `failed`, NullDocumentNamer's title; the document and its file are never lost
 *   provider unreachable  → rethrown, so NameUploadedDocument's $tries/$backoff retry it later
 *
 * The title is never left empty and the uploader's own title is never touched here — the job decides that.
 */
final class OcrDocumentNamer implements DocumentNamer
{
    public function __construct(
        private readonly NullDocumentNamer $fallback,
        private readonly PdfTextLayerExtractor $textLayer,
        private readonly OcrEngine $engine,
        private readonly DocumentTitleDeriver $deriver,
    ) {}

    public function suggest(PatientDocument $document): DocumentNaming
    {
        $fallback = $this->fallback->suggest($document);

        try {
            $text = $this->extract($document);
        } catch (OcrFailed $e) {
            // OcrEngineUnavailable is deliberately NOT caught: a transient failure is the queue's business.
            Log::warning('patients.ocr.failed', ['document_id' => $document->id, 'mime_type' => $document->mime_type, 'reason' => $e->getMessage()]);

            return new DocumentNaming($fallback->title, $fallback->documentDate, null, OcrStatus::Failed);
        }

        if ($text === null) {
            return $fallback;                                          // skipped: nothing could read this document
        }

        $derived = $this->deriver->derive(
            text: $text,
            fallbackTitle: $fallback->title,
            uploadedOn: $document->created_at->setTimezone(Clock::timezone()),
            now: Clock::now(),
        );

        return new DocumentNaming(
            title: $derived->title,
            documentDate: $derived->documentDate ?? $fallback->documentDate,
            ocrText: $derived->ocrText,
            ocrStatus: OcrStatus::Done,
        );
    }

    /** Text layer first (local, free, exact), engine second (configured clinics only), null when neither can read it. */
    private function extract(PatientDocument $document): ?string
    {
        $usable = $this->engine->isConfigured() && $this->engine->supports($document->mime_type);

        if ($document->mime_type === 'application/pdf') {
            try {
                $text = $this->textLayer->extract($document->storage_disk, $document->storage_path);

                if ($text !== null) {
                    return $text;
                }
            } catch (OcrFailed $e) {
                if (! $usable) {
                    throw $e;
                }

                Log::warning('patients.ocr.text_layer_failed', ['document_id' => $document->id, 'reason' => $e->getMessage()]);
            }
        }

        if (! $usable) {
            return null;
        }

        $bytes = Storage::disk($document->storage_disk)->get($document->storage_path);

        return $bytes === null || $bytes === '' ? null : $this->engine->text($bytes, $document->mime_type);
    }
}
