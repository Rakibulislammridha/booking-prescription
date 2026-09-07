<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class LeaveOverlaps extends DomainException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(trim('The doctor already has leave in that range. '.$detail));
    }

    public function code(): string
    {
        return 'clinic.leave.overlaps';
    }

    public function status(): int
    {
        return 409;
    }
}
