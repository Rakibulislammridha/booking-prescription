<?php

declare(strict_types=1);

namespace App\Domain\Prescription\AI;

/** De-identified input for the 3-line history summary: age, sex, dates relative to today, dx, items, vitals — never name/phone/id. */
final readonly class VisitSummaryRequest
{
    /** @param  list<array<string, mixed>>  $visits  up to 5, newest first */
    public function __construct(public ?int $ageYears, public ?string $sex, public array $visits, public string $language = 'en') {}
}
