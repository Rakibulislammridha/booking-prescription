<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Models\Central\Plan;
use App\Models\Central\PlanFeature;
use Illuminate\Support\Facades\DB;

/**
 * One shape for a plan, used by the pricing page, the sign-up wizard, the tenant's own subscription screen and
 * the super console — so a limit shown to a prospect is literally the same row that will be enforced against
 * them. The pricing page is "driven by real plan rows" in the strongest sense: there is no marketing copy of the
 * plan table anywhere.
 */
final class PlanCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function publicPlans(): array
    {
        $plans = Plan::query()
            ->with('features')
            ->whereNull('archived_at')
            ->where('is_public', true)
            ->where('is_addon', false)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        // The middle paid tier is the one most clinics should buy; highlighting the cheapest or the dearest is
        // either a race to the bottom or a wall.
        $paid = $plans->filter(fn (Plan $p) => $p->price_monthly_paisa > 0)->values();
        $featured = $paid->count() > 1 ? $paid[(int) floor(($paid->count() - 1) / 2)]->code : (string) $plans->first()?->code;

        return $plans->map(fn (Plan $plan) => $this->present($plan, $plan->code === $featured))->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function addons(): array
    {
        return Plan::query()
            ->with('features')
            ->whereNull('archived_at')
            ->where('is_public', true)
            ->where('is_addon', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (Plan $plan) => $this->present($plan, false))
            ->all();
    }

    /**
     * Every plan, archived included — the super console's plan list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return Plan::query()->with('features')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (Plan $plan) => $this->present($plan, false) + [
                'id' => $plan->id,
                'is_public' => $plan->is_public,
                'sort_order' => $plan->sort_order,
                'archived_at' => $plan->archived_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * One plan for the console's editor: `present()` plus the console-only columns — the same shape `all()`
     * returns per row, so the list and the editor agree on every field.
     *
     * @return array<string, mixed>
     */
    public function forConsole(Plan $plan): array
    {
        $plan->loadMissing('features');

        return $this->present($plan, false) + [
            'id' => $plan->id,
            'is_public' => $plan->is_public,
            'sort_order' => $plan->sort_order,
            'archived_at' => $plan->archived_at?->toIso8601String(),
        ];
    }

    /**
     * Live subscribers per plan code (trialing / active / past_due / suspended — the statuses under which a
     * clinic still depends on the plan's rows), in ONE grouped query for the whole list.
     *
     * @return array<string, int>
     */
    public function liveSubscriberCounts(): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::connection('pgsql')->table('public.subscriptions as s')
            ->join('public.plans as p', 'p.id', '=', 's.plan_id')
            ->whereIn('s.status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::Suspended->value])
            ->selectRaw('p.code, count(*) as total')
            ->groupBy('p.code')
            ->pluck('total', 'p.code')
            ->map(fn ($v): int => (int) $v)
            ->all();

        return $counts;
    }

    /** @return array<string, mixed> */
    public function present(Plan $plan, bool $featured = false): array
    {
        /** @var array<string, PlanFeature> $features */
        $features = $plan->features->keyBy(fn (PlanFeature $f) => $f->feature_key->value)->all();

        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'price_monthly_paisa' => $plan->price_monthly_paisa,
            'price_yearly_paisa' => $plan->price_yearly_paisa,
            'trial_days' => $plan->trial_days,
            'is_addon' => $plan->is_addon,
            'is_featured' => $featured,
            'limits' => array_map(fn (PlanFeatureKey $key): array => [
                'key' => $key->value,
                'value' => isset($features[$key->value]) && $features[$key->value]->enabled ? $features[$key->value]->limit_value : (isset($features[$key->value]) ? 0 : null),
                'is_bytes' => $key === PlanFeatureKey::StorageBytes,
            ], PlanFeatureKey::limits()),
            'toggles' => array_map(fn (PlanFeatureKey $key): array => [
                'key' => $key->value,
                'enabled' => isset($features[$key->value]) && $features[$key->value]->enabled,
            ], PlanFeatureKey::toggles()),
        ];
    }

    /**
     * Translated labels for every feature key, so the client never builds a `saas.feature.*` key by hand
     * (a dynamic `t()` key is invisible to `lang:check` and renders as the raw key in production).
     *
     * @return array<string, string>
     */
    public static function featureLabels(): array
    {
        $labels = [];

        foreach (PlanFeatureKey::cases() as $key) {
            $labels[$key->value] = (string) __('saas.feature.'.$key->value);
        }

        return $labels;
    }

    /** @return array<string, string> */
    public static function metricLabels(): array
    {
        $labels = [];

        foreach (UsageMetric::cases() as $metric) {
            $labels[$metric->value] = (string) __('saas.metric.'.$metric->value);
        }

        return $labels;
    }
}
