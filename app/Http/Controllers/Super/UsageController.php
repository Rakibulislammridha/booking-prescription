<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super;

use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Queries\PlanCatalog;
use App\Domain\SaaS\Queries\TenantOverview;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The usage dashboard (BRIEF §5.M). Twelve months of one metric across the platform, plus the clinics using the
 * most of it — one grouped query over `usage_counters`, which is what that table is for.
 */
final class UsageController extends Controller
{
    public function __invoke(Request $request, TenantOverview $overview): Response
    {
        $validated = $request->validate(['metric' => ['nullable', Rule::in(UsageMetric::values())]]);
        $metric = UsageMetric::tryFrom((string) ($validated['metric'] ?? '')) ?? UsageMetric::Appointments;
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

        $top = DB::connection('pgsql')->table('public.usage_counters as u')
            ->join('public.tenants as t', 't.id', '=', 'u.tenant_id')
            ->where('u.metric', $metric->value)
            ->where('u.period', $metric->isGauge() ? 'current' : $now->format('Y-m'))
            ->whereNull('t.deleted_at')
            ->orderByDesc('u.value')->limit(15)
            ->get(['t.public_id', 't.name', 't.slug', 't.status', 'u.value', 'u.limit_snapshot']);

        return Inertia::render('Super/Usage/Index', [
            'metric' => $metric->value,
            'metrics' => UsageMetric::values(),
            'metric_labels' => PlanCatalog::metricLabels(),
            'is_bytes' => $metric->isBytes(),
            'series' => $metric->isGauge()
                ? [['period' => 'current', 'total' => (int) ($series->first()->total ?? 0), 'tenants' => (int) ($series->first()->tenants ?? 0)]]
                : array_map(fn (string $p) => [
                    'period' => $p,
                    'total' => (int) ($series->firstWhere('period', $p)->total ?? 0),
                    'tenants' => (int) ($series->firstWhere('period', $p)->tenants ?? 0),
                ], $periods),
            'top' => $top->map(fn ($r) => [
                'public_id' => $r->public_id, 'name' => $r->name, 'slug' => $r->slug, 'status' => $r->status,
                'value' => (int) $r->value, 'limit' => $r->limit_snapshot === null ? null : (int) $r->limit_snapshot,
            ])->all(),
            'totals' => $overview->platformTotals(),
        ]);
    }
}
