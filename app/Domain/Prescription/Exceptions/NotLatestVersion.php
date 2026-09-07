<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Amend / void allowed only on the latest issued version of a chain (PRESCRIPTION.md §6.3). */
final class NotLatestVersion extends DomainException
{
    public function __construct(int $id, string $detail)
    {
        parent::__construct("Prescription #{$id}: {$detail}");
    }

    public function code(): string
    {
        return 'prescriptions.not_latest_version';
    }

    public function status(): int
    {
        return 409;
    }
}
