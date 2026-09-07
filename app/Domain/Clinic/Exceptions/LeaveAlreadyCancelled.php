<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class LeaveAlreadyCancelled extends DomainException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(trim('This leave was already withdrawn. '.$detail));
    }

    public function code(): string
    {
        return 'clinic.leave.already_cancelled';
    }

    public function status(): int
    {
        return 409;
    }
}
