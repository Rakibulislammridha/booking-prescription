<?php

declare(strict_types=1);

namespace App\Domain\Patients\Contracts;

use App\Domain\Patients\Data\DocumentNaming;
use App\Models\Tenant\PatientDocument;

/**
 * OCR-based naming of uploaded reports (PRESCRIPTION.md §8). NullDocumentNamer names by type + upload date and
 * marks OCR `skipped`; a real OCR driver later fills ocr_text, sets `done` and a title like "CBC – 06 Sep 2026".
 */
interface DocumentNamer
{
    public function suggest(PatientDocument $document): DocumentNaming;
}
