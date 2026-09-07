<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** The doctor is inactive or does not accept self-service (online/kiosk) booking. */
final class DoctorNotBookable extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('booking.errors.doctor_not_bookable'));
    }

    public function code(): string
    {
        return 'booking.doctor_not_bookable';
    }
}
