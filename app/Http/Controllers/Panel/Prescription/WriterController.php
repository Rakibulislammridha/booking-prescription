<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Clinic\Services\ActiveBranch;
use App\Domain\Prescription\Actions\CreateDraftPrescription;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Prescription\Services\WriterPayloadBuilder;
use App\Domain\Serials\Actions\StartConsultation;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Exceptions\IllegalTransition;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /panel/visits/{visit}/prescribe → Inertia `Prescription/Writer` (PRESCRIPTION.md §1.1–§1.2): audit `view`,
 * the draft is created inside the request when absent, the serial's consultation_started_at is stamped
 * (SERIAL_ENGINE §14), every prop group loads in one query. `?format=json` returns the same props as JSON.
 */
final class WriterController extends Controller
{
    public function show(Request $request, Visit $visit, CreateDraftPrescription $createDraft, WriterPayloadBuilder $payload, StartConsultation $startConsultation, PrescriptionAuditor $auditor, ActiveBranch $branch): Response|JsonResponse
    {
        $this->authorize('write', $visit);
        $doctor = $request->user('web')->doctor;
        AuditLog::view($visit, ['screen' => 'panel.prescription.writer']);

        $draft = $createDraft->handle($visit, $doctor, Actor::fromRequest($request));
        $this->viewOncePerSession($request, $draft->id, fn () => $auditor->viewed($draft));
        $this->stampConsultation($visit, $startConsultation, $request);

        $props = $payload->build($visit, $draft, $doctor, $branch->current()?->id);

        return $request->query('format') === 'json' || $request->wantsJson() ? response()->json($props) : Inertia::render('Prescription/Writer', $props);
    }

    private function stampConsultation(Visit $visit, StartConsultation $start, Request $request): void
    {
        $serial = $visit->serial;

        if ($serial === null || $serial->status !== SerialStatus::InConsultation || $serial->consultation_started_at !== null) {
            return;
        }

        try {
            $start->handle($serial, Actor::fromRequest($request));
        } catch (IllegalTransition) {
            // called elsewhere meanwhile — the serial state is Serials' business
        }
    }

    private function viewOncePerSession(Request $request, int $prescriptionId, \Closure $record): void
    {
        $key = "rx_viewed.{$prescriptionId}";

        if ($request->hasSession() && $request->session()->has($key)) {
            return;
        }

        $record();

        if ($request->hasSession()) {
            $request->session()->put($key, true);
        }
    }
}
