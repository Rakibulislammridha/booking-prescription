<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** A plan with live subscriptions is archived (hidden from sign-up), never deleted. */
final class PlanInUse extends DomainException
{
    public function __construct(public readonly string $planCode, public readonly int $subscriptions)
    {
        parent::__construct(__('saas.plans.in_use', ['count' => (string) $subscriptions]));
    }

    public function code(): string
    {
        return 'saas.plans.in_use';
    }

    public function status(): int
    {
        return 409;
    }
}
