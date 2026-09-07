<?php

declare(strict_types=1);

namespace App\Domain\Patients\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class OtpMobileUnknown extends DomainException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(trim('No patient record exists for this mobile number.'.' '.$detail));
    }

    public function code(): string
    {
        return 'patients.otp.unknown_mobile';
    }
}
