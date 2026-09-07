<?php

declare(strict_types=1);

namespace App\Domain\Patients\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class PatientNotInHousehold extends DomainException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(trim('That patient is not part of your household.'.' '.$detail));
    }

    public function code(): string
    {
        return 'patients.not_in_household';
    }

    public function status(): int
    {
        return 403;
    }
}
