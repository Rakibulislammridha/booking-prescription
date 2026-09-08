<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** A platform invoice that is draft, void or already settled cannot take another payment. */
final class InvoiceNotPayable extends DomainException
{
    public function __construct(public readonly string $number, public readonly string $reason)
    {
        parent::__construct(__('saas.invoices.not_payable', ['number' => $number]));
    }

    public function code(): string
    {
        return 'saas.invoices.not_payable';
    }

    public function status(): int
    {
        return 409;
    }
}
