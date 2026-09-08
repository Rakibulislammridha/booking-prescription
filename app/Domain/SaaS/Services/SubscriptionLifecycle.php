<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;

/**
 * The subscription state machine, and the ONE place `tenants.status` is written.
 *
 *      trialing ──(trial ends, free plan)──────────────────────────────► active
 *          │                                                              │  ▲
 *          └──(trial ends, paid plan → first invoice issued)──► past_due ◄─┘  │
 *                                                                 │           │ payment clears the arrears
 *      active ──(invoice passes due_at unpaid)──────────────────► past_due    │
 *                                                                 │           │
 *                                                  grace expires   ▼           │
 *                                                             suspended ───────┘
 *      any ──(customer or super admin cancels)──────────────────► cancelled
 *      active ──(cancel_at_period_end reached)──────────────────► expired
 *
 * `tenants.status` mirrors the CURRENT subscription, because that is the value
 * `App\Tenancy\Http\Middleware\EnsureTenantIsActive` reads on every single request (ARCHITECTURE §4):
 *
 *      trial / active  → served normally
 *      past_due        → served, with the dunning banner flashed into the session
 *      suspended       → 402 and the site `Suspended` page (JSON: 402 `tenancy.suspended`)
 *      cancelled       → 404, the tenant is gone as far as the internet is concerned
 *
 * An `expired` subscription maps to a SUSPENDED tenant, not a cancelled one: the clinic's data is intact and one
 * payment brings it back, so it must see the Suspended page (which explains how to pay) rather than a 404.
 *
 * Add-on subscriptions never move the tenant: only `tenants.current_subscription_id` does.
 */
final class SubscriptionLifecycle
{
    public function __construct(private readonly FeatureFlagCache $flags) {}

    public static function tenantStatusFor(SubscriptionStatus $status): TenantStatus
    {
        return match ($status) {
            SubscriptionStatus::Trialing => TenantStatus::Trial,
            SubscriptionStatus::Active => TenantStatus::Active,
            SubscriptionStatus::PastDue => TenantStatus::PastDue,
            SubscriptionStatus::Suspended, SubscriptionStatus::Expired => TenantStatus::Suspended,
            SubscriptionStatus::Cancelled => TenantStatus::Cancelled,
        };
    }

    /** @param  array<string, mixed>  $attributes */
    public function transition(Subscription $subscription, SubscriptionStatus $to, array $attributes = []): Subscription
    {
        $subscription->forceFill(['status' => $to] + $attributes)->save();

        $tenant = $subscription->tenant;

        if ($tenant->current_subscription_id === $subscription->id) {
            $this->syncTenant($tenant, $to);
        }

        $this->flags->forTenantIds([$subscription->tenant_id]);

        return $subscription;
    }

    /** Mirrors the subscription onto the tenant row, including the two suspension columns SCHEMA §2.1 defines. */
    public function syncTenant(Tenant $tenant, SubscriptionStatus $status, ?string $reason = null): Tenant
    {
        $tenantStatus = self::tenantStatusFor($status);
        $suspending = $tenantStatus === TenantStatus::Suspended;

        $tenant->forceFill([
            'status' => $tenantStatus,
            'suspended_at' => $suspending ? ($tenant->suspended_at ?? CarbonImmutable::now()) : null,
            'suspension_reason' => $suspending ? ($reason ?? $tenant->suspension_reason ?? 'saas.suspension.unpaid') : null,
        ])->save();

        return $tenant;
    }

    /** The subscription whose status the tenant follows; null for a tenant with no current subscription. */
    public function current(Tenant $tenant): ?Subscription
    {
        if ($tenant->current_subscription_id !== null) {
            $subscription = Subscription::query()->find($tenant->current_subscription_id);

            if ($subscription !== null) {
                return $subscription;
            }
        }

        return Subscription::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [
                SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value, SubscriptionStatus::Suspended->value,
            ])
            ->orderBy('id')
            ->first();
    }

    /** One billing period forward from `$from`, honouring the cycle the customer bought. */
    public function nextPeriodEnd(Subscription $subscription, CarbonImmutable $from): CarbonImmutable
    {
        return $subscription->billing_cycle->value === 'yearly' ? $from->addYear() : $from->addMonth();
    }

    /** The price for one period at the subscription's locked rate. */
    public function periodPricePaisa(Subscription $subscription): int
    {
        return max(0, $subscription->price_paisa);
    }
}
