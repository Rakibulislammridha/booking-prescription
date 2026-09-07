<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Patients;

use App\Domain\Patients\Queries\PatientTimelineQuery;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /panel/patients/{patient}/timeline?cursor=&limit=25&kinds=visit,document — PRESCRIPTION.md §8.
 * JSON from the panel surface (the writer's XHR exception, CONVENTIONS §5). Every page is an audited read.
 */
final class PatientTimelineController extends Controller
{
    public function __invoke(Request $request, Patient $patient, PatientTimelineQuery $timeline): JsonResponse
    {
        $this->authorize('view', $patient);
        AuditLog::view($patient, ['section' => 'timeline']);

        $kinds = $request->filled('kinds') ? array_values(array_filter(explode(',', (string) $request->query('kinds')))) : null;
        $page = $timeline->fetch($patient, $request->query('cursor') !== null ? (string) $request->query('cursor') : null, (int) $request->query('limit', '25'), $kinds);

        return response()->json($page->toArray());
    }
}
