<?php

declare(strict_types=1);

namespace App\Domain\Reception\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** A device may only lease blocks for (and see) sessions at its own branch. */
final class SessionNotAtDeviceBranch extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('reception.errors.session_not_at_branch'));
    }

    public function code(): string
    {
        return 'reception.session_not_at_branch';
    }

    public function status(): int
    {
        return 403;
    }
}
