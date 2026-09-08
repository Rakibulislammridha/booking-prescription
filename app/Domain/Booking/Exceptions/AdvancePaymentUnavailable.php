<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * The doctor requires payment in advance for self-service booking (`doctor_profiles.advance_payment_required`,
 * BRIEF §5.C "payment (optional / advance / full)") but this tenant cannot take money online — no gateway
 * credentials, or `booking.online_payment_enabled` is off. There is no way for the patient to pay before the
 * serial is issued, so the booking is refused BEFORE AllocateSerial runs: a number that can never be paid for
 * would only sit in the online pool until the hold expired.
 */
final class AdvancePaymentUnavailable extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('booking.errors.advance_payment_unavailable'));
    }

    public function code(): string
    {
        return 'booking.advance_payment_unavailable';
    }
}
