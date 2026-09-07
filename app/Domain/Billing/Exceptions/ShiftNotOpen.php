<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** The shift is already closed; its numbers are history. */
final class ShiftNotOpen extends DomainException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [])
    {
        parent::__construct(__('billing.errors.shift_not_open', $replace));
    }

    public function code(): string
    {
        return 'billing.shift_not_open';
    }

    public function status(): int
    {
        return 409;
    }
}
