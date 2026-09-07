<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** A visit needs a patient; a bare walk-in serial without a patient row cannot start one. */
final class SerialHasNoPatient extends DomainException
{
    public function __construct(int $serialId)
    {
        parent::__construct("Serial #{$serialId} has no patient assigned; register the patient first.");
    }

    public function code(): string
    {
        return 'prescriptions.serial_has_no_patient';
    }
}
