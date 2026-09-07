<?php

declare(strict_types=1);

namespace App\Models\Tenant\Concerns;

use App\Tenancy\Database\TenantQueryBuilder;
use App\Tenancy\Exceptions\ModelTenantMismatch;
use App\Tenancy\Exceptions\TenancyNotInitialized;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Database\Query\Builder;

/**
 * The isolation guard: a tenant-schema model can neither be queried nor written without an active tenant, and an
 * instance stays pinned to the tenant it was loaded in (or created for) — saving/deleting it while another tenant
 * is active throws ModelTenantMismatch instead of hitting the same id in the other schema (ModelGuardTest).
 * Usable by third-party model subclasses (Role, Permission, PersonalAccessToken) too.
 */
trait RequiresTenancy
{
    /** Tenant id this instance was hydrated in / created for; null until it exists. */
    protected ?int $pinnedTenantId = null;

    public static function bootRequiresTenancy(): void
    {
        // Listeners must return null: creating/updating/deleting/saving are halting events (Dispatcher::until),
        // so a listener returning true would stop the hooks registered after it (public_id, tenant_id, audit).
        foreach (['retrieved', 'creating', 'updating', 'deleting', 'saving'] as $event) {
            static::registerModelEvent($event, static function (): void {
                Tenancy::check() || throw new TenancyNotInitialized(static::class);
            });
        }

        static::registerModelEvent('created', static function (self $model): void {
            $model->pinToTenant(Tenancy::id());
        });
    }

    /**
     * Hydration (find/get/relations/fresh, with or without events) pins the instance to the active tenant.
     *
     * @param  array<string, mixed>  $attributes
     * @param  string|null  $connection
     * @return static
     */
    public function newFromBuilder($attributes = [], $connection = null)
    {
        $model = parent::newFromBuilder($attributes, $connection);
        $model->pinToTenant(Tenancy::id());

        return $model;
    }

    public function pinToTenant(?int $tenantId): void
    {
        $this->pinnedTenantId = $tenantId;
    }

    public function pinnedTenantId(): ?int
    {
        return $this->pinnedTenantId;
    }

    /**
     * Every Eloquent query for the model (newQuery, relations, factories, save/delete — quiet or not) starts here:
     * the query-time guard, and the pin check for an instance that already exists.
     *
     * @return Builder
     */
    protected function newBaseQueryBuilder()
    {
        Tenancy::check() || throw new TenancyNotInitialized(static::class);

        if ($this->exists) {
            if ($this->pinnedTenantId === null) {
                $this->pinnedTenantId = Tenancy::id();                 // created quietly: pinned at its first query
            } elseif ($this->pinnedTenantId !== Tenancy::id()) {
                throw new ModelTenantMismatch(static::class, $this->pinnedTenantId, Tenancy::id(), 'query on an instance loaded in another tenant');
            }
        }

        $connection = $this->getConnection();

        return new TenantQueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
    }
}
