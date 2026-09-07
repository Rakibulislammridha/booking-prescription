<?php

declare(strict_types=1);

namespace App\Domain\Patients\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class DuplicatePatient extends DomainException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(trim('A patient with this mobile, name and date of birth already exists.'.' '.$detail));
    }

    public function code(): string
    {
        return 'patients.duplicate';
    }

    public function status(): int
    {
        return 409;
    }
}
