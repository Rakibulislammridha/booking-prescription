<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Unassigning a compounder who is not on this doctor's desk — a stale screen, or a double submit. */
final class CompounderNotAssigned extends DomainException
{
    public function code(): string
    {
        return 'clinic.compounder.not_assigned';
    }

    public function status(): int
    {
        return 409;
    }
}
