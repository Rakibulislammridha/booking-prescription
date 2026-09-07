<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** A refund may never exceed what is left of its payment after earlier refunds and open claims. */
final class RefundExceedsPayment extends DomainException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [])
    {
        parent::__construct(__('billing.errors.refund_exceeds_payment', $replace));
    }

    public function code(): string
    {
        return 'billing.refund_exceeds_payment';
    }
}
