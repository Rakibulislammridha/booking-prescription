<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Services\PlanLimits;
use App\Domain\SaaS\Services\UsageMeter;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The usage screen's per-clinic view of one metric: every tenant's counter against its cap, sortable by share of
 * the cap, filterable to "over" and "near", and — for one tenant — every metric with six months of history.
 *
 * The board is built in PHP from three bounded reads (every counter for the metric and period, every live
 * subscription, the plan rows — `TenantLimitsSnapshot`) rather than sorted in SQL on `limit_snapshot`, which
 * SCHEMA §2.10 calls "not authoritative". Percentages here are the same ones `PlanLimits` enforces.
 *
 * @phpstan-type BoardRow array{public_id: string, name: string, slug: string, status: string, plan_name: string, value: int, limit: int|null, percent: int|null, exhausted: bool, near: bool}
 * @phpstan-type Board array{rows: array<int, BoardRow>, meta: array{current_page: int, last_page: int, total: int, per_page: int}, counts: array{all: int, over: int, near: int}}
 */
final class TenantUsageBoard
{
    public const PER_PAGE = 25;

    public const NEAR_PERCENT = PlatformKpis::NEAR_PERCENT;

    public const HISTORY_MONTHS = 6;

    public function __construct(
        private readonly TenantLimitsSnapshot $limits,
        private readonly PlanLimits $planLimits,
        private readonly UsageMeter $meter,
    ) {}

    /**
     * @param  'all'|'over'|'near'  $filter
     * @param  'percent'|'value'|'name'  $sort
     * @return Board
     */
    public function board(UsageMetric $metric, string $filter = 'all', string $sort = 'percent', int $page = 1): array
    {
        [$sorted, $counts] = $this->collect($metric, $filter, $sort);

        $total = count($sorted);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $lastPage));

        return [
            'rows' => array_slice($sorted, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'total' => $total, 'per_page' => self::PER_PAGE],
            'counts' => $counts,
        ];
    }

    /**
     * The whole board of one metric as flat rows for the CSV, unpaginated and in the same order as the screen.
     *
     * @param  'all'|'over'|'near'  $filter
     * @return array<int, BoardRow>
     */
    public function export(UsageMetric $metric, string $filter = 'all'): array
    {
        return $this->collect($metric, $filter, 'percent')[0];
    }

    /**
     * @param  'all'|'over'|'near'  $filter
     * @param  'percent'|'value'|'name'  $sort
     * @return array{0: array<int, BoardRow>, 1: array{all: int, over: int, near: int}}
     */
    private function collect(UsageMetric $metric, string $filter, string $sort): array
    {
        $period = $metric->isGauge() ? UsageMeter::GAUGE_PERIOD : CarbonImmutable::now('Asia/Dhaka')->format('Y-m');
        $limits = $this->limits->forMetric($metric);

        /** @var Collection<int, BoardRow> $rows */
        $rows = DB::connection('pgsql')->table('public.tenants as t')
            ->leftJoin('public.usage_counters as u', fn ($join) => $join->on('u.tenant_id', '=', 't.id')->where('u.metric', $metric->value)->where('u.period', $period))
            ->leftJoin('public.subscriptions as s', 's.id', '=', 't.current_subscription_id')
            ->leftJoin('public.plans as p', 'p.id', '=', 's.plan_id')
            ->whereNull('t.deleted_at')
            ->get(['t.id', 't.public_id', 't.name', 't.slug', 't.status', 'p.name as plan_name', 'u.value'])
            ->map(function ($row) use ($limits): array {
                $value = (int) ($row->value ?? 0);
                $limit = $limits[(int) $row->id] ?? null;
                $percent = $limit === null ? null : ($limit === 0 ? ($value > 0 ? 100 : 0) : (int) min(999, (int) round($value * 100 / $limit)));
                $exhausted = $limit !== null && $value >= $limit && ($limit > 0 || $value > 0);

                return [
                    'public_id' => (string) $row->public_id,
                    'name' => (string) $row->name,
                    'slug' => (string) $row->slug,
                    'status' => (string) $row->status,
                    'plan_name' => (string) ($row->plan_name ?? '—'),
                    'value' => $value,
                    'limit' => $limit,
                    'percent' => $percent,
                    'exhausted' => $exhausted,
                    'near' => ! $exhausted && $percent !== null && $percent >= self::NEAR_PERCENT,
                ];
            });

        $counts = [
            'all' => $rows->count(),
            'over' => $rows->filter(fn (array $r): bool => $r['exhausted'])->count(),
            'near' => $rows->filter(fn (array $r): bool => $r['near'] || $r['exhausted'])->count(),
        ];

        // One filter call whatever the branch: PHPStan narrows `exhausted` to `true` inside a dedicated
        // over-filter and the two collection types then stop unifying.
        $filtered = $rows->filter(fn (array $r): bool => match ($filter) {
            'over' => $r['exhausted'],
            'near' => $r['near'] || $r['exhausted'],
            default => true,
        });

        $sorted = match ($sort) {
            'value' => $filtered->sortBy([['value', 'desc'], ['name', 'asc']]),
            'name' => $filtered->sortBy('name'),
            // Unlimited caps sort last: a clinic with no cap has no share to speak of.
            default => $filtered->sort(fn (array $a, array $b): int => ($b['percent'] ?? -1) <=> ($a['percent'] ?? -1) ?: $b['value'] <=> $a['value'] ?: strcmp($a['name'], $b['name'])),
        };

        return [$sorted->values()->all(), $counts];
    }

    /**
     * One clinic, every metric: the cap in force, the current counter and six months of history for the meters
     * (a gauge has a single `current` point).
     *
     * @return array<int, array<string, mixed>>
     */
    public function detail(Tenant $tenant): array
    {
        $out = [];

        foreach ($this->planLimits->allStatuses($tenant) as $status) {
            $history = $this->meter->history($tenant, $status->metric, self::HISTORY_MONTHS);
            $percent = $status->percent();

            $out[] = $status->toArray() + [
                'near' => ! $status->isExhausted() && $percent !== null && $percent >= self::NEAR_PERCENT,
                'capped' => $status->metric->isCapped(),
                'is_gauge' => $status->metric->isGauge(),
                'history' => $history,
            ];
        }

        return $out;
    }
}
