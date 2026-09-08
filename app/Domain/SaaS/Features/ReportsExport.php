<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Features;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Features\Concerns\ResolvesFromPlan;
use Laravel\Pennant\Attributes\Name;

/** CSV / Excel / PDF export from the reports module (BRIEF §5.L). */
#[Name('reports-export')]
final class ReportsExport
{
    use ResolvesFromPlan;

    public function planFeature(): PlanFeatureKey
    {
        return PlanFeatureKey::ReportsExport;
    }
}
