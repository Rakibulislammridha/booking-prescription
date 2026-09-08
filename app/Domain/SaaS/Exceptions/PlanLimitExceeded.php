<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\Shared\Exceptions\DomainException;

/**
 * The write that would have taken this tenant past its plan's cap for `$metric`.
 *
 * 402 is deliberate (SCHEMA §5.8 "a 402-style domain error"): this is not a validation problem the user can fix by
 * typing something else and not a permission problem — it is "your plan does not include this much", and clients
 * branch on `code()` (`saas.limit.<metric>`) to offer the upgrade path.
 */
final class PlanLimitExceeded extends DomainException
{
    public function __construct(
        public readonly UsageMetric $metric,
        public readonly ?PlanFeatureKey $featureKey,
        public readonly int $limit,
        public readonly int $current,
        public readonly string $planName,
    ) {
        parent::__construct(__('saas.limit.exceeded', [
            'limit_name' => __('saas.metric.'.$metric->value),
            'limit' => (string) $limit,
            'plan' => $planName,
        ]));
    }

    public function code(): string
    {
        return 'saas.limit.'.$this->metric->value;
    }

    public function status(): int
    {
        return 402;
    }
}
