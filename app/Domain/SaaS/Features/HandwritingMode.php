<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Features;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Features\Concerns\ResolvesFromPlan;
use Laravel\Pennant\Attributes\Name;

/** Stylus / handwriting sheets in the prescription writer (BRIEF §5.G.1). */
#[Name('handwriting-mode')]
final class HandwritingMode
{
    use ResolvesFromPlan;

    public function planFeature(): PlanFeatureKey
    {
        return PlanFeatureKey::HandwritingMode;
    }
}
