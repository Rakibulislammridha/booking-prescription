<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super;

use App\Domain\SaaS\Queries\PlanCatalog;
use App\Domain\SaaS\Queries\TenantOverview;
use App\Http\Controllers\Controller;
use App\Models\Central\CatalogReconciliationReport;
use App\Models\Central\CustomBrandPromotion;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The super console's landing page: what needs a human today, then the platform's shape.
 *
 * Every number is a `public.*` aggregate — nothing here opens a tenant schema, so the dashboard is O(queries) in
 * the number of TABLES, not in the number of clinics.
 */
final class DashboardController extends Controller
{
    public function __invoke(TenantOverview $overview): Response
    {
        $now = CarbonImmutable::now();

        return Inertia::render('Super/Dashboard', [
            'totals' => $overview->platformTotals(),
            'attention' => [
                'pending_promotions' => CustomBrandPromotion::query()->where('status', 'pending')->count(),
                'unresolved_reconciliation' => CatalogReconciliationReport::query()->whereNull('resolved_at')->where('status', '!=', 'clean')->count(),
                'overdue_invoices' => SubscriptionInvoice::query()->where('status', 'overdue')->count(),
                'trials_ending_7d' => Tenant::query()->where('status', 'trial')->whereNotNull('trial_ends_at')
                    ->whereBetween('trial_ends_at', [$now, $now->addDays(7)])->count(),
            ],
            'recent' => $overview->list(null, null, 8)['data'],
            'plans' => (new PlanCatalog)->publicPlans(),
        ]);
    }
}
