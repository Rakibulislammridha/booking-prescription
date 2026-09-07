<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * The device already holds serial.max_active_blocks_per_device active blocks in this session (OFFLINE §4.1).
 * Wire code kept as OFFLINE.md specifies (`reception.block_limit`); the class lives with the LeaseBlock action.
 */
final class BlockLimitReached extends DomainException
{
    public function __construct(public readonly int $active)
    {
        parent::__construct(sprintf('The device already holds %d active block(s) for this session.', $active));
    }

    public function code(): string
    {
        return 'reception.block_limit';
    }

    public function status(): int
    {
        return 409;
    }
}
