<?php

declare(strict_types=1);

namespace App\Models\Tenant\Scopes;

use App\Tenancy\Facades\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Belt-and-braces WHERE tenant_id = current tenant on the tables that carry tenant_id (SCHEMA §5.9).
 * Isolation itself is the schema; this scope only asserts.
 */
final class TenantAssertionScope implements Scope
{
    /** @param  Builder<Model>  $builder */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('tenant_id'), '=', Tenancy::id());
    }
}
