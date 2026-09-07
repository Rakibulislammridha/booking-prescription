<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Patients;

use App\Domain\Patients\Queries\VitalsTrendQuery;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /panel/patients/{patient}/vitals-trend?limit=12 — [] until the Prescription module's vitals table exists. */
final class PatientVitalsTrendController extends Controller
{
    public function __invoke(Request $request, Patient $patient, VitalsTrendQuery $vitals): JsonResponse
    {
        $this->authorize('view', $patient);
        AuditLog::view($patient, ['section' => 'vitals_trend']);

        return response()->json(['data' => $vitals->for($patient, (int) $request->query('limit', '12')), 'available' => $vitals->available()]);
    }
}
