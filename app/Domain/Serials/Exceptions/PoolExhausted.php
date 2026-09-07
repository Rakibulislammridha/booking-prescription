<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Shared\Exceptions\DomainException;

/**
 * The pool (or block) has no unissued number left. Carries the other pools' remaining counts so the UI can offer the
 * right next step (SERIAL_ENGINE §3.3): "Issue as walk-in (buffer: 3 left)" / "Request extension".
 */
final class PoolExhausted extends DomainException
{
    /** @param  array<string, int>  $remaining */
    public function __construct(
        public readonly SerialPool $pool,
        public readonly int $sessionInstanceId,
        public readonly array $remaining = [],
    ) {
        parent::__construct(sprintf('The %s pool of session instance #%d has no serial left.', $pool->value, $sessionInstanceId));
    }

    public function code(): string
    {
        return 'serials.pool_exhausted';
    }

    public function status(): int
    {
        return 409;
    }
}
