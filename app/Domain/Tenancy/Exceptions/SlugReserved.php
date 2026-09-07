<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class SlugReserved extends DomainException
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct("Tenant slug [{$slug}] is reserved or malformed.");
    }

    public function code(): string
    {
        return 'tenancy.slug_reserved';
    }
}
