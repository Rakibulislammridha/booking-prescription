<?php

declare(strict_types=1);

namespace App\Domain\Reception\Exceptions;

use App\Domain\Reception\Enums\ConflictReason;
use App\Domain\Reception\Enums\ConflictResolution;
use App\Domain\Shared\Exceptions\DomainException;

/** The resolution does not belong to this conflict's card (OFFLINE §8), or it needs a Hospital Admin. */
final class ResolutionNotAllowed extends DomainException
{
    public function __construct(ConflictReason $reason, ConflictResolution $resolution, public readonly bool $requiresAdmin = false)
    {
        parent::__construct($requiresAdmin
            ? __('reception.errors.resolution_requires_admin', ['resolution' => $resolution->value])
            : __('reception.errors.resolution_not_allowed', ['resolution' => $resolution->value, 'reason' => $reason->value]));
    }

    public function code(): string
    {
        return $this->requiresAdmin ? 'reception.resolution_requires_admin' : 'reception.resolution_not_allowed';
    }

    public function status(): int
    {
        return $this->requiresAdmin ? 403 : 422;
    }
}
