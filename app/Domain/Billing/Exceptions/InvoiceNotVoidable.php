<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** An invoice with settled money on it is refunded, never voided — voiding would leave the payment unaccounted for. */
final class InvoiceNotVoidable extends DomainException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [])
    {
        parent::__construct(__('billing.errors.invoice_not_voidable', $replace));
    }

    public function code(): string
    {
        return 'billing.invoice_not_voidable';
    }

    public function status(): int
    {
        return 409;
    }
}
