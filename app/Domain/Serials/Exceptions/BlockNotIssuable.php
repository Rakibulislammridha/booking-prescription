<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * AllocateFromBlock: the block is not active / not owned by the device / the number is outside next_number..range_end.
 * The sync replayer (R) turns this into the OFFLINE.md §8.2 `serial_already_used` conflict.
 */
final class BlockNotIssuable extends DomainException
{
    public function __construct(public readonly string $reason, public readonly ?int $suggestedNext = null)
    {
        parent::__construct(sprintf('The block cannot issue this number: %s.', $reason));
    }

    public function code(): string
    {
        return 'serials.block_not_issuable';
    }

    public function status(): int
    {
        return 409;
    }
}
