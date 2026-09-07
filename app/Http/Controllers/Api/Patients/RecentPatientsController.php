<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Patients;

use App\Domain\Patients\Services\PatientSearch;
use App\Http\Controllers\Controller;
use App\Http\Resources\Patients\PatientSummaryResource;
use App\Models\Tenant\Patient;
use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** GET /api/patients/recent?limit=50 — offline cache warm-up for the reception PWA (most recently seen first). */
final class RecentPatientsController extends Controller
{
    public function __invoke(Request $request, PatientSearch $search): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Patient::class);
        /** @var User $user */
        $user = $request->user('web');

        return PatientSummaryResource::collection($search->recent((int) $request->query('limit', '50'), $user));
    }
}
