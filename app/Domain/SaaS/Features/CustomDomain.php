<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Features;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Features\Concerns\ResolvesFromPlan;
use Laravel\Pennant\Attributes\Name;

/** The clinic may point its own hostname at its booking site and queue pages (BRIEF §5.M). */
#[Name('custom-domain')]
final class CustomDomain
{
    use ResolvesFromPlan;

    public function planFeature(): PlanFeatureKey
    {
        return PlanFeatureKey::CustomDomain;
    }
}
