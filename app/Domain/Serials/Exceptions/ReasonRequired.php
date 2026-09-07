<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** A reason is mandatory for this action (vip priority insert, SERIAL_ENGINE §7.3). */
final class ReasonRequired extends DomainException
{
    public function __construct(string $action)
    {
        parent::__construct(sprintf('A reason is required to %s.', $action));
    }

    public function code(): string
    {
        return 'serials.reason_required';
    }
}
