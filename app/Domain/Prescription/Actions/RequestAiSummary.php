<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\AI\AiAssistant;
use App\Domain\Prescription\AI\AiGate;
use App\Domain\Prescription\AI\DeidentifiedContextBuilder;
use App\Domain\Prescription\Enums\AiSuggestionType;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Shared\Actor;
use App\Models\Tenant\AiSuggestion;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Visit;

/** POST /panel/visits/{visit}/ai/summary — every call is logged in ai_suggestions (prompt/response ENC). Null when unavailable. */
final class RequestAiSummary
{
    public function __construct(private readonly AiAssistant $assistant, private readonly AiGate $gate, private readonly DeidentifiedContextBuilder $contexts, private readonly PrescriptionAuditor $auditor) {}

    public function handle(Visit $visit, Doctor $doctor, Actor $actor): ?AiSuggestion
    {
        if (! $this->gate->enabled()) {
            return null;
        }

        $result = $this->assistant->summariseVisits($this->contexts->summary($visit));

        if (! $result->available) {
            return null;
        }

        $suggestion = new AiSuggestion([
            'visit_id' => $visit->id, 'doctor_id' => $doctor->id, 'patient_id' => $visit->patient_id, 'type' => AiSuggestionType::HistorySummary,
            'provider' => $result->provider, 'model' => $result->model, 'prompt' => $result->prompt, 'response' => json_encode($result->payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens, 'latency_ms' => $result->latencyMs, 'request_id' => $result->requestId,
        ]);
        $suggestion->save();
        $this->auditor->aiSuggested($suggestion);

        return $suggestion;
    }
}
