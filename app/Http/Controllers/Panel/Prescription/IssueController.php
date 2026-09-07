<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Prescription\Actions\AmendPrescription;
use App\Domain\Prescription\Actions\IssuePrescription;
use App\Domain\Prescription\Actions\VoidPrescription;
use App\Domain\Prescription\Services\DraftSerializer;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Prescription\IssueRequest;
use App\Http\Requests\Panel\Prescription\ReasonRequest;
use App\Http\Resources\Prescription\IssuedPrescriptionResource;
use App\Models\Tenant\Prescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/** POST …/issue (§6.1) · POST …/amend (§6.3) · POST …/void (§6.4). */
final class IssueController extends Controller
{
    public function issue(IssueRequest $request, Prescription $prescription, IssuePrescription $issue): JsonResponse
    {
        $rx = $issue->handle($prescription, $request->toData(), Actor::fromRequest($request));
        $print = Route::has('panel.prescription.print') ? route('panel.prescription.print', ['prescription' => $rx->public_id]) : null;

        return response()->json([
            'prescription' => IssuedPrescriptionResource::brief($rx),
            'print_url' => $print,
            'pdf_status' => 'pending',
            'follow_up_draft_appointment_id' => null,
        ]);
    }

    public function amend(ReasonRequest $request, Prescription $prescription, AmendPrescription $amend, DraftSerializer $serializer): JsonResponse
    {
        $draft = $amend->handle($prescription, $request->reason(), Actor::fromRequest($request));

        return response()->json(['prescription' => $serializer->draft($draft), 'writer_url' => route('panel.prescription.writer', ['visit' => $draft->visit->public_id])], 201);
    }

    public function void(ReasonRequest $request, Prescription $prescription, VoidPrescription $void): JsonResponse
    {
        $rx = $void->handle($prescription, $request->reason(), Actor::fromRequest($request));

        return response()->json(['prescription' => IssuedPrescriptionResource::brief($rx)]);
    }
}
