<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class SessionNotFound extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('booking.errors.session_not_found'));
    }

    public function code(): string
    {
        return 'booking.session_not_found';
    }
}
