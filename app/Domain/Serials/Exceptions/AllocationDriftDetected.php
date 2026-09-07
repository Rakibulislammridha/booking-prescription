<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Models\Tenant\SessionInstance;
use RuntimeException;
use Throwable;

/**
 * Reported (never thrown to the caller) when a serials_session_number_uniq violation fires during allocation: with
 * I-OWNER intact it is unreachable, so an alert means a block and a pool overlapped (SERIAL_ENGINE §4.2).
 */
final class AllocationDriftDetected extends RuntimeException
{
    public function __construct(public readonly SessionInstance $session, public readonly int $number, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Serial number %d of session %s was already taken while its owner row was locked.', $number, $session->public_id), 0, $previous);
    }
}
