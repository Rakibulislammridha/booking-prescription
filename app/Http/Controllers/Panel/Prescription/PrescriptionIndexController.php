<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Prescription\Enums\PrescriptionStatus;
use App\Domain\Prescription\Queries\PrescriptionIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Prescription\IndexPrescriptionsRequest;
use App\Http\Resources\Prescription\PrescriptionRowResource;
use App\Models\Tenant\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /panel/prescriptions (`panel.prescriptions.index`) — what the sidebar's "Prescriptions" entry opens: recently
 * issued first, searched by patient, filtered by doctor / status / day range, fifty a page. Rows open the existing
 * Show page; print and PDF are the existing output routes. Not audited: a list read is not a record read (the
 * patients index sets that convention — Show, print and PDF each audit their own row, ARCHITECTURE §8.1).
 */
final class PrescriptionIndexController extends Controller
{
    public function __invoke(IndexPrescriptionsRequest $request, PrescriptionIndexQuery $query): Response
    {
        /** @var User $user */
        $user = $request->user('web');
        $filters = $request->toData();

        return Inertia::render('Prescription/Index', [
            'filters' => $filters->toArray(),
            'prescriptions' => PrescriptionRowResource::collection($query->paginate($user, $filters))->response()->getData(true),
            'options' => ['doctors' => PrescriptionIndexQuery::doctorOptions(), 'statuses' => PrescriptionStatus::values()],
        ]);
    }
}
