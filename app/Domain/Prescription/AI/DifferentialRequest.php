<?php

declare(strict_types=1);

namespace App\Domain\Prescription\AI;

/** De-identified input for the differential suggestion (current draft values). */
final readonly class DifferentialRequest
{
    /**
     * @param  list<array<string, mixed>>  $chiefComplaints
     * @param  array<string, mixed>|null  $vitals
     */
    public function __construct(public ?int $ageYears, public ?string $sex, public array $chiefComplaints, public ?string $examinationFindings, public ?array $vitals, public string $language = 'en') {}
}
