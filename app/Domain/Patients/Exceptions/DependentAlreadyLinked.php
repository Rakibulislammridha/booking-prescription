<?php

declare(strict_types=1);

namespace App\Domain\Patients\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class DependentAlreadyLinked extends DomainException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(trim('This patient is already linked to a primary.'.' '.$detail));
    }

    public function code(): string
    {
        return 'patients.dependent_already_linked';
    }

    public function status(): int
    {
        return 409;
    }
}
