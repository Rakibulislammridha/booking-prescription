<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class HolidayAlreadyExists extends DomainException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(trim('A holiday already exists on that date for that branch. '.$detail));
    }

    public function code(): string
    {
        return 'clinic.holiday.duplicate';
    }

    public function status(): int
    {
        return 409;
    }
}
