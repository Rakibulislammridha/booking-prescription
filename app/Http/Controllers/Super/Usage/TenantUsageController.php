<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Usage;

use App\Domain\SaaS\Queries\PlanCatalog;
use App\Domain\SaaS\Queries\TenantOverview;
use App\Domain\SaaS\Queries\TenantUsageBoard;
use App\Http\Controllers\Controller;
use App\Models\Central\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/** One clinic's counters against its plan, every metric, with six months of history — the usage drill-down. */
final class TenantUsageController extends Controller
{
    public function __invoke(Tenant $tenant, TenantUsageBoard $board, TenantOverview $overview): Response
    {
        $detail = $overview->detail($tenant);

        return Inertia::render('Super/Usage/Show', [
            'tenant' => [
                'public_id' => $tenant->public_id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status->value,
                'plan_name' => $detail['plan_name'],
                'plan_code' => $detail['plan_code'],
                'timezone' => $tenant->timezone,
            ],
            'metrics' => $board->detail($tenant),
            'metric_labels' => PlanCatalog::metricLabels(),
            'near_percent' => TenantUsageBoard::NEAR_PERCENT,
            'history_months' => TenantUsageBoard::HISTORY_MONTHS,
        ]);
    }
}
