<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** One open cash shift per user (the partial unique index of SCHEMA §3.5). */
final class ShiftAlreadyOpen extends DomainException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [])
    {
        parent::__construct(__('billing.errors.shift_already_open', $replace));
    }

    public function code(): string
    {
        return 'billing.shift_already_open';
    }

    public function status(): int
    {
        return 409;
    }
}
