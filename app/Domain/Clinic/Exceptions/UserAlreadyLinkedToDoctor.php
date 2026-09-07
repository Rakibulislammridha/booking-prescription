<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class UserAlreadyLinkedToDoctor extends DomainException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(trim('That staff account is already linked to another doctor. '.$detail));
    }

    public function code(): string
    {
        return 'clinic.doctor.user_taken';
    }

    public function status(): int
    {
        return 409;
    }
}
