<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Domain\Tenancy\Events\TenancyEnded;
use App\Domain\Tenancy\Events\TenancyInitialized;
use App\Models\Central\PersonalAccessToken as CentralPersonalAccessToken;
use App\Models\Central\Tenant;
use App\Models\Tenant\PersonalAccessToken as TenantPersonalAccessToken;
use App\Tenancy\Database\TenantAwarePostgresConnection;
use App\Tenancy\Exceptions\TenantAlreadyInitialized;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\DatabaseManager;
use Laravel\Pennant\FeatureManager;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * The manager behind the Tenancy facade (ARCHITECTURE §4.2). Listed in config/octane.php 'flush' together with
 * TenantContext so that every Octane operation starts with exactly one, empty context.
 */
final class Tenancy
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly DatabaseManager $db,
        private readonly PermissionRegistrar $permissions,
        private readonly FeatureManager $features,
    ) {}

    public function initialize(Tenant $tenant): void
    {
        if ($this->context->tenant !== null) {
            if ($this->context->tenant->is($tenant)) {
                $this->context->tenant = $tenant;                      // same tenant is a no-op (keep the freshest instance)

                return;
            }

            throw new TenantAlreadyInitialized($this->context->tenant, $tenant);
        }

        $this->connection()->setSearchPath($tenant->schema_name);      // 'tenant_<id>' exactly (public.tenants.schema_name)
        $this->context->tenant = $tenant;
        $this->context->initialisedAt = CarbonImmutable::now();

        $this->permissions->cacheKey = "spatie.permission.cache.{$tenant->schema_name}";
        $this->permissions->clearPermissionsCollection();
        $this->features->flushCache();

        Sanctum::usePersonalAccessTokenModel(TenantPersonalAccessToken::class);
        config(['app.display_timezone' => $tenant->timezone]);         // display only; PHP default TZ stays UTC

        event(new TenancyInitialized($tenant));
    }

    /**
     * The search path is reset FIRST: if Postgres refuses the SET (aborted transaction, lost connection) the context
     * still says "tenant" and the caller (ResetTenancy, AssertNoTenancy) can repair it. Once the context is flushed
     * nothing here throws — a half-ended tenancy must never leave the process thinking it is central.
     */
    public function end(): void
    {
        $tenant = $this->context->tenant;

        if ($tenant === null) {
            $this->connection()->resetSearchPath();                    // never assume the session is already on public

            return;
        }

        $this->connection()->resetSearchPath();

        config(['app.display_timezone' => null]);
        Sanctum::usePersonalAccessTokenModel(CentralPersonalAccessToken::class);

        $this->features->flushCache();
        $this->permissions->cacheKey = (string) config('permission.cache.key');
        $this->permissions->clearPermissionsCollection();

        $this->context->flush();

        try {
            event(new TenancyEnded($tenant));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * initialize → try callback → finally end (restores the previous tenant if one was active).
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->context->tenant;

        if ($previous !== null && $previous->is($tenant)) {
            return $callback($tenant);
        }

        if ($previous !== null) {
            $this->end();
        }

        $this->initialize($tenant);

        try {
            return $callback($tenant);
        } finally {
            $this->end();

            if ($previous !== null) {
                $this->initialize($previous);
            }
        }
    }

    public function current(): ?Tenant
    {
        return $this->context->tenant;
    }

    public function check(): bool
    {
        return $this->context->tenant !== null;
    }

    public function id(): ?int
    {
        return $this->context->tenant?->id;
    }

    public function schema(): ?string
    {
        return $this->context->tenant?->schema_name;
    }

    private function connection(): TenantAwarePostgresConnection
    {
        $connection = $this->db->connection('pgsql');

        if (! $connection instanceof TenantAwarePostgresConnection) {
            throw new LogicException('The pgsql connection is not tenant-aware; is TenancyServiceProvider registered?');
        }

        return $connection;
    }
}
