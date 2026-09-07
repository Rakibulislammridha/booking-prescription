<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Tenancy\Console\CatalogMigrateCommand;
use App\Tenancy\Console\TenantsCreateCommand;
use App\Tenancy\Console\TenantsListCommand;
use App\Tenancy\Console\TenantsMigrateCommand;
use App\Tenancy\Console\TenantsRollbackCommand;
use App\Tenancy\Console\TenantsRunCommand;
use App\Tenancy\Console\TenantsSeedCommand;
use App\Tenancy\Database\TenantAwarePostgresConnection;
use App\Tenancy\Database\TenantMigrator;
use App\Tenancy\Facades\Tenancy as TenancyFacade;
use App\Tenancy\Listeners\BindSessionToTenant;
use App\Tenancy\Listeners\ReapplyTenantSearchPath;
use App\Tenancy\Listeners\RefuseCentralWorkInsideTenant;
use App\Tenancy\Queue\TenantQueuePayload;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

/**
 * The tenancy kernel: connection resolver, singletons, queue hooks, Pennant scope (ARCHITECTURE §4.2).
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // All pgsql connections (pgsql, catalog, catalog_admin) become tenant-aware; only 'pgsql' ever enters a tenant.
        Connection::resolverFor('pgsql', fn ($pdo, $database, $prefix, $config) => new TenantAwarePostgresConnection($pdo, $database, $prefix, $config));

        $this->app->singleton(TenantContext::class);
        $this->app->singleton(Tenancy::class);
        $this->app->singleton(TenantResolver::class);
        $this->app->singleton(TenantQueuePayload::class);
        $this->app->singleton(TenantMigrator::class);
    }

    public function boot(Dispatcher $events): void
    {
        TenantQueuePayload::register($events);

        // The base session cookie name, captured before ResolveTenant renames it per tenant host.
        config(['tenancy.session_cookie_base' => config('session.cookie')]);

        // Resolved through the facade (Container::getInstance() at call time): under Octane the request runs in a
        // sandbox container, and a closure over $this->app would answer from the base container's stale Tenancy.
        Feature::resolveScopeUsing(fn () => TenancyFacade::current());

        $events->listen(ConnectionEstablished::class, ReapplyTenantSearchPath::class);
        $events->listen(MigrationsStarted::class, [RefuseCentralWorkInsideTenant::class, 'onMigrationsStarted']);
        $events->listen(CommandStarting::class, [RefuseCentralWorkInsideTenant::class, 'onCommandStarting']);
        $events->listen(Login::class, BindSessionToTenant::class);

        if (is_dir($path = app_path('Domain/SaaS/Features'))) {
            Feature::discover('App\\Domain\\SaaS\\Features', $path);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                TenantsCreateCommand::class,
                TenantsMigrateCommand::class,
                TenantsRollbackCommand::class,
                TenantsSeedCommand::class,
                TenantsListCommand::class,
                TenantsRunCommand::class,
                CatalogMigrateCommand::class,
            ]);
        }
    }
}
