<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** A published `drug_information.public_slug` is snapshotted into prescriptions and is never renamed (CATALOG.md §2). */
final class DrugInformationSlugLocked extends DomainException
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct("The slug '{$slug}' is published and printed on prescriptions; it cannot be changed. Unpublish first, or edit the text only.");
    }

    public function code(): string
    {
        return 'catalog.drug_information_slug_locked';
    }
}
