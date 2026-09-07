<?php

declare(strict_types=1);

namespace App\Domain\Patients\Data;

use App\Domain\Patients\Enums\OcrStatus;
use Carbon\CarbonImmutable;

/** What a DocumentNamer suggests for an uploaded report: `{title, document_date, ocr_text}` + the OCR outcome. */
final readonly class DocumentNaming
{
    public function __construct(
        public string $title,
        public ?CarbonImmutable $documentDate,
        public ?string $ocrText,
        public OcrStatus $ocrStatus,
    ) {}
}
