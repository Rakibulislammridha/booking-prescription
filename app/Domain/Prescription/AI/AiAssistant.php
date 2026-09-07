<?php

declare(strict_types=1);

namespace App\Domain\Prescription\AI;

/** Advisory only, never auto-inserted (PRESCRIPTION.md §5.7). */
interface AiAssistant
{
    public function isAvailable(): bool;

    /** Exactly 3 lines, ≤ 140 chars each. */
    public function summariseVisits(VisitSummaryRequest $r): AiResult;

    /** ≤ 5 {label, icd10_code|null, rationale}. */
    public function suggestDifferentials(DifferentialRequest $r): AiResult;
}
