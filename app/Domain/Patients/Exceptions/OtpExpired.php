<?php

declare(strict_types=1);

namespace App\Domain\Patients\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class OtpExpired extends DomainException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(trim('The code has expired; request a new one.'.' '.$detail));
    }

    public function code(): string
    {
        return 'patients.otp.expired';
    }
}
