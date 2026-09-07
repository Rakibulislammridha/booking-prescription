<?php

declare(strict_types=1);

namespace App\Models\Tenant\Concerns;

use App\Models\Tenant\Scopes\TenantAssertionScope;
use App\Tenancy\Exceptions\ModelTenantMismatch;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Database\Eloquent\Model;

/**
 * Attaches TenantAssertionScope, fills tenant_id on creating and asserts it on every write: a row's tenant_id
 * always equals the active tenant, so it can never be moved to a foreign tenant through save()/forceFill()
 * (bulk writes are caught by TenantQueryBuilder, and the per-schema CHECK constraint is the backstop).
 * TenantModel applies it when static::$assertsTenantId is true; User applies it directly.
 */
trait AssertsTenantId
{
    public static function attachTenantAssertion(): void
    {
        static::addGlobalScope(new TenantAssertionScope);

        static::creating(function (Model $model): void {
            $current = Tenancy::id();
            $given = $model->getAttribute('tenant_id');

            if ($given !== null && (int) $given !== $current) {
                throw new ModelTenantMismatch(static::class, (int) $given, $current, 'create');
            }

            $model->setAttribute('tenant_id', $current);
        });

        foreach (['updating', 'deleting'] as $event) {
            static::registerModelEvent($event, function (Model $model) use ($event): void {
                $current = Tenancy::id();
                $given = $model->getAttribute('tenant_id');

                if ($given === null || (int) $given !== $current) {
                    throw new ModelTenantMismatch(static::class, $given === null ? null : (int) $given, $current, $event);
                }
            });
        }
    }
}
