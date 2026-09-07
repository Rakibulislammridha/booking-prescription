<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Contracts;

use App\Domain\Notifications\Data\GatewayConfig;
use App\Domain\Notifications\Enums\NotificationChannel;

/**
 * Resolves the driver a channel should use for the CURRENT tenant. Implemented by
 * App\Domain\Notifications\Services\GatewayResolver; tests bind a fake to send everything to the log driver.
 */
interface DriverFactory
{
    public function for(NotificationChannel $channel): ChannelDriver;

    /** Explicitly build the driver for one configured gateway (the panel's "send test message"). */
    public function fromConfig(GatewayConfig $config): ChannelDriver;

    /** The gateway row backing `for($channel)`, or null when the module is falling back to the log driver. */
    public function configFor(NotificationChannel $channel): ?GatewayConfig;
}
