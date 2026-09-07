<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Billing;

use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\RecordPayment;
use App\Domain\Billing\Data\PaymentRequest;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Services\CurrentShift;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Billing\CollectPaymentRequest;
use App\Http\Resources\Billing\InvoiceResource;
use App\Models\Tenant\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/** Counter collection against a specific invoice (the collect-payment dialog, usable from the desk). */
final class PaymentController extends Controller
{
    public function store(CollectPaymentRequest $request, Invoice $invoice, RecordPayment $payments, IssueInvoice $issue, CurrentShift $shifts): JsonResponse
    {
        $actor = Actor::fromRequest($request);

        if ($invoice->isDraft()) {
            $invoice = $issue->handle($invoice, $actor);
        }

        $clientEventId = $request->validated('client_event_id');
        $receipt = $request->validated('receipt_number');

        $result = $payments->handle($invoice, new PaymentRequest(
            method: PaymentMethod::from((string) $request->validated('method')),
            amountPaisa: (int) $request->validated('amount_paisa'),
            // The client's event id is the idempotency key when it sends one, so a double-tap or a retried
            // request lands on the same payment row instead of charging twice.
            idempotencyKey: is_string($clientEventId) && $clientEventId !== '' ? 'evt:'.$clientEventId : (string) Str::ulid(),
            receiptNumber: is_string($receipt) && $receipt !== '' ? $receipt : null,
            clientEventId: is_string($clientEventId) && $clientEventId !== '' ? $clientEventId : null,
            cashShiftId: $shifts->idForUser($actor->userId),
        ), $actor);

        return response()->json([
            'payment' => $result->toArray(),
            'invoice' => (new InvoiceResource($result->invoice->refresh()->load(['items', 'payments', 'refunds', 'discounts'])))->resolve(),
        ]);
    }
}
