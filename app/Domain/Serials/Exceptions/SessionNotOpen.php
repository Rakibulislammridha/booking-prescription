<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;
use App\Models\Tenant\SessionInstance;

/** LeaseBlock on a closed/cancelled session (OFFLINE §4.1; wire code kept as specified there). */
final class SessionNotOpen extends DomainException
{
    public function __construct(public readonly SessionInstance $session)
    {
        parent::__construct(sprintf('Session %s is %s.', $session->public_id, $session->status->value));
    }

    public function code(): string
    {
        return 'reception.session_not_open';
    }

    public function status(): int
    {
        return 409;
    }
}
