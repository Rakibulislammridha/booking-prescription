<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Gateways\GatewayManager;
use App\Domain\Booking\Contracts\OnlinePaymentGateway;
use App\Domain\Booking\Services\NoOnlinePayment;
use App\Domain\Clinic\Services\Settings;
use App\Models\Tenant\Appointment;

/**
 * The real implementation of the Booking seam (`App\Domain\Booking\Contracts\OnlinePaymentGateway`), replacing
 * `NoOnlinePayment`. Online payment is offered only when this tenant actually has gateway credentials, so a
 * clinic that has not configured bKash/Nagad/SSLCommerz keeps seeing the "pay at counter" notice — the module
 * degrades gracefully instead of sending patients to a checkout that cannot work.
 *
 * Both halves must be true: working credentials AND the tenant switch `booking.online_payment_enabled` (SCHEMA
 * Appendix B, default false). Configuring a merchant account is not the same act as opening online payment to
 * patients, and the default is the safe one.
 */
final class BillingOnlinePaymentGateway implements OnlinePaymentGateway
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly Settings $settings,
    ) {}

    public function enabled(): bool
    {
        if (! $this->gateways->isAnyConfigured()) {
            return false;
        }

        return (bool) $this->settings->get(NoOnlinePayment::SETTING);
    }

    public function checkoutUrl(Appointment $appointment): ?string
    {
        if (! $this->enabled() || $appointment->fee_paisa <= 0) {
            return null;
        }

        return route('site.billing.checkout', ['appointment' => $appointment->public_id]);
    }
}
