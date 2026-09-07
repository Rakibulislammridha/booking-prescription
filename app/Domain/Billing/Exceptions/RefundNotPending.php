<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Only a pending or approved refund can be processed or rejected; a processed one is history. */
final class RefundNotPending extends DomainException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [])
    {
        parent::__construct(__('billing.errors.refund_not_pending', $replace));
    }

    public function code(): string
    {
        return 'billing.refund_not_pending';
    }

    public function status(): int
    {
        return 409;
    }
}
