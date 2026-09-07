<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Exceptions\CatalogIsReadOnly;
use App\Domain\Catalog\Services\CatalogWriteContext;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->useLangPath(resource_path('lang'));
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Foundation-owned named limiter for the api group (throttle:api); modules register their own (queue-state, otp, booking).
        // Keyed by tenant + user: users.id is per schema, so user #1 of every clinic would otherwise share one bucket.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by(
            (string) (Tenancy::id() ?? 'central').':'.(string) (Auth::guard('sanctum')->id() ?? $request->ip()),
        ));

        // CATALOG.md §1.3 second net (non-production; the role grant is the first): the read-only `catalog` connection
        // executes SELECT/WITH/EXPLAIN only, unless a CatalogWriteContext is open. Attached on ConnectionEstablished so a
        // purged/rebuilt connection is netted too.
        if (! $this->app->isProduction()) {
            Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
                if ($event->connection->getName() === 'catalog') {
                    $this->netCatalogConnection($event->connection);
                }
            });
        }

        // Two broadcasting auth endpoints (REALTIME.md §2): staff session and device bearer token.
        Broadcast::routes(['middleware' => ['web', 'tenant']]);
        Broadcast::routes(['middleware' => ['auth:device', 'tenant'], 'prefix' => 'api/device']);

        require base_path('routes/channels.php');
    }

    private function netCatalogConnection(Connection $connection): void
    {
        $connection->beforeExecuting(function (string $query) {
            if (preg_match('/^\s*(select|with|explain|show|set\s)/i', $query) === 1) {
                return;
            }

            if (class_exists(CatalogWriteContext::class) && app(CatalogWriteContext::class)->isOpen()) {
                return;
            }

            throw new CatalogIsReadOnly('connection catalog: '.mb_substr(trim($query), 0, 80));
        });
    }
}
