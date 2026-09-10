<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Platform invoices across every clinic — the accounts-receivable ledger. Money is the stored integer paisa of
 * each row; `due_paisa` is `total − paid`, computed here for the table and never written anywhere.
 *
 * `overdue` as a FILTER means "issued or overdue and past `due_at`", which is the accountant's question; the
 * `status` column still shows the row's own state, because an invoice only becomes `overdue` when the dunning
 * sweep touches it and the two can legitimately differ for a few hours.
 */
final class BillingInvoices
{
    /**
     * @param  array{q?: string|null, status?: string|null, tenant?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(array $filters, int $perPage = 25): array
    {
        /** @var LengthAwarePaginator<int, SubscriptionInvoice> $page */
        $page = $this->query($filters)->paginate($perPage)->withQueryString();

        return [
            'data' => $page->getCollection()->map(fn (SubscriptionInvoice $i) => $this->row($i))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    /**
     * @param  array{q?: string|null, status?: string|null, tenant?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Generator<int, array<string, mixed>>
     */
    public function export(array $filters): Generator
    {
        foreach ($this->query($filters)->lazyById(200, 'subscription_invoices.id') as $invoice) {
            yield $this->row($invoice);
        }
    }

    /**
     * One clinic's invoices, newest first — the tenant billing card and the tenant detail page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forTenant(Tenant $tenant, int $limit = 36): array
    {
        return SubscriptionInvoice::query()
            ->with('tenant')
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (SubscriptionInvoice $i) => $this->row($i))
            ->all();
    }

    /** @return array<string, int> status => count, for the filter chips (plus the accountant's `overdue`) */
    public function statusCounts(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        /** @var array<string, int> $counts */
        $counts = DB::connection('pgsql')->table('public.subscription_invoices')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($v): int => (int) $v)
            ->all();

        $out = [];

        foreach (SubscriptionInvoiceStatus::cases() as $status) {
            $out[$status->value] = $counts[$status->value] ?? 0;
        }

        $out['past_due'] = DB::connection('pgsql')->table('public.subscription_invoices')
            ->whereIn('status', [SubscriptionInvoiceStatus::Issued->value, SubscriptionInvoiceStatus::Overdue->value])
            ->whereNotNull('due_at')->where('due_at', '<', $now)->count();

        return $out;
    }

    /** @return array<string, mixed> */
    public function row(SubscriptionInvoice $i): array
    {
        $tenant = $i->tenant;
        $paid = (int) $i->getAttribute('paid_paisa');
        $dueAt = $i->getAttribute('due_at');

        return [
            'public_id' => $i->public_id,
            'number' => $i->number,
            'tenant' => $tenant === null ? null : ['public_id' => $tenant->public_id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'status' => $tenant->status->value],
            'status' => $i->status->value,
            'is_past_due' => in_array($i->status, [SubscriptionInvoiceStatus::Issued, SubscriptionInvoiceStatus::Overdue], true)
                && $dueAt instanceof CarbonImmutable && $dueAt->isPast(),
            'period_start' => $i->getAttribute('period_start')?->toDateString(),
            'period_end' => $i->getAttribute('period_end')?->toDateString(),
            'subtotal_paisa' => (int) $i->getAttribute('subtotal_paisa'),
            'discount_paisa' => (int) $i->getAttribute('discount_paisa'),
            'tax_paisa' => (int) $i->getAttribute('tax_paisa'),
            'total_paisa' => $i->total_paisa,
            'paid_paisa' => $paid,
            'due_paisa' => max(0, $i->total_paisa - $paid),
            'issued_at' => $i->getAttribute('issued_at')?->toIso8601String(),
            'due_at' => $dueAt?->toIso8601String(),
            'paid_at' => $i->getAttribute('paid_at')?->toIso8601String(),
            'voided_at' => $i->getAttribute('voided_at')?->toIso8601String(),
            'dunning_step' => (int) $i->getAttribute('dunning_step'),
            'line_items' => $i->line_items,
        ];
    }

    /**
     * @param  array{q?: string|null, status?: string|null, tenant?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Builder<SubscriptionInvoice>
     */
    private function query(array $filters): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $status = (string) ($filters['status'] ?? '');
        $tenant = (string) ($filters['tenant'] ?? '');
        $from = (string) ($filters['from'] ?? '');
        $to = (string) ($filters['to'] ?? '');

        return SubscriptionInvoice::query()
            ->select('subscription_invoices.*')
            ->with('tenant')
            ->join('public.tenants as t', 't.id', '=', 'subscription_invoices.tenant_id')
            ->when($q !== '', function (Builder $query) use ($q): void {
                $term = '%'.mb_strtolower($q).'%';
                $query->where(function (Builder $w) use ($term): void {
                    $w->whereRaw('lower(subscription_invoices.number) like ?', [$term])
                        ->orWhereRaw('lower(t.name) like ?', [$term])
                        ->orWhereRaw('lower(t.slug) like ?', [$term]);
                });
            })
            ->when($tenant !== '', fn (Builder $query) => $query->where('t.public_id', $tenant))
            ->when($status === 'past_due', fn (Builder $query) => $query
                ->whereIn('subscription_invoices.status', [SubscriptionInvoiceStatus::Issued->value, SubscriptionInvoiceStatus::Overdue->value])
                ->whereNotNull('subscription_invoices.due_at')
                ->where('subscription_invoices.due_at', '<', CarbonImmutable::now()))
            ->when(SubscriptionInvoiceStatus::tryFrom($status) !== null, fn (Builder $query) => $query->where('subscription_invoices.status', $status))
            ->when($from !== '', fn (Builder $query) => $query->where('subscription_invoices.created_at', '>=', CarbonImmutable::parse($from, 'Asia/Dhaka')->startOfDay()->setTimezone('UTC')))
            ->when($to !== '', fn (Builder $query) => $query->where('subscription_invoices.created_at', '<', CarbonImmutable::parse($to, 'Asia/Dhaka')->addDay()->startOfDay()->setTimezone('UTC')))
            ->orderByDesc('subscription_invoices.id');
    }
}
