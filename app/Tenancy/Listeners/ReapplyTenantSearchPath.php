<?php

declare(strict_types=1);

namespace App\Tenancy\Listeners;

use App\Tenancy\Database\TenantAwarePostgresConnection;
use App\Tenancy\TenantContext;
use Illuminate\Database\Events\ConnectionEstablished;

/**
 * DB::purge('pgsql') (or any rebuilt connection) starts a fresh session on 'public' while TenantContext may still
 * hold a tenant: re-apply the active tenant's search path the moment the connection is (re)established.
 */
final class ReapplyTenantSearchPath
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(ConnectionEstablished $event): void
    {
        $connection = $event->connection;
        $tenant = $this->context->tenant;

        if ($tenant === null || $connection->getName() !== 'pgsql' || ! $connection instanceof TenantAwarePostgresConnection) {
            return;
        }

        if ($connection->currentTenantSchema() !== $tenant->schema_name) {
            $connection->setSearchPath($tenant->schema_name);
        }
    }
}
