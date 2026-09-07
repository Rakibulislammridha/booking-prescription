<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** The gateway reported a different amount than the payment we created. Client-supplied amounts are never trusted. */
final class GatewayAmountMismatch extends DomainException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [])
    {
        parent::__construct(__('billing.errors.gateway_amount_mismatch', $replace));
    }

    public function code(): string
    {
        return 'billing.gateway_amount_mismatch';
    }
}
