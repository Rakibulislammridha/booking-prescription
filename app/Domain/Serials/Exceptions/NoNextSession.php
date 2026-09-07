<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** PostponeSerial with target null and no later instance for the doctor at the branch (SERIAL_ENGINE §9.1). */
final class NoNextSession extends DomainException
{
    public function __construct()
    {
        parent::__construct('The doctor has no later session at this branch to postpone to.');
    }

    public function code(): string
    {
        return 'serials.no_next_session';
    }

    public function status(): int
    {
        return 409;
    }
}
