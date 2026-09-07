<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class PlanNotFound extends DomainException
{
    public function __construct(public readonly string $planCode)
    {
        parent::__construct("Plan [{$planCode}] does not exist.");
    }

    public function code(): string
    {
        return 'tenancy.plan_not_found';
    }
}
