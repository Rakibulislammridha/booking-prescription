<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** OFFLINE §6.2: a reception device may never create a refund. */
final class RefundsAreOnlineOnly extends DomainException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [])
    {
        parent::__construct(__('billing.errors.refunds_are_online_only', $replace));
    }

    public function code(): string
    {
        return 'billing.refunds_are_online_only';
    }

    public function status(): int
    {
        return 403;
    }
}
