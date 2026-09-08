<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** `public.domains.domain` is globally unique: one hostname routes to exactly one tenant. */
final class DomainAlreadyClaimed extends DomainException
{
    public function __construct(public readonly string $host)
    {
        parent::__construct(__('saas.domains.already_claimed', ['domain' => $host]));
    }

    public function code(): string
    {
        return 'saas.domains.already_claimed';
    }

    public function status(): int
    {
        return 409;
    }
}
