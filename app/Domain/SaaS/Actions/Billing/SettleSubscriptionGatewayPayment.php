<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Billing;

use App\Domain\Billing\Data\GatewayCallback;
use App\Domain\SaaS\Enums\SubscriptionPaymentStatus;
use App\Domain\SaaS\Events\SubscriptionPaymentReceived;
use App\Domain\SaaS\Gateways\SubscriptionGatewayManager;
use App\Domain\SaaS\Services\InvoiceSettlement;
use App\Models\Central\SubscriptionPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The inbound half of a gateway payment, and the place replay safety is actually proved.
 *
 * Three defences, in order:
 *   1. the callback was signature-verified before it got here (`verifyCallback`) and carries identifiers only;
 *   2. `verifyTransaction()` asks the gateway what happened, and its amount must EQUAL the amount frozen on the
 *      pending row — a mismatch credits nothing and is logged loudly;
 *   3. the promotion pending → succeeded is a conditional UPDATE (`where status = 'pending'`). A webhook the
 *      gateway delivers five times therefore promotes the row once; the four replays update zero rows, find the
 *      row already succeeded, and return the SAME payment without touching the invoice a second time.
 *
 * The invoice total is then recomputed from the succeeded rows, so even a lost update elsewhere cannot make
 * `paid_paisa` disagree with the payments that exist.
 */
final class SettleSubscriptionGatewayPayment
{
    public function __construct(private readonly InvoiceSettlement $settlement) {}

    public function handle(GatewayCallback $callback): ?SubscriptionPayment
    {
        $payment = SubscriptionPayment::query()->where('public_id', (string) $callback->merchantRef)->first();

        if ($payment === null) {
            Log::warning('saas.payment.unknown_reference', ['gateway' => $callback->gateway->value, 'ref' => $callback->merchantRef]);

            return null;
        }

        if ($payment->status === SubscriptionPaymentStatus::Succeeded) {
            return $payment;                                       // replay: already settled, same answer
        }

        $driver = app(SubscriptionGatewayManager::class)->driver($callback->gateway);
        $verification = $driver->verifyTransaction($callback, $payment);

        if (! $verification->succeeded) {
            $this->fail($payment, $verification->failureReason ?? $callback->rawStatus, $callback->payload);

            return $payment->refresh();
        }

        if ($verification->amountPaisa !== $payment->amount_paisa) {
            Log::error('saas.payment.amount_mismatch', [
                'payment' => $payment->public_id,
                'frozen_paisa' => $payment->amount_paisa,
                'gateway_paisa' => $verification->amountPaisa,
            ]);
            $this->fail($payment, 'amount_mismatch', $verification->payload);

            return $payment->refresh();
        }

        $promoted = DB::connection('pgsql')->table('public.subscription_payments')
            ->where('id', $payment->id)
            ->where('status', SubscriptionPaymentStatus::Pending->value)
            ->update([
                'status' => SubscriptionPaymentStatus::Succeeded->value,
                'gateway_txn_id' => $verification->gatewayTxnId ?? $payment->getAttribute('gateway_txn_id'),
                'idempotency_key' => 'gw:'.$callback->gateway->value.':'.($verification->gatewayTxnId ?? $payment->public_id),
                'gateway_payload' => json_encode($verification->payload),
                'paid_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);

        $payment->refresh();

        if ($promoted !== 1) {
            return $payment;                                       // another delivery won the race and did the work
        }

        $invoice = $payment->invoice;

        if ($invoice !== null && $this->settlement->apply($invoice)) {
            SubscriptionPaymentReceived::dispatch($payment->tenant_id, $invoice->id, $payment->id, $payment->amount_paisa, $payment->method->value);
        }

        return $payment;
    }

    /** @param  array<string, mixed>  $payload */
    private function fail(SubscriptionPayment $payment, string $reason, array $payload): void
    {
        DB::connection('pgsql')->table('public.subscription_payments')
            ->where('id', $payment->id)
            ->where('status', SubscriptionPaymentStatus::Pending->value)
            ->update([
                'status' => SubscriptionPaymentStatus::Failed->value,
                'gateway_payload' => json_encode($payload + ['failure_reason' => $reason]),
                'updated_at' => CarbonImmutable::now(),
            ]);
    }
}
