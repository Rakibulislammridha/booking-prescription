<?php

declare(strict_types=1);

namespace App\Domain\Reception\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** /sync/resolve for an event this device never sent, or one that is not in `conflict`. */
final class ConflictNotFound extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('reception.errors.conflict_not_found'));
    }

    public function code(): string
    {
        return 'reception.conflict_not_found';
    }

    public function status(): int
    {
        return 404;
    }
}
