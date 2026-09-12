<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Prescription\Services\DraftSerializer;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Prescription\IssuedPrescriptionResource;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use App\Support\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /panel/prescriptions/{prescription} — a draft returns the PrescriptionDraft shape; an issued/amended/voided row
 * returns the frozen snapshot document + version chain (IssuedPrescriptionResource, catalog-free) plus `queue`,
 * the way back into today's session (the post-issue bar's "Call next patient" / "Back to today's session").
 * Inertia page `Prescription/Show` unless JSON is requested. Audit `view` once per session.
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
            : ['prescription' => (new IssuedPrescriptionResource($prescription))->resolve($request), 'queue' => $this->queue($request, $prescription)];

        return $request->query('format') === 'json' || $request->wantsJson() ? response()->json($props) : Inertia::render('Prescription/Show', $props);
    }

    /**
     * The session this prescription's visit belongs to, when it is one of today's and still open — the doctor's
     * next step after issuing is the next patient of that session, not a list. `can_call_next` is the session's
     * callNext policy (the doctor on their own session, or an operator); the bar hides the button without it.
     *
     * @return array{session_id: string, code: string, session_url: string, can_call_next: bool}|null
     */
    private function queue(Request $request, Prescription $prescription): ?array
    {
        $session = $prescription->visit->sessionInstance;
        $user = $request->user('web');

        if (! $session instanceof SessionInstance || ! $user instanceof User) {
            return null;
        }

        if (! $session->session_date->isSameDay(Clock::today()) || in_array($session->status, [SessionStatus::Closed, SessionStatus::Cancelled], true)) {
            return null;
        }

        return [
            'session_id' => $session->public_id,
            'code' => $session->session_code,
            'session_url' => route('panel.queue.doctor', ['session' => $session->public_id]),
            'can_call_next' => $user->can('callNext', $session),
        ];
    }

    /** GET /panel/prescriptions/{prescription}/versions — the chain of the root, oldest first. */
    public function versions(Prescription $prescription): JsonResponse
    {
        $this->authorize('view', $prescription);

        return response()->json(['data' => Prescription::versions($prescription->root_prescription_id ?? $prescription->id)->map(fn (Prescription $v) => IssuedPrescriptionResource::brief($v))->values()->all()]);
    }
}
