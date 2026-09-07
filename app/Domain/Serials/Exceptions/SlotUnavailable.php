<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;
use Carbon\CarbonImmutable;

/** Slot mode: the (session, slot_start_at) partial unique index rejected a double-booked time; never retried (§10). */
final class SlotUnavailable extends DomainException
{
    public function __construct(public readonly int $sessionInstanceId, public readonly ?CarbonImmutable $slotStartAt)
    {
        parent::__construct(sprintf('The slot %s is already taken.', $slotStartAt?->toIso8601String() ?? '?'));
    }

    public function code(): string
    {
        return 'serials.slot_taken';
    }

    public function status(): int
    {
        return 409;
    }
}
