<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\SaaS\Enums\BillingCycle;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionPaymentStatus;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The platform's own money, in one read model: what it earns per month, what it is owed, and what it collected.
 * Every number is an aggregate over `public.*` — nothing here opens a tenant schema, and nothing here is a float.
 *
 *   MRR   `active` + `past_due` subscriptions that are a tenant's CURRENT subscription. Monthly rows count at
 *         their price, yearly rows at one twelfth (integer division — the remainder is paisa the platform
 *         genuinely has not earned this month). `past_due` is included because the contract is still live and the
 *         invoice is still owed; it leaves MRR when the ladder suspends it. Add-ons are separate subscription
 *         rows and are counted too — they are recurring revenue.
 *   ARR   monthly × 12 + yearly, from the same rows.
 *   Overdue    issued/overdue invoices whose `due_at` has passed: total − paid.
 *   Outstanding every issued/overdue invoice, due or not.
 *   Collected   succeeded payments whose `paid_at` falls in the current Dhaka calendar month.
 *
 * Statuses are counted per TENANT (the row `tenants.current_subscription_id` points at), so a clinic on Pro plus
 * a telemedicine add-on is one "active", not two.
 */
final class PlatformRevenueSummary
{
    /** @var array<int, string> the subscription statuses that carry recurring revenue */
    public const RECURRING = [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value];

    /**
     * @return array{
     *     month: string,
     *     mrr_paisa: int,
     *     arr_paisa: int,
     *     monthly_paisa: int,
     *     yearly_paisa: int,
     *     recurring_subscriptions: int,
     *     trialing: int,
     *     active: int,
     *     past_due: int,
     *     suspended: int,
     *     cancelled: int,
     *     expired: int,
     *     overdue_paisa: int,
     *     overdue_invoices: int,
     *     outstanding_paisa: int,
     *     outstanding_invoices: int,
     *     collected_month_paisa: int,
     *     collected_month_payments: int,
     *     draft_invoices: int
     * }
     */
    public function summary(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        [$monthStart, $monthEnd] = $this->monthWindow($now);

        $recurring = DB::connection('pgsql')->table('public.subscriptions')
            ->whereIn('status', self::RECURRING)
            ->selectRaw('billing_cycle, count(*) as rows_count, sum(price_paisa) as total')
            ->groupBy('billing_cycle')
            ->get();

        $monthly = 0;
        $yearly = 0;
        $recurringRows = 0;

        foreach ($recurring as $row) {
            $recurringRows += (int) $row->rows_count;

            if ((string) $row->billing_cycle === BillingCycle::Yearly->value) {
                $yearly += (int) $row->total;
            } else {
                $monthly += (int) $row->total;
            }
        }

        /** @var array<string, int> $byStatus */
        $byStatus = DB::connection('pgsql')->table('public.subscriptions as s')
            ->join('public.tenants as t', 't.current_subscription_id', '=', 's.id')
            ->whereNull('t.deleted_at')
            ->selectRaw('s.status, count(*) as total')
            ->groupBy('s.status')
            ->pluck('total', 's.status')
            ->map(fn ($v): int => (int) $v)
            ->all();

        $open = [SubscriptionInvoiceStatus::Issued->value, SubscriptionInvoiceStatus::Overdue->value];

        $overdue = DB::connection('pgsql')->table('public.subscription_invoices')
            ->whereIn('status', $open)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $now)
            ->selectRaw('count(*) as invoices, coalesce(sum(total_paisa - paid_paisa), 0) as due')
            ->first();

        $outstanding = DB::connection('pgsql')->table('public.subscription_invoices')
            ->whereIn('status', $open)
            ->selectRaw('count(*) as invoices, coalesce(sum(total_paisa - paid_paisa), 0) as due')
            ->first();

        $collected = DB::connection('pgsql')->table('public.subscription_payments')
            ->where('status', SubscriptionPaymentStatus::Succeeded->value)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $monthStart)
            ->where('paid_at', '<', $monthEnd)
            ->selectRaw('count(*) as payments, coalesce(sum(amount_paisa), 0) as total')
            ->first();

        return [
            'month' => $monthStart->format('Y-m'),
            'mrr_paisa' => $monthly + intdiv($yearly, 12),
            'arr_paisa' => $monthly * 12 + $yearly,
            'monthly_paisa' => $monthly,
            'yearly_paisa' => $yearly,
            'recurring_subscriptions' => $recurringRows,
            'trialing' => $byStatus[SubscriptionStatus::Trialing->value] ?? 0,
            'active' => $byStatus[SubscriptionStatus::Active->value] ?? 0,
            'past_due' => $byStatus[SubscriptionStatus::PastDue->value] ?? 0,
            'suspended' => $byStatus[SubscriptionStatus::Suspended->value] ?? 0,
            'cancelled' => $byStatus[SubscriptionStatus::Cancelled->value] ?? 0,
            'expired' => $byStatus[SubscriptionStatus::Expired->value] ?? 0,
            'overdue_paisa' => (int) ($overdue->due ?? 0),
            'overdue_invoices' => (int) ($overdue->invoices ?? 0),
            'outstanding_paisa' => (int) ($outstanding->due ?? 0),
            'outstanding_invoices' => (int) ($outstanding->invoices ?? 0),
            'collected_month_paisa' => (int) ($collected->total ?? 0),
            'collected_month_payments' => (int) ($collected->payments ?? 0),
            'draft_invoices' => (int) DB::connection('pgsql')->table('public.subscription_invoices')
                ->where('status', SubscriptionInvoiceStatus::Draft->value)->count(),
        ];
    }

    /**
     * Collected per Dhaka calendar month, oldest first, the current month last — zero-filled so a quiet month
     * is a row that says 0 rather than a gap the eye skips over.
     *
     * @return array<int, array{period: string, collected_paisa: int, payments: int, invoiced_paisa: int, invoices: int}>
     */
    public function collectedByMonth(int $months = 12, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $months = max(1, min(36, $months));
        $current = $now->setTimezone(Clock::DEFAULT_TIMEZONE)->startOfMonth();
        $from = $current->subMonths($months - 1);

        $payments = DB::connection('pgsql')->table('public.subscription_payments')
            ->where('status', SubscriptionPaymentStatus::Succeeded->value)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $from->setTimezone('UTC'))
            ->selectRaw("to_char(paid_at at time zone 'Asia/Dhaka', 'YYYY-MM') as period, count(*) as payments, sum(amount_paisa) as total")
            ->groupBy('period')
            ->get()
            ->keyBy('period');

        $invoices = DB::connection('pgsql')->table('public.subscription_invoices')
            ->whereNot('status', SubscriptionInvoiceStatus::Draft->value)
            ->whereNot('status', SubscriptionInvoiceStatus::Void->value)
            ->whereNotNull('issued_at')
            ->where('issued_at', '>=', $from->setTimezone('UTC'))
            ->selectRaw("to_char(issued_at at time zone 'Asia/Dhaka', 'YYYY-MM') as period, count(*) as invoices, sum(total_paisa) as total")
            ->groupBy('period')
            ->get()
            ->keyBy('period');

        $out = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $period = $current->subMonths($i)->format('Y-m');
            $out[] = [
                'period' => $period,
                'collected_paisa' => (int) ($payments[$period]->total ?? 0),
                'payments' => (int) ($payments[$period]->payments ?? 0),
                'invoiced_paisa' => (int) ($invoices[$period]->total ?? 0),
                'invoices' => (int) ($invoices[$period]->invoices ?? 0),
            ];
        }

        return $out;
    }

    /**
     * The clinics that owe the most, for the overview's "chase these first" list.
     *
     * @return array<int, array{public_id: string, name: string, slug: string, status: string, arrears_paisa: int, invoices: int, oldest_due_at: string|null}>
     */
    public function arrearsByTenant(int $limit = 10): array
    {
        return DB::connection('pgsql')->table('public.subscription_invoices as i')
            ->join('public.tenants as t', 't.id', '=', 'i.tenant_id')
            ->whereNull('t.deleted_at')
            ->whereIn('i.status', [SubscriptionInvoiceStatus::Issued->value, SubscriptionInvoiceStatus::Overdue->value])
            ->selectRaw('t.public_id, t.name, t.slug, t.status, sum(i.total_paisa - i.paid_paisa) as due, count(*) as invoices, min(i.due_at) as oldest_due_at')
            ->groupBy('t.id', 't.public_id', 't.name', 't.slug', 't.status')
            ->orderByDesc('due')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($row): array => [
                'public_id' => (string) $row->public_id,
                'name' => (string) $row->name,
                'slug' => (string) $row->slug,
                'status' => (string) $row->status,
                'arrears_paisa' => (int) $row->due,
                'invoices' => (int) $row->invoices,
                'oldest_due_at' => $row->oldest_due_at === null ? null : CarbonImmutable::parse((string) $row->oldest_due_at)->toIso8601String(),
            ])
            ->all();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC instants bounding the current Dhaka month */
    private function monthWindow(CarbonImmutable $now): array
    {
        $start = $now->setTimezone(Clock::DEFAULT_TIMEZONE)->startOfMonth();

        return [$start->setTimezone('UTC'), $start->addMonth()->setTimezone('UTC')];
    }
}
