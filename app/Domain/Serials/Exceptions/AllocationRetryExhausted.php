<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Five consecutive unique violations in one allocation — the I-OWNER invariant is breached somewhere; alarm. */
final class AllocationRetryExhausted extends DomainException
{
    public function __construct(public readonly int $sessionInstanceId)
    {
        parent::__construct(sprintf('Allocation in session instance #%d hit five consecutive unique violations.', $sessionInstanceId));
    }

    public function code(): string
    {
        return 'serials.allocation_retry_exhausted';
    }

    public function status(): int
    {
        return 409;
    }
}
