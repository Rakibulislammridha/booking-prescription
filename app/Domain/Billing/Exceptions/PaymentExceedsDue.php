<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Collecting more than the outstanding balance would create an unrecorded credit. */
final class PaymentExceedsDue extends DomainException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [])
    {
        parent::__construct(__('billing.errors.payment_exceeds_due', $replace));
    }

    public function code(): string
    {
        return 'billing.payment_exceeds_due';
    }
}
