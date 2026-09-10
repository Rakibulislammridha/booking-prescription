<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Billing;

use App\Domain\SaaS\Queries\BillingDunningQueue;
use App\Domain\SaaS\Queries\PlatformRevenueSummary;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The billing desk's landing page: what the platform earns, what it is owed, what it collected — and the few
 * clinics that need chasing. Every number comes from `PlatformRevenueSummary`, which the dashboard shares.
 */
final class OverviewController extends Controller
{
    public function __invoke(PlatformRevenueSummary $revenue, BillingDunningQueue $dunning): Response
    {
        return Inertia::render('Super/Billing/Index', [
            'summary' => $revenue->summary(),
            'months' => $revenue->collectedByMonth(6),
            'arrears' => $revenue->arrearsByTenant(8),
            'dunning' => $dunning->totals(),
        ]);
    }
}
