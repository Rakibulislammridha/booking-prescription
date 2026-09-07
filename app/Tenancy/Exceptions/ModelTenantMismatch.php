<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use LogicException;

/**
 * A tenant-schema model (or a tenant_id value) is being written into a tenant other than the one it belongs to.
 * A LogicException (not a DomainException) on purpose: this is a programming error that must surface as a 500,
 * never as a 422 the client could retry. code() is the dotted identifier logs and tests branch on.
 */
final class ModelTenantMismatch extends LogicException
{
    public function __construct(
        public readonly string $model,
        public readonly ?int $expected,
        public readonly ?int $active,
        public readonly string $operation,
    ) {
        parent::__construct(sprintf(
            '%s: %s bound to tenant [%s] while tenant [%s] is active.',
            $model, $operation, $expected ?? 'null', $active ?? 'null',
        ));
    }

    public function code(): string
    {
        return 'tenancy.model_tenant_mismatch';
    }
}
