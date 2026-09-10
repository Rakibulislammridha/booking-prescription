<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super;

use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Queries\PlanCatalog;
use App\Domain\SaaS\Queries\TenantOverview;
use App\Domain\SaaS\Queries\TenantUsageBoard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Usage\UsageBoardRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The usage dashboard (BRIEF §5.M): twelve months of one metric across the platform, then every clinic's counter
 * against its cap — sortable by share of the cap, filterable to the ones over or near it. A row opens the
 * clinic's own drill-down (`Usage\TenantUsageController`).
 */
final class UsageController extends Controller
{
    public function __invoke(UsageBoardRequest $request, TenantOverview $overview, TenantUsageBoard $board): Response
    {
        $metric = $request->metric();
        $now = CarbonImmutable::now('Asia/Dhaka')->startOfMonth();
        $periods = [];

        for ($i = 11; $i >= 0; $i--) {
            $periods[] = $now->subMonths($i)->format('Y-m');
        }

        $series = DB::connection('pgsql')->table('public.usage_counters')
            ->where('metric', $metric->value)
            ->when($metric->isGauge(), fn ($q) => $q->where('period', 'current'), fn ($q) => $q->whereIn('period', $periods))
            ->selectRaw('period, sum(value) as total, count(*) as tenants')
            ->groupBy('period')->orderBy('period')->get();

        $page = $board->board($metric, $request->filter(), $request->sort(), $request->page());

        return Inertia::render('Super/Usage/Index', [
            'metric' => $metric->value,
            'metrics' => UsageMetric::values(),
            'metric_labels' => PlanCatalog::metricLabels(),
            'is_bytes' => $metric->isBytes(),
            'is_capped' => $metric->isCapped(),
            'series' => $metric->isGauge()
                ? [['period' => 'current', 'total' => (int) ($series->first()->total ?? 0), 'tenants' => (int) ($series->first()->tenants ?? 0)]]
                : array_map(fn (string $p) => [
                    'period' => $p,
                    'total' => (int) ($series->firstWhere('period', $p)->total ?? 0),
                    'tenants' => (int) ($series->firstWhere('period', $p)->tenants ?? 0),
                ], $periods),
            'board' => $page['rows'],
            'meta' => $page['meta'],
            'counts' => $page['counts'],
            'filters' => ['filter' => $request->filter(), 'sort' => $request->sort()],
            'near_percent' => TenantUsageBoard::NEAR_PERCENT,
            'totals' => $overview->platformTotals(),
        ]);
    }
}
