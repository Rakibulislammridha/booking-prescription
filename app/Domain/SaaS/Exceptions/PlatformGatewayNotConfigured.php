<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** A platform SMS test was asked for, but `sms.*` names no provider or carries no credential. */
final class PlatformGatewayNotConfigured extends DomainException
{
    public function __construct()
    {
        parent::__construct('The platform SMS gateway is not configured: choose a provider and enter its credentials first.');
    }

    public function code(): string
    {
        return 'saas.platform_gateway_not_configured';
    }
}
