<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;
use Throwable;

final class ProvisioningFailed extends DomainException
{
    public function __construct(string $slug, Throwable $previous)
    {
        parent::__construct("Provisioning tenant [{$slug}] failed: {$previous->getMessage()}", 0, $previous);
    }

    public function code(): string
    {
        return 'tenancy.provisioning_failed';
    }
}
