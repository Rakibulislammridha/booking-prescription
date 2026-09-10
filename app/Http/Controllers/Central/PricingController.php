<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Domain\SaaS\Queries\PlanCatalog;
use App\Domain\SaaS\Support\CentralCopy;
use App\Http\Controllers\Central\Concerns\BuildsCentralLinks;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pricing, driven by the real `public.plans` / `public.plan_features` rows — the same rows `PlanLimits` enforces.
 * There is no second copy of the plan table in marketing copy, so a plan edited in the super console changes the
 * pricing page on the next request and cannot disagree with what a customer is actually sold.
 */
final class PricingController extends Controller
{
    use BuildsCentralLinks;

    public function __invoke(PlanCatalog $catalog): Response
    {
        return Inertia::render('Central/Pricing', [
            'plans' => $catalog->publicPlans(),
            'addons' => $catalog->addons(),
            'feature_labels' => PlanCatalog::featureLabels(),
            'links' => $this->centralLinks(),
            'platform' => $this->platformProps(),
            'copy' => CentralCopy::for('pricing'),
        ]);
    }
}
