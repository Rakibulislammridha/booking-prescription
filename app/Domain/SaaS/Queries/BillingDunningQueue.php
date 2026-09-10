<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Support\DunningSchedule;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the next `saas:dun` run will do, per invoice and per clinic, computed with the SAME selection and the
 * SAME ladder arithmetic as `DunSubscriptionsCommand`:
 *
 *   · candidates are issued/overdue invoices whose `due_at` has passed;
 *   · a reminder is due when `DunningSchedule::dueStep()` is strictly above the recorded `dunning_step`;
 *   · a suspension is due when `graceDeadline()` has passed and the tenant is not already suspended/cancelled.
 *
 * `BillingDunningQueueTest` pins the preview to the command's actual effects: every row this says will happen
 * is what the sweep then does, and nothing else.
 */
final class BillingDunningQueue
{
    /**
     * @return array<int, array<string, mixed>> one entry per clinic with anything on the ladder, most urgent first
     */
    public function preview(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $groups = [];

        foreach ($this->candidates($now)->lazyById(200) as $invoice) {
            $row = $this->row($invoice, $now);

            if ($row === null) {
                continue;
            }

            $tenant = $invoice->tenant;
            $key = $tenant->id;

            $groups[$key] ??= [
                'tenant' => [
                    'public_id' => $tenant->public_id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'status' => $tenant->status->value,
                    'owner_email' => $tenant->owner_email,
                ],
                'invoices' => [],
                'arrears_paisa' => 0,
                'will_notify' => 0,
                'will_suspend' => false,
                'grace_deadline' => null,
                'days_overdue' => 0,
            ];

            $groups[$key]['invoices'][] = $row;
            $groups[$key]['arrears_paisa'] += $row['due_paisa'];
            $groups[$key]['will_notify'] += $row['will_notify'] ? 1 : 0;
            $groups[$key]['will_suspend'] = $groups[$key]['will_suspend'] || $row['will_suspend'];
            $groups[$key]['days_overdue'] = max($groups[$key]['days_overdue'], $row['days_overdue']);

            if ($groups[$key]['grace_deadline'] === null || $row['grace_deadline'] < $groups[$key]['grace_deadline']) {
                $groups[$key]['grace_deadline'] = $row['grace_deadline'];
            }
        }

        $out = array_values($groups);

        usort($out, function (array $a, array $b): int {
            return [$b['will_suspend'], $b['days_overdue'], $b['arrears_paisa']] <=> [$a['will_suspend'], $a['days_overdue'], $a['arrears_paisa']];
        });

        return $out;
    }

    /**
     * The same preview restricted to one clinic — what "run now for this tenant" is about to do.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forTenant(Tenant $tenant, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $rows = [];

        foreach ($this->candidates($now)->where('tenant_id', $tenant->id)->get() as $invoice) {
            $row = $this->row($invoice, $now);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array{tenants: int, notices: int, suspensions: int, arrears_paisa: int} */
    public function totals(?CarbonImmutable $now = null): array
    {
        $groups = $this->preview($now);

        return [
            'tenants' => count($groups),
            'notices' => array_sum(array_column($groups, 'will_notify')),
            'suspensions' => count(array_filter($groups, fn (array $g) => (bool) $g['will_suspend'])),
            'arrears_paisa' => array_sum(array_column($groups, 'arrears_paisa')),
        ];
    }

    /**
     * Exactly `DunSubscriptionsCommand`'s candidate set.
     *
     * @return Builder<SubscriptionInvoice>
     */
    private function candidates(CarbonImmutable $now): Builder
    {
        return SubscriptionInvoice::query()
            ->with(['tenant', 'subscription'])
            ->whereIn('status', [SubscriptionInvoiceStatus::Issued->value, SubscriptionInvoiceStatus::Overdue->value])
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now)
            ->orderBy('id');
    }

    /** @return array<string, mixed>|null */
    private function row(SubscriptionInvoice $invoice, CarbonImmutable $now): ?array
    {
        $dueAt = $invoice->getAttribute('due_at');
        $tenant = $invoice->tenant;

        if (! $dueAt instanceof CarbonImmutable || $tenant === null || $tenant->getAttribute('deleted_at') !== null) {
            return null;
        }

        $recorded = (int) $invoice->getAttribute('dunning_step');
        $step = DunningSchedule::dueStep($dueAt, $now);
        $grace = DunningSchedule::graceDeadline($dueAt);
        $willNotify = $step > $recorded;
        $willSuspend = $now->greaterThanOrEqualTo($grace)
            && $tenant->status !== TenantStatus::Suspended
            && $tenant->status !== TenantStatus::Cancelled;
        $nextStepAt = null;

        foreach (DunningSchedule::STEPS as $index => $days) {
            if ($index + 1 > max($step, $recorded)) {
                $nextStepAt = $dueAt->addDays($days);

                break;
            }
        }

        return [
            'public_id' => $invoice->public_id,
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'total_paisa' => $invoice->total_paisa,
            'paid_paisa' => (int) $invoice->getAttribute('paid_paisa'),
            'due_paisa' => max(0, $invoice->total_paisa - (int) $invoice->getAttribute('paid_paisa')),
            'due_at' => $dueAt->toIso8601String(),
            'days_overdue' => (int) $dueAt->diffInDays($now, false),
            'dunning_step' => $recorded,
            'due_step' => $step,
            'will_notify' => $willNotify,
            'is_final_notice' => $willNotify && DunningSchedule::isFinalNotice($step),
            'will_suspend' => $willSuspend,
            'grace_deadline' => $grace->toIso8601String(),
            'next_step_at' => $willSuspend ? null : ($nextStepAt?->toIso8601String() ?? $grace->toIso8601String()),
        ];
    }
}
