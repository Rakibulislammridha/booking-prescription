<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Patients;

use App\Domain\Patients\Actions\MergePatients;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Patients\MergePatientRequest;
use App\Models\Tenant\Patient;
use Illuminate\Http\RedirectResponse;

/** POST /panel/patients/{patient}/merge — {patient} wins; loser_public_id is folded in and soft-deleted. */
final class PatientMergeController extends Controller
{
    public function __invoke(MergePatientRequest $request, Patient $patient, MergePatients $merge): RedirectResponse
    {
        $loser = Patient::query()->wherePublicId((string) $request->validated('loser_public_id'))->firstOrFail();

        $merge->handle($patient, $loser, Actor::fromRequest($request), $request->validated('reason'));

        return redirect()->route('panel.patients.show', ['patient' => $patient->public_id])->with('flash.success', __('patients.flash.merged', ['code' => $loser->patient_code]));
    }
}
