<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Billing;

use App\Domain\SaaS\Actions\Billing\RunDunningForTenant;
use App\Domain\SaaS\Actions\Subscriptions\CancelSubscription;
use App\Domain\SaaS\Actions\Subscriptions\ChangePlan;
use App\Domain\SaaS\Actions\Subscriptions\EndTrial;
use App\Domain\SaaS\Actions\Subscriptions\ReactivateTenant;
use App\Domain\SaaS\Actions\Subscriptions\SuspendTenant;
use App\Domain\SaaS\Enums\BillingCycle;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Queries\BillingSubscriptions;
use App\Domain\SaaS\Queries\PlanCatalog;
use App\Domain\SaaS\Services\SubscriptionLifecycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Billing\ChangePlanRequest;
use App\Http\Requests\Super\Billing\ReasonedActionRequest;
use App\Models\Central\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The subscriptions ledger and the per-row buttons. Every button calls the SAME Action the scheduled sweeps
 * call (`EndTrial`, `StartDunning` via `RunDunningForTenant`, `SuspendTenant`, `ReactivateTenant`,
 * `CancelSubscription`, `ChangePlan`), so a subscription moved by hand ends in exactly the state the clock would
 * have put it in — with the operator's name on the audit row instead of "system".
 *
 * Rows are addressed by TENANT (public_id): each action acts on the tenant's CURRENT subscription, which is what
 * `tenants.status` follows. Add-ons never move a tenant and have no buttons.
 */
final class SubscriptionController extends Controller
{
    public function index(Request $request, BillingSubscriptions $subscriptions, PlanCatalog $catalog): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(SubscriptionStatus::values())],
            'plan' => ['nullable', 'string', 'max:40'],
            'cycle' => ['nullable', Rule::in(BillingCycle::values())],
            'sort' => ['nullable', Rule::in(BillingSubscriptions::SORTABLE)],
        ]);

        $filters = [
            'q' => (string) ($validated['q'] ?? ''),
            'status' => (string) ($validated['status'] ?? ''),
            'plan' => (string) ($validated['plan'] ?? ''),
            'cycle' => (string) ($validated['cycle'] ?? ''),
            'sort' => (string) ($validated['sort'] ?? 'renewal'),
        ];
        $page = $subscriptions->list($filters);

        return Inertia::render('Super/Billing/Subscriptions', [
            'subscriptions' => $page['data'],
            'meta' => $page['meta'],
            'filters' => $filters,
            'statuses' => SubscriptionStatus::values(),
            'status_counts' => $subscriptions->statusCounts(),
            'cycles' => BillingCycle::values(),
            'plans' => $catalog->all(),
        ]);
    }

    public function plan(ChangePlanRequest $request, Tenant $tenant, ChangePlan $change): RedirectResponse
    {
        $plan = $request->plan();
        $change->handle($tenant, $plan, $request->cycle());

        return back()->with('flash.success', __('saas.tenants.flash.plan_changed', ['plan' => $plan->name]));
    }

    public function endTrial(Tenant $tenant, SubscriptionLifecycle $lifecycle, EndTrial $endTrial): RedirectResponse
    {
        $subscription = $lifecycle->current($tenant);

        if ($subscription === null || $subscription->status !== SubscriptionStatus::Trialing) {
            throw ValidationException::withMessages(['domain' => __('super.billing.subscriptions.not_trialing', ['clinic' => $tenant->name])]);
        }

        $endTrial->handle($subscription);

        return back()->with('flash.success', __('super.billing.subscriptions.flash.trial_ended', ['clinic' => $tenant->name]));
    }

    public function dun(Tenant $tenant, RunDunningForTenant $run): RedirectResponse
    {
        $result = $run->handle($tenant);

        return back()->with(
            $result['suspended'] ? 'flash.warning' : 'flash.success',
            __('super.billing.dunning.flash.ran', ['clinic' => $tenant->name, 'notices' => (string) $result['notices']]),
        );
    }

    public function suspend(ReasonedActionRequest $request, Tenant $tenant, SuspendTenant $suspend): RedirectResponse
    {
        $suspend->handle($tenant, $request->reason());

        return back()->with('flash.warning', __('saas.tenants.flash.suspended', ['clinic' => $tenant->name]));
    }

    public function reactivate(ReasonedActionRequest $request, Tenant $tenant, ReactivateTenant $reactivate): RedirectResponse
    {
        $reactivate->handle($tenant, $request->reason(), $request->force());

        return back()->with('flash.success', __('saas.tenants.flash.reactivated', ['clinic' => $tenant->name]));
    }

    public function cancel(ReasonedActionRequest $request, Tenant $tenant, CancelSubscription $cancel): RedirectResponse
    {
        $cancel->handle($tenant, $request->reason(), $request->immediately());

        return back()->with('flash.warning', __('saas.tenants.flash.cancelled', ['clinic' => $tenant->name]));
    }
}
