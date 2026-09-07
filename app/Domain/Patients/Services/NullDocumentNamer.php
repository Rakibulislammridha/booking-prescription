<?php

declare(strict_types=1);

namespace App\Domain\Patients\Services;

use App\Domain\Patients\Contracts\DocumentNamer;
use App\Domain\Patients\Data\DocumentNaming;
use App\Domain\Patients\Enums\OcrStatus;
use App\Models\Tenant\PatientDocument;
use App\Support\Clock;

/** No OCR: "{Type} – {upload date}" and ocr_status = skipped (PRESCRIPTION.md §8). */
final class NullDocumentNamer implements DocumentNamer
{
    public function suggest(PatientDocument $document): DocumentNaming
    {
        $label = __('patients.documents.types.'.$document->type->value);
        $date = $document->created_at->setTimezone(Clock::timezone())->format('d M Y');

        return new DocumentNaming(
            title: "{$label} – {$date}",
            documentDate: $document->document_date,
            ocrText: null,
            ocrStatus: OcrStatus::Skipped,
        );
    }
}
