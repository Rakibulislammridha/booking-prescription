<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Portal;

use App\Domain\Patients\Exceptions\PatientNotInHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Site\Portal\ActingForRequest;
use App\Models\Tenant\Patient;
use Illuminate\Http\RedirectResponse;

/** PATCH /portal/acting-for {patient: public_id} — pick the family member to act for (ARCHITECTURE §6.3). */
final class ActingForController extends Controller
{
    public function __invoke(ActingForRequest $request): RedirectResponse
    {
        /** @var Patient $patient */
        $patient = $request->user('patient');
        $target = Patient::query()->active()->household($patient->mobile)->wherePublicId((string) $request->validated('patient'))->first();

        if ($target === null) {
            throw new PatientNotInHousehold;
        }

        $request->session()->put(OtpController::SESSION_ACTING_FOR, $target->public_id);

        return redirect()->route('site.portal.home');
    }
}
