<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\SaaS\Enums\BillingCycle;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Models\Central\Subscription;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The platform's subscription ledger as the billing desk reads it: one row per subscription (add-ons included,
 * flagged), with the tenant, the plan, where it is in the state machine, when it renews and what it owes.
 *
 * One paginated query plus ONE grouped arrears query over the page — never a per-row lookup and never a tenant
 * schema (the same discipline as `TenantOverview`).
 */
final class BillingSubscriptions
{
    /** @var array<int, string> */
    public const SORTABLE = ['renewal', 'arrears', 'tenant', 'price'];

    /**
     * @param  array{q?: string|null, status?: string|null, plan?: string|null, cycle?: string|null, sort?: string|null}  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(array $filters, int $perPage = 25): array
    {
        /** @var LengthAwarePaginator<int, Subscription> $page */
        $page = $this->query($filters)->paginate($perPage)->withQueryString();

        /** @var array<int, int> $tenantIds */
        $tenantIds = $page->getCollection()->map(fn (Subscription $s): int => $s->tenant_id)->unique()->values()->all();
        $arrears = $this->arrearsFor($tenantIds);

        return [
            'data' => $page->getCollection()->map(fn (Subscription $s) => $this->row($s, $arrears[$s->tenant_id] ?? ['count' => 0, 'paisa' => 0]))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    /**
     * Every matching row, for the CSV export — a generator, so a platform with thousands of clinics streams
     * rather than materialising.
     *
     * @param  array{q?: string|null, status?: string|null, plan?: string|null, cycle?: string|null, sort?: string|null}  $filters
     * @return Generator<int, array<string, mixed>>
     */
    public function export(array $filters): Generator
    {
        foreach ($this->query($filters)->lazyById(200, 'subscriptions.id') as $subscription) {
            $arrears = $this->arrearsFor([$subscription->tenant_id])[$subscription->tenant_id] ?? ['count' => 0, 'paisa' => 0];

            yield $this->row($subscription, $arrears);
        }
    }

    /** @return array<string, int> status => count, for the filter chips */
    public function statusCounts(): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::connection('pgsql')->table('public.subscriptions as s')
            ->join('public.tenants as t', 't.id', '=', 's.tenant_id')
            ->whereNull('t.deleted_at')
            ->selectRaw('s.status, count(*) as total')
            ->groupBy('s.status')
            ->pluck('total', 's.status')
            ->map(fn ($v): int => (int) $v)
            ->all();

        $out = [];

        foreach (SubscriptionStatus::cases() as $status) {
            $out[$status->value] = $counts[$status->value] ?? 0;
        }

        return $out;
    }

    /**
     * @param  array{q?: string|null, status?: string|null, plan?: string|null, cycle?: string|null, sort?: string|null}  $filters
     * @return Builder<Subscription>
     */
    private function query(array $filters): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $status = (string) ($filters['status'] ?? '');
        $plan = (string) ($filters['plan'] ?? '');
        $cycle = (string) ($filters['cycle'] ?? '');
        $sort = (string) ($filters['sort'] ?? 'renewal');

        return Subscription::query()
            ->select('subscriptions.*')
            ->with(['plan', 'tenant'])
            ->join('public.tenants as t', 't.id', '=', 'subscriptions.tenant_id')
            ->join('public.plans as p', 'p.id', '=', 'subscriptions.plan_id')
            ->whereNull('t.deleted_at')
            ->when($q !== '', function (Builder $query) use ($q): void {
                $term = '%'.mb_strtolower($q).'%';
                $query->where(function (Builder $w) use ($term): void {
                    $w->whereRaw('lower(t.name) like ?', [$term])
                        ->orWhereRaw('lower(t.slug) like ?', [$term])
                        ->orWhereRaw('lower(t.owner_email) like ?', [$term]);
                });
            })
            ->when(SubscriptionStatus::tryFrom($status) !== null, fn (Builder $query) => $query->where('subscriptions.status', $status))
            ->when($plan !== '', fn (Builder $query) => $query->where('p.code', $plan))
            ->when(BillingCycle::tryFrom($cycle) !== null, fn (Builder $query) => $query->where('subscriptions.billing_cycle', $cycle))
            ->when($sort === 'tenant', fn (Builder $query) => $query->orderBy('t.name')->orderBy('subscriptions.id'))
            ->when($sort === 'price', fn (Builder $query) => $query->orderByDesc('subscriptions.price_paisa')->orderBy('subscriptions.id'))
            ->when($sort === 'arrears', fn (Builder $query) => $query
                ->orderByDesc(DB::connection('pgsql')->table('public.subscription_invoices as i')
                    ->whereColumn('i.tenant_id', 'subscriptions.tenant_id')
                    ->whereIn('i.status', [SubscriptionInvoiceStatus::Issued->value, SubscriptionInvoiceStatus::Overdue->value])
                    ->selectRaw('coalesce(sum(i.total_paisa - i.paid_paisa), 0)'))
                ->orderBy('subscriptions.id'))
            ->when(! in_array($sort, ['tenant', 'price', 'arrears'], true), fn (Builder $query) => $query->orderBy('subscriptions.current_period_end')->orderBy('subscriptions.id'));
    }

    /**
     * @param  array{count: int, paisa: int}  $arrears
     * @return array<string, mixed>
     */
    private function row(Subscription $s, array $arrears): array
    {
        $tenant = $s->tenant;

        return [
            'id' => $s->id,
            'tenant' => [
                'public_id' => $tenant->public_id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status->value,
                'owner_email' => $tenant->owner_email,
            ],
            'is_current' => $tenant->current_subscription_id === $s->id,
            'plan_code' => $s->plan->code,
            'plan_name' => $s->plan->name,
            'is_addon' => $s->plan->is_addon,
            'status' => $s->status->value,
            'billing_cycle' => $s->billing_cycle->value,
            'price_paisa' => $s->price_paisa,
            'current_period_start' => $s->current_period_start->toIso8601String(),
            'current_period_end' => $s->current_period_end->toIso8601String(),
            'trial_ends_at' => $s->getAttribute('trial_ends_at')?->toIso8601String(),
            'grace_until' => $s->getAttribute('grace_until')?->toIso8601String(),
            'auto_renew' => (bool) $s->getAttribute('auto_renew'),
            'cancel_at_period_end' => (bool) $s->getAttribute('cancel_at_period_end'),
            'cancelled_at' => $s->getAttribute('cancelled_at')?->toIso8601String(),
            'arrears_paisa' => $arrears['paisa'],
            'arrears_invoices' => $arrears['count'],
        ];
    }

    /**
     * @param  array<int, int>  $tenantIds
     * @return array<int, array{count: int, paisa: int}>
     */
    private function arrearsFor(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $rows = DB::connection('pgsql')->table('public.subscription_invoices')
            ->whereIn('tenant_id', $tenantIds)
            ->whereIn('status', [SubscriptionInvoiceStatus::Issued->value, SubscriptionInvoiceStatus::Overdue->value])
            ->selectRaw('tenant_id, count(*) as invoices, sum(total_paisa - paid_paisa) as due')
            ->groupBy('tenant_id')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->tenant_id] = ['count' => (int) $row->invoices, 'paisa' => (int) $row->due];
        }

        return $out;
    }
}
