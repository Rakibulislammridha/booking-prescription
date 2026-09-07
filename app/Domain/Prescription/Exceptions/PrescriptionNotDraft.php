<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class PrescriptionNotDraft extends DomainException
{
    public function __construct(int $id, string $status)
    {
        parent::__construct("Prescription #{$id} is {$status}, not a draft.");
    }

    public function code(): string
    {
        return 'prescriptions.not_draft';
    }

    public function status(): int
    {
        return 409;
    }
}
