<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Issued, paid and void invoices are immutable; a correction is a new row (Billing's rule, applied centrally). */
final class InvoiceNotEditable extends DomainException
{
    public function __construct(public readonly string $number)
    {
        parent::__construct(__('saas.invoices.not_editable', ['number' => $number]));
    }

    public function code(): string
    {
        return 'saas.invoices.not_editable';
    }

    public function status(): int
    {
        return 409;
    }
}
