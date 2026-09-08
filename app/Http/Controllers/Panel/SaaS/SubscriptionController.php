<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\SaaS;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Queries\PlanCatalog;
use App\Domain\SaaS\Services\PlanLimits;
use App\Domain\SaaS\Services\SubscriptionLifecycle;
use App\Http\Controllers\Controller;
use App\Models\Central\SubscriptionInvoice;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The clinic's own view of what it is paying for: plan, usage against every limit, and its invoices.
 *
 * The pay link is a SIGNED CENTRAL url, not a panel route, for the same reason the dunning email uses one — the
 * day this screen matters most is the day the tenant is `past_due` heading for `suspended`, and a suspended
 * tenant's panel answers 402 on everything. Handing the customer a link that keeps working is the difference
 * between churn and a payment.
 */
final class SubscriptionController extends Controller
{
    public function index(Request $request, PlanLimits $limits, SubscriptionLifecycle $lifecycle, PlanCatalog $catalog): Response
    {
        $tenant = Tenancy::current();
        abort_if($tenant === null, 404);
        $this->authorizeManage($request);

        $subscription = $lifecycle->current($tenant);
        $entitlements = $limits->entitlements($tenant);

        return Inertia::render('SaaS/Subscription', [
            'tenant' => ['name' => $tenant->name, 'status' => $tenant->status->value, 'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String()],
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->status->value,
                'plan_code' => $subscription->plan->code,
                'plan_name' => $subscription->plan->name,
                'billing_cycle' => $subscription->billing_cycle->value,
                'price_paisa' => $subscription->price_paisa,
                'current_period_end' => $subscription->current_period_end->toIso8601String(),
                'grace_until' => $subscription->getAttribute('grace_until')?->toIso8601String(),
                'cancel_at_period_end' => (bool) $subscription->getAttribute('cancel_at_period_end'),
            ],
            'entitlements' => ['plan_name' => $entitlements->planName, 'toggles' => $entitlements->toggles],
            'usage' => array_map(fn ($s) => $s->toArray(), $limits->statuses($tenant)),
            'metric_labels' => PlanCatalog::metricLabels(),
            'feature_labels' => PlanCatalog::featureLabels(),
            'plans' => $catalog->publicPlans(),
            'invoices' => SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(24)->get()
                ->map(fn (SubscriptionInvoice $i) => [
                    'public_id' => $i->public_id,
                    'number' => $i->number,
                    'status' => $i->status->value,
                    'total_paisa' => $i->total_paisa,
                    'paid_paisa' => (int) $i->getAttribute('paid_paisa'),
                    'due_paisa' => max(0, $i->total_paisa - (int) $i->getAttribute('paid_paisa')),
                    'issued_at' => $i->getAttribute('issued_at')?->toIso8601String(),
                    'due_at' => $i->getAttribute('due_at')?->toIso8601String(),
                    'pay_url' => in_array($i->status, [SubscriptionInvoiceStatus::Issued, SubscriptionInvoiceStatus::Overdue], true) && $i->total_paisa > (int) $i->getAttribute('paid_paisa')
                        ? URL::signedRoute('central.billing.invoice', ['invoice' => $i->public_id], CarbonImmutable::now()->addDays(60))
                        : null,
                ])->all(),
        ]);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user('web')?->can(Permission::SaasSettingsManage->value) === true, 403);
    }
}
