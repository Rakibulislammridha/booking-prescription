<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Thrown by the model guard before the DB trigger would (PRESCRIPTION.md §6.5). */
final class ImmutablePrescriptionException extends DomainException
{
    public function __construct(int $prescriptionId, string $status, string $detail = '')
    {
        parent::__construct(trim("Prescription #{$prescriptionId} is immutable (status {$status}). {$detail}"));
    }

    public function code(): string
    {
        return 'prescriptions.immutable';
    }

    public function status(): int
    {
        return 409;
    }
}
