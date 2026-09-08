<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Subscriptions;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Events\TenantAutoSuspended;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\FeatureFlagCache;
use App\Domain\SaaS\Services\SubscriptionLifecycle;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Suspension — from the dunning sweep (grace expired) or from a super admin's hand.
 *
 * What it does to the clinic is entirely `EnsureTenantIsActive`'s existing behaviour: every request, panel and
 * public site alike, answers 402 with the `Suspended` page; JSON callers get `{"code":"tenancy.suspended"}`. The
 * schema is untouched, the queue keeps its data, and one payment reverses it.
 */
final class SuspendTenant
{
    public function __construct(
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly CentralAudit $audit,
        private readonly FeatureFlagCache $flags,
    ) {}

    public function handle(Tenant $tenant, string $reason, ?int $invoiceId = null, bool $automatic = false): Tenant
    {
        if ($tenant->status === TenantStatus::Suspended) {
            return $tenant;
        }

        $before = ['status' => $tenant->status->value];

        DB::connection('pgsql')->transaction(function () use ($tenant, $reason): void {
            $subscription = $this->lifecycle->current($tenant);

            if ($subscription !== null) {
                $subscription->forceFill(['status' => SubscriptionStatus::Suspended])->save();
            }

            $tenant->forceFill([
                'status' => TenantStatus::Suspended,
                'suspended_at' => CarbonImmutable::now(),
                'suspension_reason' => mb_substr($reason, 0, 255),
            ])->save();
        });

        $this->flags->forTenant($tenant);
        $this->audit->record(CentralAuditAction::Suspend, $tenant, $tenant, $before, ['status' => TenantStatus::Suspended->value, 'reason' => $reason]);

        if ($automatic && $invoiceId !== null) {
            TenantAutoSuspended::dispatch($tenant->id, $invoiceId);
        }

        return $tenant;
    }
}
