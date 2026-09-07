<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class InvalidOverride extends DomainException
{
    public function __construct(string $why)
    {
        parent::__construct($why);
    }

    public function code(): string
    {
        return 'scheduling.invalid_override';
    }
}
