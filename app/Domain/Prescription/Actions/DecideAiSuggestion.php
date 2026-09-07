<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Shared\Actor;
use App\Models\Tenant\AiSuggestion;

/** PATCH /panel/ai-suggestions/{id} {accepted, accepted_fragment?} — nothing is inserted by the AI itself. */
final class DecideAiSuggestion
{
    public function __construct(private readonly PrescriptionAuditor $auditor) {}

    public function handle(AiSuggestion $suggestion, bool $accepted, ?string $fragment, Actor $actor): AiSuggestion
    {
        $suggestion->forceFill(['accepted' => $accepted, 'accepted_at' => now(), 'accepted_fragment' => $accepted ? $fragment : null])->save();
        $this->auditor->aiDecided($suggestion, $accepted);

        return $suggestion;
    }
}
