<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;
use Throwable;

/**
 * Expired, inactive, exhausted, below the minimum invoice value or out of scope. `$previous` is set when the
 * refusal is a translated database error (ApplyCoupon's unique-index backstop) rather than the validator's own
 * decision — the concurrency suite tells the two apart by it.
 */
final class CouponInvalid extends DomainException
{
    private readonly ?string $reason;

    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [], ?Throwable $previous = null)
    {
        $this->reason = isset($replace['reason']) ? (string) $replace['reason'] : null;

        parent::__construct(__('billing.errors.coupon_invalid', $replace), 0, $previous);
    }

    public function code(): string
    {
        return 'billing.coupon_invalid';
    }

    /** The translated `billing.coupon.reason.*` sentence this refusal was made with, when there is one. */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
