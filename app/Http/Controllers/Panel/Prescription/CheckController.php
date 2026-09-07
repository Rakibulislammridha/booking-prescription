<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Prescription\Enums\SafetyStage;
use App\Domain\Prescription\Services\ItemResolver;
use App\Domain\Prescription\Services\SafetyChecker;
use App\Domain\Prescription\Services\SafetyContextBuilder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Prescription\CheckRequest;
use App\Models\Tenant\Prescription;
use Illuminate\Http\JsonResponse;

/** POST /panel/prescriptions/{prescription}/check — the pipeline on the posted body, nothing persisted (PRESCRIPTION.md §5.5). */
final class CheckController extends Controller
{
    public function __invoke(CheckRequest $request, Prescription $prescription, ItemResolver $items, SafetyChecker $safety): JsonResponse
    {
        $v = $request->validated();
        $prescription->load(['items', 'visit.patient.allergies', 'visit.patient.conditions', 'visit.patient.medications', 'visit.latestVitals', 'doctor.profile']);
        $prefs = (array) ($prescription->doctor->profile->prefs ?? []);
        $resolved = $items->resolve($v['items'], $prescription->items->keyBy('id')->all(), (int) ($prefs['cont_days'] ?? config('prescription.cont_days', 30)), $prescription->language->value);

        $overrides = SafetyContextBuilder::overridesFrom($resolved, (int) $request->user('web')->getAuthIdentifier());

        foreach ((array) ($v['overrides'] ?? []) as $o) {
            $overrides[(string) $o['fingerprint']] ??= ['reason' => (string) $o['reason'], 'by' => null, 'at' => null];
        }

        $report = $safety->check($prescription, $resolved, SafetyStage::Draft, $overrides);

        return response()->json($report->toArray() + ['catalog_version' => $safety->catalogVersion()]);
    }
}
