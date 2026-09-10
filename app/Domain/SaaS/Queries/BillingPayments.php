<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\SaaS\Enums\SubscriptionPaymentMethod;
use App\Domain\SaaS\Enums\SubscriptionPaymentStatus;
use App\Models\Central\SubscriptionPayment;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Every payment the platform has received or attempted, with the gateway reference and who recorded it — the
 * list the finance person reconciles against the bank statement, which is why `gateway_txn_id` and
 * `idempotency_key` are on the row and in the export.
 */
final class BillingPayments
{
    /**
     * @param  array{q?: string|null, method?: string|null, status?: string|null, tenant?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(array $filters, int $perPage = 25): array
    {
        /** @var LengthAwarePaginator<int, SubscriptionPayment> $page */
        $page = $this->query($filters)->paginate($perPage)->withQueryString();

        return [
            'data' => $page->getCollection()->map(fn (SubscriptionPayment $p) => $this->row($p))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    /**
     * @param  array{q?: string|null, method?: string|null, status?: string|null, tenant?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Generator<int, array<string, mixed>>
     */
    public function export(array $filters): Generator
    {
        foreach ($this->query($filters)->lazyById(200, 'subscription_payments.id') as $payment) {
            yield $this->row($payment);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function forTenant(Tenant $tenant, int $limit = 36): array
    {
        return SubscriptionPayment::query()
            ->with(['tenant', 'invoice', 'recordedBy'])
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (SubscriptionPayment $p) => $this->row($p))
            ->all();
    }

    /** @return array<string, int> succeeded paisa per method this Dhaka month, for the filter strip */
    public function methodTotals(?CarbonImmutable $now = null): array
    {
        $start = ($now ?? CarbonImmutable::now())->setTimezone('Asia/Dhaka')->startOfMonth()->setTimezone('UTC');

        /** @var array<string, int> $totals */
        $totals = DB::connection('pgsql')->table('public.subscription_payments')
            ->where('status', SubscriptionPaymentStatus::Succeeded->value)
            ->where('paid_at', '>=', $start)
            ->selectRaw('method, sum(amount_paisa) as total')
            ->groupBy('method')
            ->pluck('total', 'method')
            ->map(fn ($v): int => (int) $v)
            ->all();

        $out = [];

        foreach (SubscriptionPaymentMethod::cases() as $method) {
            $out[$method->value] = $totals[$method->value] ?? 0;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function row(SubscriptionPayment $p): array
    {
        $tenant = $p->tenant;
        $invoice = $p->invoice;

        return [
            'public_id' => $p->public_id,
            'tenant' => $tenant === null ? null : ['public_id' => $tenant->public_id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'status' => $tenant->status->value],
            'invoice' => $invoice === null ? null : ['public_id' => $invoice->public_id, 'number' => $invoice->number, 'status' => $invoice->status->value],
            'method' => $p->method->value,
            'status' => $p->status->value,
            'amount_paisa' => $p->amount_paisa,
            'gateway_txn_id' => $p->getAttribute('gateway_txn_id'),
            'idempotency_key' => $p->getAttribute('idempotency_key'),
            'paid_at' => $p->getAttribute('paid_at')?->toIso8601String(),
            'created_at' => $p->getAttribute('created_at')?->toIso8601String(),
            'recorded_by' => $p->recordedBy?->name,
        ];
    }

    /**
     * @param  array{q?: string|null, method?: string|null, status?: string|null, tenant?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Builder<SubscriptionPayment>
     */
    private function query(array $filters): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $method = (string) ($filters['method'] ?? '');
        $status = (string) ($filters['status'] ?? '');
        $tenant = (string) ($filters['tenant'] ?? '');
        $from = (string) ($filters['from'] ?? '');
        $to = (string) ($filters['to'] ?? '');

        return SubscriptionPayment::query()
            ->select('subscription_payments.*')
            ->with(['tenant', 'invoice', 'recordedBy'])
            ->join('public.tenants as t', 't.id', '=', 'subscription_payments.tenant_id')
            ->join('public.subscription_invoices as i', 'i.id', '=', 'subscription_payments.subscription_invoice_id')
            ->when($q !== '', function (Builder $query) use ($q): void {
                $term = '%'.mb_strtolower($q).'%';
                $query->where(function (Builder $w) use ($term): void {
                    $w->whereRaw('lower(coalesce(subscription_payments.gateway_txn_id, \'\')) like ?', [$term])
                        ->orWhereRaw('lower(i.number) like ?', [$term])
                        ->orWhereRaw('lower(t.name) like ?', [$term])
                        ->orWhereRaw('lower(t.slug) like ?', [$term]);
                });
            })
            ->when($tenant !== '', fn (Builder $query) => $query->where('t.public_id', $tenant))
            ->when(SubscriptionPaymentMethod::tryFrom($method) !== null, fn (Builder $query) => $query->where('subscription_payments.method', $method))
            ->when(SubscriptionPaymentStatus::tryFrom($status) !== null, fn (Builder $query) => $query->where('subscription_payments.status', $status))
            ->when($from !== '', fn (Builder $query) => $query->where('subscription_payments.created_at', '>=', CarbonImmutable::parse($from, 'Asia/Dhaka')->startOfDay()->setTimezone('UTC')))
            ->when($to !== '', fn (Builder $query) => $query->where('subscription_payments.created_at', '<', CarbonImmutable::parse($to, 'Asia/Dhaka')->addDay()->startOfDay()->setTimezone('UTC')))
            ->orderByDesc('subscription_payments.id');
    }
}
