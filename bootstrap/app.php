<?php

declare(strict_types=1);

use App\Domain\SaaS\Http\Middleware\EnsureSuperTwoFactor;
use App\Domain\Shared\Exceptions\DomainException;
use App\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetActiveBranch;
use App\Http\Middleware\TrustProxies;
use App\Tenancy\Http\Middleware\EnsureSessionBelongsToTenant;
use App\Tenancy\Http\Middleware\EnsureTenantIsActive;
use App\Tenancy\Http\Middleware\RequireCentral;
use App\Tenancy\Http\Middleware\RequireTenant;
use App\Tenancy\Http\Middleware\ResolveTenant;
use App\Tenancy\Http\Middleware\SetTenantLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (Application $app): void {
            $central = config('tenancy.central_domain');

            // 1. super — host-constrained, registered FIRST so it wins over unconstrained site routes.
            //    EnsureSuperTwoFactor is on the whole group (ARCHITECTURE §6.5): the console that can impersonate
            //    into any clinic's records is not reachable without a second factor, and an operator who has not
            //    enrolled is held on the enrolment screen. The login pair, the challenge and that screen opt out.
            foreach (glob(base_path('routes/super/*.php')) ?: [] as $file) {
                Route::domain('super.'.$central)
                    ->middleware(['web', 'central', 'auth:super', 'idle:super', EnsureSuperTwoFactor::class])
                    ->name('super.')
                    ->group($file);
            }

            // 2. central marketing — bare central domain (and www.).
            foreach (glob(base_path('routes/central/*.php')) ?: [] as $file) {
                foreach ([$central, 'www.'.$central] as $host) {
                    Route::domain($host)->middleware(['web', 'central'])->name('central.')->group($file);
                }
            }

            // 3. api — tenant host, Sanctum (stateful cookies for same-origin, device bearer tokens for the PWA's event-log replay).
            foreach (glob(base_path('routes/api/*.php')) ?: [] as $file) {
                Route::middleware(['api', 'tenant'])->prefix('api')->name('api.')->group($file);
            }

            // 4. panel — tenant host, staff session guard.
            foreach (glob(base_path('routes/panel/*.php')) ?: [] as $file) {
                // `idle:web` (BRIEF §5.N) sits after auth:web and before the screens: it is what makes
                // users.session_timeout_minutes / settings.security.session_timeout_minutes mean something.
                Route::middleware(['web', 'tenant', 'auth:web', 'idle:web', SetActiveBranch::class])
                    ->prefix('panel')->name('panel.')->group($file);
            }

            // 5. site — tenant host, public; individual routes opt into auth:patient.
            foreach (glob(base_path('routes/site/*.php')) ?: [] as $file) {
                Route::middleware(['web', 'tenant'])->name('site.')->group($file);
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only the proxies named in TRUSTED_PROXIES are trusted, and X-Forwarded-Host only when explicitly enabled:
        // the host selects the tenant, so an untrusted client must never rewrite it (App\Http\Middleware\TrustProxies).
        $middleware->replace(Illuminate\Http\Middleware\TrustProxies::class, TrustProxies::class);
        $middleware->append(ResolveTenant::class);              // global: after TrustProxies/HandleCors/...; never aborts
        $middleware->append(AssignRequestId::class);            // global: request id + Log::withContext (needs the tenant)

        $middleware->web(append: [
            EnsureSessionBelongsToTenant::class,                 // sorted right after StartSession (priority list below)
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->statefulApi();                              // Sanctum: EnsureFrontendRequestsAreStateful on the api group
        $middleware->throttleApi();                              // throttle:api on the api group
        $middleware->api(append: [
            EnsureSessionBelongsToTenant::class,                 // no-op without a session; guards Sanctum's stateful cookie path
        ]);
        $middleware->appendToPriorityList(StartSession::class, EnsureSessionBelongsToTenant::class);

        // The tenant group runs before auth, throttling and SubstituteBindings: a tenant-model binding
        // ({session:public_id}) on the central or an unknown host must be a 404 from RequireTenant, never a
        // TenancyNotInitialized 500 from the binding, and a suspended tenant answers 402 before auth redirects.
        foreach ([RequireTenant::class, EnsureTenantIsActive::class, SetTenantLocale::class] as $tenantMiddleware) {
            $middleware->prependToPriorityList(AuthenticatesRequests::class, $tenantMiddleware);
        }

        $middleware->group('tenant', [
            RequireTenant::class,
            EnsureTenantIsActive::class,
            SetTenantLocale::class,
        ]);
        $middleware->group('central', [
            RequireCentral::class,
        ]);

        $middleware->alias([
            'idle' => EnforceIdleTimeout::class,
            'tenant.require' => RequireTenant::class,
            'central.require' => RequireCentral::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'feature' => EnsureFeaturesAreActive::class,
            'plan' => 'App\\Domain\\SaaS\\Http\\Middleware\\EnsurePlanAllows',   // SaaS module; resolved lazily when the alias is used
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        $middleware->redirectGuestsTo(fn ($request) => match (true) {
            $request->routeIs('super.*') => route('super.login'),
            $request->routeIs('site.portal.*') => route('site.portal.login'),
            default => route('panel.login'),
        });
        $middleware->redirectUsersTo(fn ($request) => match (true) {   // guest:* middleware on login pages
            $request->routeIs('super.*') => route('super.dashboard'),
            $request->routeIs('site.portal.*') && Route::has('site.portal.home') => route('site.portal.home'),
            default => route('panel.dashboard'),
        });
        $middleware->validateCsrfTokens(except: ['api/webhooks/*']);   // payment gateway callbacks
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (DomainException $e, $request) => $request->expectsJson()
            ? response()->json(['message' => $e->getMessage(), 'code' => $e->code()], $e->status())   // 422 by default; 409 for conflicts
            : back()->withErrors(['domain' => $e->getMessage()])
        );
    })->create();
