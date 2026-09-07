<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Draft, cancelled or finished appointments have no live serial to move. */
final class AppointmentNotReschedulable extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('booking.errors.not_reschedulable'));
    }

    public function code(): string
    {
        return 'booking.not_reschedulable';
    }

    public function status(): int
    {
        return 409;
    }
}
