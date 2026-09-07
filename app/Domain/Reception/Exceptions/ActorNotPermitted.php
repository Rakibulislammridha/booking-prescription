<?php

declare(strict_types=1);

namespace App\Domain\Reception\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** X-Actor-User is missing, inactive, not desk staff, or not at the device's branch (OFFLINE §2.2). */
final class ActorNotPermitted extends DomainException
{
    public function __construct(public readonly string $reason = 'actor_not_permitted')
    {
        parent::__construct(__('reception.errors.actor_not_permitted'));
    }

    public function code(): string
    {
        return 'reception.actor_not_permitted';
    }

    public function status(): int
    {
        return 403;
    }
}
