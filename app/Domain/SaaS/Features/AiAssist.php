<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Features;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Features\Concerns\ResolvesFromPlan;
use Laravel\Pennant\Attributes\Name;

/** Advisory AI summaries and differentials in the writer (BRIEF §5.G.3); read by App\\Domain\\Prescription\\AI\\AiGate. */
#[Name('ai-assist')]
final class AiAssist
{
    use ResolvesFromPlan;

    public function planFeature(): PlanFeatureKey
    {
        return PlanFeatureKey::AiAssist;
    }
}
