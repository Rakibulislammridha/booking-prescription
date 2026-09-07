<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Self-service booking with kiosk.otp_required on and no verified OTP for the mobile. */
final class OtpRequired extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('booking.errors.otp_required'));
    }

    public function code(): string
    {
        return 'booking.otp_required';
    }
}
