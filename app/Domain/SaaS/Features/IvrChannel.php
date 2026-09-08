<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Features;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Features\Concerns\ResolvesFromPlan;
use Laravel\Pennant\Attributes\Name;

/** Outbound IVR voice calls for patients who do not read SMS (BRIEF §5.J). */
#[Name('ivr')]
final class IvrChannel
{
    use ResolvesFromPlan;

    public function planFeature(): PlanFeatureKey
    {
        return PlanFeatureKey::Ivr;
    }
}
