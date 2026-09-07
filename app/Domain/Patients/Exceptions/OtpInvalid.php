<?php

declare(strict_types=1);

namespace App\Domain\Patients\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class OtpInvalid extends DomainException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(trim('The code is incorrect.'.' '.$detail));
    }

    public function code(): string
    {
        return 'patients.otp.invalid';
    }
}
