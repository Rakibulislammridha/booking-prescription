<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Void, draft or fully paid: no money may be taken against this invoice. */
final class InvoiceNotPayable extends DomainException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [])
    {
        parent::__construct(__('billing.errors.invoice_not_payable', $replace));
    }

    public function code(): string
    {
        return 'billing.invoice_not_payable';
    }

    public function status(): int
    {
        return 409;
    }
}
