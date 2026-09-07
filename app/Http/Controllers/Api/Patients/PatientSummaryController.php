<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Patients;

use App\Http\Controllers\Controller;
use App\Http\Resources\Patients\PatientClinicalSummaryResource;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Patient;

/** GET /api/patients/{patient}/summary — PRESCRIPTION.md §1.1 PatientSummary for the writer (audited view). */
final class PatientSummaryController extends Controller
{
    public function __invoke(Patient $patient): PatientClinicalSummaryResource
    {
        $this->authorize('view', $patient);
        AuditLog::view($patient, ['section' => 'summary']);

        $patient->load(['primaryRelation.primary', 'allergies', 'conditions', 'medications', 'consents']);

        return new PatientClinicalSummaryResource($patient);
    }
}
