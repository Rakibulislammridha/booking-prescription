<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Reception;

use App\Domain\Patients\Services\PatientSearch;
use App\Http\Controllers\Controller;
use App\Http\Resources\Patients\PatientSummaryResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Patient;
use App\Models\Tenant\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /panel/reception/patients/lookup?q= — quick search (mobile / name / code) with each person's last 10 bookings. */
final class PatientLookupController extends Controller
{
    public function __invoke(Request $request, PatientSearch $search): JsonResponse
    {
        $this->authorize('viewAny', Patient::class);
        /** @var User $user */
        $user = $request->user('web');
        $q = trim((string) $request->query('q', ''));
        $rows = $q === '' ? collect() : $search->search($q, 20, $user);
        $ids = $rows->pluck('id')->all();

        $history = Appointment::query()->whereIn('patient_id', $ids)->whereNotNull('scheduled_date')->with('doctor')
            ->orderByDesc('scheduled_date')->orderByDesc('id')->limit(10 * max(1, count($ids)))->get()->groupBy('patient_id');

        return response()->json([
            'data' => $rows->map(fn (Patient $p) => (new PatientSummaryResource($p))->toArray($request) + [
                'history' => $history->get($p->id, collect())->take(10)->map(fn (Appointment $a) => [
                    'public_id' => $a->public_id, 'date' => $a->scheduled_date?->toDateString(), 'doctor' => $a->doctor->name, 'doctor_public_id' => $a->doctor->public_id,
                    'status' => $a->status->value, 'type' => $a->type->value, 'fee_paisa' => $a->fee_paisa, 'payment_status' => $a->payment_status->value,
                ])->values()->all(),
            ])->values()->all(),
            'meta' => ['engine' => $search->usesMeilisearch() ? 'meilisearch' : 'database'],
        ]);
    }
}
