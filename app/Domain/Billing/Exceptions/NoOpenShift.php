<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Counter cash needs an open drawer to land in. */
final class NoOpenShift extends DomainException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [])
    {
        parent::__construct(__('billing.errors.no_open_shift', $replace));
    }

    public function code(): string
    {
        return 'billing.no_open_shift';
    }
}
