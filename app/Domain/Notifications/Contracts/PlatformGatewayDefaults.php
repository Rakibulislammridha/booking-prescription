<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Contracts;

use App\Domain\Notifications\Data\GatewayConfig;
use App\Domain\Notifications\Enums\NotificationChannel;

/**
 * The gateway a tenant INHERITS when it has no usable `sms_gateway_settings` row for a channel — the platform's
 * own credentials, configured in the super console (SaaS binds `App\Domain\SaaS\Services\PlatformSmsGateway`).
 * Without a binding, or when nothing is configured, GatewayResolver falls back to the log driver as before.
 */
interface PlatformGatewayDefaults
{
    public function configFor(NotificationChannel $channel): ?GatewayConfig;
}
