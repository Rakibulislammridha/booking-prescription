<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Billing;

use App\Domain\Billing\Actions\IssueRefund;
use App\Domain\Billing\Services\RefundEligibility;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Billing\IssueRefundRequest;
use App\Http\Resources\Billing\InvoiceResource;
use App\Http\Resources\Billing\RefundResource;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Payment;
use App\Models\Tenant\Refund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Refunds with a reason code, plus approve/reject of the pending ones a cancellation raised. */
final class RefundController extends Controller
{
    public function store(IssueRefundRequest $request, Invoice $invoice, IssueRefund $action): JsonResponse
    {
        /** @var Payment $payment */
        $payment = $invoice->payments()->where('public_id', (string) $request->validated('payment'))->firstOrFail();
        $refund = $action->handle($payment, $request->toData(), Actor::fromRequest($request));

        return response()->json([
            'refund' => (new RefundResource($refund))->resolve(),
            'invoice' => (new InvoiceResource($invoice->refresh()->load(['items', 'payments', 'refunds', 'discounts'])))->resolve(),
        ]);
    }

    public function process(Request $request, Invoice $invoice, Refund $refund, IssueRefund $action): JsonResponse
    {
        $this->authorize('refund', $invoice);
        $abandon = $request->boolean('reject');

        $result = $abandon
            ? $action->reject($refund, Actor::fromRequest($request), $request->string('note')->value() ?: null)
            : $action->process($refund, Actor::fromRequest($request));

        return response()->json([
            'refund' => (new RefundResource($result))->resolve(),
            'invoice' => (new InvoiceResource($invoice->refresh()->load(['items', 'payments', 'refunds', 'discounts'])))->resolve(),
        ]);
    }

    /** "Would a refund be due if this were cancelled now?" — the desk's pre-flight question. */
    public function eligibility(Request $request, Invoice $invoice, RefundEligibility $eligibility): JsonResponse
    {
        $this->authorize('view', $invoice);
        $appointment = $invoice->appointment;

        if ($appointment === null) {
            return response()->json(['eligible' => false, 'explanation' => __('billing.refund.reason.no_appointment')]);
        }

        $decision = $eligibility->forCancellation(
            $appointment,
            $appointment->cancel_reason_code ?? CancelReason::PatientRequest,
            cancelledByPatient: $request->boolean('by_patient'),
        );

        return response()->json($decision->toArray() + ['paid_paisa' => $invoice->paid_paisa]);
    }
}
