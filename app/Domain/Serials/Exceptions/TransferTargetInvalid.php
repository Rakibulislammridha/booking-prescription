<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Transfer needs a different doctor's session; postpone needs the same doctor's (SERIAL_ENGINE §9). */
final class TransferTargetInvalid extends DomainException
{
    public function __construct(string $why)
    {
        parent::__construct($why);
    }

    public function code(): string
    {
        return 'serials.transfer_target_invalid';
    }

    public function status(): int
    {
        return 409;
    }
}
