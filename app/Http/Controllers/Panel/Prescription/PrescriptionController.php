<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Prescription\Services\DraftSerializer;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Http\Controllers\Controller;
use App\Http\Resources\Prescription\IssuedPrescriptionResource;
use App\Models\Tenant\Prescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /panel/prescriptions/{prescription} — a draft returns the PrescriptionDraft shape; an issued/amended/voided row
 * returns the frozen snapshot document + version chain (IssuedPrescriptionResource, catalog-free). Inertia page
 * `Prescription/Show` unless JSON is requested. Audit `view` once per session.
 */
final class PrescriptionController extends Controller
{
    public function show(Request $request, Prescription $prescription, DraftSerializer $serializer, PrescriptionAuditor $auditor): Response|JsonResponse
    {
        $this->authorize('view', $prescription);
        $key = "rx_viewed.{$prescription->id}";

        if (! $request->hasSession() || ! $request->session()->has($key)) {
            $auditor->viewed($prescription);
            $request->hasSession() && $request->session()->put($key, true);
        }

        $props = $prescription->isDraft()
            ? ['prescription' => $serializer->draft($prescription), 'visit' => $serializer->visit($prescription->visit->load(['serial', 'sessionInstance']))]
            : ['prescription' => (new IssuedPrescriptionResource($prescription))->resolve($request)];

        return $request->query('format') === 'json' || $request->wantsJson() ? response()->json($props) : Inertia::render('Prescription/Show', $props);
    }

    /** GET /panel/prescriptions/{prescription}/versions — the chain of the root, oldest first. */
    public function versions(Prescription $prescription): JsonResponse
    {
        $this->authorize('view', $prescription);

        return response()->json(['data' => Prescription::versions($prescription->root_prescription_id ?? $prescription->id)->map(fn (Prescription $v) => IssuedPrescriptionResource::brief($v))->values()->all()]);
    }
}
