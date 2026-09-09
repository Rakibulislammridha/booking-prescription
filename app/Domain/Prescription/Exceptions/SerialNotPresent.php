<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Exceptions;

use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Shared\Exceptions\DomainException;

/** The desk asked for an encounter on a patient who is not in the building (booked, no-show, cancelled…). */
final class SerialNotPresent extends DomainException
{
    public function __construct(int $serialId, SerialStatus $status)
    {
        parent::__construct(__('prescriptions.errors.serial_not_present', ['serial' => $serialId, 'status' => $status->value]));
    }

    public function code(): string
    {
        return 'prescriptions.serial_not_present';
    }

    public function status(): int
    {
        return 409;
    }
}
