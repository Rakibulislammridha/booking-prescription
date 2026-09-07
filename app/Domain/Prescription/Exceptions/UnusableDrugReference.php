<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** A drug reference that cannot be resolved to a live generic (I4) reached an Action. */
final class UnusableDrugReference extends DomainException
{
    public function __construct(string $key, string $detail)
    {
        parent::__construct("Item {$key}: {$detail}");
    }

    public function code(): string
    {
        return 'prescriptions.unusable_drug_reference';
    }
}
