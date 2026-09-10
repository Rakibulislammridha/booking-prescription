<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Events\DunningNoticeDue;
use App\Domain\SaaS\Events\TenantAutoSuspended;
use App\Domain\SaaS\Queries\BillingDunningQueue;
use App\Domain\SaaS\Support\DunningSchedule;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The dunning queue is a PREVIEW of `saas:dun`, and this proves it: for a ladder of invoices at every interesting
 * age the preview says what will happen, the command is run, and the rows are exactly what the preview said —
 * step for step, suspension for suspension — after which the preview has nothing left to announce.
 * "Run now for this clinic" does that clinic's slice through the same Actions, under the operator's name.
 */
final class BillingDunningQueueTest extends TestCase
{
    public function test_the_preview_matches_what_the_command_then_does_and_is_empty_afterwards(): void
    {
        Event::fake([DunningNoticeDue::class, TenantAutoSuspended::class]);
        $now = CarbonImmutable::now();
        $plan = Plan::query()->where('code', 'pro')->firstOrFail();

        // Four clinics, four rungs of the ladder.
        $fresh = $this->overdueClinic($plan, dueDaysAgo: 0, recordedStep: 0);                       // step 1 due, none sent   → reminder 1
        $second = $this->overdueClinic($plan, dueDaysAgo: 4, recordedStep: 1);                      // step 2 due, 1 sent      → reminder 2
        $quiet = $this->overdueClinic($plan, dueDaysAgo: 8, recordedStep: 3);                       // final already sent      → nothing
        $expired = $this->overdueClinic($plan, dueDaysAgo: DunningSchedule::GRACE_DAYS + 1, recordedStep: 3); // grace over → suspend
        $notDue = $this->overdueClinic($plan, dueDaysAgo: -3, recordedStep: 0);                     // not due yet             → not on the ladder

        $queue = app(BillingDunningQueue::class);
        $preview = collect($queue->preview($now))->keyBy(fn (array $g): string => (string) $g['tenant']['slug']);

        $this->assertArrayNotHasKey($notDue->slug, $preview->all());
        $this->assertSame(1, $preview[$fresh->slug]['invoices'][0]['due_step']);
        $this->assertTrue($preview[$fresh->slug]['invoices'][0]['will_notify']);
        $this->assertFalse($preview[$fresh->slug]['will_suspend']);
        $this->assertSame(2, $preview[$second->slug]['invoices'][0]['due_step']);
        $this->assertTrue($preview[$second->slug]['invoices'][0]['will_notify']);
        $this->assertFalse($preview[$quiet->slug]['invoices'][0]['will_notify']);
        $this->assertFalse($preview[$quiet->slug]['will_suspend']);
        $this->assertTrue($preview[$expired->slug]['will_suspend']);
        $this->assertFalse($preview[$expired->slug]['invoices'][0]['will_notify'], 'final notice already sent; only the suspension is left');
        $this->assertSame($expired->slug, $preview->keys()->first(), 'suspensions sort first');

        $totals = $queue->totals($now);
        $this->assertSame(4, $totals['tenants']);
        $this->assertSame(2, $totals['notices']);
        $this->assertSame(1, $totals['suspensions']);

        // The console page shows the same thing.
        $this->actingAsSuper();
        $this->get(route('super.billing.dunning.index', absolute: false))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Billing/Dunning')
                ->where('totals.notices', 2)
                ->where('totals.suspensions', 1)
                ->where('queue.0.tenant.slug', $expired->slug)
                ->where('schedule.grace_days', DunningSchedule::GRACE_DAYS));

        // Now the real sweep.
        $this->artisan('saas:dun')->assertSuccessful();

        foreach ([$fresh, $second, $quiet, $expired] as $tenant) {
            $expected = $preview[$tenant->slug]['invoices'][0];
            $invoice = SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->firstOrFail();
            $this->assertSame(max((int) $expected['dunning_step'], (int) $expected['due_step']), (int) $invoice->getAttribute('dunning_step'), $tenant->slug);
            $this->assertSame($expected['will_suspend'] ? TenantStatus::Suspended : TenantStatus::PastDue, $tenant->refresh()->status, $tenant->slug);
        }
        $this->assertSame(TenantStatus::PastDue, $notDue->refresh()->status);
        Event::assertDispatchedTimes(DunningNoticeDue::class, 2);
        Event::assertDispatchedTimes(TenantAutoSuspended::class, 1);

        // Nothing left to announce — and a second sweep agrees.
        $after = $queue->totals($now);
        $this->assertSame(0, $after['notices']);
        $this->assertSame(0, $after['suspensions']);
        $this->artisan('saas:dun')->assertSuccessful();
        Event::assertDispatchedTimes(DunningNoticeDue::class, 2);
    }

    public function test_run_now_for_one_clinic_does_that_clinics_share_of_the_sweep_under_the_operators_name(): void
    {
        Event::fake([DunningNoticeDue::class, TenantAutoSuspended::class]);
        $plan = Plan::query()->where('code', 'pro')->firstOrFail();
        $mine = $this->overdueClinic($plan, dueDaysAgo: 4, recordedStep: 0);          // steps 1 and 2 are both due → one notice at step 2
        $theirs = $this->overdueClinic($plan, dueDaysAgo: 4, recordedStep: 0);
        $gone = $this->overdueClinic($plan, dueDaysAgo: DunningSchedule::GRACE_DAYS + 2, recordedStep: 3);

        $admin = $this->actingAsSuper();

        $this->post(route('super.billing.dunning.run', ['tenant' => $mine->public_id], false))->assertRedirect()->assertSessionHas('flash.success');
        $this->assertSame(2, (int) SubscriptionInvoice::query()->where('tenant_id', $mine->id)->value('dunning_step'));
        $this->assertSame(0, (int) SubscriptionInvoice::query()->where('tenant_id', $theirs->id)->value('dunning_step'), 'only this clinic');
        $this->assertSame(TenantStatus::PastDue, $mine->refresh()->status);
        Event::assertDispatchedTimes(DunningNoticeDue::class, 1);

        // Pressing it again sends nothing twice.
        $this->post(route('super.billing.dunning.run', ['tenant' => $mine->public_id], false))->assertRedirect();
        Event::assertDispatchedTimes(DunningNoticeDue::class, 1);

        // A clinic past its grace is suspended, and the subscriptions-row button is the same action.
        $this->post(route('super.billing.subscriptions.dun', ['tenant' => $gone->public_id], false))->assertRedirect()->assertSessionHas('flash.warning');
        $this->assertSame(TenantStatus::Suspended, $gone->refresh()->status);
        $this->assertSame(SubscriptionStatus::Suspended, Subscription::query()->findOrFail($gone->current_subscription_id)->status);
        Event::assertDispatchedTimes(TenantAutoSuspended::class, 1);

        $runs = AuditLogCentral::query()->where('super_admin_id', $admin->id)->where('action', CentralAuditAction::Update->value)
            ->whereIn('tenant_id', [$mine->id, $gone->id])->get()->filter(fn (AuditLogCentral $l) => ($l->getAttribute('after')['dunning_run'] ?? null) === 'manual');
        $this->assertCount(3, $runs);
        $this->assertTrue(AuditLogCentral::query()->where('tenant_id', $gone->id)->where('action', CentralAuditAction::Suspend->value)->where('super_admin_id', $admin->id)->exists());
    }

    private function overdueClinic(Plan $plan, int $dueDaysAgo, int $recordedStep): Tenant
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::PastDue]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::PastDue,
            'price_paisa' => 400000,
            'current_period_start' => CarbonImmutable::now()->subDays(30),
            'current_period_end' => CarbonImmutable::now()->addDays(1),
            'trial_ends_at' => null,
        ]);
        $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

        $dueAt = CarbonImmutable::now()->subDays($dueDaysAgo)->subMinute();

        SubscriptionInvoice::query()->create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'number' => 'SI-DUN-'.$tenant->id,
            'status' => $recordedStep > 0 ? SubscriptionInvoiceStatus::Overdue : SubscriptionInvoiceStatus::Issued,
            'period_start' => $dueAt->subDays(7)->toDateString(),
            'period_end' => $dueAt->addDays(23)->toDateString(),
            'subtotal_paisa' => 400000,
            'total_paisa' => 400000,
            'paid_paisa' => 0,
            'line_items' => [['description' => 'Pro · monthly', 'quantity' => 1, 'unit_paisa' => 400000, 'total_paisa' => 400000, 'feature_key' => null]],
            'issued_at' => $dueAt->subDays(7),
            'due_at' => $dueAt,
            'dunning_step' => $recordedStep,
        ]);

        return $tenant;
    }
}
