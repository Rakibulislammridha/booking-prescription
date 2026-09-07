<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Patients;

use App\Domain\Patients\Actions\RecordConsent;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Patients\StoreConsentRequest;
use App\Http\Resources\Patients\ConsentResource;
use App\Models\Tenant\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/** POST /panel/patients/{patient}/consents — append-only grant / revoke. */
final class PatientConsentController extends Controller
{
    public function store(StoreConsentRequest $request, Patient $patient, RecordConsent $record): JsonResponse|RedirectResponse
    {
        $consent = $record->handle($patient, $request->toData(), Actor::fromRequest($request));

        return $request->wantsJson()
            ? (new ConsentResource($consent))->response()->setStatusCode(201)
            : back()->with('flash.success', __('patients.flash.consent_recorded'));
    }
}
