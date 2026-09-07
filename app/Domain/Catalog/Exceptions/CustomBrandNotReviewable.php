<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Approve/reject on a promotion row that is no longer pending (CATALOG.md §8). */
final class CustomBrandNotReviewable extends DomainException
{
    public function __construct(string $status)
    {
        parent::__construct("This promotion request has already been reviewed ({$status}).");
    }

    public function code(): string
    {
        return 'catalog.promotion_not_pending';
    }

    public function status(): int
    {
        return 409;
    }
}
