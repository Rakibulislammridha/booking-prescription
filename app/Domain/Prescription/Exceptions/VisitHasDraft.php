<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** One draft per visit (partial unique index). */
final class VisitHasDraft extends DomainException
{
    public function __construct(int $visitId)
    {
        parent::__construct("Visit #{$visitId} already has a draft prescription.");
    }

    public function code(): string
    {
        return 'prescriptions.visit_has_draft';
    }

    public function status(): int
    {
        return 409;
    }
}
