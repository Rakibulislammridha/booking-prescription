<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** `doctors.accepts_telemedicine` is false: this doctor does not see patients over video. */
final class DoctorNotTelemedicine extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('telemedicine.errors.doctor_not_available'));
    }

    public function code(): string
    {
        return 'telemedicine.doctor_not_available';
    }
}
