<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\Shared\Exceptions\DomainException;

/** A module toggle (`telemedicine`, `whatsapp`, `custom_domain`, …) the tenant's plan does not include. */
final class FeatureNotInPlan extends DomainException
{
    public function __construct(public readonly PlanFeatureKey $featureKey, public readonly string $planName)
    {
        parent::__construct(__('saas.feature.not_in_plan', [
            'feature' => __('saas.feature.'.$featureKey->value),
            'plan' => $planName,
        ]));
    }

    public function code(): string
    {
        return 'saas.feature.'.$this->featureKey->value;
    }

    public function status(): int
    {
        return 402;
    }
}
