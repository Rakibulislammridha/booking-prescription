<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Patients;

use App\Domain\Patients\Actions\AddDependent;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Patients\StoreDependentRequest;
use App\Models\Tenant\Patient;
use Illuminate\Http\RedirectResponse;

/** POST /panel/patients/{patient}/dependents — "add family member" on the same mobile. */
final class PatientDependentController extends Controller
{
    public function store(StoreDependentRequest $request, Patient $patient, AddDependent $add): RedirectResponse
    {
        $this->authorize('view', $patient);

        $dependent = $add->handle($patient, $request->toData($patient), Actor::fromRequest($request));

        return redirect()->route('panel.patients.show', ['patient' => $dependent->public_id])->with('flash.success', __('patients.flash.dependent_added', ['name' => $dependent->name]));
    }
}
