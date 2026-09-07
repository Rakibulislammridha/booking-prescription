<?php

declare(strict_types=1);

namespace App\Tenancy\Octane;

use App\Tenancy\Database\TenantAwarePostgresConnection;
use App\Tenancy\Tenancy;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;

/**
 * RequestReceived / TaskReceived / TickReceived: defensive — a tenant must never be active when an operation starts.
 */
final class AssertNoTenancy
{
    public function handle(object $event): void
    {
        /** @var Application $app */
        $app = property_exists($event, 'sandbox') ? $event->sandbox : $event->app;

        /** @var DatabaseManager $db */
        $db = $app->make('db');
        $connection = $db->connection('pgsql');
        $context = $app->make(TenantContext::class);

        $leaked = $context->tenant !== null
            || ($connection instanceof TenantAwarePostgresConnection && $connection->currentTenantSchema() !== null);

        if (! $leaked && $app->hasDebugModeEnabled() && $connection->getRawPdo() !== null) {
            $leaked = $connection->scalar('select current_schema()') !== 'public';
        }

        if ($leaked) {
            Log::critical('tenancy.leak', ['tenant_id' => $context->tenant?->id, 'schema' => $connection instanceof TenantAwarePostgresConnection ? $connection->currentTenantSchema() : null]);
            $app->make(Tenancy::class)->end();
        }
    }
}
