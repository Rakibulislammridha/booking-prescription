<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Plans;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Data\PlanData;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\FeatureFlagCache;
use App\Models\Central\Plan;
use App\Models\Central\PlanFeature;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a plan and REPLACE its feature rows in one transaction.
 *
 * Editing a plan changes what every tenant on it may do, so the last thing this does is purge the resolved
 * Pennant values for those tenants (`FeatureFlagCache::forPlan`). Without that, turning WhatsApp on for "Pro"
 * would reach existing Pro clinics only when their cached flag happened to be evicted — which is to say, never.
 *
 * The price of an existing subscription is NOT touched: `subscriptions.price_paisa` is the rate the customer
 * agreed to, and it changes when they change plan, not when we reprice the catalogue.
 */
final class SavePlan
{
    public function __construct(
        private readonly CentralAudit $audit,
        private readonly FeatureFlagCache $flags,
    ) {}

    public function handle(PlanData $data, ?Plan $plan = null): Plan
    {
        $before = $plan === null ? null : $plan->only(['code', 'name', 'price_monthly_paisa', 'price_yearly_paisa', 'trial_days', 'is_public', 'is_addon', 'sort_order']);

        $plan = DB::connection('pgsql')->transaction(function () use ($data, $plan): Plan {
            $plan = $plan ?? new Plan;

            $plan->forceFill([
                'code' => $data->code,
                'name' => $data->name,
                'description' => $data->description,
                'price_monthly_paisa' => max(0, $data->priceMonthlyPaisa),
                'price_yearly_paisa' => max(0, $data->priceYearlyPaisa),
                'trial_days' => max(0, $data->trialDays),
                'is_public' => $data->isPublic,
                'is_addon' => $data->isAddon,
                'sort_order' => $data->sortOrder,
            ])->save();

            $keep = [];

            foreach ($data->limits as $key => $value) {
                $feature = PlanFeatureKey::tryFrom((string) $key);

                if ($feature === null || $feature->isToggle()) {
                    continue;
                }

                PlanFeature::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'feature_key' => $feature->value],
                    ['limit_value' => $value === null ? null : max(0, $value), 'enabled' => true],
                );
                $keep[] = $feature->value;
            }

            foreach ($data->toggles as $key => $enabled) {
                $feature = PlanFeatureKey::tryFrom((string) $key);

                if ($feature === null || ! $feature->isToggle()) {
                    continue;
                }

                PlanFeature::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'feature_key' => $feature->value],
                    ['limit_value' => null, 'enabled' => $enabled],
                );
                $keep[] = $feature->value;
            }

            PlanFeature::query()->where('plan_id', $plan->id)->whereNotIn('feature_key', $keep === [] ? [''] : $keep)->delete();

            return $plan;
        });

        $this->flags->forPlan($plan->id);
        $this->audit->record($before === null ? CentralAuditAction::Create : CentralAuditAction::Update, null, $plan, $before, $plan->only(['code', 'name', 'price_monthly_paisa', 'price_yearly_paisa', 'trial_days', 'is_public', 'is_addon', 'sort_order']));

        return $plan->refresh();
    }
}
