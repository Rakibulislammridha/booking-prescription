<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super;

use App\Domain\SaaS\Queries\AttentionItems;
use App\Domain\SaaS\Queries\PlanCatalog;
use App\Domain\SaaS\Queries\PlatformKpis;
use App\Domain\SaaS\Queries\PlatformTrend;
use App\Domain\SaaS\Queries\TenantOverview;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The super console's landing page — the operator's morning screen: what needs a human today (the attention
 * list, each entry a real count with a link to where it is dealt with), the platform's vital signs (tiles) and
 * thirty days of sign-ups and appointments.
 *
 * Every number is a `public.*` aggregate — nothing here opens a tenant schema — except the appointment strip,
 * which is one cached cross-schema statement (PlatformTrend). The dashboard is O(queries) in the number of
 * TABLES, not in the number of clinics.
 */
final class DashboardController extends Controller
{
    public function __invoke(TenantOverview $overview, PlatformKpis $kpis, AttentionItems $attention, PlatformTrend $trend): Response
    {
        $tiles = $kpis->all();

        return Inertia::render('Super/Dashboard', [
            'totals' => $overview->platformTotals(),
            'kpis' => $tiles,
            'attention' => $attention->all($tiles),
            'trend' => $trend->last30Days(),
            'recent' => $overview->list(null, null, 8)['data'],
            'plans' => (new PlanCatalog)->publicPlans(),
        ]);
    }
}
