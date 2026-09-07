<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use LogicException;

final class CentralCommandInsideTenant extends LogicException
{
    public function __construct(public readonly string $command, public readonly int $tenantId)
    {
        parent::__construct(sprintf(
            '[%s] is a central-only operation and cannot run while tenant [%d] is active (it would target the tenant schema). Use tenants:migrate / tenants:seed for tenant schemas.',
            $command, $tenantId,
        ));
    }
}
