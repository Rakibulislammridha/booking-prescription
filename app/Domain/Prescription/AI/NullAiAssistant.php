<?php

declare(strict_types=1);

namespace App\Domain\Prescription\AI;

/** Bound unless services.ai.key is set and the `ai-assist` feature is on — the product works with no AI key. */
final class NullAiAssistant implements AiAssistant
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function summariseVisits(VisitSummaryRequest $r): AiResult
    {
        return AiResult::unavailable();
    }

    public function suggestDifferentials(DifferentialRequest $r): AiResult
    {
        return AiResult::unavailable();
    }
}
