<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** No credentials for this gateway in tenant settings or config — never guess, never silently accept. */
final class GatewayNotConfigured extends DomainException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [])
    {
        parent::__construct(__('billing.errors.gateway_not_configured', $replace));
    }

    public function code(): string
    {
        return 'billing.gateway_not_configured';
    }
}
