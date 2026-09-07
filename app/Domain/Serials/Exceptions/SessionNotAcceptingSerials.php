<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;
use App\Models\Tenant\SessionInstance;

/** status closed|cancelled (SERIAL_ENGINE §4.1). */
final class SessionNotAcceptingSerials extends DomainException
{
    public function __construct(public readonly SessionInstance $session)
    {
        parent::__construct(sprintf('Session %s is %s and does not accept serials.', $session->public_id, $session->status->value));
    }

    public function code(): string
    {
        return 'serials.session_not_accepting';
    }

    public function status(): int
    {
        return 409;
    }
}
