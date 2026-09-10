<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Services\PlanLimits;
use App\Domain\SaaS\Services\UsageMeter;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The super console's read model.
 *
 * The list is one paginated query over `public.tenants` plus THREE aggregate queries over the whole page — never
 * a per-row lookup, and never a per-tenant `Tenancy::run()`. A control plane that opened a clinic's schema just
 * to render a row would be both slow and a tenancy hazard; every number here comes from `usage_counters`,
 * `subscriptions` and `subscription_invoices`, which is exactly why those tables exist.
 */
final class TenantOverview
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly UsageMeter $meter,
    ) {}

    /** `attention` filter values: what the console can ask the list to surface. */
    public const ATTENTION = ['trial_ending', 'arrears', 'no_subscription'];

    /** `sort` values; every one is a column of `public.tenants` or a correlated aggregate — never a per-row query. */
    public const SORTS = ['created', 'name', 'slug', 'status', 'trial_ends', 'arrears'];

    /** The soft-deleted rows, reachable through `status=deleted`: their export archive stays downloadable. */
    public const STATUS_DELETED = 'deleted';

    /** Days ahead the `trial_ending` filter looks. */
    public const TRIAL_ENDING_DAYS = 7;

    /**
     * @param  array{plan?: string|null, attention?: string|null, sort?: string|null, dir?: string|null}  $options
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(?string $search, ?string $status, int $perPage = 25, array $options = []): array
    {
        $deleted = $status === self::STATUS_DELETED;
        $plan = $options['plan'] ?? null;
        $attention = $options['attention'] ?? null;
        $sort = in_array($options['sort'] ?? null, self::SORTS, true) ? (string) $options['sort'] : 'created';
        $dir = ($options['dir'] ?? null) === 'asc' ? 'asc' : (($options['dir'] ?? null) === 'desc' ? 'desc' : null);

        /** @var LengthAwarePaginator<int, Tenant> $page */
        $page = Tenant::query()
            ->with(['currentSubscription.plan'])
            ->when($deleted, fn ($q) => $q->onlyTrashed())
            ->when($search !== null && $search !== '', fn ($q) => $q->where(function ($w) use ($search): void {
                $term = '%'.mb_strtolower($search).'%';
                $w->whereRaw('lower(name) like ?', [$term])
                    ->orWhereRaw('lower(slug) like ?', [$term])
                    ->orWhereRaw('lower(owner_email) like ?', [$term]);
            }))
            ->when(! $deleted && $status !== null && $status !== '', fn ($q) => $q->where('status', $status))
            ->when($plan !== null && $plan !== '', fn ($q) => $q->whereHas('currentSubscription', fn ($s) => $s->whereHas('plan', fn ($p) => $p->where('code', $plan))))
            ->when($attention === 'trial_ending', fn ($q) => $q->where('status', 'trial')
                ->whereNotNull('trial_ends_at')
                ->whereBetween('trial_ends_at', [CarbonImmutable::now(), CarbonImmutable::now()->addDays(self::TRIAL_ENDING_DAYS)]))
            ->when($attention === 'arrears', fn ($q) => $q->whereExists($this->unsettledInvoicesQuery()))
            ->when($attention === 'no_subscription', fn ($q) => $q->whereNull('current_subscription_id'))
            ->tap(fn ($q) => $this->applySort($q, $sort, $dir))
            ->paginate($perPage)
            ->withQueryString();

        /** @var array<int, int> $ids */
        $ids = $page->getCollection()->map(fn (Tenant $t): int => $t->id)->all();
        $usage = $this->usageFor($ids);
        $arrears = $this->arrearsFor($ids);
        $exports = $deleted ? $this->latestExportsFor($ids) : [];

        return [
            'data' => $page->getCollection()->map(fn (Tenant $tenant) => $this->row($tenant, $usage[$tenant->id] ?? [], $arrears[$tenant->id] ?? ['count' => 0, 'paisa' => 0])
                + ['deleted_at' => $tenant->deleted_at?->toIso8601String(), 'last_export_id' => $exports[$tenant->id] ?? null])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    /** @return array<string, int> platform-wide counters for the dashboard tiles */
    public function platformTotals(): array
    {
        /** @var array<string, int> $byStatus */
        $byStatus = DB::connection('pgsql')->table('public.tenants')
            ->whereNull('deleted_at')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($v): int => (int) $v)
            ->all();

        $month = CarbonImmutable::now('Asia/Dhaka')->format('Y-m');

        return [
            'tenants' => array_sum($byStatus),
            'trial' => $byStatus['trial'] ?? 0,
            'active' => $byStatus['active'] ?? 0,
            'past_due' => $byStatus['past_due'] ?? 0,
            'suspended' => $byStatus['suspended'] ?? 0,
            'cancelled' => $byStatus['cancelled'] ?? 0,
            'appointments_this_month' => (int) DB::connection('pgsql')->table('public.usage_counters')
                ->where('metric', UsageMetric::Appointments->value)->where('period', $month)->sum('value'),
            'sms_this_month' => (int) DB::connection('pgsql')->table('public.usage_counters')
                ->where('metric', UsageMetric::SmsCredits->value)->where('period', $month)->sum('value'),
            'mrr_paisa' => (int) DB::connection('pgsql')->table('public.subscriptions')
                ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
                ->selectRaw("sum(case when billing_cycle = 'yearly' then price_paisa / 12 else price_paisa end) as total")
                ->value('total'),
            'outstanding_paisa' => (int) DB::connection('pgsql')->table('public.subscription_invoices')
                ->whereIn('status', [SubscriptionInvoiceStatus::Issued->value, SubscriptionInvoiceStatus::Overdue->value])
                ->selectRaw('sum(total_paisa - paid_paisa) as total')->value('total'),
        ];
    }

    /**
     * One tenant, in full: subscription, usage against limits, entitlements and the money.
     *
     * @return array<string, mixed>
     */
    public function detail(Tenant $tenant): array
    {
        $subscription = $tenant->currentSubscription;
        $entitlements = $this->limits->entitlements($tenant);

        return $this->row($tenant, $this->meter->current($tenant), $this->arrearsFor([$tenant->id])[$tenant->id] ?? ['count' => 0, 'paisa' => 0]) + [
            'owner' => ['name' => $tenant->owner_name, 'email' => $tenant->owner_email, 'mobile' => $tenant->owner_mobile],
            'timezone' => $tenant->timezone,
            'locale' => $tenant->locale->value,
            'schema_name' => $tenant->schema_name,
            'provisioned_at' => $tenant->provisioned_at?->toIso8601String(),
            'last_backup_at' => $tenant->last_backup_at?->toIso8601String(),
            'data_export_requested_at' => $tenant->data_export_requested_at?->toIso8601String(),
            'onboarding' => $tenant->onboarding,
            'suspension_reason' => $tenant->suspension_reason,
            'platform_notes' => $tenant->platform_notes,
            'deleted_at' => $tenant->deleted_at?->toIso8601String(),
            'branding' => [
                'name_bn' => is_string($tenant->branding['name_bn'] ?? null) ? $tenant->branding['name_bn'] : null,
                'primary_color' => is_string($tenant->branding['primary_color'] ?? null) ? $tenant->branding['primary_color'] : null,
                'accent_color' => is_string($tenant->branding['accent_color'] ?? null) ? $tenant->branding['accent_color'] : null,
                'logo_path' => is_string($tenant->branding['logo_path'] ?? null) ? $tenant->branding['logo_path'] : null,
                'logo_url' => HandleInertiaRequests::publicUrl($tenant->branding['logo_path'] ?? null),
            ],
            'subscription' => $subscription === null ? null : [
                'id' => $subscription->id,
                'status' => $subscription->status->value,
                'plan_code' => $subscription->plan->code,
                'plan_name' => $subscription->plan->name,
                'billing_cycle' => $subscription->billing_cycle->value,
                'price_paisa' => $subscription->price_paisa,
                'current_period_start' => $subscription->current_period_start->toIso8601String(),
                'current_period_end' => $subscription->current_period_end->toIso8601String(),
                'trial_ends_at' => $subscription->getAttribute('trial_ends_at')?->toIso8601String(),
                'grace_until' => $subscription->getAttribute('grace_until')?->toIso8601String(),
                'auto_renew' => (bool) $subscription->getAttribute('auto_renew'),
                'cancel_at_period_end' => (bool) $subscription->getAttribute('cancel_at_period_end'),
                'feature_overrides' => $subscription->feature_overrides,
            ],
            'addons' => Subscription::query()->with('plan')->where('tenant_id', $tenant->id)
                ->whereKeyNot($subscription === null ? 0 : $subscription->id)
                ->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
                ->get()->map(fn (Subscription $s) => ['plan_code' => $s->plan->code, 'plan_name' => $s->plan->name, 'status' => $s->status->value])->all(),
            'usage' => array_map(fn ($status) => $status->toArray(), $this->limits->allStatuses($tenant)),
            'entitlements' => ['plan_code' => $entitlements->planCode, 'plan_name' => $entitlements->planName, 'toggles' => $entitlements->toggles, 'limits' => $entitlements->limits],
        ];
    }

    /**
     * @param  array<string, int>  $usage
     * @param  array{count: int, paisa: int}  $arrears
     * @return array<string, mixed>
     */
    private function row(Tenant $tenant, array $usage, array $arrears): array
    {
        $subscription = $tenant->currentSubscription;
        $entitlements = $this->limits->entitlements($tenant);

        return [
            'public_id' => $tenant->public_id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'host' => $tenant->primaryHost(),
            'status' => $tenant->status->value,
            'plan_code' => $subscription?->plan->code,
            'plan_name' => $subscription?->plan->name ?? $entitlements->planName,
            'subscription_status' => $subscription?->status->value,
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            'period_end' => $subscription?->current_period_end->toIso8601String(),
            'suspended_at' => $tenant->suspended_at?->toIso8601String(),
            'created_at' => $tenant->created_at?->toIso8601String(),
            'arrears_paisa' => $arrears['paisa'],
            'arrears_invoices' => $arrears['count'],
            'health' => $this->health($tenant, $arrears),
            'counts' => [
                'doctors' => $usage[UsageMetric::Doctors->value] ?? 0,
                'branches' => $usage[UsageMetric::Branches->value] ?? 0,
                'appointments' => $usage[UsageMetric::Appointments->value] ?? 0,
                'prescriptions' => $usage[UsageMetric::Prescriptions->value] ?? 0,
                'sms_credits' => $usage[UsageMetric::SmsCredits->value] ?? 0,
                'storage_bytes' => $usage[UsageMetric::StorageBytes->value] ?? 0,
            ],
            'limits' => [
                'doctors' => $entitlements->limitFor(UsageMetric::Doctors),
                'branches' => $entitlements->limitFor(UsageMetric::Branches),
                'appointments' => $entitlements->limitFor(UsageMetric::Appointments),
                'sms_credits' => $entitlements->limitFor(UsageMetric::SmsCredits),
                'storage_bytes' => $entitlements->limitFor(UsageMetric::StorageBytes),
            ],
        ];
    }

    /** @param  array{count: int, paisa: int}  $arrears */
    private function health(Tenant $tenant, array $arrears): string
    {
        return match (true) {
            $tenant->status->value === 'suspended', $tenant->status->value === 'cancelled' => 'critical',
            $arrears['count'] > 0, $tenant->status->value === 'past_due' => 'at_risk',
            $tenant->status->value === 'trial' => 'trial',
            default => 'healthy',
        };
    }

    /**
     * @param  array<int, int>  $tenantIds
     * @return array<int, array<string, int>>
     */
    private function usageFor(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $month = CarbonImmutable::now('Asia/Dhaka')->format('Y-m');

        $rows = DB::connection('pgsql')->table('public.usage_counters')
            ->whereIn('tenant_id', $tenantIds)
            ->whereIn('period', [UsageMeter::GAUGE_PERIOD, $month])
            ->get(['tenant_id', 'metric', 'period', 'value']);

        $out = [];

        foreach ($rows as $row) {
            $metric = UsageMetric::tryFrom((string) $row->metric);

            if ($metric !== null && $metric->isGauge() === ($row->period === UsageMeter::GAUGE_PERIOD)) {
                $out[(int) $row->tenant_id][$metric->value] = (int) $row->value;
            }
        }

        return $out;
    }

    /**
     * `sort=arrears` orders by a correlated sum over `subscription_invoices` — one extra aggregate per PAGE row
     * inside the same statement, not a query per tenant. `trial_ends` puts the clinics with no trial last.
     *
     * @param  Builder<Tenant>  $query
     */
    private function applySort(Builder $query, string $sort, ?string $dir): void
    {
        match ($sort) {
            'name' => $query->orderByRaw('lower(name) '.($dir ?? 'asc')),
            'slug' => $query->orderBy('slug', $dir ?? 'asc'),
            'status' => $query->orderBy('status', $dir ?? 'asc')->orderByDesc('id'),
            'trial_ends' => $query->orderByRaw('trial_ends_at '.($dir ?? 'asc').' nulls last')->orderByDesc('id'),
            'arrears' => $query->orderBy(
                DB::connection('pgsql')->table('public.subscription_invoices')
                    ->selectRaw('coalesce(sum(total_paisa - paid_paisa), 0)')
                    ->whereColumn('subscription_invoices.tenant_id', 'tenants.id')
                    ->whereIn('status', [SubscriptionInvoiceStatus::Issued->value, SubscriptionInvoiceStatus::Overdue->value]),
                $dir ?? 'desc',
            )->orderByDesc('id'),
            default => $query->orderBy('id', $dir ?? 'desc'),
        };
    }

    /** @return QueryBuilder an EXISTS subquery: the tenant has an issued or overdue invoice with a balance */
    private function unsettledInvoicesQuery(): QueryBuilder
    {
        return DB::connection('pgsql')->table('public.subscription_invoices')
            ->selectRaw('1')
            ->whereColumn('subscription_invoices.tenant_id', 'tenants.id')
            ->whereIn('status', [SubscriptionInvoiceStatus::Issued->value, SubscriptionInvoiceStatus::Overdue->value])
            ->whereColumn('paid_paisa', '<', 'total_paisa');
    }

    /**
     * The newest COMPLETED churn export per tenant — what the deleted-clinics list offers for download.
     *
     * @param  array<int, int>  $tenantIds
     * @return array<int, int> tenant id → tenant_backups.id
     */
    private function latestExportsFor(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $rows = DB::connection('pgsql')->table('public.tenant_backups')
            ->whereIn('tenant_id', $tenantIds)
            ->where('type', 'export')
            ->where('status', 'completed')
            ->selectRaw('tenant_id, max(id) as id')
            ->groupBy('tenant_id')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->tenant_id] = (int) $row->id;
        }

        return $out;
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
