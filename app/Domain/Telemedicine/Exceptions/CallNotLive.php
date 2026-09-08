<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** End/record was asked for on a room with no live call attempt. */
final class CallNotLive extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('telemedicine.errors.call_not_live'));
    }

    public function code(): string
    {
        return 'telemedicine.call_not_live';
    }

    public function status(): int
    {
        return 409;
    }
}
