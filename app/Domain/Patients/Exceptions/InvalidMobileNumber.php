<?php

declare(strict_types=1);

namespace App\Domain\Patients\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class InvalidMobileNumber extends DomainException
{
    public function __construct(public readonly string $input)
    {
        parent::__construct('Not a valid Bangladeshi mobile number.');
    }

    public function code(): string
    {
        return 'patients.invalid_mobile';
    }
}
