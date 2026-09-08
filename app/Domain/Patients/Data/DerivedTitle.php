<?php

declare(strict_types=1);

namespace App\Domain\Patients\Data;

use Carbon\CarbonImmutable;

/** What DocumentTitleDeriver read out of a page of OCR text: the title to show, the date on the report, the text to store. */
final readonly class DerivedTitle
{
    public function __construct(
        public string $title,
        public ?CarbonImmutable $documentDate,
        public string $ocrText,
        public bool $matchedHeading,
    ) {}
}
