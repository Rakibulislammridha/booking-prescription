<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** The discount would drop the total below what has already been collected — refund the difference instead. */
final class DiscountBelowPaid extends DomainException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [])
    {
        parent::__construct(__('billing.errors.discount_below_paid', $replace));
    }

    public function code(): string
    {
        return 'billing.discount_below_paid';
    }

    public function status(): int
    {
        return 409;
    }
}
