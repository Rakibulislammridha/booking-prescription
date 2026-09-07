<?php

declare(strict_types=1);

namespace App\Domain\Booking\Contracts;

use App\Models\Tenant\Appointment;

/**
 * The extension point for online payment at booking time (bKash / Nagad / SSLCommerz — BRIEF §5.I, Billing module).
 * Booking v1 is "pay at counter": the bound implementation answers `enabled() === false` and the site shows the
 * counter-payment notice. Billing rebinds this contract to start a gateway checkout after AppointmentBooked.
 */
interface OnlinePaymentGateway
{
    /** Whether online payment is offered on the booking site (the `online_payment_enabled` tenant setting when registered). */
    public function enabled(): bool;

    /** URL the site redirects to after a booking when payment is required, or null to finish at "pay at counter". */
    public function checkoutUrl(Appointment $appointment): ?string;
}
