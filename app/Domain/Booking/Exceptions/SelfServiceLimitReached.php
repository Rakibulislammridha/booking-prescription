<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * A mobile number has already made `booking.self_service_daily_limit` self-service bookings this clinic day
 * (SCHEMA Appendix B). Online / kiosk / telemedicine only — the guard that stands in for the OTP when a clinic
 * leaves `kiosk.otp_required` off; staff counter bookings are never counted or refused.
 */
final class SelfServiceLimitReached extends DomainException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct(__('booking.errors.self_service_daily_limit', ['limit' => (string) $limit]));
    }

    public function code(): string
    {
        return 'booking.self_service_limit_reached';
    }
}
