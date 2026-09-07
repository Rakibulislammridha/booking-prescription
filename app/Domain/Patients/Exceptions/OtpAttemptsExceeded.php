<?php

declare(strict_types=1);

namespace App\Domain\Patients\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class OtpAttemptsExceeded extends DomainException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(trim('Too many wrong attempts; request a new code.'.' '.$detail));
    }

    public function code(): string
    {
        return 'patients.otp.attempts_exceeded';
    }

    public function status(): int
    {
        return 429;
    }
}
