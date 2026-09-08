<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Billing;

use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Support\InvoiceNumber;
use App\Models\Central\Subscription;
use App\Models\Central\SubscriptionInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A draft invoice for one billing period of one subscription.
 *
 * Money is integer paisa end to end (CONVENTIONS §3.2) and the totals are computed, not accepted:
 * `total = subtotal − discount + tax`, which is also the CHECK the table carries. Platform invoices carry no VAT
 * at launch — `tax_paisa` is 0 and exists for the day the platform is VAT-registered; `billing.vat_percent` is a
 * TENANT setting for a clinic's own patient invoices and has nothing to do with what the clinic owes us.
 *
 * Idempotent per period: a renewal sweep that runs twice (two schedulers, a retried job) finds the invoice it
 * already wrote instead of billing the clinic twice.
 */
final class GenerateSubscriptionInvoice
{
    public function handle(Subscription $subscription, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, ?int $amountPaisa = null): SubscriptionInvoice
    {
        $existing = SubscriptionInvoice::query()
            ->where('subscription_id', $subscription->id)
            ->whereDate('period_start', $periodStart->toDateString())
            ->whereNot('status', SubscriptionInvoiceStatus::Void->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $plan = $subscription->plan;
        $subtotal = max(0, $amountPaisa ?? $subscription->price_paisa);

        return DB::connection('pgsql')->transaction(fn () => SubscriptionInvoice::query()->create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'number' => InvoiceNumber::next($periodStart),
            'status' => SubscriptionInvoiceStatus::Draft,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'subtotal_paisa' => $subtotal,
            'discount_paisa' => 0,
            'tax_paisa' => 0,
            'total_paisa' => $subtotal,
            'paid_paisa' => 0,
            'line_items' => [[
                'description' => $plan->name.' · '.$subscription->billing_cycle->value,
                'quantity' => 1,
                'unit_paisa' => $subtotal,
                'total_paisa' => $subtotal,
                'feature_key' => null,
            ]],
            'dunning_step' => 0,
        ]));
    }
}
