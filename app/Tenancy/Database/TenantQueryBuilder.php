<?php

declare(strict_types=1);

namespace App\Tenancy\Database;

use App\Tenancy\Exceptions\ModelTenantMismatch;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;

/**
 * The base query builder every tenant-schema model starts from (RequiresTenancy::newBaseQueryBuilder()). Bulk
 * writes bypass model events, so the one invariant that must hold at this level is enforced here: a `tenant_id`
 * value written into a tenant schema equals the active tenant (ModelGuardTest). The CHECK constraints on users and
 * audit_logs are the database-level backstop.
 */
final class TenantQueryBuilder extends Builder
{
    /** @param  array<int|string, mixed>  $values */
    public function insert(array $values): bool
    {
        $this->assertTenantIdValues($values, 'insert');

        return parent::insert($values);
    }

    /** @param  array<int|string, mixed>  $values */
    public function insertOrIgnore(array $values): int
    {
        $this->assertTenantIdValues($values, 'insertOrIgnore');

        return parent::insertOrIgnore($values);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  string|null  $sequence
     */
    public function insertGetId(array $values, $sequence = null): int
    {
        $this->assertTenantIdValues($values, 'insertGetId');

        return parent::insertGetId($values, $sequence);
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int|string, mixed>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        $this->assertTenantIdValues($values, 'upsert');

        if (is_array($update)) {
            $this->assertTenantIdValues($update, 'upsert');
        }

        return parent::upsert($values, $uniqueBy, $update);
    }

    /** @param  array<string, mixed>  $values */
    public function update(array $values): int
    {
        $this->assertTenantIdValues($values, 'update');

        return parent::update($values);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    public function updateOrInsert(array $attributes, array|callable $values = []): bool
    {
        $this->assertTenantIdValues($attributes, 'updateOrInsert');

        if (! is_callable($values)) {
            $this->assertTenantIdValues($values, 'updateOrInsert');
        }

        return parent::updateOrInsert($attributes, $values);
    }

    /** @param  array<int|string, mixed>  $values  one row, or a list of rows */
    private function assertTenantIdValues(array $values, string $operation): void
    {
        if ($values === []) {
            return;
        }

        /** @var array<int, array<int|string, mixed>> $rows */
        $rows = array_is_list($values) ? array_values(array_filter($values, 'is_array')) : [$values];
        $active = Tenancy::id();

        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                if (! is_string($column) || $value instanceof Expression || $value === null) {
                    continue;
                }

                $name = str_contains($column, '.') ? substr($column, (int) strrpos($column, '.') + 1) : $column;

                if ($name === 'tenant_id' && (! is_numeric($value) || (int) $value !== $active)) {
                    throw new ModelTenantMismatch($this->from instanceof Expression ? 'query' : (string) $this->from, is_numeric($value) ? (int) $value : null, $active, $operation);
                }
            }
        }
    }
}
