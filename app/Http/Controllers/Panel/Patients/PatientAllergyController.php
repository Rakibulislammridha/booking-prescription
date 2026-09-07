<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Patients;

use App\Domain\Patients\Actions\RemoveAllergy;
use App\Domain\Patients\Actions\SaveAllergy;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Patients\SaveAllergyRequest;
use App\Http\Resources\Patients\AllergyResource;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientAllergy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET/POST/PATCH/DELETE /panel/patients/{patient}/allergies[/{allergy}] (PRESCRIPTION.md §8). The writer calls
 * these over XHR (JSON); the Patients/Show tab posts Inertia forms (redirect back). DELETE deactivates.
 */
final class PatientAllergyController extends Controller
{
    public function index(Patient $patient): AnonymousResourceCollection
    {
        $this->authorize('view', $patient);
        AuditLog::view($patient, ['section' => 'allergies']);

        return AllergyResource::collection($patient->allergies()->orderByDesc('is_active')->orderByDesc('id')->get());
    }

    public function store(SaveAllergyRequest $request, Patient $patient, SaveAllergy $save): JsonResponse|RedirectResponse
    {
        $allergy = $save->handle($patient, $request->toData(), Actor::fromRequest($request));

        return $request->wantsJson()
            ? (new AllergyResource($allergy))->response()->setStatusCode(201)
            : back()->with('flash.success', __('patients.flash.allergy_saved'));
    }

    public function update(SaveAllergyRequest $request, Patient $patient, PatientAllergy $allergy, SaveAllergy $save): JsonResponse|RedirectResponse
    {
        $allergy = $save->handle($patient, $request->toData(), Actor::fromRequest($request), $allergy);

        return $request->wantsJson()
            ? (new AllergyResource($allergy))->response()
            : back()->with('flash.success', __('patients.flash.allergy_saved'));
    }

    public function destroy(Request $request, Patient $patient, PatientAllergy $allergy, RemoveAllergy $remove): JsonResponse|RedirectResponse
    {
        $this->authorize('manageClinical', $patient);
        $allergy = $remove->handle($allergy, Actor::fromRequest($request));

        return $request->wantsJson()
            ? (new AllergyResource($allergy))->response()
            : back()->with('flash.success', __('patients.flash.allergy_removed'));
    }
}
