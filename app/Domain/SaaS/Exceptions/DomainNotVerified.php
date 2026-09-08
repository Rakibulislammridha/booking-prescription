<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** The TXT record is missing or carries a different token; the resolver keeps ignoring the row. */
final class DomainNotVerified extends DomainException
{
    public function __construct(public readonly string $host, public readonly string $reason)
    {
        parent::__construct(__('saas.domains.not_verified', ['domain' => $host]));
    }

    public function code(): string
    {
        return 'saas.domains.not_verified';
    }
}
