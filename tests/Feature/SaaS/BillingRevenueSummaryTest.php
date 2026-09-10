<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\SaaS\Enums\BillingCycle;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionPaymentMethod;
use App\Domain\SaaS\Enums\SubscriptionPaymentStatus;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Queries\PlatformRevenueSummary;
use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\SubscriptionPayment;
use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The platform's own numbers against a fixture whose answer is known by hand: MRR from active + past_due
 * subscriptions by cycle (yearly ÷ 12, integer), ARR from the same rows, overdue = issued & past due & unpaid,
 * collected = succeeded payments this month. The shared fixture tenants exist too, so every assertion is a DELTA
 * over the baseline read before the fixture is built.
 */
final class BillingRevenueSummaryTest extends TestCase
{
    public function test_mrr_arr_overdue_and_collected_are_computed_from_the_rows_in_integer_paisa(): void
    {
        $query = app(PlatformRevenueSummary::class);
        $before = $query->summary();
        $plan = Plan::query()->where('code', 'pro')->firstOrFail();

        $monthly = $this->clinic($plan, SubscriptionStatus::Active, BillingCycle::Monthly, 400000);
        $yearly = $this->clinic($plan, SubscriptionStatus::Active, BillingCycle::Yearly, 4000000);   // 4000000 / 12 = 333333 r4
        $late = $this->clinic($plan, SubscriptionStatus::PastDue, BillingCycle::Monthly, 150000);
        $this->clinic($plan, SubscriptionStatus::Trialing, BillingCycle::Monthly, 400000);        // trialing: no revenue yet
        $this->clinic($plan, SubscriptionStatus::Suspended, BillingCycle::Monthly, 400000);       // suspended: left MRR

        // Overdue: issued, past due, 300000 of which 50000 has been paid → 250000 owed.
        $overdue = $this->invoice($late, 300000, SubscriptionInvoiceStatus::Overdue, CarbonImmutable::now()->subDays(5), paid: 50000);
        // Outstanding but not yet due: 100000.
        $this->invoice($monthly, 100000, SubscriptionInvoiceStatus::Issued, CarbonImmutable::now()->addDays(5));
        // Paid in full: contributes nothing to arrears.
        $paid = $this->invoice($yearly, 4000000, SubscriptionInvoiceStatus::Paid, CarbonImmutable::now()->subDays(20), paid: 4000000);
        // Void: never counted.
        $this->invoice($monthly, 999999, SubscriptionInvoiceStatus::Void, CarbonImmutable::now()->subDays(1));

        // Payments: two succeeded this month, one pending (not money), one failed (not money).
        $this->payment($overdue, 50000, SubscriptionPaymentStatus::Succeeded);
        $this->payment($paid, 4000000, SubscriptionPaymentStatus::Succeeded);
        $this->payment($overdue, 10000, SubscriptionPaymentStatus::Pending);
        $this->payment($overdue, 10000, SubscriptionPaymentStatus::Failed);

        $after = $query->summary();

        $this->assertSame(400000 + 333333 + 150000, $after['mrr_paisa'] - $before['mrr_paisa'], 'monthly at face value, yearly ÷ 12 by integer division, past_due still counted');
        $this->assertSame(400000 * 12 + 4000000 + 150000 * 12, $after['arr_paisa'] - $before['arr_paisa']);
        $this->assertSame(3, $after['recurring_subscriptions'] - $before['recurring_subscriptions']);
        $this->assertSame(2, $after['active'] - $before['active']);
        $this->assertSame(1, $after['past_due'] - $before['past_due']);
        $this->assertSame(1, $after['trialing'] - $before['trialing']);
        $this->assertSame(1, $after['suspended'] - $before['suspended']);
        $this->assertSame(250000, $after['overdue_paisa'] - $before['overdue_paisa'], 'issued & past due & unpaid, net of the partial payment');
        $this->assertSame(1, $after['overdue_invoices'] - $before['overdue_invoices']);
        $this->assertSame(250000 + 100000, $after['outstanding_paisa'] - $before['outstanding_paisa']);
        $this->assertSame(4050000, $after['collected_month_paisa'] - $before['collected_month_paisa'], 'only succeeded payments are money');
        $this->assertSame(2, $after['collected_month_payments'] - $before['collected_month_payments']);

        foreach ($after as $key => $value) {
            if ($key !== 'month') {
                $this->assertIsInt($value, "{$key} must be an integer, never a float");
            }
        }

        // The arrears list names the late clinic first with the net amount.
        $arrears = collect($query->arrearsByTenant(50));
        $this->assertSame(250000, $arrears->firstWhere('slug', $late->slug)['arrears_paisa']);
        $this->assertNull($arrears->firstWhere('slug', $yearly->slug), 'a fully paid clinic is not in arrears');

        // Collected by month ends on the current month and carries this month's two payments.
        $months = $query->collectedByMonth(3);
        $this->assertCount(3, $months);
        $this->assertSame(CarbonImmutable::now('Asia/Dhaka')->format('Y-m'), $months[2]['period']);
        $this->assertGreaterThanOrEqual(4050000, $months[2]['collected_paisa']);
    }

    public function test_the_overview_screen_renders_the_summary_and_leaves_no_tenancy_behind(): void
    {
        $this->actingAsSuper();

        $this->get(route('super.billing.index', absolute: false))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Billing/Index')
                ->has('summary.mrr_paisa')
                ->has('summary.arr_paisa')
                ->has('summary.overdue_paisa')
                ->has('summary.collected_month_paisa')
                ->has('summary.active')
                ->has('summary.trialing')
                ->has('summary.past_due')
                ->has('summary.suspended')
                ->has('months.5.period')
                ->has('arrears')
                ->has('dunning.tenants'));

        $this->assertFalse(Tenancy::check());
    }

    private function clinic(Plan $plan, SubscriptionStatus $status, BillingCycle $cycle, int $price): Tenant
    {
        $tenant = Tenant::factory()->create(['status' => match ($status) {
            SubscriptionStatus::Trialing => TenantStatus::Trial,
            SubscriptionStatus::Active => TenantStatus::Active,
            SubscriptionStatus::PastDue => TenantStatus::PastDue,
            default => TenantStatus::Suspended,
        }]);

        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'billing_cycle' => $cycle,
            'price_paisa' => $price,
            'current_period_start' => CarbonImmutable::now()->subDays(10),
            'current_period_end' => CarbonImmutable::now()->addDays(20),
            'trial_ends_at' => $status === SubscriptionStatus::Trialing ? CarbonImmutable::now()->addDays(4) : null,
        ]);

        $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

        return $tenant;
    }

    private function invoice(Tenant $tenant, int $total, SubscriptionInvoiceStatus $status, CarbonImmutable $dueAt, int $paid = 0): SubscriptionInvoice
    {
        return SubscriptionInvoice::query()->create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $tenant->current_subscription_id,
            'number' => 'SI-RS-'.$tenant->id.'-'.random_int(1000, 9999),
            'status' => $status,
            'period_start' => $dueAt->subDays(7)->toDateString(),
            'period_end' => $dueAt->addDays(23)->toDateString(),
            'subtotal_paisa' => $total,
            'total_paisa' => $total,
            'paid_paisa' => $paid,
            'line_items' => [['description' => 'Pro · monthly', 'quantity' => 1, 'unit_paisa' => $total, 'total_paisa' => $total, 'feature_key' => null]],
            'issued_at' => $status === SubscriptionInvoiceStatus::Draft ? null : $dueAt->subDays(7),
            'due_at' => $status === SubscriptionInvoiceStatus::Draft ? null : $dueAt,
            'paid_at' => $status === SubscriptionInvoiceStatus::Paid ? CarbonImmutable::now() : null,
            'voided_at' => $status === SubscriptionInvoiceStatus::Void ? CarbonImmutable::now() : null,
            'dunning_step' => 0,
        ]);
    }

    private function payment(SubscriptionInvoice $invoice, int $amount, SubscriptionPaymentStatus $status): SubscriptionPayment
    {
        return SubscriptionPayment::query()->create([
            'tenant_id' => $invoice->tenant_id,
            'subscription_invoice_id' => $invoice->id,
            'method' => SubscriptionPaymentMethod::BankTransfer,
            'status' => $status,
            'amount_paisa' => $amount,
            'gateway_payload' => [],
            'paid_at' => $status === SubscriptionPaymentStatus::Succeeded ? CarbonImmutable::now() : null,
        ]);
    }
}
