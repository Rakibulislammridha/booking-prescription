<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;
use Throwable;

/** UNIQUE (invoice_id) on coupon_redemptions — one coupon per bill. `$previous` when the index, not the validator, said so. */
final class CouponAlreadyApplied extends DomainException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [], ?Throwable $previous = null)
    {
        parent::__construct(__('billing.errors.coupon_already_applied', $replace), 0, $previous);
    }

    public function code(): string
    {
        return 'billing.coupon_already_applied';
    }

    public function status(): int
    {
        return 409;
    }
}
