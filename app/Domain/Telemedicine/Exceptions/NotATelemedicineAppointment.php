<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** A room was asked for on an in-person booking. The channel decides, not the caller. */
final class NotATelemedicineAppointment extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('telemedicine.errors.not_telemedicine'));
    }

    public function code(): string
    {
        return 'telemedicine.not_telemedicine';
    }
}
