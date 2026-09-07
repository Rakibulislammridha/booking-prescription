<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Lines are frozen once an invoice leaves draft; adjust through discounts and refunds instead (SCHEMA §5.10). */
final class InvoiceNotEditable extends DomainException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [])
    {
        parent::__construct(__('billing.errors.invoice_not_editable', $replace));
    }

    public function code(): string
    {
        return 'billing.invoice_not_editable';
    }

    public function status(): int
    {
        return 409;
    }
}
