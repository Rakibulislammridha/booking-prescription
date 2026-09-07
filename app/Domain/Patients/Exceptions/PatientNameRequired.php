<?php

declare(strict_types=1);

namespace App\Domain\Patients\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class PatientNameRequired extends DomainException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(trim('A name is required to register a new patient on this mobile.'.' '.$detail));
    }

    public function code(): string
    {
        return 'patients.name_required';
    }
}
