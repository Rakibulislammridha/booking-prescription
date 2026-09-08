<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Reception;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Prescription\Actions\StartVisit;
use App\Domain\Prescription\Services\DraftSerializer;
use App\Domain\Reception\Services\SerialPresenter;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Serial;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The compounder's half of BRIEF §5.G.2: vitals are "entered by the compounder before the doctor sees the
 * patient", so the desk needs a way in. The board's checked-in row opens the encounter (the same idempotent
 * `StartVisit` the doctor screen uses) and lands on a single-purpose entry screen that writes through the
 * Prescription module's own endpoint — there is no second write path, and no prescription content is reachable
 * from here. Clinical data, so online only (OFFLINE.md §6.2: "Prescriptions, vitals, clinical history bodies").
 */
final class VitalsDeskController extends Controller
{
    /** POST /panel/reception/serials/{serial}/vitals — open (idempotently) the visit and go to the entry screen. */
    public function open(Request $request, Serial $serial, StartVisit $start): RedirectResponse
    {
        $this->authorize('view', $serial);
        abort_unless($request->user('web')?->can(Permission::PrescriptionsVitalsRecord->value) ?? false, 403);

        $visit = $start->handle($serial, Actor::fromRequest($request), 'reception_desk');

        return redirect()->route('panel.reception.vitals.edit', ['visit' => $visit->public_id]);
    }

    /** GET /panel/reception/visits/{visit}/vitals — patient header, every reading of this visit, the entry form. */
    public function edit(Request $request, Visit $visit, DraftSerializer $serializer): Response
    {
        $this->authorize('recordVitals', $visit);
        AuditLog::view($visit, ['screen' => 'panel.reception.vitals']);

        $visit->load(['patient', 'doctor', 'serial', 'sessionInstance']);
        $patient = $visit->patient;

        return Inertia::render('Reception/Vitals', [
            'visit' => [
                'public_id' => $visit->public_id,
                'started_at' => $visit->started_at->toIso8601String(),
                'status' => $visit->status->value,
            ],
            'serial' => $visit->serial === null ? null : [
                'display_code' => $visit->serial->display_code,
                'status' => $visit->serial->status->value,
            ],
            'doctor' => ['name' => $visit->doctor->name, 'name_bn' => $visit->doctor->name_bn],
            'session_code' => $visit->sessionInstance?->session_code,
            'patient' => SerialPresenter::patient($patient) + [
                'age_years' => $patient->age_years,
                'blood_group' => $patient->blood_group?->value,
            ],
            'vitals' => $visit->vitals()->with('recordedBy')->orderByDesc('recorded_at')->orderByDesc('id')
                ->get()->map(fn (Vital $v) => $serializer->vitals($v))->values()->all(),
        ]);
    }
}
