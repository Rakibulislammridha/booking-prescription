<?php

declare(strict_types=1);

namespace App\Domain\Reception\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Release/renew of a block another device (or the desk) owns. */
final class BlockNotOwned extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('reception.errors.block_not_owned'));
    }

    public function code(): string
    {
        return 'reception.block_not_owned';
    }

    public function status(): int
    {
        return 403;
    }
}
