<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Features;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Features\Concerns\ResolvesFromPlan;
use Laravel\Pennant\Attributes\Name;

/** WhatsApp delivery for notifications and prescriptions (BRIEF §5.J). */
#[Name('whatsapp')]
final class WhatsAppChannel
{
    use ResolvesFromPlan;

    public function planFeature(): PlanFeatureKey
    {
        return PlanFeatureKey::Whatsapp;
    }
}
