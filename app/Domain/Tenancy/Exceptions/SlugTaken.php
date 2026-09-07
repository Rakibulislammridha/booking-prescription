<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class SlugTaken extends DomainException
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct("Tenant slug [{$slug}] is already taken.");
    }

    public function code(): string
    {
        return 'tenancy.slug_taken';
    }

    public function status(): int
    {
        return 409;
    }
}
