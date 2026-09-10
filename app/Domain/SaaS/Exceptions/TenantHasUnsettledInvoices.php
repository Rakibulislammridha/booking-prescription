<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Deleting a clinic with money on the table would erase the very records the dispute is about. */
final class TenantHasUnsettledInvoices extends DomainException
{
    public function __construct(public readonly int $count)
    {
        parent::__construct((string) __('super.tenants.delete.error.unsettled', ['count' => (string) $count]));
    }

    public function code(): string
    {
        return 'saas.tenants.unsettled_invoices';
    }
}
