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

/** GET /api/patients/search?q=017…|Rahim|P-000123&limit=20 — the desk / writer quick search. */
final class PatientSearchController extends Controller
{
    public function __invoke(Request $request, PatientSearch $search): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Patient::class);
        /** @var User $user */
        $user = $request->user('web');

        return PatientSummaryResource::collection($search->search((string) $request->query('q', ''), (int) $request->query('limit', '20'), $user))
            ->additional(['meta' => ['engine' => $search->usesMeilisearch() ? 'meilisearch' : 'database']]);
    }
}
