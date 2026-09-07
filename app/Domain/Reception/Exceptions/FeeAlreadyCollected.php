<?php

declare(strict_types=1);

namespace App\Domain\Reception\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class FeeAlreadyCollected extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('reception.errors.fee_already_collected'));
    }

    public function code(): string
    {
        return 'reception.fee_already_collected';
    }

    public function status(): int
    {
        return 409;
    }
}
