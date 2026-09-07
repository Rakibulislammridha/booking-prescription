<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Shared\Exceptions\DomainException;

/** A test send was asked for on a channel this tenant has no active gateway row for. */
final class GatewayNotConfigured extends DomainException
{
    public function __construct(public readonly NotificationChannel $channel)
    {
        parent::__construct(sprintf('No active %s gateway is configured for this tenant.', $channel->value));
    }

    public function code(): string
    {
        return 'notifications.gateway_not_configured';
    }
}
