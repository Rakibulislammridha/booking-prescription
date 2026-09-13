<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Billing;

use App\Domain\Billing\Queries\OutstandingDuesQuery;
use App\Domain\Clinic\Services\DoctorScope;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /panel/billing/patients/{patient}/dues` — what this patient still owes (BRIEF §5.I "due tracking").
 *
 * A JSON seam rather than a page: the desk board and the patient record are owned by other modules, and this
 * lets them show the outstanding balance without Billing reaching into their screens (or them re-deriving the
 * number). The Reports module reuses `OutstandingDuesQuery` directly.
 *
 * `viewAny` is a clinic-wide permission and the patient is a route binding, so without the caller's DoctorScope
 * this endpoint answers for ANY patient in the building. Scoped, it answers what this patient owes the doctors the
 * caller works for — which is the only number a compounder taking a fee at that desk needs.
 *
 * Scoping the numbers is not enough on its own, because the body also NAMES the patient: a restricted caller
 * handed any patient id would otherwise get that person's name back with a zero balance attached, which is the
 * "other doctors' patient lists" the role exists to be kept out of. So a restricted caller must first be able to
 * reach the patient AT ALL — proved by an invoice of one of their own doctors, which every booking raises the
 * moment it is made, so the desk still reads the balance of the patient it is taking a fee from.
 */
final class PatientDuesController extends Controller
{
    public function __invoke(Request $request, Patient $patient, OutstandingDuesQuery $dues, DoctorScope $scope): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);
        $user = $request->user('web');
        $doctorIds = $user === null ? [] : $scope->doctorIds($user);

        abort_if(
            $doctorIds !== null && Invoice::query()->where('patient_id', $patient->id)->whereIn('doctor_id', $doctorIds)->doesntExist(),
            403,
        );

        return response()->json([
            'patient' => ['public_id' => $patient->public_id, 'name' => $patient->name],
            'due_paisa' => $dues->totalPaisa(null, $patient->id, $doctorIds),
            'invoices' => $dues->forPatient($patient->id, doctorIds: $doctorIds),
        ]);
    }
}
