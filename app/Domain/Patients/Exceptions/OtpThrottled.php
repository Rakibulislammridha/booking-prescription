<?php

declare(strict_types=1);

namespace App\Domain\Patients\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class OtpThrottled extends DomainException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(trim('Please wait before requesting another code.'.' '.$detail));
    }

    public function code(): string
    {
        return 'patients.otp.throttled';
    }

    public function status(): int
    {
        return 429;
    }
}
