<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Billing;

use App\Domain\Billing\Queries\OutstandingDuesQuery;
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
 */
final class PatientDuesController extends Controller
{
    public function __invoke(Request $request, Patient $patient, OutstandingDuesQuery $dues): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        return response()->json([
            'patient' => ['public_id' => $patient->public_id, 'name' => $patient->name],
            'due_paisa' => $dues->totalPaisa(null, $patient->id),
            'invoices' => $dues->forPatient($patient->id),
        ]);
    }
}
