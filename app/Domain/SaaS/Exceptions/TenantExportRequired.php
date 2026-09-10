<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** BRIEF §5.N: full data export on churn — the schema is not dropped until a fresh archive of it exists. */
final class TenantExportRequired extends DomainException
{
    public function __construct()
    {
        parent::__construct((string) __('super.tenants.delete.error.export_required'));
    }

    public function code(): string
    {
        return 'saas.tenants.export_required';
    }
}
