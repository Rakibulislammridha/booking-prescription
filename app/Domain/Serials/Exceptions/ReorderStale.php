<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** A drag-reorder whose neighbours are no longer adjacent / non-terminal; the board reloads (SERIAL_ENGINE §7.2). */
final class ReorderStale extends DomainException
{
    public function __construct(string $why = 'The queue changed since the board was loaded.')
    {
        parent::__construct($why);
    }

    public function code(): string
    {
        return 'serials.reorder_stale';
    }

    public function status(): int
    {
        return 409;
    }
}
