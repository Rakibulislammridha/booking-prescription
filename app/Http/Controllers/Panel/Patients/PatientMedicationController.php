<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Patients;

use App\Domain\Patients\Actions\RemoveMedication;
use App\Domain\Patients\Actions\SaveMedication;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Patients\SaveMedicationRequest;
use App\Http\Resources\Patients\MedicationResource;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientMedication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** GET/POST/PATCH/DELETE /panel/patients/{patient}/medications[/{medication}]. DELETE stops the medication. */
final class PatientMedicationController extends Controller
{
    public function index(Patient $patient): AnonymousResourceCollection
    {
        $this->authorize('view', $patient);
        AuditLog::view($patient, ['section' => 'medications']);

        return MedicationResource::collection($patient->medications()->orderByDesc('is_active')->orderByDesc('id')->get());
    }

    public function store(SaveMedicationRequest $request, Patient $patient, SaveMedication $save): JsonResponse|RedirectResponse
    {
        $medication = $save->handle($patient, $request->toData(), Actor::fromRequest($request));

        return $request->wantsJson()
            ? (new MedicationResource($medication))->response()->setStatusCode(201)
            : back()->with('flash.success', __('patients.flash.medication_saved'));
    }

    public function update(SaveMedicationRequest $request, Patient $patient, PatientMedication $medication, SaveMedication $save): JsonResponse|RedirectResponse
    {
        $medication = $save->handle($patient, $request->toData(), Actor::fromRequest($request), $medication);

        return $request->wantsJson()
            ? (new MedicationResource($medication))->response()
            : back()->with('flash.success', __('patients.flash.medication_saved'));
    }

    public function destroy(Request $request, Patient $patient, PatientMedication $medication, RemoveMedication $remove): JsonResponse|RedirectResponse
    {
        $this->authorize('manageClinical', $patient);
        $medication = $remove->handle($medication, Actor::fromRequest($request));

        return $request->wantsJson()
            ? (new MedicationResource($medication))->response()
            : back()->with('flash.success', __('patients.flash.medication_stopped'));
    }
}
