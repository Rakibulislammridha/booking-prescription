<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Expired, inactive, exhausted, below the minimum invoice value or out of scope. */
final class CouponInvalid extends DomainException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [])
    {
        parent::__construct(__('billing.errors.coupon_invalid', $replace));
    }

    public function code(): string
    {
        return 'billing.coupon_invalid';
    }
}
