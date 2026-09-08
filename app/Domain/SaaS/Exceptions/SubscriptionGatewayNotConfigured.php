<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Shared\Exceptions\DomainException;

/** The platform has no credentials for this gateway: refuse rather than accept money nothing can verify. */
final class SubscriptionGatewayNotConfigured extends DomainException
{
    public function __construct(public readonly PaymentGateway $gateway)
    {
        parent::__construct(__('saas.payments.gateway_unavailable'));
    }

    public function code(): string
    {
        return 'saas.payments.gateway_unavailable';
    }
}
