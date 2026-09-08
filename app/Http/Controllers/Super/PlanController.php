<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super;

use App\Domain\SaaS\Actions\Plans\ArchivePlan;
use App\Domain\SaaS\Actions\Plans\SavePlan;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Queries\PlanCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\SaaS\SavePlanRequest;
use App\Models\Central\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plan CRUD (BRIEF §5.M: "Plan tiers with enforced limits"). Editing here is what the pricing page shows AND what
 * `PlanLimits` enforces — one table, no second copy — so a limit cannot be advertised and not enforced.
 */
final class PlanController extends Controller
{
    public function index(PlanCatalog $catalog, ArchivePlan $archive): Response
    {
        $usage = Plan::query()->orderBy('sort_order')->get()
            ->mapWithKeys(fn (Plan $p) => [$p->code => $archive->liveSubscriptions($p)])->all();

        return Inertia::render('Super/Plans/Index', [
            'plans' => $catalog->all(),
            'subscriptions' => $usage,
            'feature_labels' => PlanCatalog::featureLabels(),
            'limit_keys' => array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::limits()),
            'toggle_keys' => array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::toggles()),
        ]);
    }

    public function store(SavePlanRequest $request, SavePlan $save): RedirectResponse
    {
        $plan = $save->handle($request->toData());

        return redirect()->route('super.plans.index')->with('flash.success', __('saas.plans.flash.created', ['plan' => $plan->name]));
    }

    public function update(SavePlanRequest $request, Plan $plan, SavePlan $save): RedirectResponse
    {
        $save->handle($request->toData(), $plan);

        return redirect()->route('super.plans.index')->with('flash.success', __('saas.plans.flash.updated', ['plan' => $plan->name]));
    }

    public function destroy(Request $request, Plan $plan, ArchivePlan $archive): RedirectResponse
    {
        $archive->handle($plan, ! $request->boolean('restore'));

        return redirect()->route('super.plans.index')->with('flash.warning', __('saas.plans.flash.archived', ['plan' => $plan->name]));
    }
}
