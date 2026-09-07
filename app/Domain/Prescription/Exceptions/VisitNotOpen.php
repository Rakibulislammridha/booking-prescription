<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class VisitNotOpen extends DomainException
{
    public function __construct(int $visitId, string $status)
    {
        parent::__construct("Visit #{$visitId} is {$status}.");
    }

    public function code(): string
    {
        return 'prescriptions.visit_not_open';
    }

    public function status(): int
    {
        return 409;
    }
}
