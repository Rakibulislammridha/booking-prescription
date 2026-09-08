<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Subscriptions;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Events\TenantReactivated;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\FeatureFlagCache;
use App\Domain\SaaS\Services\SubscriptionLifecycle;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Back to `active` — on payment, or because a super admin says so.
 *
 * A payment only reactivates when it clears the ARREARS: an invoice paid while an older one is still overdue
 * leaves the clinic past_due, which is the only reading that keeps the ladder honest. `$force` is the super
 * admin's override for the cases money cannot express (a goodwill period, a billing dispute).
 */
final class ReactivateTenant
{
    public function __construct(
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly CentralAudit $audit,
        private readonly FeatureFlagCache $flags,
    ) {}

    public function handle(Tenant $tenant, string $reason, bool $force = false): Tenant
    {
        if (! $force && $this->hasArrears($tenant)) {
            return $tenant;
        }

        if (in_array($tenant->status, [TenantStatus::Active, TenantStatus::Trial], true)) {
            return $tenant;
        }

        $before = ['status' => $tenant->status->value];

        DB::connection('pgsql')->transaction(function () use ($tenant): void {
            $subscription = $this->lifecycle->current($tenant);
            $now = CarbonImmutable::now();

            if ($subscription !== null) {
                $attributes = ['status' => SubscriptionStatus::Active, 'grace_until' => null];

                // An expired or long-suspended subscription needs a live window again, or the renewal sweep
                // would immediately bill it for a period that is already in the past.
                if ($subscription->current_period_end->lessThanOrEqualTo($now)) {
                    $attributes['current_period_start'] = $now;
                    $attributes['current_period_end'] = $this->lifecycle->nextPeriodEnd($subscription, $now);
                }

                $subscription->forceFill($attributes)->save();
            }

            $tenant->forceFill([
                'status' => TenantStatus::Active,
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();
        });

        $this->flags->forTenant($tenant);
        $this->audit->record(CentralAuditAction::Reactivate, $tenant, $tenant, $before, ['status' => TenantStatus::Active->value, 'reason' => $reason]);

        TenantReactivated::dispatch($tenant->id, $reason);

        return $tenant;
    }

    public function hasArrears(Tenant $tenant): bool
    {
        return SubscriptionInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [SubscriptionInvoiceStatus::Issued->value, SubscriptionInvoiceStatus::Overdue->value])
            ->whereNotNull('due_at')
            ->where('due_at', '<', CarbonImmutable::now())
            ->exists();
    }
}
