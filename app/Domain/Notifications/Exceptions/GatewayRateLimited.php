<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Shared\Exceptions\DomainException;

/** The tenant's per-minute allowance for a channel is spent; the job is released, not failed. */
final class GatewayRateLimited extends DomainException
{
    public function __construct(public readonly NotificationChannel $channel, public readonly int $retryAfterSeconds)
    {
        parent::__construct(sprintf('The %s send allowance for this tenant is spent; retry in %d s.', $channel->value, $retryAfterSeconds));
    }

    public function code(): string
    {
        return 'notifications.rate_limited';
    }

    public function status(): int
    {
        return 429;
    }
}
