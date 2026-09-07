<?php

declare(strict_types=1);

namespace App\Domain\Reception\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** No valid device token, a revoked device, or a token without the route's ability — 401 (OFFLINE §2.2). */
final class DeviceNotActive extends DomainException
{
    public function __construct(public readonly string $reason = 'unauthenticated')
    {
        parent::__construct(__('reception.errors.device_not_active'));
    }

    public function code(): string
    {
        return 'reception.device_not_active';
    }

    public function status(): int
    {
        return 401;
    }
}
