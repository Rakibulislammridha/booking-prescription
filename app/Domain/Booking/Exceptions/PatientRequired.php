<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Neither a patient nor a mobile number was given, or the mobile is unknown and no name was supplied. */
final class PatientRequired extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('booking.errors.patient_required'));
    }

    public function code(): string
    {
        return 'booking.patient_required';
    }
}
