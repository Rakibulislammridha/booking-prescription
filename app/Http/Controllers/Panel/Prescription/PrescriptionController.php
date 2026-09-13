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
 * Both shapes carry `can` — see abilities(): the page renders no action this viewer would be refused.
 * Inertia page `Prescription/Show` unless JSON is requested. Audit `view` once per session.
 */
final class PrescriptionController extends Controller
{
    public function show(Request $request, Prescription $prescription, DraftSerializer $serializer, PrescriptionAuditor $auditor): Response|JsonResponse
    {
        $this->authorize('view', $prescription);
        /** @var User $user */
        $user = $request->user('web');
        $key = "rx_viewed.{$prescription->id}";

        if (! $request->hasSession() || ! $request->session()->has($key)) {
            $auditor->viewed($prescription);
            $request->hasSession() && $request->session()->put($key, true);
        }

        $props = ($prescription->isDraft()
            ? ['prescription' => $serializer->draft($prescription), 'visit' => $serializer->visit($prescription->visit->load(['serial', 'sessionInstance']))]
            : ['prescription' => (new IssuedPrescriptionResource($prescription))->resolve($request), 'queue' => $this->queue($request, $prescription)])
            + ['can' => $this->abilities($user, $prescription)];

        return $request->query('format') === 'json' || $request->wantsJson() ? response()->json($props) : Inertia::render('Prescription/Show', $props);
    }

    /**
     * What this viewer may DO with the sheet, asked of the policy rather than guessed from a role in the client.
     * The page shipped none of this and offered Send / Amend / Void to whoever could open it — and `view` is
     * deliberately the WIDE door (PrescriptionPolicy: `prescriptions.vitals.record` grants it, because BRIEF
     * §5.G.4 has the sheet printed and handed over at the desk). So all three buttons were rendered to a
     * compounder, whose Send is refused outright — a restricted user prints a sheet but never speaks to the
     * patient in the doctor's name — and whose Amend / Void need `prescriptions.write`, which a RECEPTIONIST
     * lacks too: this was never only a compounder bug.
     *
     * `write` is the draft branch's own button, the way back into the writer, and it asks VisitPolicy::write —
     * the same ability WriterController itself authorises, so the page cannot offer a door the next request
     * refuses. Every prescription has a visit (`prescriptions.visit_id` is NOT NULL), which is why it is asked
     * without a guard here and why the draft branch above dereferences the relation just as bluntly.
     *
     * @return array{write: bool, send: bool, amend: bool, void: bool}
     */
    private function abilities(User $user, Prescription $prescription): array
    {
        return [
            'write' => $user->can('write', $prescription->visit),
            'send' => $user->can('send', $prescription),
            'amend' => $user->can('amend', $prescription),
            'void' => $user->can('void', $prescription),
        ];
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
