<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;
use App\Models\Tenant\SessionInstance;

/** A session lifecycle action that is not legal from the current status (SERIAL_ENGINE §2.5). */
final class IllegalSessionState extends DomainException
{
    public function __construct(public readonly SessionInstance $session, string $attempted)
    {
        parent::__construct(sprintf('Session %s is %s; cannot %s.', $session->public_id, $session->status->value, $attempted));
    }

    public function code(): string
    {
        return 'serials.illegal_session_state';
    }

    public function status(): int
    {
        return 409;
    }
}
