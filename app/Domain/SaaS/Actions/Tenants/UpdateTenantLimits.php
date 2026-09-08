<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Tenants;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\FeatureFlagCache;
use App\Domain\SaaS\Services\SubscriptionLifecycle;
use App\Models\Central\Tenant;

/**
 * Negotiated numeric caps for one tenant — "Pro, but 12 doctors" — written to `subscriptions.feature_overrides`.
 *
 * The input is `feature_key => int|null`, where an absent key removes the override entirely and a present `null`
 * means UNLIMITED. Those two have to be distinguishable, which is why the caller passes a sparse map of what it
 * is changing rather than the whole table.
 */
final class UpdateTenantLimits
{
    public function __construct(
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly FeatureFlagCache $flags,
        private readonly CentralAudit $audit,
    ) {}

    /**
     * @param  array<string, int|null>  $limits  set a cap (null = unlimited)
     * @param  array<int, string>  $clear  keys to hand back to the plan
     */
    public function handle(Tenant $tenant, array $limits, array $clear = []): void
    {
        $subscription = $this->lifecycle->current($tenant);

        if ($subscription === null) {
            return;
        }

        /** @var array<string, mixed> $overrides */
        $overrides = (array) $subscription->feature_overrides;
        $before = $overrides;

        foreach ($clear as $key) {
            unset($overrides[$key]);
        }

        foreach ($limits as $key => $value) {
            if (PlanFeatureKey::tryFrom($key)?->isToggle() !== false) {
                continue;                                          // toggles go through ToggleTenantFeature
            }

            $overrides[$key] = ['limit_value' => $value === null ? null : max(0, $value), 'enabled' => true];
        }

        $subscription->forceFill(['feature_overrides' => $overrides])->save();
        $this->flags->forTenant($tenant);

        $this->audit->record(CentralAuditAction::SettingsChange, $tenant, $subscription, $before, $overrides);
    }
}
