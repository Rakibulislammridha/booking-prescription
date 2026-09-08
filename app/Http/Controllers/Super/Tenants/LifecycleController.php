<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Tenants;

use App\Domain\SaaS\Actions\Subscriptions\CancelSubscription;
use App\Domain\SaaS\Actions\Subscriptions\ChangePlan;
use App\Domain\SaaS\Actions\Subscriptions\ReactivateTenant;
use App\Domain\SaaS\Actions\Subscriptions\SuspendTenant;
use App\Domain\SaaS\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Models\Central\Plan;
use App\Models\Central\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The four buttons that move a clinic's subscription by hand. Everything they do goes through the same Actions
 * the dunning sweep uses, so a manual suspension and an automatic one leave the tenant, the subscription and the
 * audit trail in exactly the same state.
 */
final class LifecycleController extends Controller
{
    public function suspend(Request $request, Tenant $tenant, SuspendTenant $suspend): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $suspend->handle($tenant, (string) $validated['reason']);

        return back()->with('flash.warning', __('saas.tenants.flash.suspended', ['clinic' => $tenant->name]));
    }

    public function reactivate(Request $request, Tenant $tenant, ReactivateTenant $reactivate): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255'], 'force' => ['boolean']]);
        $reactivate->handle($tenant, (string) ($validated['reason'] ?? 'saas.reactivate.manual'), $request->boolean('force'));

        return back()->with('flash.success', __('saas.tenants.flash.reactivated', ['clinic' => $tenant->name]));
    }

    public function cancel(Request $request, Tenant $tenant, CancelSubscription $cancel): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255'], 'immediately' => ['boolean']]);
        $cancel->handle($tenant, (string) $validated['reason'], $request->boolean('immediately'));

        return back()->with('flash.warning', __('saas.tenants.flash.cancelled', ['clinic' => $tenant->name]));
    }

    public function plan(Request $request, Tenant $tenant, ChangePlan $change): RedirectResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', Rule::exists(Plan::class, 'code')],
            'billing_cycle' => ['nullable', Rule::in(BillingCycle::values())],
        ]);

        $plan = Plan::query()->where('code', $validated['plan'])->firstOrFail();
        $change->handle($tenant, $plan, isset($validated['billing_cycle']) ? BillingCycle::from((string) $validated['billing_cycle']) : null);

        return back()->with('flash.success', __('saas.tenants.flash.plan_changed', ['plan' => $plan->name]));
    }
}
