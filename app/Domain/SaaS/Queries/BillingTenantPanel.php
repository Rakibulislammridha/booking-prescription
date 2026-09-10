<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Services\SubscriptionLifecycle;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The `billing` prop of one clinic's page in the console — the shape `Components/Super/TenantBillingCard.tsx`
 * renders. The tenant detail page (TenantController::show, owned elsewhere) calls `for($tenant)` and passes the
 * result straight through; nothing in this module reaches into that page.
 *
 *   billing: {
 *     subscription: TenantSubscription | null,     the CURRENT subscription (what tenants.status follows)
 *     addons: TenantAddon[],
 *     arrears_paisa, arrears_invoices,             issued/overdue invoices, total − paid
 *     next_invoice_at: string | null,              when the renewal sweep will next bill this clinic
 *     invoices: BillingInvoiceRow[],               newest first, BillingInvoices::row() shape
 *     payments: BillingPaymentRow[],               newest first, BillingPayments::row() shape
 *     dunning: DunningInvoicePreview[],            what the next saas:dun run does to this clinic
 *   }
 */
final class BillingTenantPanel
{
    public function __construct(
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly BillingInvoices $invoices,
        private readonly BillingPayments $payments,
        private readonly BillingDunningQueue $dunning,
    ) {}

    /** @return array<string, mixed> */
    public function for(Tenant $tenant, ?CarbonImmutable $now = null): array
    {
        $subscription = $this->lifecycle->current($tenant);

        $arrears = DB::connection('pgsql')->table('public.subscription_invoices')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [SubscriptionInvoiceStatus::Issued->value, SubscriptionInvoiceStatus::Overdue->value])
            ->selectRaw('count(*) as invoices, coalesce(sum(total_paisa - paid_paisa), 0) as due')
            ->first();

        return [
            'subscription' => $subscription === null ? null : self::subscription($subscription),
            'addons' => Subscription::query()->with('plan')->where('tenant_id', $tenant->id)
                ->whereKeyNot($subscription === null ? 0 : $subscription->id)
                ->whereIn('status', ['trialing', 'active', 'past_due'])
                ->get()->map(fn (Subscription $s) => ['plan_code' => $s->plan->code, 'plan_name' => $s->plan->name, 'status' => $s->status->value])->all(),
            'arrears_paisa' => (int) ($arrears->due ?? 0),
            'arrears_invoices' => (int) ($arrears->invoices ?? 0),
            'next_invoice_at' => $subscription === null ? null : match ($subscription->status->value) {
                'trialing' => $subscription->getAttribute('trial_ends_at')?->toIso8601String(),
                'active' => (bool) $subscription->getAttribute('cancel_at_period_end') ? null : $subscription->current_period_end->toIso8601String(),
                default => null,
            },
            'invoices' => $this->invoices->forTenant($tenant),
            'payments' => $this->payments->forTenant($tenant),
            'dunning' => $this->dunning->forTenant($tenant, $now),
        ];
    }

    /**
     * The same `TenantSubscription` shape `TenantOverview::detail()` sends, so one client type serves both.
     *
     * @return array<string, mixed>
     */
    public static function subscription(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'status' => $subscription->status->value,
            'plan_code' => $subscription->plan->code,
            'plan_name' => $subscription->plan->name,
            'billing_cycle' => $subscription->billing_cycle->value,
            'price_paisa' => $subscription->price_paisa,
            'current_period_start' => $subscription->current_period_start->toIso8601String(),
            'current_period_end' => $subscription->current_period_end->toIso8601String(),
            'trial_ends_at' => $subscription->getAttribute('trial_ends_at')?->toIso8601String(),
            'grace_until' => $subscription->getAttribute('grace_until')?->toIso8601String(),
            'auto_renew' => (bool) $subscription->getAttribute('auto_renew'),
            'cancel_at_period_end' => (bool) $subscription->getAttribute('cancel_at_period_end'),
            'feature_overrides' => $subscription->feature_overrides,
        ];
    }
}
