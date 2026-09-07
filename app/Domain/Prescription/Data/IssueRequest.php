<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

/** POST …/issue body (PRESCRIPTION.md §6.1). */
final readonly class IssueRequest
{
    /** @param  list<string>  $acknowledgedWarnings  fingerprints the doctor ticked in the Issue dialog */
    public function __construct(
        public ?string $language = null,
        public bool $print = false,
        public array $acknowledgedWarnings = [],
        public ?string $expectedUpdatedAt = null,
        public bool $addToMedicationList = false,
    ) {}
}
