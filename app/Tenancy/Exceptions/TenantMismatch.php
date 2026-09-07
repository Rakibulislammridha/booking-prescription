<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use RuntimeException;

final class TenantMismatch extends RuntimeException
{
    public function __construct(public readonly ?int $active, public readonly ?int $expected)
    {
        parent::__construct(sprintf('Job is bound to tenant [%s] but tenant [%s] is active.', $expected ?? 'null', $active ?? 'null'));
    }
}
