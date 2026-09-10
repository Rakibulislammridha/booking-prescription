<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super;

use App\Domain\SaaS\Actions\Plans\ArchivePlan;
use App\Domain\SaaS\Actions\Plans\SavePlan;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Queries\PlanCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Plans\ArchivePlanRequest;
use App\Http\Requests\Super\Plans\SavePlanRequest;
use App\Models\Central\Plan;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plan CRUD (BRIEF §5.M: "Plan tiers with enforced limits"). Editing here is what the pricing page shows AND what
 * `PlanLimits` enforces — one table, no second copy — so a limit cannot be advertised and not enforced.
 *
 * The editor is a page rather than a dialog: thirteen feature keys, two prices, the flags and a diff-style
 * confirmation for a plan clinics are already on is a form, not a popover.
 */
final class PlanController extends Controller
{
    public function index(PlanCatalog $catalog): Response
    {
        return Inertia::render('Super/Plans/Index', [
            'plans' => $catalog->all(),
            'subscriptions' => $catalog->liveSubscriberCounts(),
            'feature_labels' => PlanCatalog::featureLabels(),
            'limit_keys' => array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::limits()),
            'toggle_keys' => array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::toggles()),
        ]);
    }

    public function create(PlanCatalog $catalog): Response
    {
        return $this->editor(null, 0, $catalog);
    }

    public function store(SavePlanRequest $request, SavePlan $save): RedirectResponse
    {
        $plan = $save->handle($request->toData());

        return redirect()->route('super.plans.index')->with('flash.success', __('saas.plans.flash.created', ['plan' => $plan->name]));
    }

    public function edit(Plan $plan, PlanCatalog $catalog, ArchivePlan $archive): Response
    {
        return $this->editor($plan, $archive->liveSubscriptions($plan), $catalog);
    }

    public function update(SavePlanRequest $request, Plan $plan, SavePlan $save): RedirectResponse
    {
        $save->handle($request->toData(), $plan);

        return redirect()->route('super.plans.index')->with('flash.success', __('saas.plans.flash.updated', ['plan' => $plan->name]));
    }

    public function destroy(ArchivePlanRequest $request, Plan $plan, ArchivePlan $archive): RedirectResponse
    {
        $archive->handle($plan, ! $request->restoring(), $request->confirmed());

        return redirect()->route('super.plans.index')->with(
            $request->restoring() ? 'flash.success' : 'flash.warning',
            __($request->restoring() ? 'saas.plans.flash.restored' : 'saas.plans.flash.archived', ['plan' => $plan->name]),
        );
    }

    private function editor(?Plan $plan, int $subscribers, PlanCatalog $catalog): Response
    {
        return Inertia::render('Super/Plans/Edit', [
            'plan' => $plan === null ? null : $catalog->forConsole($plan),
            'subscribers' => $subscribers,
            'next_sort_order' => ((int) Plan::query()->max('sort_order')) + 10,
            'feature_labels' => PlanCatalog::featureLabels(),
            'limit_keys' => array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::limits()),
            'toggle_keys' => array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::toggles()),
        ]);
    }
}
