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
 * Per-tenant module toggle from the super console.
 *
 * The durable truth is `subscriptions.feature_overrides` (SCHEMA §2.4: "overrides win over plan_features"), NOT
 * the Pennant row — because `public.feature_flags` holds resolved values and is purged whenever a plan changes,
 * and a negotiated exception ("this hospital gets telemedicine at no extra charge") must survive that. Pennant
 * stays the read API the rest of the app already uses (`Feature::active('telemedicine')`); this action writes the
 * override and drops the cached value so the next read re-resolves.
 *
 * `$enabled = null` removes the override: back to whatever the plan says.
 */
final class ToggleTenantFeature
{
    public function __construct(
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly FeatureFlagCache $flags,
        private readonly CentralAudit $audit,
    ) {}

    public function handle(Tenant $tenant, PlanFeatureKey $key, ?bool $enabled): void
    {
        $subscription = $this->lifecycle->current($tenant);

        if ($subscription === null) {
            return;
        }

        /** @var array<string, mixed> $overrides */
        $overrides = (array) $subscription->feature_overrides;
        $before = $overrides[$key->value] ?? null;

        if ($enabled === null) {
            unset($overrides[$key->value]);
        } else {
            $existing = is_array($before) ? $before : [];
            $overrides[$key->value] = $existing + ['enabled' => $enabled];
            $overrides[$key->value]['enabled'] = $enabled;
        }

        $subscription->forceFill(['feature_overrides' => $overrides])->save();
        $this->flags->forTenant($tenant);

        $this->audit->record(
            CentralAuditAction::SettingsChange,
            $tenant,
            $subscription,
            [$key->value => $before],
            [$key->value => $overrides[$key->value] ?? null],
        );
    }
}
