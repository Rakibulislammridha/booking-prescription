<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** The callback carried no signature this tenant can verify. Nothing is credited. */
final class GatewaySignatureInvalid extends DomainException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [])
    {
        parent::__construct(__('billing.errors.gateway_signature_invalid', $replace));
    }

    public function code(): string
    {
        return 'billing.gateway_signature_invalid';
    }
}
