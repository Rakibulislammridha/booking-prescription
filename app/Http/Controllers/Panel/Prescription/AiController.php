<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Prescription\Actions\DecideAiSuggestion;
use App\Domain\Prescription\Actions\RequestAiDifferentials;
use App\Domain\Prescription\Actions\RequestAiSummary;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Prescription\AiDifferentialsRequest;
use App\Http\Requests\Panel\Prescription\DecideAiSuggestionRequest;
use App\Models\Tenant\AiSuggestion;
use App\Models\Tenant\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /panel/visits/{visit}/ai/{summary|differentials} · PATCH /panel/ai-suggestions/{suggestion} (PRESCRIPTION.md §5.7). */
final class AiController extends Controller
{
    public function summary(Request $request, Visit $visit, RequestAiSummary $summarise): JsonResponse
    {
        $this->authorize('write', $visit);
        $s = $summarise->handle($visit, $request->user('web')->doctor, Actor::fromRequest($request));

        if ($s === null) {
            return response()->json(['available' => false], 204);
        }

        return response()->json(['suggestion_id' => $s->id, 'type' => $s->type->value, 'lines' => $s->payload()['lines'] ?? [], 'model' => $s->model, 'generated_at' => $s->created_at?->toIso8601String(), 'available' => true]);
    }

    public function differentials(AiDifferentialsRequest $request, Visit $visit, RequestAiDifferentials $suggest): JsonResponse
    {
        $s = $suggest->handle($visit, $request->user('web')->doctor, $request->validated(), Actor::fromRequest($request));

        if ($s === null) {
            return response()->json(['available' => false], 204);
        }

        return response()->json(['suggestion_id' => $s->id, 'type' => $s->type->value, 'items' => $s->payload()['items'] ?? [], 'model' => $s->model, 'generated_at' => $s->created_at?->toIso8601String(), 'available' => true]);
    }

    public function decide(DecideAiSuggestionRequest $request, AiSuggestion $suggestion, DecideAiSuggestion $decide): JsonResponse
    {
        $s = $decide->handle($suggestion, (bool) $request->validated('accepted'), $request->validated('accepted_fragment'), Actor::fromRequest($request));

        return response()->json(['suggestion_id' => $s->id, 'accepted' => $s->accepted, 'accepted_at' => $s->accepted_at?->toIso8601String()]);
    }
}
