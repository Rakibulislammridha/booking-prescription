<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;
use App\Models\Tenant\Appointment;

/** One live booking per patient per session (the partial unique of SCHEMA §3.3). */
final class AlreadyBooked extends DomainException
{
    public function __construct(public readonly Appointment $existing)
    {
        parent::__construct(__('booking.errors.already_booked', ['code' => (string) ($existing->serial->display_code ?? '')]));
    }

    public function code(): string
    {
        return 'booking.already_booked';
    }

    public function status(): int
    {
        return 409;
    }
}
