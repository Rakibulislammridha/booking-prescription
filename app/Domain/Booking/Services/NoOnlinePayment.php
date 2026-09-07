<?php

declare(strict_types=1);

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Contracts\OnlinePaymentGateway;
use App\Domain\Clinic\Services\Settings;
use App\Models\Tenant\Appointment;

/**
 * "Pay at counter" — the binding used until Billing rebinds the seam. It reads the tenant switch
 * `booking.online_payment_enabled` (SCHEMA Appendix B, default false) and has no checkout to offer either way, so
 * the site says "pay at counter".
 */
final class NoOnlinePayment implements OnlinePaymentGateway
{
    public const SETTING = 'booking.online_payment_enabled';

    public function __construct(private readonly Settings $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::SETTING);
    }

    public function checkoutUrl(Appointment $appointment): ?string
    {
        return null;
    }
}
