<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reception;

use App\Domain\Patients\Services\PatientSearch;
use App\Http\Controllers\Api\Reception\Concerns\ResolvesDevice;
use App\Http\Controllers\Controller;
use App\Http\Resources\Patients\PatientSummaryResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Device-token variants of the Patients module's desk endpoints (E's /api/patients/* need the staff session, which
 * expires during an outage): recent patients for the offline cache and a household lookup with history summary.
 */
final class PatientController extends Controller
{
    use ResolvesDevice;

    public function recent(Request $request, PatientSearch $search): JsonResponse
    {
        $this->device($request);
        $rows = $search->recent(min(500, max(1, (int) $request->query('limit', '200'))), $this->actorUser($request));

        return response()->json(['data' => PatientSummaryResource::collection($rows)->resolve()]);
    }

    public function lookup(Request $request, PatientSearch $search): JsonResponse
    {
        $this->device($request);
        $q = (string) $request->query('q', (string) $request->query('mobile', ''));
        $household = $q === '' ? collect() : $search->search($q, 20, $this->actorUser($request));
        $ids = $household->pluck('id')->all();

        $history = Appointment::query()->whereIn('patient_id', $ids)
            ->whereNotNull('scheduled_date')
            ->with('doctor')
            ->orderByDesc('scheduled_date')->orderByDesc('id')
            ->limit(10 * max(1, count($ids)))
            ->get()
            ->groupBy('patient_id');

        return response()->json([
            'data' => $household->map(fn (Patient $p) => (new PatientSummaryResource($p))->toArray($request) + [
                'history' => $history->get($p->id, collect())->take(10)->map(fn (Appointment $a) => [
                    'public_id' => $a->public_id, 'date' => $a->scheduled_date?->toDateString(), 'doctor' => $a->doctor->name, 'doctor_public_id' => $a->doctor->public_id,
                    'status' => $a->status->value, 'type' => $a->type->value, 'fee_paisa' => $a->fee_paisa, 'payment_status' => $a->payment_status->value,
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }
}
