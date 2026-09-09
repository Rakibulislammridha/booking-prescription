# ARCHITECTURE — Booking to Prescription (Laravel 12 multi-tenant clinic SaaS)

Spec set reconciled 2026-09-06; SCHEMA.md is authoritative for names of tables/columns, CONVENTIONS.md §15 for names of classes/routes/keys.

Authoritative technical architecture. `docs/BRIEF.md` is the product brief and its LOCKED
decisions are not re-litigated here. `docs/CONVENTIONS.md` is the companion document
that says *how* engineers write and test code; this document says *what is built and how
it fits together*. When the two disagree, this document wins on structure and CONVENTIONS
wins on style. Module-level specifications live in `docs/SCHEMA.md` (every table and column),
`docs/SERIAL_ENGINE.md`, `docs/REALTIME.md`, `docs/OFFLINE.md`, `docs/PRESCRIPTION.md` and `docs/CATALOG.md`; this document
defers to them for table names, payload shapes and algorithms and only restates what is needed to show
how the pieces connect. Where a class, route, key or queue is named in more than one document, the
canonical spelling is the one in CONVENTIONS.md §15 (glossary of canonical names).

Every framework/package claim below was verified against the installed code in
`vendor/` and `node_modules/` on 2026-09-06 (Laravel v12.69.1, Octane 2.x, Horizon 5.48,
Reverb 1.11, inertia-laravel 3.3, @inertiajs/react 3.7.0, Scout 11.6, spatie/laravel-permission
8.3, Pennant 1.26, Sanctum 4.3, Browsershot 5.4, Ziggy 2.6, laravel-echo 2.4.0, pusher-js 8.6.0,
vite-plugin-pwa 1.3.0, laravel-vite-plugin 2.1.0, MUI 9.4.0, TypeScript 7.0.2, PHP 8.4.1,
Node 23.11.0). Where a method name is quoted, it exists with that name in that version.

---

## 0. Verified environment (do not redo)

| Item | Value |
|---|---|
| Repo | `/home/laralink/www/booking-prescription` (git, branch `main`) |
| App server | Octane + FrankenPHP; binary at `./frankenphp` (Octane finds it via `ExecutableFinder::find('frankenphp', null, [base_path()])` in `FindsFrankenPhpBinary`) |
| Databases | PostgreSQL 16 `127.0.0.1:5432`, user `root`/`password`; `booking` and `catalog` exist and are empty; `booking_test_1..16`, `catalog_test_1..16` exist for parallel engineers |
| Redis | Dragonfly on `127.0.0.1:6379` (`scripts/dev-services.sh start`) |
| Search | Meilisearch `http://127.0.0.1:7700`, key `bp-dev-master-key-0123456789abcdef` |
| Chrome | `/usr/bin/google-chrome` (`CHROME_PATH`), Node/npm paths in `NODE_BINARY`/`NPM_BINARY` |
| Fonts | Noto Sans/Serif Bengali installed system-wide; `@fontsource/noto-sans-bengali` + `@fontsource/inter` in npm |
| Constraints | No sudo, no Docker, no compiler on the dev box. Disk is tight — no git worktrees. |

Already present and reused as-is: `config/{octane,horizon,reverb,scout,permission,pennant,sanctum}.php`,
`app/Http/Middleware/HandleInertiaRequests.php`, `app/Providers/HorizonServiceProvider.php`,
Pennant `features` migration, Spatie permission migration, Sanctum `personal_access_tokens`
migration (the last two are *moved* into the tenant migration directory — see §3.4).

---

## 1. System shape

```
                       ┌──────────────── booking (PostgreSQL) ────────────────┐
                       │ public: tenants, domains, plans, subscriptions, ...   │
Browser / PWA ──HTTP──▶│ tenant_<id>: patients, serials, prescriptions, ...    │
   │                   └───────────────────────────────────────────────────────┘
   │  WebSocket                     ▲ search_path = "tenant_<id>"  (exactly; no public fallback)
   ▼                                │
 Reverb ◀── broadcast ── Octane/FrankenPHP workers ── Horizon workers (Redis queues)
                                    │                         │
                       ┌────────────┴───────────┐   ┌─────────┴──────────┐
                       │ catalog (PostgreSQL)   │   │ Meilisearch        │
                       │ generics, brands, ...  │   │ catalog_* shared   │
                       │ SELECT-only at runtime │   │ t<id>_* per tenant │
                       └────────────────────────┘   └────────────────────┘
```

Four HTTP **surfaces**, each with its own route directory, controller namespace, Inertia
root view and middleware stack:

| Surface | Host | Path prefix | Route name prefix | Controllers | JS entry | Guard |
|---|---|---|---|---|---|---|
| `panel` | tenant host | `/panel` | `panel.` | `App\Http\Controllers\Panel` | `resources/js/panel/app.tsx` (MUI) | `web` (staff) |
| `site` | tenant host (and `queue.`/`book.`/`display.` vanity prefixes) | `/` | `site.` | `App\Http\Controllers\Site` | `resources/js/site/app.tsx` (Tailwind) | none / `patient` |
| `api` | tenant host | `/api` | `api.` | `App\Http\Controllers\Api` | — | `sanctum` |
| `super` | `super.{central_domain}` | `/` | `super.` | `App\Http\Controllers\Super` | `resources/js/panel/app.tsx` (pages under `Pages/Super`) | `super` |

Plus one tiny fifth surface, `central` (marketing site, pricing, docs, domain-verification
landing) on the bare central domain: `routes/central/*.php`, `App\Http\Controllers\Central`,
rendered with the `site` bundle. It is owned by the SaaS module and exists so that the
public marketing pages are not smuggled into `super` or into a tenant surface.

Hosts in development: `APP_CENTRAL_DOMAIN=bp.localhost`, `APP_URL=http://bp.localhost:8000`.
`*.localhost` resolves to 127.0.0.1 without editing `/etc/hosts`, so tenant `demo` is
`http://demo.bp.localhost:8000`, the super panel is `http://super.bp.localhost:8000`, and
the live queue vanity host is `http://queue.demo.bp.localhost:8000/dr-rahman/today`.

---

## 2. HTTP wiring — `bootstrap/app.php` (foundation-owned, exact)

```php
<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Tenancy\Http\Middleware\EnsureTenantIsActive;
use App\Tenancy\Http\Middleware\RequireCentral;
use App\Tenancy\Http\Middleware\RequireTenant;
use App\Tenancy\Http\Middleware\ResolveTenant;
use App\Tenancy\Http\Middleware\SetTenantLocale;
use App\Http\Middleware\SetActiveBranch;
use App\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (Application $app): void {
            $central = config('tenancy.central_domain');

            // 1. super — host-constrained, registered FIRST so it wins over unconstrained site routes.
            foreach (glob(base_path('routes/super/*.php')) as $file) {
                Route::domain('super.'.$central)
                    ->middleware(['web', 'central', 'auth:super'])
                    ->name('super.')
                    ->group($file);
            }

            // 2. central marketing — bare central domain (and www.).
            foreach (glob(base_path('routes/central/*.php')) as $file) {
                foreach ([$central, 'www.'.$central] as $host) {
                    Route::domain($host)->middleware(['web', 'central'])->name('central.')->group($file);
                }
            }

            // 3. api — tenant host, Sanctum (stateful cookies for same-origin, device bearer tokens for the PWA's event-log replay).
            foreach (glob(base_path('routes/api/*.php')) as $file) {
                Route::middleware(['api', 'tenant'])->prefix('api')->name('api.')->group($file);
            }

            // 4. panel — tenant host, staff session guard.
            foreach (glob(base_path('routes/panel/*.php')) as $file) {
                Route::middleware(['web', 'tenant', 'auth:web', SetActiveBranch::class])
                    ->prefix('panel')->name('panel.')->group($file);
            }

            // 5. site — tenant host, public; individual routes opt into auth:patient.
            foreach (glob(base_path('routes/site/*.php')) as $file) {
                Route::middleware(['web', 'tenant'])->name('site.')->group($file);
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only TRUSTED_PROXIES are trusted, and X-Forwarded-Host only with TRUSTED_PROXY_HOST_HEADER=true:
        // the host selects the tenant (App\Http\Middleware\TrustProxies).
        $middleware->replace(\Illuminate\Http\Middleware\TrustProxies::class, \App\Http\Middleware\TrustProxies::class);
        $middleware->append(ResolveTenant::class);              // global: after TrustProxies/HandleCors/...; never aborts
        $middleware->append(AssignRequestId::class);            // global: request id + Log::withContext

        $middleware->web(append: [
            EnsureSessionBelongsToTenant::class,                 // sorted right after StartSession (priority list)
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->statefulApi();                              // Sanctum: EnsureFrontendRequestsAreStateful on the api group
        $middleware->throttleApi();                              // throttle:api on the api group
        $middleware->api(append: [EnsureSessionBelongsToTenant::class]);   // no-op without a session; guards Sanctum's stateful cookie path
        $middleware->appendToPriorityList(StartSession::class, EnsureSessionBelongsToTenant::class);
        // The tenant group runs before auth, throttling and SubstituteBindings: a tenant-model binding on the
        // central/unknown host is a 404 from RequireTenant, never a TenancyNotInitialized 500 from the binding.
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
            'tenant.require' => RequireTenant::class,
            'central.require' => RequireCentral::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'feature' => \Laravel\Pennant\Middleware\EnsureFeaturesAreActive::class,
            'plan' => \App\Domain\SaaS\Http\Middleware\EnsurePlanAllows::class,
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);

        $middleware->redirectGuestsTo(fn ($request) => match (true) {
            $request->routeIs('super.*') => route('super.login'),
            $request->routeIs('site.portal.*') => route('site.portal.login'),
            default => route('panel.login'),
        });
        $middleware->redirectUsersTo(fn ($request) => match (true) {   // guest:* on login pages
            $request->routeIs('super.*') => route('super.dashboard'),
            $request->routeIs('site.portal.*') && Route::has('site.portal.home') => route('site.portal.home'),
            default => route('panel.dashboard'),
        });
        $middleware->validateCsrfTokens(except: ['api/webhooks/*']);   // payment gateway callbacks
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (\App\Domain\Shared\Exceptions\DomainException $e, $request) =>
            $request->expectsJson()
                ? response()->json(['message' => $e->getMessage(), 'code' => $e->code()], $e->status())   // 422 by default; 409 for conflicts
                : back()->withErrors(['domain' => $e->getMessage()])
        );
    })->create();
```

Notes on why it is shaped this way (all verified in `Illuminate\Foundation\Configuration\ApplicationBuilder`):

* `withRouting(then:)` runs after the framework's own `web`/`api` loading inside
  `buildRoutingCallback()`; we pass **no** `web:`/`api:` file so nothing is auto-registered,
  and `routes/web.php` is deleted.
* Route files are globbed per surface so that two engineers never edit the same route
  file. A module adds `routes/panel/<module>.php`; it never touches `bootstrap/app.php`.
* `Route::domain()` on `super`/`central` is mandatory: the router returns the first
  matching route in registration order, and site routes are unconstrained, so host-bound
  routes must be registered first.
* Middleware groups can be nested by name (`'web'`, `'tenant'` inside a route group) — the
  router's `MiddlewareNameResolver` expands group names recursively.
* `ResolveTenant` is **global** (`$middleware->append`) so that tenancy is initialised
  before session start, before `auth`, and before `broadcasting/auth`.
* Broadcasting auth routes are **not** registered through `withRouting(channels:)` because two
  registrations are needed (REALTIME.md §2): `App\Providers\AppServiceProvider::boot()` calls
  `Broadcast::routes(['middleware' => ['web', 'tenant']])` and
  `Broadcast::routes(['middleware' => ['auth:device', 'tenant'], 'prefix' => 'api/device'])`, then
  `require base_path('routes/channels.php')`.
* `App\Domain\Shared\Exceptions\DomainException` carries `code(): string` (dotted, `<module>.<condition>`,
  e.g. `serials.pool_exhausted`) and `status(): int` — 422 by default; conflict-type exceptions
  (`PoolExhausted`, `IllegalTransition`, `ReorderStale`, `SplitLocked`, `SlotUnavailable`,
  `BlockLimitReached`, `SessionNotOpen`) override it to 409. Clients branch on `code`, never on the status.

Deleted from the skeleton: `routes/web.php`, `resources/views/welcome.blade.php`,
`resources/js/app.js`, `resources/js/bootstrap.js`, `vite.config.js`, `app/Models/User.php`,
`database/migrations/0001_01_01_000000_create_users_table.php`.

---

## 3. Databases and connections

### 3.1 `config/database.php` (foundation-owned)

```php
'default' => env('DB_CONNECTION', 'pgsql'),

'connections' => [
    'pgsql' => [
        'driver' => 'pgsql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '5432'),
        'database' => env('DB_DATABASE', 'booking'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',          // MUST stay exactly 'public' — see §4.1 on dropAllTables()
        'timezone' => 'UTC',                // PostgresConnector::configureTimezone() → SET time zone 'UTC'
        'sslmode' => env('DB_SSLMODE', 'prefer'),
        'application_name' => 'bp-'.env('APP_ENV', 'local'),
    ],

    // Runtime catalog connection. Production role has SELECT only.
    'catalog' => [
        'driver' => 'pgsql',
        'host' => env('CATALOG_DB_HOST', '127.0.0.1'),
        'port' => env('CATALOG_DB_PORT', '5432'),
        'database' => env('CATALOG_DB_DATABASE', 'catalog'),
        'username' => env('CATALOG_DB_USERNAME', 'root'),
        'password' => env('CATALOG_DB_PASSWORD', ''),
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'timezone' => 'UTC',
        'sslmode' => env('CATALOG_DB_SSLMODE', 'prefer'),
    ],

    // Admin (write) catalog connection: catalog:migrate, catalog:import, brand promotion.
    'catalog_admin' => [
        /* same as catalog but */ 
        'username' => env('CATALOG_ADMIN_DB_USERNAME', env('CATALOG_DB_USERNAME', 'root')),
        'password' => env('CATALOG_ADMIN_DB_PASSWORD', env('CATALOG_DB_PASSWORD', '')),
    ],
],

'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
```

Because the tenant search path contains **only** the tenant schema (§4), every package that reads a
central table while a tenant is active is configured with the schema-qualified name:
`config/pennant.php` → `'stores.database.table' => 'public.feature_flags'` (the Pennant table is renamed
`feature_flags`, migration edited accordingly); `config/queue.php` → `'failed.table' => 'public.failed_jobs'`,
`'batching.table' => 'public.job_batches'`; `config/auth.php` password brokers →
`'table' => 'public.password_reset_tokens'` for super admins (staff use the tenant-schema table, bare name).
Sessions, cache and queues are Redis, so `sessions`/`cache`/`cache_locks`/`jobs` tables are not created.

Locally `catalog` and `catalog_admin` use the same `root` credentials; in production they are
two Postgres roles. Read-only enforcement at runtime is *also* done in code (§5.3) because
the dev box cannot enforce it.

### 3.2 Schema layout of `booking`

* `public` — control plane (SCHEMA.md §2): `tenants, plans, plan_features, subscriptions,
  subscription_invoices, subscription_payments, domains, super_admins, feature_flags (Pennant),
  usage_counters, tenant_backups, catalog_reconciliation_reports, audit_logs_central,
  personal_access_tokens, password_reset_tokens, impersonation_tokens, failed_jobs, job_batches, migrations`.
* `tenant_<id>` — one schema per tenant, created by `tenants:create`. Contains every table in
  BRIEF §4 "tenant schema" plus `roles, permissions, model_has_roles, model_has_permissions,
  role_has_permissions` (Spatie), `personal_access_tokens` (Sanctum), `reception_devices,
  serial_blocks, offline_events`, `audit_logs`, and its own `migrations` table.

**Qualified-reference rule.** The session search path while a tenant is active is exactly
`"tenant_<id>"` — `public` is deliberately **not** appended. A tenant table that is missing (or a
name that exists only centrally) therefore errors loudly instead of silently reading the central
table of the same name (`personal_access_tokens`, `password_reset_tokens`, `migrations`, `audit_logs*`
exist in both places). Consequently every reference to a central table is schema-qualified:
`CentralModel` subclasses declare `protected $table = 'public.tenants'` etc., raw SQL says
`public.xxx`, tenant→central FKs say `->constrained('public.plans')`, trigger functions installed
in `public` are invoked as `public.fn_prescription_guard()`, and packages are configured with
qualified names (§3.1). Central (no-tenant) requests run with the search path explicitly reset to
`public` — never left at the previous tenant's value. This is SCHEMA.md §0.1 and §5.9, restated.

### 3.3 `catalog` database

Tables exactly as SCHEMA.md §4 (BRIEF §3.2 plus `drug_information` and `catalog_import_issues`): `generics, brands,
strengths, dosage_forms, routes, icd10_codes, drug_interactions, allergy_classes, allergy_class_generics,
pregnancy_categories, renal_cautions, hepatic_cautions, max_daily_doses, drug_information, catalog_versions,
catalog_import_issues, migrations`. Never a table named `drugs`. Primary keys are `bigint identity`; every row
carries `catalog_version_id` (the DGDA release that last touched it), `is_active` and `timestampsTz()`; the DGDA
registration number is `brands.dar_number`. Migrations live in `database/migrations/catalog/` and run on the
`catalog_admin` connection via `catalog:migrate`; they are recorded in `catalog.public.migrations`.

### 3.4 Migration directories and their repositories

| Directory | Connection | Recorded in | Run by |
|---|---|---|---|
| `database/migrations/` | `pgsql` with search_path `public` | `public.migrations` | `php artisan migrate` |
| `database/migrations/tenant/` | `pgsql` with search_path `"tenant_<id>"` (exactly) | `tenant_<id>.migrations` | `php artisan tenants:migrate` |
| `database/migrations/catalog/` | `catalog_admin` | `catalog` db `public.migrations` | `php artisan catalog:migrate` |

The stock migrator only globs `<path>/*_*.php` (verified: `Migrator::getMigrationFiles()` uses
`$this->files->glob($path.'/*_*.php')`, non-recursive), so `php artisan migrate` never picks up the
`tenant/` or `catalog/` subdirectories.

Files the foundation owner moves/renames on day one:

* `2026_09_06_054911_create_permission_tables.php` → `database/migrations/tenant/2026_02_01_000300_create_permission_tables.php`
* `2026_09_06_054911_create_personal_access_tokens_table.php` → `database/migrations/tenant/2026_02_01_000400_create_personal_access_tokens_table.php`
* `2026_09_06_054911_create_features_table.php` → `database/migrations/2026_01_01_000900_create_feature_flags_table.php` (stays central; table renamed `feature_flags`, `timestamps()` → `timestampsTz()`; Pennant scope is the tenant, storage is public)
* `0001_01_01_000001_create_cache_table.php` → deleted; `0001_01_01_000002_create_jobs_table.php` → `2026_01_01_000020_create_failed_jobs_table.php` (kept as `failed_jobs`/`job_batches` only — the `jobs`, `cache` and `cache_locks` `Schema::create` calls are removed)
* `0001_01_01_000000_create_users_table.php` → deleted; replaced by `2026_01_01_000100_create_super_admins_table.php`.

---

## 4. Tenancy (`app/Tenancy/`) — custom, schema-per-tenant

Not stancl/tenancy. One `pgsql` connection; a tenant is "entered" by changing the Postgres
session `search_path` to exactly `"tenant_<id>"` and "left" by resetting it to exactly `public`.
No `public` fallback in the tenant path (see §3.2); verified on the dev Postgres that `btree_gist`
exclusion constraints (SCHEMA.md §5.1.2) create fine under a tenant-only search path because
**default** operator classes are found without qualification.

**Named extension objects are NOT found under the tenant-only search path.** Extensions install
their opclasses and functions into `public`; only *default* opclasses (what `btree_gist` provides for
`=`/`&&`) resolve without a schema. A tenant migration that names one must schema-qualify it:
`gin (name public.gin_trgm_ops)`, `public.similarity(a, b)`, `public.unaccent(x)` — an unqualified
`gin_trgm_ops` makes `tenants:migrate` fail with "operator class does not exist"
(`MigrationIsolationTest::test_extension_operator_classes_must_be_schema_qualified…`; rule in CONVENTIONS §3.2).

### 4.1 How Laravel 12.69 resolves the schema — verified mechanism

There are **two different sources of truth** in the framework and the design must satisfy both:

1. **The live session** (`SET search_path`) governs every ordinary query
   (`select * from serials` → `tenant_7.serials`) **and** the existence/introspection checks
   for *unqualified* names. In `Illuminate\Database\Schema\Grammars\PostgresGrammar` the
   methods `compileTableExists()`, `compileColumns()`, `compileIndexes()`,
   `compileForeignKeys()` and `compileSchemas()` all compile
   `$schema ? $this->quoteString($schema) : 'current_schema()'`. So
   `Schema::hasTable('serials')` (which goes through `Builder::hasTable()` →
   `parseSchemaAndTable()` → schema `null` → `compileTableExists(null, 'serials')`) asks
   Postgres for `current_schema()` — the live session.
2. **The connection config array** governs the "default schema listing".
   `Illuminate\Database\Schema\PostgresBuilder::getCurrentSchemaListing()` returns
   `parseSearchPath($this->connection->getConfig('search_path') ?: getConfig('schema') ?: 'public')`
   (mapping `$user` to the configured username), and `Builder::getCurrentSchemaName()` returns
   its first element. `Connection::getConfig()` is just `Arr::get($this->config, $option)` on the
   in-memory `$config` array given to the constructor. Consumers of this config-derived value:
   `PostgresBuilder::dropAllTables()/dropAllViews()/dropAllTypes()` (used by `migrate:fresh` and
   `db:wipe`), `Builder::parseSchemaAndTable($ref, withDefaultSchema: true)` (used by
   `PostgresSchemaState` for `schema:dump`/`schema:load`), and `db:table`.
3. **Reconnects re-read the original config.** `Connection::reconnect()` calls the
   reconnector closure, i.e. `DatabaseManager::reconnect($name)` →
   `refreshPdoConnections($name)`, which builds a *fresh* connection from
   `configuration($name)` (the `config/database.php` array, `search_path=public`), lets
   `PostgresConnector::configureSearchPath()` run `set search_path to "public"` on the new PDO,
   then calls `setPdo()`/`setReadPdo()` on the **existing** connection object. The object keeps
   its in-memory `$config`, but its new PDO session is back on `public`. This happens after any
   "server has gone away" retry (`Connection::tryAgainIfCausedByLostConnection()`), after
   `disconnect()` and — critically — after every test's `beginDatabaseTransaction()` teardown.

Design consequences, all implemented in `App\Tenancy\Database\TenantAwarePostgresConnection`:

```php
namespace App\Tenancy\Database;

final class TenantAwarePostgresConnection extends \Illuminate\Database\PostgresConnection
{
    private ?string $tenantSchema = null;          // null = central
    private bool $searchPathDirty = false;         // PDO replaced while a tenant was active

    /** Enter a tenant schema: live session + in-memory config, both. */
    public function setSearchPath(string $tenantSchema): void
    {
        $this->assertValidSchemaName($tenantSchema);           // /^tenant_[a-z0-9_]+$/
        $this->tenantSchema = $tenantSchema;
        $this->config['search_path'] = $tenantSchema;                  // exactly one schema
        $this->applySearchPath();
    }

    /** Leave the tenant: back to exactly 'public'. */
    public function resetSearchPath(): void
    {
        $this->tenantSchema = null;
        $this->config['search_path'] = 'public';
        $this->applySearchPath();
    }

    public function currentTenantSchema(): ?string { return $this->tenantSchema; }

    /** DatabaseManager::refreshPdoConnections() calls this with a PDO whose session is on 'public'. */
    public function setPdo($pdo)
    {
        parent::setPdo($pdo);
        $this->searchPathDirty = $this->tenantSchema !== null && $pdo !== null;
        return $this;
    }

    public function setReadPdo($pdo) { parent::setReadPdo($pdo); $this->searchPathDirty = $this->searchPathDirty || ($this->tenantSchema !== null && $pdo !== null); return $this; }

    /** Lazily re-apply after a reconnect; PDO may be a Closure until first use. */
    public function getPdo()
    {
        $pdo = parent::getPdo();
        if ($this->searchPathDirty) { $this->searchPathDirty = false; $this->applySearchPath(); }
        return $pdo;
    }

    private function applySearchPath(): void
    {
        if ($this->pdo === null) { return; }                    // nothing connected yet; connector will set 'public', getPdo() fixes it
        $path = '"'.($this->tenantSchema ?? 'public').'"';
        parent::getPdo()->exec("set search_path to {$path}");   // bypass query log/listeners
        if ($this->readPdo instanceof \PDO) { $this->readPdo->exec("set search_path to {$path}"); }
    }
}
```

Registered once in `App\Tenancy\TenancyServiceProvider::register()`:

```php
\Illuminate\Database\Connection::resolverFor('pgsql',
    fn ($pdo, $database, $prefix, $config) => new TenantAwarePostgresConnection($pdo, $database, $prefix, $config));
```

`ConnectionFactory::createConnection()` consults `Connection::getResolver($driver)` before the
built-in `match`, so **all three** pgsql connections (`pgsql`, `catalog`, `catalog_admin`) become
`TenantAwarePostgresConnection`; only `pgsql` ever has `setSearchPath()` called on it.

Rules that follow from the mechanism:

* `migrate:fresh`, `db:wipe`, `schema:dump` run **only** in central context (search path config
  `public`), so `dropAllTables()` drops only public tables. `tenants:migrate --fresh` never uses
  `dropAllTables()`; it executes `DROP SCHEMA "tenant_<id>" CASCADE; CREATE SCHEMA "tenant_<id>"`.
* `Schema::hasTable('x')` / `Schema::create('x')` inside a tenant context act on the tenant
  schema (live `current_schema()` / the only search-path entry), which is exactly what the tenant
  migrator needs, including auto-creating `tenant_<id>.migrations` on first run
  (`DatabaseMigrationRepository::repositoryExists()` → `hasTable('migrations')` →
  `current_schema()` = the tenant schema → false → `createRepository()` creates it there).
* Nobody calls `DB::reconnect()` or `DB::purge()` in application code. If a lost connection is
  retried by the framework, `getPdo()` restores the tenant search path transparently; a *rebuilt*
  connection (`DB::purge()` + `DB::connection()`) fires `ConnectionEstablished`, and
  `App\Tenancy\Listeners\ReapplyTenantSearchPath` re-applies the active tenant's schema to it.
* `SET search_path` is transactional in Postgres: issued inside a transaction that later rolls
  back, the session value reverts. `Tenancy::end()` therefore always issues an explicit reset —
  it never assumes the session is already on `public` — and resets the search path **before**
  flushing the context (a refused `SET` leaves the context saying "tenant", so the Octane listeners
  can repair it); nothing throws after the flush. `ResetTenancy` rolls back any open transaction
  **first**, then ends the tenancy, then verifies the live session with `select current_schema()`.
* Central-only work is refused inside a tenant: `App\Tenancy\Listeners\RefuseCentralWorkInsideTenant`
  throws `CentralCommandInsideTenant` from `CommandStarting` for `migrate*`, `db:*`, `schema:*`
  (so `tenants:run migrate` cannot build central tables inside `tenant_<id>`) and from
  `MigrationsStarted` for any migrator that is not the running `TenantMigrator`.
* Model instances are pinned to the tenant they were hydrated in (`RequiresTenancy::newFromBuilder()`,
  `created`): saving, deleting or querying through an instance while another tenant is active throws
  `App\Tenancy\Exceptions\ModelTenantMismatch` (a `LogicException`, code `tenancy.model_tenant_mismatch`).
  On the tables that carry `tenant_id` (SCHEMA §5.9) the column is asserted on `creating/updating/deleting`,
  bulk writes through the Eloquent builder (`insert/upsert/update`) are checked by
  `App\Tenancy\Database\TenantQueryBuilder`, and the tenant migrations add a per-schema
  `CHECK (tenant_id = <id>)` (`users_tenant_id_check`, `audit_logs_tenant_id_check`) as the database backstop.
* The isolation test (CONVENTIONS §6.5) asserts `SHOW search_path` returns exactly `"tenant_test_a"`
  while tenancy is active and exactly `public` when it is not, and that a `TenantModel` query with no
  tenancy throws `TenancyNotInitialized` rather than reaching `public`.

### 4.2 Core classes

```
app/Tenancy/
  TenancyServiceProvider.php        registers resolver, singleton TenantContext, queue hooks, Octane listeners, Pennant scope, permission cache key
  TenantContext.php                 singleton: ?Tenant $tenant, ?string $surfaceHint, ?string $host, initialisedAt; flushed on every Octane operation
  Tenancy.php                       the manager behind the facade
  Facades/Tenancy.php               initialize(Tenant) / end() / run(Tenant, Closure) / current(): ?Tenant / check(): bool / id(): ?int / schema(): ?string
  TenantResolver.php                host → ?Tenant (public.domains), Redis-cached
  Database/TenantAwarePostgresConnection.php
  Database/TenantMigrator.php       wraps Illuminate\Database\Migrations\Migrator for tenant paths
  Http/Middleware/{ResolveTenant,RequireTenant,RequireCentral,EnsureTenantIsActive,SetTenantLocale}.php
  Queue/TenantAware.php             trait for jobs/listeners/notifications
  Queue/Middleware/InitializeTenancyForJob.php
  Queue/TenantQueuePayload.php      Queue::createPayloadUsing + JobProcessing/JobProcessed/JobFailed listeners
  Octane/ResetTenancy.php           listener for RequestTerminated / TaskTerminated / TickTerminated
  Octane/AssertNoTenancy.php        listener for RequestReceived / TaskReceived / TickReceived (defensive)
  Console/{TenantsCreate,TenantsMigrate,TenantsRollback,TenantsSeed,TenantsList,TenantsRun,TenantsBackup,TenantsRestore,TenantsExport,CatalogMigrate,CatalogSeed}Command.php
  Exceptions/{TenancyNotInitialized,TenantNotFound,TenantSuspended,TenantAlreadyInitialized}.php
```

`Tenancy` (concrete signatures):

```php
final class Tenancy
{
    public function __construct(private TenantContext $context, private DatabaseManager $db, private PermissionRegistrar $permissions, private FeatureManager $features) {}

    public function initialize(Tenant $tenant): void
    // 1. throws TenantAlreadyInitialized if another tenant is active (same tenant is a no-op)
    // 2. $this->db->connection('pgsql')->setSearchPath($tenant->schema_name)   // 'tenant_<id>' exactly (public.tenants.schema_name)
    // 3. $this->context->tenant = $tenant
    // 4. $this->permissions->cacheKey = "spatie.permission.cache.{$tenant->schema_name}"; $this->permissions->clearPermissionsCollection()
    // 5. $this->features->flushCache()
    // 6. config(['app.timezone.display' => $tenant->timezone]) — display only; PHP default TZ stays UTC
    // 7. event(new TenancyInitialized($tenant))

    public function end(): void
    // reverse order; resetSearchPath(); cacheKey back to config('permission.cache.key'); flushCache(); context->flush(); event(TenancyEnded)

    public function run(Tenant $tenant, Closure $callback): mixed   // initialize → try callback → finally end (restores previous tenant if any)
    public function current(): ?Tenant
    public function check(): bool
    public function id(): ?int
    public function schema(): ?string
}
```

`PermissionRegistrar::$cacheKey` is a public property set from `config('permission.cache.key')`
in `initializeCache()`, and `clearPermissionsCollection()` drops the in-memory collection
(verified). Without step 4 the Spatie cache would leak role→permission maps across tenants.
`config/permission.php` gets `'register_octane_reset_listener' => true`, and the `models.role` /
`models.permission` keys point at `App\Models\Tenant\Role` / `App\Models\Tenant\Permission`
(§5.2) so the tenancy guard applies to them.

`TenantResolver::resolve(string $host): ?Tenant`:

1. Lower-case the host, strip port.
2. If host is `super.{central}`, `{central}` or `www.{central}` → `null` (central surfaces).
3. Strip one leading **service label** from `config('tenancy.service_prefixes')` =
   `['queue', 'book', 'display']` (so `queue.hospital.com` → `hospital.com`,
   `queue.demo.bp.localhost` → `demo.bp.localhost`); remember it in `TenantContext::$surfaceHint`.
4. If the remaining host is `{slug}.{central}` → `Tenant::where('slug', $slug)`.
5. Else → `Domain::where('domain', $host)->where('verification_status', 'verified')->first()?->tenant`
   (the columns of `public.domains`, SCHEMA.md §2.7).
6. Cache the host → tenant-id mapping in Redis key `tenancy:host:{host}` for 300 s; the
   `Domain` and `Tenant` models forget it on `saved`/`deleted`.

Middleware:

* `ResolveTenant` (global): `$tenant = resolver->resolve($request->getHost())`; if found
  `Tenancy::initialize($tenant)`; never aborts. Also sets `URL::forceRootUrl($request->getSchemeAndHttpHost())`
  and names the session cookie per tenant host **before** StartSession reads it —
  `bp_{slug}_session` (`config('tenancy.session_cookie_prefix')`), the configured base name on
  central/super hosts — so a cookie minted on one tenant host is never even read on another.
* `EnsureSessionBelongsToTenant` (web group and Sanctum's stateful api path, right after StartSession):
  every session carries `tenant_id` (written on first use and by `BindSessionToTenant` on every `Login`
  event); a session presented on another tenant's host, a tenant session on a central surface or a
  super session on a tenant host is invalidated (`invalidate()` + `regenerateToken()`) before any guard
  can resolve `login_web_*` by a per-schema id. Central sessions carry no `tenant_id`.
* `App\Http\Middleware\TrustProxies` replaces the framework's: only `TRUSTED_PROXIES` (comma list or `*`,
  default none) are trusted with `FOR|PORT|PROTO|PREFIX`; `X-Forwarded-Host` only when
  `TRUSTED_PROXY_HOST_HEADER=true` — an untrusted client can never pick the tenant with a header.
* `RequireTenant`: `abort_unless(Tenancy::check(), 404)`.
* `RequireCentral`: `abort_if(Tenancy::check(), 404)`.
* `EnsureTenantIsActive`: `status` must be `trial|active|past_due` (`past_due` is served with a dunning
  banner via `SharedProps.flash.warning`); `suspended` renders `site/Suspended` (Inertia) with 402;
  `cancelled` → 404. (Status list is SCHEMA.md §2.1 — there is no `archived`.)
* `SetTenantLocale`: `app()->setLocale(session('locale') ?? $tenant->locale->value ?? 'bn')` (`tenants.locale`
  and `users.locale` are cast to `App\Domain\Clinic\Enums\Locale`).
* Priority (bootstrap/app.php): `StartSession → EnsureSessionBelongsToTenant → … → RequireTenant →
  EnsureTenantIsActive → SetTenantLocale → Authenticate → ThrottleRequests → … → SubstituteBindings`, so the
  tenant group runs before auth, throttling and route-model binding on every surface (`MiddlewareOrderTest`).

### 4.3 Artisan commands

| Command | Signature | Behaviour |
|---|---|---|
| `tenants:create` | `{name} {--slug=} {--plan=starter} {--domain=} {--admin-email=} {--admin-password=} {--demo}` | Calls `App\Domain\Tenancy\Actions\ProvisionTenant` (§4.4). Prints id, schema, panel URL. |
| `tenants:migrate` | `{--tenant=* : ids or slugs} {--fresh} {--seed} {--step} {--pretend}` | For each tenant (all active by default): `Tenancy::run($t, fn() => TenantMigrator::migrate(...))`. `--fresh` drops and recreates the schema first. `--seed` runs `Database\Seeders\Tenant\TenantDatabaseSeeder`. Exit code non-zero if any tenant fails; continues with the rest. |
| `tenants:rollback` | `{--tenant=*} {--step=1} {--pretend}` | `Migrator::rollback([$path], ['step' => n])` inside `Tenancy::run`. `--step` has Laravel's meaning: the last *n migration files*, not batches. |
| `tenants:seed` | `{--tenant=*} {--class=TenantDatabaseSeeder}` | Runs `Database\Seeders\Tenant\<class>` per tenant. `RolesAndPermissionsSeeder` is idempotent and is what deployments run after adding permissions. |
| `tenants:list` | `{--json}` | Table: id, slug, schema, status, plan, domains, migrated batch, size (`pg_total_relation_size` sum). |
| `tenants:run` | `{command} {--tenant=*} {--option=*}` | Scheduler fan-out helper (§4.7). `migrate*`, `db:*`, `schema:*` are refused inside a tenant (`RefuseCentralWorkInsideTenant`). |
| `lang:check` | — | CONVENTIONS §7.5: en/bn parse, identical key sets, keys sorted within each prefix block, every `__()`/`t()` literal exists. |
| `patients:prune-otp` | — | Deletes day-old `patient_otp_codes` (`OtpService::prune`); tenant-scoped, scheduled as `tenants:run patients:prune-otp` daily 03:30 in `routes/console.php`. |
| `tenants:backup` | `{--tenant=*} {--disk=backups}` | §8.9. |
| `tenants:restore` | `{tenant} {file} {--force}` | §8.9. |
| `tenants:export` | `{tenant} {--disk=backups}` | JSON + CSV bundle for churn (BRIEF §N). |
| `catalog:migrate` | `{--fresh} {--seed} {--pretend}` | `Migrator::usingConnection('catalog_admin', ...)` over `database/migrations/catalog`. `--fresh` = `Schema::connection('catalog_admin')->dropAllTables()`. |
| `catalog:seed` | `{--class=CatalogSampleSeeder}` | `Database\Seeders\Catalog\CatalogSampleSeeder` — delegates to `catalog:import database/seeders/Catalog/data --source=seed` so the dev sample goes through the importer (CATALOG.md §3; counts are asserted there). |
| `catalog:import` | `{path} {--source=dgda\|seed\|manual} {--version=} {--full} {--dry-run} {--force} {--reindex}` | CATALOG.md §5 (Catalog module, `App\Console\Commands\Catalog`). |
| `catalog:reconcile` | `{--tenant=} {--since=}` | §8.3 / CATALOG.md §6 nightly orphan scan. |
| `catalog:index-search` | `{--index=all\|catalog_drugs\|catalog_icd10} {--fresh} {--changed-since=}` | Builds the shared Meilisearch indexes through `App\Domain\Catalog\Search\CatalogSearchIndexer` (zero-downtime swap; CATALOG.md §4.4). Catalog models are **not** Scout-searchable — the documents are joins. |
| `tenants:sync-search-settings` | `{--tenant=*}` | Applies `CustomBrandIndexSettings::array()` / patient index settings to each tenant's `t{id}_*` indexes (CATALOG.md §4.2). |
| `tenants:reindex` | `{--tenant=*} {--model=}` | `scout:import` for the tenant's `Searchable` models (`Patient`, `CustomBrand`, …) inside `Tenancy::run`. |

`TenantMigrator` (foundation): constructs its own `DatabaseMigrationRepository($resolver, 'migrations')`
and `Migrator($repository, $resolver, $files, $events)` bound to connection `pgsql`, then — inside
`Tenancy::run` — calls `repositoryExists()`/`getRepository()->createRepository()` and
`run([database_path('migrations/tenant')], ['step' => ...])`. Migration classes in that directory
must **not** set `$connection`.

### 4.4 Provisioning — `App\Domain\Tenancy\Actions\ProvisionTenant`

```php
public function handle(ProvisionTenantData $data): Tenant
```
1. `DB::transaction` on `pgsql` (central): insert `public.tenants` (status `trial`, `schema_name` =
   `tenant_{id}` filled after insert, `locale` `bn`, `timezone` `Asia/Dhaka`), `public.domains`
   row for `{slug}.{central}` (verified), subscription for the plan.
2. `CREATE SCHEMA "tenant_{id}"` (outside the transaction; DDL is cheap and idempotent-guarded).
3. `Tenancy::run($tenant, ...)`: run tenant migrations, `RolesAndPermissionsSeeder`, create
   first branch + Hospital Admin user, `App\Domain\Catalog\Search\MeilisearchIndexes::ensureTenantIndexes($tenant)`
   (creates `t{id}_patients` and `t{id}_custom_brands` with their settings; §8.6).
4. Optional `--demo` → `DemoDataSeeder` (2 doctors, schedules for 14 days, 30 patients).
5. `event(new TenantProvisioned($tenant))`. On any failure after step 2: drop the schema, delete
   the rows, rethrow (`ProvisioningFailed`).

The onboarding wizard (SaaS module) calls the same action. Slugs are validated first: a well-formed DNS
label (2–63 chars, `[a-z0-9-]`) that is not reserved — `config('tenancy.reserved_slugs')` = super, www,
queue, book, display, api, admin, app, mail, static, cdn (+ the service prefixes) — else `SlugReserved`
(`tenancy.slug_reserved`). `App\Domain\Tenancy\Rules\NotReservedSlug` is the reusable validation rule.

### 4.5 Octane safety

`config/octane.php` (foundation-owned) changes:

```php
'listeners' => [
    RequestReceived::class => [...Octane::prepareApplicationForNextOperation(), ...Octane::prepareApplicationForNextRequest(), AssertNoTenancy::class],
    TaskReceived::class    => [...Octane::prepareApplicationForNextOperation(), AssertNoTenancy::class],
    TickReceived::class    => [...Octane::prepareApplicationForNextOperation(), AssertNoTenancy::class],
    RequestTerminated::class => [ResetTenancy::class],
    TaskTerminated::class    => [ResetTenancy::class],
    TickTerminated::class    => [ResetTenancy::class],
    // OperationTerminated / WorkerErrorOccurred / WorkerStopping unchanged
],
'flush' => [
    \App\Tenancy\TenantContext::class,
    \App\Tenancy\TenantResolver::class,
    \App\Domain\Clinic\Services\ActiveBranch::class,      // the staff user's active branch (SetActiveBranch middleware, SharedProps.branch)
    \App\Domain\Audit\AuditRecorder::class,          // holds request id / actor for the current request
    \App\Domain\Catalog\Services\CatalogWriteContext::class,
],
```

* `ResetTenancy::handle($event)` → `$event->sandbox->make(Tenancy::class)->end()` wrapped in
  try/catch/log; then verifies `DB::connection('pgsql')->currentTenantSchema() === null`. It also
  asserts `DB::connection('pgsql')->transactionLevel() === 0` and, if not, rolls back and logs
  `tenancy.leaked_transaction` at `critical` — a leaked transaction would keep serial-pool row locks
  and freeze booking clinic-wide (SERIAL_ENGINE.md §4.5).
* `AssertNoTenancy` runs `select current_schema()` only when `app()->hasDebugModeEnabled()`
  (local/testing); in production it just checks the in-memory `currentTenantSchema()`. If a
  tenant is still active it resets and logs `tenancy.leak` at `critical` — this must never fire.
* Why not `DisconnectFromDatabases`? It would cost a new PG connection per request. The
  connection persists across requests inside a worker (the `db` manager is not flushed;
  `GiveNewApplicationInstanceToDatabaseManager` only swaps the container reference), so the
  search path *must* be reset explicitly — that is `ResetTenancy`.
* Pennant already listens to `RequestReceived/TaskReceived/TickReceived` and calls
  `FeatureManager::flushCache()` (verified in `PennantServiceProvider`). Spatie's Octane listener
  (`register_octane_reset_listener => true`) clears its in-memory permission collection on
  `OperationTerminated`. Both are kept; `Tenancy::initialize()` still does its own flush because
  tenants can change *within* a worker via `Tenancy::run()` in jobs and commands.
* Static state is forbidden in `App\*` except enum/const tables. `Ziggy::$cache` (route list) is
  fine — routes do not vary per tenant.
* `flush` also lists `App\Tenancy\Tenancy` itself (it holds the `TenantContext`), so a request never
  sees two contexts; the `Tenancy` facade is not cached (`$cached = false`) for the same reason.
  Pennant's default scope is resolved through the facade at call time (`Feature::resolveScopeUsing(fn () =>
  Tenancy::current())`), never through a closure over the provider's container, which under Octane would be
  the base container and answer `null` in a worker's first request.

Run: `php artisan octane:start --server=frankenphp --host=127.0.0.1 --port=8000 --workers=4 --max-requests=500 --watch`.

### 4.6 Queue jobs and Horizon

Verified order in `Illuminate\Queue\Worker::process()`: `raiseBeforeJobEvent()` (`JobProcessing`)
fires **before** `$job->fire()`, and `fire()` is where `CallQueuedHandler::call()` unserialises
the command (and therefore where `SerializesModels` restores Eloquent models by querying).
Job middleware runs even later, from the unserialised command. Hence tenancy must be
initialised from the **payload**, at `JobProcessing`, not from the job object.

`App\Tenancy\Queue\TenantQueuePayload::register()` (called from `TenancyServiceProvider::boot()`):

```php
Queue::createPayloadUsing(fn (string $connection, ?string $queue, array $payload) => [
    'tenant_id' => Tenancy::id(),                  // null when dispatched centrally
]);
Event::listen(JobProcessing::class, function (JobProcessing $e) {
    $tenantId = $e->job->payload()['tenant_id'] ?? null;
    if ($tenantId !== null) { Tenancy::initialize(Tenant::query()->findOrFail($tenantId)); }
});
foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class, JobReleasedAfterException::class] as $ev) {
    Event::listen($ev, fn () => Tenancy::check() && Tenancy::end());
}
```

`TenantQueuePayload` keeps a **frame stack**, not a flag: every `JobProcessing` pushes a frame recording
whether it initialised the tenancy; the matching end event (`JobProcessed`, or the first of
`JobExceptionOccurred`/`JobReleasedAfterException`/`JobFailed` — a job pops exactly once) pops it and ends
the tenancy only when the frame that started it unwinds. Nested inline dispatch (`dispatch_sync`, a sync
chain, a queued listener run inline) therefore never leaks the outer tenant into the worker. If a payload
arrives while a tenancy is active that no frame owns (a leak from a previous operation), it is ended with an
`error`-level `tenancy.queue.leaked_tenancy` log and the job runs in its own tenant (or centrally); a nested
job whose payload names a different tenant throws `TenantMismatch`.

`withCreatePayloadHooks()` merges the returned array into every payload (verified), so this
covers Scout's `MakeSearchable`, queued notifications/mails/listeners and broadcasts without
any per-class work.

The `TenantAware` trait is for jobs that are *explicitly* tenant-bound (dispatched from central
context such as the scheduler, or that must assert their tenant):

```php
trait TenantAware
{
    public ?int $tenantId = null;                                     // serialised with the job

    public function forTenant(Tenant|int $tenant): static { $this->tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant; return $this; }
    public function middleware(): array { return [new InitializeTenancyForJob]; }
    public function tags(): array { return ['tenant:'.($this->tenantId ?? Tenancy::id() ?? 'central')]; }   // Horizon
}
```

`InitializeTenancyForJob::handle($job, Closure $next)`: if `Tenancy::check()` and
`Tenancy::id() !== $job->tenantId` → throw `TenantMismatch` (never silently switch); if
tenancy is not active → `Tenancy::initialize(Tenant::findOrFail($job->tenantId))` and end it in
`finally`. Dispatch from a scheduler: `SendDayBeforeReminders::dispatch()->forTenant($tenant)` is
wrong (dispatch is immediate); write `dispatch((new SendDayBeforeReminders)->forTenant($tenant))`.

Horizon (`config/horizon.php`) — the **only** queue names in the system (a job that names another
queue is a bug; there is no `sessions`, `maintenance` or `realtime` queue):

| Queue | Used by |
|---|---|
| `critical` | queue-state pushes and call-next broadcasts (`QueueStateUpdated`, `BoardUpdated`, REALTIME.md §3), `InvalidateQueueState` follow-ups |
| `default` | everything not listed below: session materialisation (`MaterialiseTenantSessions`), ETA refresh, `catalog:reconcile` per-tenant jobs, doctor-favourite recompute, replay side effects |
| `notifications` | SMS/WhatsApp/push/email sends, `NotifyApproachingSerials`, `FanOutSessionDelay`, reminders |
| `pdf` | `GeneratePrescriptionPdf`, invoice/receipt PDFs (Browsershot; 2 processes) |
| `search` | Scout `MakeSearchable`/`RemoveFromSearch`, `tenants:reindex` chunks |
| `reports` | report exports, nightly aggregates |
| `backups` | `tenants:backup`, `tenants:export` |

Three supervisors: `supervisor-critical` (queue `critical`, `maxProcesses` 4, `timeout` 30),
`supervisor-pdf` (queue `pdf`, `maxProcesses` 2, `timeout` 150), `supervisor-default` (the rest,
balance `auto`). `HORIZON_DOMAIN=super.{central}`, `'middleware' => ['web', 'central', 'auth:super']`,
`HorizonServiceProvider::gate()` → `Auth::guard('super')->check()`. Tags come from
`TenantAware::tags()` so Horizon can filter by `tenant:<id>`.

### 4.7 Scheduled work

`routes/console.php` (foundation-owned; modules register their schedule in
`app/Domain/<Module>/Schedule.php` classes implementing `App\Support\Scheduling\RegistersSchedule`
which the foundation loops over — so no module edits `routes/console.php`):

```php
public function register(Schedule $schedule): void
{
    $schedule->command('tenants:run notifications:send-reminders --option=window=day-before')->hourly()->timezone('Asia/Dhaka');
    $schedule->command('sessions:materialise --days=14')->dailyAt('00:10')->timezone('Asia/Dhaka');      // Scheduling: fans out MaterialiseTenantSessions per tenant (SERIAL_ENGINE.md §2.2)
    $schedule->command('tenants:run sessions:close-stale')->dailyAt('23:55')->timezone('Asia/Dhaka');     // SERIAL_ENGINE.md §5.1
    $schedule->command('tenants:run queue:refresh-eta')->everyMinute();                                   // REALTIME.md §4.3
    $schedule->command('catalog:reconcile')->dailyAt('02:00');                                             // CATALOG.md §6
    $schedule->command('tenants:run prescriptions:recompute-favourites')->dailyAt('03:00')->timezone('Asia/Dhaka'); // PRESCRIPTION.md §3.5
    $schedule->command('tenants:backup')->dailyAt('02:30')->timezone('Asia/Dhaka')->onOneServer();
}
```

`tenants:run {command}` iterates `Tenant::active()->orderBy('id')->cursor()`, runs
`Tenancy::run($tenant, fn () => Artisan::call($command, $options))`, catches and reports
per-tenant exceptions, and returns non-zero if any failed. Long per-tenant work must instead
dispatch a `TenantAware` job per tenant (fan-out to Horizon) so one slow clinic cannot delay
another's reminders.

### 4.8 Broadcast channels (Reverb)

One Reverb app for all tenants; isolation is by channel name + authorisation. The design is
REALTIME.md §2 — restated: `{tenantId}` is the tenant's **public id** (ULID), never the bigint.

| Channel | Kind | Name | Guards (`routes/channels.php`) |
|---|---|---|---|
| queue (patient page, slip QR, display tiles) | public | `tenant.{tenantId}.queue.{sessionInstancePublicId}` | none |
| reception board | private | `tenant.{tenantId}.reception.{branchPublicId}` | `web`, `sanctum`, `device` |
| doctor screen | private | `tenant.{tenantId}.doctor.{doctorPublicId}` | `web`, `sanctum` |
| waiting-room display | private | `tenant.{tenantId}.display.{branchPublicId}` | `device`, `web` |
| prescription writer (PDF ready) | private | `tenant.{tenantId}.prescription.{prescriptionPublicId}` | `web` (PRESCRIPTION.md §7.5) |

`App\Domain\Queue\TenantChannel::queue(SessionInstance)/reception(Branch)/doctor(Doctor)/display(Branch)/prescription(Prescription)`
build `Channel`/`PrivateChannel` instances — events never build names by hand;
`App\Domain\Queue\ChannelGuards` authorises the four queue channels and
`App\Domain\Prescription\Services\PrescriptionChannelGuard` the prescription channel (REALTIME.md §2;
`routes/channels.php` is foundation-owned and adds the lines on request).
Public channels carry serial codes and statuses only. Broadcast events are `ShouldBroadcast`/`ShouldBroadcastNow`
classes in `App\Domain\Queue\Events` (queue `critical`), built by listeners on the Serials domain events;
the `queue.state` payload is the `QueueState` document (REALTIME.md §4.1) so realtime and polling clients
see identical documents (SCHEMA.md §5.7 states the same keys, channels and ETag).

---

## 5. Models and the domain layer

### 5.1 Three model families, three base classes

```php
// app/Models/Central/CentralModel.php
abstract class CentralModel extends Model
{
    protected $connection = 'pgsql';
    // Subclasses MUST declare protected $table = 'public.<name>' (schema-qualified). Verified: Grammar::wrapTable()
    // splits on '.' and quotes each segment → "public"."tenants", so the model is correct under any search path.
    public static function bootCentralModel(): void
    {
        str_starts_with((new static)->getTable(), 'public.') || throw new \LogicException(static::class.' must set $table = "public.…"');
    }
}

// app/Models/Tenant/Concerns/RequiresTenancy.php  — the isolation guard, usable by 3rd-party model subclasses too
trait RequiresTenancy
{
    public static function bootRequiresTenancy(): void
    {
        foreach (['retrieved', 'creating', 'updating', 'deleting', 'saving'] as $event) {
            static::registerModelEvent($event, fn () => Tenancy::check() || throw new TenancyNotInitialized(static::class));
        }
    }
    public function newEloquentBuilder($query) { Tenancy::check() || throw new TenancyNotInitialized(static::class); return parent::newEloquentBuilder($query); }
}

// app/Models/Tenant/TenantModel.php
abstract class TenantModel extends Model
{
    use RequiresTenancy, Auditable, HasPublicId;    // Auditable is opt-in via static::$audited = true (§8.1); HasPublicId acts only when the table has public_id
    protected $connection = 'pgsql';                // bare table names; resolved by the tenant-only search path
    protected static bool $audited = false;
    protected static bool $assertsTenantId = false;  // true on users, patients, appointments, prescriptions, invoices, payments, audit_logs (SCHEMA.md §5.9)
    public static function bootTenantModel(): void
    {
        if (static::$assertsTenantId) {
            static::addGlobalScope(new TenantAssertionScope);                 // WHERE tenant_id = Tenancy::id()
            static::creating(fn (self $m) => $m->tenant_id ??= Tenancy::id());
        }
    }
    public function searchableAs() { return config('scout.prefix').'t'.Tenancy::id().'_'.parent::getTable(); }
}

// app/Models/Catalog/CatalogModel.php
abstract class CatalogModel extends Model
{
    public $timestamps = true;                       // created_at/updated_at are written by the importer under catalog_admin (SCHEMA.md §4); catalog_version_id marks the release
    protected $guarded = [];
    public function getConnectionName() { return app(CatalogWriteContext::class)->isOpen() ? 'catalog_admin' : 'catalog'; }
    public static function bootCatalogModel(): void
    {
        foreach (['creating', 'updating', 'deleting', 'saving'] as $event) {
            static::registerModelEvent($event, fn () => app(CatalogWriteContext::class)->isOpen() || throw new CatalogIsReadOnly(static::class));
        }
    }
    // NOT Searchable: the shared indexes are joins built by App\Domain\Catalog\Search\CatalogSearchIndexer (CATALOG.md §4).
}
```

`App\Domain\Catalog\Services\CatalogWriteContext` (singleton, in Octane `flush`; no static state):
`run(Closure $fn, ?string $reason = null): mixed` opens the admin context for the duration of the
closure (inside a `catalog_admin` transaction), `isOpen(): bool` reports it. It is used only by
`catalog:migrate`, `catalog:seed`, `catalog:import` and `App\Domain\Catalog\Actions\PromoteCustomBrand`
(super admin, guard `super`). Writes outside it throw `App\Domain\Catalog\Exceptions\CatalogIsReadOnly`.
Query-builder writes (`DB::connection('catalog')->table()->update()`) are forbidden and caught by PHPStan rule
`App\Support\PhpStan\NoCatalogQueryBuilderWrites` (identifier `bp.catalogQueryBuilderWrite`, registered in
`phpstan.neon`). It walks the fluent chain back from `insert`/`update`/`upsert`/`delete`/`truncate`/`increment` and
their variants to whatever produced the builder, following an alias (`$catalog = DB::connection('catalog')`) when the
name is bound to nothing else. It keys on the literal connection name, so the sanctioned `catalog_admin` path
(`CatalogWriteContext`, `Import\Upserter`, `catalog:migrate`) needs no exemption and a non-literal
`DB::connection($name)` is left alone. Two deliberate limits: raw connection SQL (`->statement()`, `->unprepared()`)
is not a query-builder write, and files in the `Tests\` namespace are skipped — `CatalogModelBaseGuardTest` proves the
runtime net by performing exactly this write and asserting `CatalogIsReadOnly`, and no static check can tell that call
apart from a real one. Both of those remain covered at runtime by the SELECT-only grant on the `catalog` role.

Third-party models that live in the tenant schema subclass the vendor model and add the trait:
`App\Models\Tenant\Role extends \Spatie\Permission\Models\Role { use RequiresTenancy; }`,
`App\Models\Tenant\Permission`, `App\Models\Tenant\PersonalAccessToken extends \Laravel\Sanctum\PersonalAccessToken { use RequiresTenancy; }`
and `App\Models\Central\PersonalAccessToken` (`$table = 'public.personal_access_tokens'`, super admins).
`AuthServiceProvider::boot()` calls `Sanctum::usePersonalAccessTokenModel(Tenant\PersonalAccessToken::class)`
and `TenancyEnded` swaps it to the central class (Sanctum holds one static model; the swap is done in
`Tenancy::initialize()/end()` so each context sees its own token table).

### 5.2 Model inventory (owner in brackets; full column lists live in the migrations)

Model class names are exactly the `**Model**` lines of SCHEMA.md (one model per table; no model for a
table SCHEMA.md does not list). Central (`app/Models/Central`, columns per SCHEMA.md §2): `Tenant` (implements `FeatureScopeable`;
`schema_name` column = `tenant_{id}`, filled by provisioning), `Domain`, `Plan`, `PlanFeature`, `Subscription`,
`SubscriptionInvoice`, `SubscriptionPayment`, `SuperAdmin` (Authenticatable), `UsageCounter`, `TenantBackup`,
`CatalogReconciliationReport`, `CustomBrandPromotion`, `AuditLogCentral` (`audit_logs_central`), `PersonalAccessToken`, `ImpersonationToken`.

Tenant (`app/Models/Tenant`) — by module: **Clinic** `Branch, Department, Specialty, User
(Authenticatable, HasRoles, HasApiTokens), Doctor, DoctorProfile, DoctorSpecialty, DoctorPadSetting, Setting`; **Scheduling**
`DoctorSchedule, ScheduleOverride, Holiday, DoctorLeave` (there is no `doctor_sessions` table — BRIEF's
"doctor session" is `SessionInstance`); **Serials**
`SessionInstance, SerialPool, Serial, SerialEvent, SerialBlock`; **Booking** `Appointment`; **Reception**
`ReceptionDevice (HasApiTokens — the `device` guard's tokenable), OfflineEvent`; **Patients** `Patient (Authenticatable), PatientRelation,
PatientAllergy, PatientCondition, PatientMedication, PatientDocument, PatientConsent, PatientOtpCode`;
**Prescription** `Visit, Vital, Prescription, PrescriptionItem, PrescriptionInvestigation, PrescriptionAdvice,
PrescriptionReferral, PrescriptionTemplate, PrescriptionTemplateItem, DoctorFavourite, DoctorDrugUsage,
AdviceSnippet, InvestigationCatalogItem, ExternalDiagnosticCentre, AiSuggestion` (versions and handwriting
pages are rows/paths on `Prescription`, not models); **Catalog (tenant side)** `CustomBrand`;
**Billing** `Invoice, InvoiceItem, Payment, Refund, Discount, Coupon, CouponRedemption, DoctorRevenueShare, CashShift`;
**Notifications** `NotificationTemplate, Notification, NotificationLog, SmsGatewaySetting, PushSubscription`;
**Audit** `AuditLog`; **Telemedicine** `TelemedicineRoom, TelemedicineSession`.

Catalog (`app/Models/Catalog`): `Generic, Brand, Strength, DosageForm, Route, Icd10Code,
DrugInteraction, AllergyClass, AllergyClassGeneric, PregnancyCategory, RenalCaution, HepaticCaution, MaxDailyDose,
DrugInformation, CatalogVersion, CatalogImportIssue`.

Primary keys: `bigint identity` everywhere; externally visible rows carry `public_id char(26)` ULID via
`App\Models\Concerns\HasPublicId` (SCHEMA.md §0.2); offline-created rows carry `client_event_id` ULID as
the idempotency key (see CONVENTIONS §3.2). Tenant→central foreign keys are real FKs
(`->constrained('public.plans')` compiles to `references "id" on "public"."plans"`).

### 5.3 Domain layer — `app/Domain/<Module>/`

```
app/Domain/<Module>/
  Actions/        one class per write use-case; `final class AllocateSerial { public function __invoke(AllocationRequest $r): Serial }` (one DTO → `__invoke`; otherwise `handle(...)`, CONVENTIONS §4)
  Services/       stateless read/compute helpers (EtaCalculator, ShorthandParser, SafetyPipeline)
  Data/           `final readonly class` DTOs with `public static function fromRequest(FormRequest $r): self` (e.g. `App\Domain\Serials\Data\AllocationRequest`)
  Events/         domain events; broadcasting ones implement ShouldBroadcast
  Listeners/      event listeners (queued ones use TenantAware)
  Jobs/           queued jobs (TenantAware)
  Enums/          PHP 8 backed enums (string-backed; stored as varchar)
  Rules/          validation rules
  Exceptions/     extend App\Domain\Shared\Exceptions\DomainException (carries a stable `code()` string, e.g. 'serials.pool_exhausted')
  Policies/       Eloquent policies (auto-discovered by model name convention is OFF; registered in the module's ServiceProvider)
  Features/       Pennant feature classes (SaaS module owns most)
  Notifications/  Laravel notifications for the module's events
  <Module>ServiceProvider.php   registers policies, event listeners, schedule, Pennant features; listed in bootstrap/providers.php by the foundation owner once per module
  Schedule.php    implements App\Support\Scheduling\RegistersSchedule
```

Modules: `Tenancy, Clinic, Patients, Scheduling, Serials, Booking, Queue, Reception, Prescription,
Catalog, Billing, Notifications, Reports, SaaS, Telemedicine, Audit`, plus `Shared` (base
exception, `Money`, `Result`, `Actor`). There is **no** `Realtime` module: channels, guards, the
`QueueState` builder/repository and every broadcast event live in `App\Domain\Queue` (REALTIME.md).
A large internal subsystem may add module-private sub-namespaces beside the standard ones
(Prescription: `Safety`, `Shorthand`, `Render`, `AI`; Reception: `Handlers`; Catalog: `Search`, `Import`;
module middleware: `App\Domain\<Module>\Http\Middleware`), but enums always sit in `Enums`, DTOs in
`Data` and exceptions in `Exceptions`. Controllers are thin: validate (FormRequest) → build Data → call
Action → return Inertia/Resource/redirect. No business logic in controllers, models or jobs.

`App\Domain\Shared\Actor` is the readonly DTO every write action receives for "who did this":
`{userId: ?int, role: ?string, deviceId: ?int, patientId: ?int, ip: ?string, source: web|api|offline_replay|system}`;
`Actor::fromRequest()` / `Actor::system()` build it, `AuditRecorder` and `SerialEventWriter` persist it.

Cross-module calls go through the other module's **Actions/Services only**, never its models'
internals; a module may read another module's models directly (e.g. Queue reads `Serial`) but
writes only through that module's Actions. Cross-module *reactions* go through the events in §5.4.

### 5.4 Cross-module event registry

Every event one module raises and another consumes. Producers dispatch after commit
(`ShouldDispatchAfterCommit`); the **consuming** module owns its listener (queued listeners use
`TenantAware`). A listener not listed here is module-internal. Broadcast classes are listed
separately at the end because they mirror domain events under the same short names.

| Event (FQCN) | Payload (summary) | Producer | Consumers (listener) |
|---|---|---|---|
| `App\Domain\Tenancy\Events\TenantProvisioned` | tenant | Tenancy | SaaS (`SendWelcome`), Notifications |
| `App\Domain\Tenancy\Events\TenancyInitialized` / `TenancyEnded` | tenant | Tenancy | Tenancy only (Sanctum model swap, Pennant/permission cache) |
| `App\Domain\Serials\Events\SerialAllocated` | serial, session, source, pool, appointment_id | Serials | Queue (`InvalidateQueueState`), Billing (`CreateInvoiceLineForSerial`), Notifications (`SendBookingConfirmation`), SaaS (usage via `AppointmentBooked`, not this event) |
| `App\Domain\Serials\Events\SerialStatusChanged` | serial, from, to, actor | Serials | Queue (`InvalidateQueueState`, broadcast), Audit |
| `App\Domain\Serials\Events\SerialCalled` | serial, session, previous now_serving | Serials | Queue (`InvalidateQueueState`, `BroadcastSerialCalled`, `NotifyApproachingSerials`), Serials (`ApplyAutoNoShow`) |
| `App\Domain\Serials\Events\SerialCompleted` | serial, duration_seconds | Serials | Queue, Reports |
| `App\Domain\Serials\Events\SerialCancelled` | serial, reason_code, cancelled_by_role, minutes_before_planned_start, refund_eligible | Serials | Billing (`DecideRefundOnCancellation`), Queue, Notifications |
| `App\Domain\Serials\Events\SerialNoShow` | serial, reason `auto\|manual\|session_closed` | Serials | Billing, Notifications, Queue |
| `App\Domain\Serials\Events\SerialReinstated` / `SerialReinstatedAfterCancel` | serial (+ prior refund status) | Serials | Queue; Billing (`VoidPendingRefund`) for the *AfterCancel* variant |
| `App\Domain\Serials\Events\SerialPostponed` / `SerialTransferred` | old, new (+ old_fee_snapshot, target_fee) | Serials | Notifications (patient), Billing (fee delta), Queue (both sessions) |
| `App\Domain\Serials\Events\SerialReordered` / `SerialPriorityInserted` | serial, from_position, to_position | Serials | Queue |
| `App\Domain\Serials\Events\SessionCapacityExtended`, `SessionDelayed`, `SessionCancelled`, `SessionClosed`, `DoctorArrived`, `SessionPaused`, `SessionResumed` | session, actor, values | Serials | Queue (`InvalidateQueueState`, broadcasts), Notifications (`FanOutSessionDelay` on `SessionDelayed`; `SessionCancelled` fan-out), Reception (`ReleaseBlocksOnSessionClosed` — SERIAL_ENGINE.md §2.5) |
| `App\Domain\Queue\Events\SerialApproaching` | serial, ahead | Queue (`NotifyApproachingSerials`, REALTIME.md §7) | Notifications (`SendThreeAheadSms`) |
| `App\Domain\Booking\Events\AppointmentBooked` | appointment, serial, channel, previous_visit_id | Booking | SaaS (`IncrementMonthlyAppointments`), Billing (free follow-up window), Notifications |
| `App\Domain\Prescription\Events\PrescriptionIssued` | tenantId, prescriptionId, visitId, patientId, doctorId, serialId | Prescription | Prescription (`GeneratePrescriptionPdf`, `RecordDoctorUsage`), Notifications (`SendPrescriptionReady`), Serials (`CompleteConsultationOnPrescriptionIssued` → `CompleteConsultation` if the visit's serial is `in_consultation`), SaaS (usage `prescriptions`) |
| `App\Domain\Prescription\Events\FollowUpScheduled` | tenantId, prescriptionId, visitId, patientId, doctorId, branchId, followUpDate, note, createBooking | Prescription | Booking (`CreateDraftFollowUpAppointment`, PRESCRIPTION.md §4.8), Notifications (`ScheduleFollowUpReminder`) |
| `App\Domain\Prescription\Events\PrescriptionDeliveryRequested` | tenantId, prescriptionId, patientId, channel, to, verificationUrl, pdfPath, language | Prescription | Notifications (`DeliverPrescription`; waits for `PdfReady` when `pdfPath` is null) |
| `App\Domain\Prescription\Events\PdfReady` | prescriptionId, path | Prescription | Prescription (broadcast on the prescription channel), Notifications |
| `App\Domain\Notifications\Events\SmsSent` | tenantId, notificationLogId, segments | Notifications | SaaS (`IncrementSmsCredits`) |
| `App\Domain\Catalog\Events\CatalogVersionPublished` | version, stats, open issues | Catalog (central) | SaaS (super-admin notification) |
| `App\Domain\Catalog\Events\CatalogReconciliationCompleted` | runId, tenants, orphans, inactive | Catalog (central) | SaaS (dashboard tile, email when `orphans > 0`) |
| `App\Domain\Catalog\Events\CustomBrandCreated` | tenantId, customBrandId, snapshot | Catalog | Catalog (`QueueCustomBrandForPromotion` → `public.custom_brand_promotions`) |

Broadcast (wire) events — all in `App\Domain\Queue\Events`, built by Queue listeners on the domain events
above, never dispatched from controllers (REALTIME.md §3): `SerialCalled`, `SerialCalledPrivate`,
`SerialStatusChanged`, `QueueStateUpdated`, `SessionDelayed`, `SessionCancelled`, `DoctorArrived`,
`BoardUpdated`, `CallNext`. Four of them share a short name with a Serials domain event — they are
**different classes**; import with the FQCN and never `use App\Domain\Serials\Events\SerialCalled` inside
`App\Domain\Queue\Events` or vice versa. The prescription channel's `PdfReady` is the only broadcast
outside Queue (`App\Domain\Prescription\Events\PdfReady implements ShouldBroadcast`).

---

## 6. Authentication and authorisation

### 6.1 `config/auth.php`

```php
'defaults' => ['guard' => 'web', 'passwords' => 'users'],
'guards' => [
    'web'     => ['driver' => 'session', 'provider' => 'staff'],
    'patient' => ['driver' => 'session', 'provider' => 'patients'],
    'super'   => ['driver' => 'session', 'provider' => 'super_admins'],
    'device'  => ['driver' => 'sanctum', 'provider' => 'reception_devices'],   // reception/display devices (OFFLINE.md §2)
    // 'sanctum' is registered by Sanctum: tries bearer token, else falls back to config('sanctum.guard') = ['web']
],
'providers' => [
    'staff'        => ['driver' => 'eloquent', 'model' => App\Models\Tenant\User::class],
    'patients'     => ['driver' => 'eloquent', 'model' => App\Models\Tenant\Patient::class],
    'super_admins' => ['driver' => 'eloquent', 'model' => App\Models\Central\SuperAdmin::class],
    'reception_devices' => ['driver' => 'eloquent', 'model' => App\Models\Tenant\ReceptionDevice::class],
],
'passwords' => ['users' => ['provider' => 'staff', 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60],
                'super_admins' => ['provider' => 'super_admins', 'table' => 'public.password_reset_tokens', 'expire' => 60, 'throttle' => 60]],
```

Sessions are Redis (`SESSION_DRIVER=redis`), cookie is host-only (`SESSION_DOMAIN=null`). Because
`users.id` is per schema (id 1 is every clinic's hospital admin), a session id replayed on another tenant
host would otherwise load *that* tenant's user with the same id; two layers prevent it
(`SessionReplayAcrossTenantsTest`, `SessionTenantBindingTest`): the session cookie **name** is per tenant
host (`bp_{slug}_session`, set by `ResolveTenant` before StartSession), and every session is **bound** to
its tenant (`tenant_id`, written by `BindSessionToTenant` on `Login` and by `EnsureSessionBelongsToTenant`
on first use) and invalidated when presented on any other tenant or central host (§4.2). The `staff`,
`patients` and `reception_devices` providers use driver `tenant` (`App\Auth\TenantUserProvider`): Eloquent,
but they answer `null` without an active tenant, so a stray `login_web_*` marker on `super.{central}` is
"not authenticated", never a `TenancyNotInitialized` 500. The `api` rate limiter is keyed
`{tenant_id|central}:{user id|ip}` so clinics never share a bucket. Session lifetime 120 min with
`authenticateSessions()` **not** enabled (it would force re-login on password change across devices).

**Idle timeout and device management (BRIEF §5.N).** `App\Http\Middleware\EnforceIdleTimeout` (alias `idle`) runs
on the `panel` group as `idle:web` and the `super` group as `idle:super`. It stamps `idle_last_activity_at` on the
session and, past the effective limit, logs the guard out, invalidates the session and returns the person to their
login screen with `auth.idle_timeout` (401 JSON for an XHR). The limit is `users.session_timeout_minutes` falling
back to the tenant setting `security.session_timeout_minutes`; the super console has no tenant to ask and uses
`config('session.idle_timeout_minutes')`. It is deliberately **not** on the `api` group (`/api/ping` lives there),
and the two panel routes the desk and the doctor screen poll every 5 s — `panel.reception.board.data` and
`panel.queue.today.data` — are listed in the middleware's `POLL_ROUTES` so they neither extend nor expire the
clock: a timer an open tab silently resets is not an idle timeout. Revoking a device or deactivating an account
also rotates `remember_token`, so every remembered browser is signed out together (the token is one column per
user, not per device). Device management is the `sessions_by_user` Redis index,
`App\Domain\Clinic\Services\StaffSessionIndex` (`bp:sessions_by_user:{tenant}:{user}` → session id ⇒ ip, user
agent, login_at, last_seen_at, TTL = the session lifetime). The entry is created by the middleware on the first
authenticated request rather than by the `Login` listener, because the login controller regenerates the session id
right after `Login` fires; `last_seen_at` is refreshed at most once a minute. `panel.clinic.staff.sessions.*`
lists and revokes them (a session is addressed by an opaque `ref`, a hash of its id — the id itself never leaves
the server), revoking destroys the session payload through the configured session handler, and deactivating a
staff account revokes every session it still holds.

### 6.2 Staff (`web`) — spatie/laravel-permission inside the tenant schema

* `teams => false`; each tenant schema has its own `roles/permissions` tables, so no team id.
* Roles (`App\Domain\Clinic\Enums\Role`, values snake_case): `hospital_admin`, `doctor`, `receptionist`
  (a.k.a. Compounder), `accountant`. **Patient is not a Spatie role** — patients are a separate
  guard/model. **Super Admin is central** (`SuperAdmin` model, `super` guard, no Spatie).
* Permission names: `<module>.<resource>.<action>` — string-backed enum
  `App\Domain\Clinic\Enums\Permission` is the single source of truth, e.g.
  `scheduling.schedules.manage`, `serials.issue.counter`, `serials.split.adjust` (Doctor and Super
  Admin only — the one spelling), `serials.reorder`,
  `queue.call-next` (also authorises the doctor-screen channel and the desk's call-next button),
  `queue.delay.broadcast`, `reception.devices.register`,
  `reception.blocks.revoke`, `prescriptions.write`, `prescriptions.vitals.record`,
  `prescriptions.view.any` (vs default own-patients only), `patients.view`, `patients.export`,
  `billing.payments.collect`, `billing.refunds.issue`, `billing.reports.view`,
  `reports.view`, `clinic.users.manage`, `clinic.pad.design`, `notifications.templates.manage`,
  `notifications.logs.view` (the outbound log — hospital admin and accountant, because SMS credits are money),
  `notifications.gateways.manage` (credentials, and the staff push-subscription screen),
  `notifications.send.test` (anything that spends a credit from a management screen: the gateway test send and a
  log retry), `saas.settings.manage`. Actions are `view|create|update|delete|manage|<verb>`.
* `Database\Seeders\Tenant\RolesAndPermissionsSeeder` creates every enum permission
  (`firstOrCreate(['name' => ..., 'guard_name' => 'web'])`), the four roles, and syncs the role
  → permission matrix defined in `App\Domain\Clinic\Support\RoleMatrix::permissionsFor(Role)`.
  It runs in `ProvisionTenant` and in `tenants:seed --class=RolesAndPermissionsSeeder` on every
  deploy that adds a permission. Custom per-tenant roles are allowed (Hospital Admin UI).
* Authorisation in code: policies (`$this->authorize('write', $prescription)`) call
  `$user->can(Permission::PrescriptionsWrite->value)` and add row-level rules (a doctor sees only
  his own patients unless `prescriptions.view.any`). Route-level `permission:` middleware is used
  only for whole sections.

### 6.3 Patients (`patient`) — mobile OTP

`App\Domain\Patients\Services\OtpService`: `request(string $mobile): void` (6 digits, Redis key
`otp:{tenantId}:{mobile}` (bigint tenant id) TTL 300 s, resend throttle 60 s, 5 verify attempts), `verify(mobile, code): bool`.
`Site\Portal\OtpController@verify` → `Patient::firstOrCreate(['mobile' => $e164])`, then
`Auth::guard('patient')->login($patient, remember: true)`. Family members select their
dependent (`patient_relations`) after login; `session('patient.acting_for')` holds it.
Delivery through the Notifications module SMS channel; in `local`/`testing` the code is logged
and fixed to `000000` when `OTP_FIXED_CODE` is set.

### 6.4 Reception PWA — Sanctum device tokens (OFFLINE.md §2 is authoritative)

Same-origin panel requests use Sanctum's **stateful** cookie path (`statefulApi()`;
`SANCTUM_STATEFUL_DOMAINS` is computed at boot by `AuthServiceProvider` from the central domain
wildcard plus verified `public.domains`, not from a static env list). The desk PWA additionally
authenticates as a **device**: `reception_devices` is the tokenable (`HasApiTokens`), guard
`device` (driver `sanctum`, provider `reception_devices`). Registration
`POST /api/reception/devices/register` (`routes/api/reception.php`, `auth:sanctum` staff session, permission `reception.devices.register`) →
`App\Domain\Reception\Actions\RegisterReceptionDevice` → `$device->createToken("device:{$device->public_id}", ['reception:offline','reception:sync','reception:blocks','reception:read'], now()->addDays(90))`;
the plain token lives in IndexedDB `meta`. Every other `/api/reception/*` call sends
`Authorization: Bearer <device token>` + `X-Actor-User: <user public id>`; middleware
`App\Domain\Reception\Http\Middleware\AuthenticateReceptionDevice` (applied by class in
`routes/api/reception.php`, OFFLINE.md §2.2) validates device, ability and actor. This is why the
offline event log can replay after the 2-hour staff session has expired during an outage. Display TVs
register with `kind = display` and use the same guard for the private display channel.

### 6.5 Super admin (`super`)

Email + password + TOTP (clinic-independent; `pragmarx/google2fa` is **not** installed — the SaaS module
implements TOTP itself in `App\Domain\SaaS\Services\Totp`: RFC 6238, HMAC-SHA1, 6 digits, a 30-second step, the
counter packed big-endian, comparison with `hash_equals`, verified against the RFC's own test vectors).

**Enforcement is on by default.** `config('saas.two_factor.required')` (env `SUPER_2FA_REQUIRED`, default `true`)
puts `App\Domain\SaaS\Http\Middleware\EnsureSuperTwoFactor` in charge of the whole `super.` route group: an
operator who has not enrolled reaches the enrolment screen and nothing else — not a banner, a wall — because this
is the one account that can read every clinic's records. Only `super.login`, `super.two-factor.challenge`,
`super.two-factor.*` and `super.logout` opt out.

**The flow.** `Super\Auth\LoginController` checks the password through the provider directly rather than
`attempt()`, and for an enrolled operator does **not** log them in: it parks `{id, remember, at}` under
`SuperTwoFactor::SESSION_PENDING` (TTL `saas.two_factor.pending_ttl_seconds`) and redirects to
`Super\Auth\TwoFactorChallengeController`. So an unchallenged session is a GUEST — it cannot reach a super route
and cannot mint an impersonation token, by construction rather than by check. The challenge logs the operator in,
regenerates the session id and stamps `SESSION_PASSED_AT`, which the middleware also verifies as a second line.

**Enrolment** (`Super\Auth\TwoFactorController`, page `Super/Auth/TwoFactor`) is confirm-before-enable: the secret
is written with `two_factor_confirmed_at` NULL and only a code that verifies against it turns the factor on, so a
mis-scanned QR is discovered while the operator is still signed in. The QR is `Endroid` through the prescription
module's `QrCodeRenderer::svgDataUri()` (§8.5) — one QR renderer in the codebase. Re-enrolling on an already-enabled
account is refused; rotating means disabling first, which re-asks for the password.

**Threat model.** Replay: a TOTP code is valid for its whole step, so the accepted step is *spent* — an atomic
`Cache::add` keyed by admin+step — and the same code fails the second time. Skew: ±1 step, no more. Lockout:
`saas.two_factor.challenge_attempts` (5) per admin+IP for `challenge_decay_seconds` (900), plus a per-IP
`throttle:super-2fa`. Recovery: 8 single-use codes, shown once, SHA-256 digests inside the already-`encrypted`
`super_admins.two_factor_recovery_codes` (the same reasoning that has Sanctum hash API tokens with SHA-256 —
these are our own CSPRNG output, not a chosen password). Every enrolment, disablement, failed challenge and spent
recovery code is a `public.audit_logs_central` row (`two_factor_enabled | two_factor_disabled | two_factor_failed |
two_factor_recovery_used`, SCHEMA §2.13).

Impersonation: `super` creates `public.impersonation_tokens` (single-use, 60 s), redirects to
`https://{tenant-host}/panel/impersonate/{token}`; `Panel\Auth\ImpersonationController` logs the
super admin in as the chosen tenant user with `session('impersonated_by' => superAdminId)`; every
audit row written during that session carries `impersonator_super_admin_id` (SCHEMA.md §3.7). Leaving impersonation logs out.

---

## 7. Frontend

### 7.1 Layout

```
resources/js/
  panel/   app.tsx  Layouts/  Pages/<Module>/<Page>.tsx  Pages/Super/...  Components/<Module>/  hooks/<module>/  lib/<module>/  api/<module>.ts  theme.ts  sw.ts  pwa.ts
  site/    app.tsx  Layouts/  Pages/{Booking,Queue,Display,Portal,Central}/  Components/<Module>/  hooks/<module>/  api/
  shared/  connection/{store,constants,heartbeat,echoBridge}.ts  connection/ConnectionIndicator.tsx
           realtime/{echo,liveQueue,types,useQueueState,useChannel}.ts   offline/{db,eventLog,blocks,sync}.ts   ulid.ts
           http.ts  i18n.ts  routes.ts  format/{money,date,serial}.ts  types/{shared-props,models,ziggy}.d.ts  inertia.ts
resources/css/panel.css   resources/css/site.css
resources/lang/en.json    resources/lang/bn.json
resources/views/panel.blade.php   resources/views/site.blade.php   resources/views/print/**   resources/views/site/{rx,drug}/show.blade.php
```

`resources/views/print/**` holds every Browsershot/print template (prescription, token slip, invoice,
receipt, report); `resources/views/site/{rx,drug}/show.blade.php` are the two public Blade pages of the
site surface that are deliberately not Inertia (PRESCRIPTION.md §7.4, §7.8). `shared/ulid.ts` is the
one client-side id generator (client_event_id, writer item keys).

TypeScript everywhere (`.ts`/`.tsx`; no `.js` under `resources/js`). `resources/js/site` must not
import from `@mui/*`, `@emotion/*`, `recharts`, `@dnd-kit/*` or `@mui/x-date-pickers`
(enforced by `scripts/check-site-deps.sh` in CI — an ESLint-free import-graph walk from `site/app.tsx` and every
page, so a panel dependency reached through `resources/js/shared/**` is caught as well; the same script checks
the ≤ 95 KB gzip first-load budget of §7.2/§7.6 from `public/build/manifest.json`).

### 7.2 `vite.config.ts` (foundation-owned)

```ts
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';
import path from 'node:path';

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/js/panel/app.tsx', 'resources/css/panel.css', 'resources/js/site/app.tsx', 'resources/css/site.css'],
      refresh: ['resources/views/**', 'routes/**'],
    }),
    react(),
    tailwindcss(),
    VitePWA({
      strategies: 'injectManifest',          // we own the service worker (resources/js/panel/sw.ts, OFFLINE.md §11)
      srcDir: 'resources/js/panel',
      filename: 'sw.ts',
      outDir: 'public',                      // emits public/sw.js + public/panel.webmanifest → served from '/', so the SW may control '/panel/'
      buildBase: '/build/',                  // precache URLs of laravel-vite-plugin assets live under /build/
      base: '/',
      scope: '/panel/',
      registerType: 'prompt',
      injectRegister: null,                  // registration is explicit in resources/js/panel/pwa.ts
      manifestFilename: 'panel.webmanifest',
      includeAssets: ['fonts/*.woff2', 'icons/*.png'],
      manifest: {
        name: 'Clinic Desk', short_name: 'Desk', start_url: '/panel/reception', scope: '/panel/',
        display: 'standalone', background_color: '#ffffff', theme_color: '#0f766e', lang: 'bn',
        icons: [{ src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' }, { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' }],
      },
      // Precache every hashed asset: laravel-vite-plugin names both entries app-*.js and Rollup auto-names common
      // chunks, so a {panel,shared}-* glob would leave the desk unable to cold-boot offline.
      injectManifest: { globDirectory: 'public', globPatterns: ['build/assets/*.{js,css,woff2}', 'icons/*.{png,svg}'], maximumFileSizeToCacheInBytes: 4_000_000 },
      devOptions: { enabled: true, type: 'module' },
    }),
    copyPanelManifest(),                     // closeBundle: public/build/panel.webmanifest → public/panel.webmanifest (see below)
  ],
  resolve: { alias: [ ...(IS_SITE ? PREACT_ALIASES : []),
    { find: '@panel', replacement: path.resolve('resources/js/panel') }, { find: '@site', replacement: path.resolve('resources/js/site') },
    { find: '@shared', replacement: path.resolve('resources/js/shared') }, { find: '@lang', replacement: path.resolve('resources/lang') } ] },
  build: {
    emptyOutDir: SURFACE !== 'panel',        // the site pass runs first and cleans public/build; the panel pass appends
    target: IS_SITE ? 'es2019' : undefined,  // REALTIME.md §8: Chrome/WebView ≥ 80, Android 8+ for the public site
    rollupOptions: {
      output: {
        manualChunks: IS_SITE
          ? { vendor: ['preact', 'preact/compat', 'preact/hooks', '@inertiajs/react', 'i18next', 'react-i18next'],
              realtime: ['laravel-echo', 'pusher-js'] }
          : { shared: ['react', 'react-dom', '@inertiajs/react', 'zustand', 'i18next', 'react-i18next', 'dayjs'],
              realtime: ['laravel-echo', 'pusher-js'] },   // loaded with import() after first paint (REALTIME.md §8)
      },
    },
  },
  server: { host: '127.0.0.1', port: 5173, watch: { ignored: ['**/storage/framework/views/**'] } },
});
```

**Two build passes, one manifest.** `npm run build` is `BP_SURFACE=site vite build && BP_SURFACE=panel vite build`.
The two apps need different module graphs to fit REALTIME.md §8's ≤95 KB gzip first load: React 19 + react-dom is
60 KB gzip on its own and @inertiajs/react + core another 40 KB, so the site pass aliases `react`/`react-dom` to
**preact/compat** (~10 KB) and carries only its own vendor chunk, while the panel pass keeps React 19 for MUI,
emotion and the date pickers. Everything else about the build is unchanged: both passes write into `public/build`,
`bp:merge-surface-manifests` folds the site pass's entries back into `public/build/manifest.json` in the panel
pass's `closeBundle`, so Laravel still reads one manifest and `@vite([...])` is untouched in both root views. The
site pass runs first (it owns `emptyOutDir`) so the PWA precache glob in the panel pass still sees every asset.
Two site-only plugins complete the picture: `bp:preact-react-shim` supplies the `use(Context)` that preact/compat
lacks and @inertiajs/react 3.x calls inside `usePage()`, and `bp:site-date-formatter` resolves
`@shared/format/date` to the Intl-based `date.site.ts` so no site page pulls dayjs. `npm run dev` (no
`BP_SURFACE`) still serves both entries from one server with real React — preact only ever enters the production
site bundle, which is what `npm run build` gates.

Notes on the real file (it is the source of truth; this excerpt omits the small `copyPanelManifest()`
plugin): `buildBase` is `'/'` because `registerSW()` registers `${buildBase}${filename}`, which must be
`/sw.js`; precache entries are globbed from `public` so they already carry the `build/` prefix.
vite-plugin-pwa emits the web manifest into Vite's outDir — `public/build/panel.webmanifest` — while the
service worker precaches it at its own root (`panel.webmanifest` → `/panel.webmanifest`) and
`panel.blade.php` links `/panel.webmanifest`; `copyPanelManifest()` copies it up in `closeBundle` so the
precache entry exists and the SW installs. `public/sw.js`, `public/workbox-*.js`, `public/panel.webmanifest`
and `public/build/` are build outputs and are gitignored. `outDir: 'public'`
(rather than the default `public/build`) is what lets a worker served from `/sw.js` claim scope
`/panel/` without a `Service-Worker-Allowed` header. The route table of
`resources/js/panel/sw.ts` (NetworkFirst shell, NetworkOnly for every mutation, `/api/ping`,
`/broadcasting/*`, `/sanctum/*`, `/queue/*`; CacheFirst for `/build/*` and `/fonts/*`) is
OFFLINE.md §11. `resources/js/panel/pwa.ts` calls `registerSW({ immediate: true, onNeedRefresh })`
from `virtual:pwa-register`, applies updates only when `pendingEvents === 0`, and is imported only by `panel/app.tsx`.

The panel has its own guard, `scripts/check-panel-budget.sh` (CONVENTIONS §7.3): the same manifest arithmetic as
the site's, applied to `resources/js/panel/app.tsx` and every page under `panel/Pages/**`, with ≤ 355 KB gzip for
any panel route and ≤ 345 KB for the clinical ones. A panel route's first load is the entry's static closure +
the `bp-lang/lang-panel-<locale>` base slice + the route's `bp-lang/lang-panel-<module>-<locale>` slice (§7.5 —
both are requested in the same tick as the page chunk) + the page chunk and its static imports. It also enforces
the §7.3 import rules that cause the payload in the first place (no MUI barrel imports, dnd-kit only where it
belongs, no date pickers in the entry or a layout, and **recharts only inside `panel/Components/Charts/**`** —
every chart on the panel is reached through `React.lazy` and is not mounted at all when there are no rows, so
recharts' ~97 KB gzip never enters a first load; `Components/Charts/LazyChart.tsx` is the wrapper that does it).

`tsconfig.json`: `"strict": true`, `"jsx": "react-jsx"`, `"module": "ESNext"`,
`"moduleResolution": "bundler"`, `"target": "ES2022"`, `"lib": ["ES2022","DOM","DOM.Iterable","WebWorker"]`,
`"types": ["vite/client", "vite-plugin-pwa/client"]`, `"paths"` mirroring the aliases,
`"noUncheckedIndexedAccess": true`, `"include": ["resources/js/**/*", "vite.config.ts"]`.
`npm run typecheck` = `tsc --noEmit -p tsconfig.json` (TypeScript 7.0.2 — verified `tsc` runs).

### 7.3 Inertia root views and page resolution

`HandleInertiaRequests` (foundation-owned) overrides `rootView(Request $request): string`
(verified hook in `Inertia\Middleware`): returns `'panel'` when the route name starts with
`panel.` or `super.`, otherwise `'site'`. `resources/views/panel.blade.php` loads
`@vite(['resources/css/panel.css', 'resources/js/panel/app.tsx'])`, links
`/panel.webmanifest`, sets `<html lang="{{ app()->getLocale() }}">`; `site.blade.php` loads the site
pair and injects the tenant theme:

```blade
<style>:root{ @foreach($page['props']['tenant']['theme'] ?? [] as $k => $v) --tenant-{{ $k }}: {{ $v }}; @endforeach }</style>
```

`config/inertia.php` (published with `php artisan vendor:publish --provider="Inertia\ServiceProvider"`):
`'pages.paths' => [resource_path('js/panel/Pages'), resource_path('js/site/Pages')]`,
`'testing.ensure_pages_exist' => true`, `'ssr.enabled' => false`. Each app resolves components
from its own root: `resolvePageComponent(`./Pages/${name}.tsx`, import.meta.glob('./Pages/**/*.tsx'))`.
Therefore controllers render **surface-relative** names: `Inertia::render('Reception/Board')` from
a panel controller, `Inertia::render('Queue/Today')` from a site controller, `Inertia::render('Super/Tenants/Index')`
from a super controller (the super pages live in the panel root). `EnsureTenantIsActive` renders
`Suspended` (HTTP 402) with the `site` root view; the page exists in **both** roots (`panel/Pages/Suspended.tsx`,
`site/Pages/Suspended.tsx`). Feature tests assert pages with `assertInertia(fn ($p) => $p->component('…'))`
on the HTML response (`Tests\TestCase` calls `withoutVite()`), which also proves the page file exists
(`config('inertia.testing.ensure_pages_exist')`).

### 7.4 Shared props contract (`HandleInertiaRequests::share()` → `resources/js/shared/types/shared-props.d.ts`)

```ts
export interface SharedProps {
  auth: { guard: 'web' | 'patient' | 'super' | null;
          user: { id: number; name: string; roles: string[]; permissions: string[]; doctor_id: number | null } | null;
          impersonating: boolean };
  tenant: { id: number; slug: string; name: string; locale: 'bn' | 'en'; timezone: string; logo_url: string | null;
            theme: Record<string, string>; modules: string[] } | null;
  branch: { id: number; name: string; code: string } | null;          // staff's active branch (SetActiveBranch); null on site/super
  branches: { id: number; name: string }[];                            // for the branch switcher; [] elsewhere
  locale: 'bn' | 'en';
  flash: { success: string | null; error: string | null; warning: string | null; info: string | null };   // session keys flash.*
  features: Record<string, boolean>;                                   // Pennant values for the current tenant (Inertia::once)
  ziggy: ZiggyConfig;                                                  // Inertia::once, per surface group
  csrf_token: string;
  app: { name: string; env: string; version: string; reverb: { key: string; host: string; port: number; scheme: 'http' | 'https' } };
  errors: Record<string, string>;
}
```

`share()` is evaluated by Inertia's middleware *before* the route middleware that follow it
(`SetTenantLocale`, `auth`, `SetActiveBranch`), so `auth`, `branch`, `branches`, `locale` and the four
`flash.*` entries are **closures** resolved at render time. Flash is a session convention, not
`Inertia::flash()`: controllers `return redirect()->…->with('flash.success', __('…'))` (or
`session()->now('flash.warning', …)` for the same request, as the dunning banner does); the values are
`null` when unset. `tenant.logo_url` is a URL (`Storage::disk('public')->url($branding['logo_path'])`,
`null` without a logo); `tenant.theme` carries `primary`, `accent`, `on-primary` when `branding` has
`primary_color`/`accent_color`/`on_primary_color`; `theme` and `features` serialise as JSON objects (`{}`) even
when empty. `share()` computes `auth` per guard (`Auth::guard('super')->user()` on `super.*` routes, etc.),
`branch` from `ActiveBranch` service, `features` via `Inertia::once(fn () => Feature::for(Tenancy::current())->all())`,
`ziggy` via `Inertia::once(fn () => (new Ziggy($group, $request->getSchemeAndHttpHost()))->toArray())`
with `$group` = `panel|site|super` chosen from the route-name prefix; `config/ziggy.php`
defines `'groups' => ['panel' => ['panel.*','api.*'], 'site' => ['site.*','api.*'], 'super' => ['super.*']]`.
Route names for the client come from `php artisan ziggy:generate --types-only resources/js/shared/types/ziggy.d.ts`
(committed; regenerated whenever a route is added). `resources/js/shared/routes.ts` exports
`route(name, params?, absolute?)` which wraps `ziggy-js`'s `route()` with the config captured
from the first page load (`setZiggy(props.ziggy)` in each `app.tsx`). No `@routes` Blade directive.

### 7.5 i18n

`resources/lang/en.json` and `resources/lang/bn.json` are **the** translation files for both
PHP (`__('reception.board.title')`; `AppServiceProvider::register()` calls
`$this->app->useLangPath(resource_path('lang'))`) and the client — unchanged for anyone adding a key.
They are **not** bundled into the entries: shipping both locales × every key cost 38 KB gzip on every
page. The Vite plugin `bp:lang-bundles` slices them at build time into one chunk per (bundle, locale)
— `@lang/<locale>.json?<bundle>`, with `resources/js/shared/lang/surfaces.ts` holding every bundle's
key-prefix list. There are three kinds of bundle:

* **`site`** — the site's allowlist (`SITE_KEY_PREFIXES`): the site never renders `prescriptions.*`,
  `reception.*`, `scheduling.*`, `serials.*`, `catalog.*`.
* **`panel`** — the panel's **base**: `PANEL_BASE_PREFIXES` = `auth. common. connection. dashboard. nav.
  passwords. pwa. roles. tenancy. validation.`, about 160 of the ~2 900 keys (~4 KB gzip in Bangla). This is
  the shell's own copy — the nav, the flash snackbars, the connection indicator, the PWA prompt, the field
  errors — so it must be in the base or every route flashes raw keys before its module arrives.
* **`panel-<module>`** — one chunk per directory under `panel/Pages/` (`PANEL_MODULES`, mapped by
  `panelModuleForPage()`), carrying only that module's blocks and never repeating a base key. A reception
  desk downloads `reception.`, `serials.`, `booking.`, `patients.`, `billing.`, `scheduling.` and
  `prescriptions.` — and not `reports.*`, `saas.*`, `super.*`, `catalog.*`, `notifications.*`, `clinic.*`
  or `telemedicine.*`. Shipping all ~2 900 keys to every route cost 51 KB gzip in Bangla; the base plus the
  heaviest module is 20 KB, and most routes are under 10 KB.

Each `app.tsx` loads exactly what its route needs: `registerMessageLoader(loadSiteMessages)` +
`ensureMessages(documentLocale())` at module scope, awaited inside `createInertiaApp({ resolve })` so the
locale chunk downloads **in parallel with** the page chunk and nothing ever renders untranslated. The panel
additionally calls `registerModuleLoader(loadPanelModuleMessages)` and, inside the same `resolve(name)`
`Promise.all`, `ensureModuleMessages(panelModuleForPage(name), documentLocale())` — the page name is known
before either request goes out, so the module slice is a *parallel* request, never an extra round trip.
`setLocale()` re-fetches every module slice this session has shown, so a language switch never leaves half
the screen in keys. `resources/js/shared/lang/__tests__/bundles.test.ts` walks each panel page's real import
graph (dynamic imports included) and fails if it can render a key its bundle does not carry, naming the list
in `surfaces.ts` to add the prefix to. `documentLocale()` reads `<html lang>`, which the root Blade
renders from `app()->getLocale()` — the same expression `share()` uses for `SharedProps.locale`, so the
two can never disagree. `shared/i18n.ts` initialises
`i18next.use(initReactI18next).init({ resources: {}, lng, fallbackLng: 'en', interpolation: { escapeValue: false } })`
and installs messages with `addMessages(locale, messages)`; `setLocale(locale)` (loads, then switches) is
called from `syncSharedOnNavigate` for `PATCH /locale`. Keys are flat, dot-namespaced `module.screen.label`;
placeholders use Laravel style `:name` in PHP and `{{name}}` in the client — a key used on both
sides has both forms (`"booking.confirm.title": "Serial :serial confirmed"` for PHP, and the
client wraps with `i18n.t(key, { interpolation: { prefix: ':', suffix: '' } })` — done once in
`i18n.ts` so call sites just use `t('booking.confirm.title', { serial })`). Language switch:
`PATCH /locale {locale}` (site and panel) stores it in the session and Inertia re-renders the same URL;
`syncSharedOnNavigate()` subscribes to **both** `navigate` and `success`, because Inertia skips `navigate`
when it replaces the history entry — which is exactly what a redirect back to the same URL does — and
`success` never fires for the initial render or history back/forward.

### 7.6 MUI theme and Bangla fonts

`resources/js/panel/theme.ts`: `createTheme({ typography: { fontFamily: '"Inter", "Noto Sans Bengali", system-ui, sans-serif', fontSize: 14 }, palette: { primary: { main: '#0f766e' } }, components: { MuiButton: { defaultProps: { disableElevation: true } } } })`.
Date pickers use `LocalizationProvider` with `AdapterDayjs` from `@mui/x-date-pickers/AdapterDayjs` and `dayjs`
locale `bn`/`en` plus the `timezone` plugin pinned to `Asia/Dhaka` — but the provider is **not** in `panel/app.tsx`:
no page mounts a picker today and the provider cost every route ~5 KB gzip, so a page that needs one wraps itself
inside its own chunk (CONVENTIONS §7.3, `scripts/check-panel-budget.sh`). `panel/app.tsx` still imports
`@shared/format/date`, which registers the dayjs utc/timezone plugins and the `bn` locale for whoever does.
Fonts: `panel/app.tsx` imports `@fontsource/inter/{400,500,600,700}.css` and
`@fontsource/noto-sans-bengali/bengali-{400,500,700}.css` (Bengali unicode-range subsets only;
fontsource defaults to `font-display: swap`). `site/app.tsx` imports only
`@fontsource/noto-sans-bengali/bengali-{400,600}.css` and uses `system-ui` for Latin. The site's
first-load budget is REALTIME.md §8's **≤ 95 KB gzip of JS** (plus ≤ 8 KB CSS); measure it from
`public/build/manifest.json` — the entry's static-import closure + the route's page chunk + the
locale chunk + the two stylesheets — not from the terminal's chunk list. Note that the two Bengali
weights are 44 + 48 KB woff2, so a Bangla page is over §8's ≤ 70 KB font line whenever both load. `resources/css/site.css` declares Tailwind tokens from the tenant variables:
`@theme { --color-primary: var(--tenant-primary, #0f766e); --color-accent: var(--tenant-accent, #f59e0b); --font-sans: system-ui, "Noto Sans Bengali", sans-serif; }`.

### 7.7 Connection state, realtime and offline — one model

`resources/js/shared/connection/store.ts` (`useConnection`, zustand + `subscribeWithSelector`) is the
**only** source of network truth, for the desk PWA and the public queue page alike. Its shape and
transition rules are OFFLINE.md §3 (three modes `online | degraded | offline`; inputs
`navigator.onLine`, laravel-echo `ConnectionStatus` via `echo.connector.onConnectionChange()`
(verified in laravel-echo 2.4.0), the `GET /api/ping` heartbeat, and axios success/`ERR_NETWORK`
evidence; debounced WS transitions). Files: `connection/{store,constants,heartbeat,echoBridge}.ts`.
`shared/realtime/echo.ts` builds the Reverb `Echo` lazily (`broadcaster: 'reverb'`, key/host/port/scheme
from `SharedProps.app.reverb`, `enabledTransports: ['ws','wss']`; the device Echo uses
`authEndpoint: '/api/device/broadcasting/auth'` with `bearerToken`). `shared/realtime/liveQueue.ts`
exports `subscribeQueue()` (REALTIME.md §6): WebSocket when `mode === 'online'`, poll `GET /queue/{doctorSlug}/state`
with `If-None-Match` every 5 s when `degraded`, dedupe by `version`; `shared/realtime/useQueueState.ts`
is its React hook and `shared/realtime/useChannel.ts` the hook for the private channels. The reception
offline module (`shared/offline/{db,eventLog,blocks,sync}.ts`, Dexie 4 — schema in OFFLINE.md §5)
reads the same store, writes `pendingEvents/conflicts/syncPhase/activeBlock` through `setSyncFacts`,
and flushes the event log (`sync.ts`) when `mode` leaves `offline`. One
`shared/connection/ConnectionIndicator.tsx` (plain React + CSS, no MUI; spec OFFLINE.md §9) renders
`mode` in both bundles — a receptionist is never unsure whether they are online.

---

## 8. Cross-cutting concerns

### 8.1 Audit (`app/Domain/Audit`)

Table `audit_logs` (tenant schema; columns exactly SCHEMA.md §3.7: `tenant_id, actor_type, actor_id,
impersonator_super_admin_id, action, auditable_type, auditable_id, patient_id, before jsonb, after jsonb,
context jsonb, ip inet, user_agent, request_id char(26), occurred_at`) plus `public.audit_logs_central`
for super-admin actions. Append-only: the app role has no UPDATE/DELETE and `AuditLog` has no
`update()`/`delete()` path.

* `Auditable` trait on `TenantModel` — active when the model sets `protected static bool $audited = true`
  (all clinical models: Patient*, Visit, Vital, Prescription*, Serial (reorder/transfer), Payment, Refund,
  Appointment). Hooks `created/updated/deleted` → `AuditRecorder::record(AuditAction, Model $auditable, ?array $before, ?array $after, array $context = [])`.
  `before/after` are `getOriginal()`/`getDirty()` restricted to `$auditedAttributes`; attributes with an
  `encrypted*` cast are written as the literal string `"[encrypted]"` (SCHEMA.md §5.5). `patient_id` is
  filled from `$auditable->patient_id ?? ($auditable instanceof Patient ? id : null)`.
* Reads are explicit: `AuditLog::view(Model $subject, array $context = [])` (static helper, forwards to
  `AuditRecorder`). Every controller/action that returns, prints, exports or downloads a clinical record
  calls it; `print`, `export`, `download`, `share` use the matching `AuditAction`. Tests assert with
  `assertAudited(AuditAction::View, $prescription)` (CONVENTIONS §6.4).
* **Coverage boundary.** `Auditable` hooks the `created/updated/deleted` *model events* only. Query-builder
  writes (`Model::query()->update()/insert()/upsert()/delete()`), `saveQuietly()`/`updateQuietly()` and
  `DB::table()` leave no audit row — and `DB::table()` is not tenancy-guarded at all (it runs on whatever
  search path is active; bare tenant names fail loudly on `public`, but `personal_access_tokens`,
  `password_reset_tokens` and `migrations` exist in both schemas and resolve silently). Clinical writes
  therefore go through Eloquent `save()`/`delete()` on the model (CONVENTIONS §4); a justified bulk write
  records itself with an explicit `AuditRecorder::record()` (`AuditCoverageTest` documents the boundary).
* `AuditRecorder` (singleton, in Octane `flush`) captures ip, user agent, `request_id` (ULID set by the
  global `AssignRequestId` middleware and echoed in the `X-Request-Id` response header), the actor from
  whichever guard authenticated (`user | patient | device | super_admin`, else `system` inside jobs), and
  `session('impersonated_by')` → `impersonator_super_admin_id`.

### 8.2 Encryption at rest

Two layers. Layer 1 (infrastructure): full-volume encryption on the Postgres host, encrypted S3
buckets for uploads/handwriting/drawings, and `pg_dump`s encrypted by
`App\Domain\SaaS\Services\BackupCipher` before they leave the host. This section used to specify `age`; there
is no `age` binary on the platform host and no way to build one, so the dumps were in fact being uploaded in the
clear. What runs is libsodium's `crypto_secretstream_xchacha20poly1305` (ships with PHP): a self-describing
`"BPBACKUP"` + version prefix, the 24-byte stream header, then 1 MiB plaintext chunks each carrying a Poly1305
tag, the last one tagged FINAL so a truncated object is a decryption error rather than half a clinic. Nothing is
ever held whole — encrypt, upload, download and decrypt all stream. The key is
`config('saas.backups.encryption_key')` (`BP_BACKUP_KEY`, base64 of 32 raw bytes), one per platform, stored beside
`APP_KEY`; lose it and the objects written with it are unrecoverable. With no key `local`/`testing` log a warning
and write plaintext, every other environment refuses the backup outright and records `status = failed` on the
`tenant_backups` row, and every row carries `encryption` (`none` | `xchacha20poly1305`) so "which of these objects
is in the clear" has an answer. Restores detect the format from the object's own magic, so plaintext dumps taken
before this existed still restore. **Not** encrypted this way: the churn export zip (`type = export`), which is
handed to a departing clinic and must open without us. Layer 2 (application): Eloquent
`encrypted`, `encrypted:array`, `encrypted:json` casts (all verified in `HasAttributes`) on the columns
marked **ENC** in SCHEMA.md — the authoritative list is SCHEMA.md §5.5; in short: `patients.national_id`,
`patients.notes`, the `notes` columns of `patient_allergies/conditions/medications`, `visits.private_notes`,
`ai_suggestions.prompt/response/accepted_fragment`, `patient_documents.ocr_text`,
`patient_consents.signature_data`, `users.two_factor_*`, `super_admins.two_factor_*`,
`sms_gateway_settings.credentials`, `push_subscriptions.keys`, `telemedicine_sessions.recording_path`.
Deliberately **plain**: `patients.mobile/name/dob` (identity and lookup; no `mobile_hash`), coded clinical
fields (`generic_id`, `icd10_code`) needed by safety checks and reports, and `prescriptions.snapshot`
(the legal document, verifiable without a session). ENC columns are `text`, never indexed, never in a
`WHERE`, never in Meilisearch, logged as `"[encrypted]"` in audit rows. Key rotation via `APP_PREVIOUS_KEYS`.
A PHPStan rule (`App\Support\PhpStan\NoWhereOnEncryptedCasts`, identifier `bp.whereOnEncryptedCast`, registered in
`phpstan.neon`) flags `where`/`orWhere`/`whereIn`/`whereNot`/`whereLike`/`whereBetween`/`firstWhere` (and their
`or`/`not` variants) whose first argument is a constant string naming a column the model casts `encrypted`,
`encrypted:array|json|object|collection` or `AsEncrypted*`. The model comes from the call receiver — a `Model`
subclass, the `TModel` of an Eloquent `Builder`, or the `TRelatedModel` of a `Relation` — and its cast list is read
from its own source (`protected function casts()` or a `$casts` property, ancestors merged). `whereNull()` /
`whereNotNull()` are not flagged (NULL survives the cast) and a raw `DB::table()` query has no model to check.

### 8.3 Soft references to `catalog`

* `App\Domain\Catalog\Rules\CatalogIdExists(string $table, bool $requireActive = true)` (CATALOG.md §7; macro
  `Rule::catalog('generics')`) — a `ValidationRule` that checks the **catalog database** through
  `App\Domain\Catalog\Services\CatalogCache` (Redis read-through keyed by catalog version, `catalog:{ver}:{table}:{id}`,
  PRESCRIPTION.md §5.6). Meilisearch is not used for validation (index lag);
  it is the hot path for autocomplete only. Used by the prescription `DraftRequest`, `PatientAllergyRequest`,
  `PatientMedicationRequest`, `CustomBrandRequest` (`generic_id` is `required` — a custom brand without a molecule cannot be saved) and
  `Prescription\Actions\IssuePrescription` re-validates ids inside the transaction.
* Snapshot on write: `prescription_items` stores `generic_name, brand_name, strength, form, route` as text
  (BRIEF §3.3) and `prescriptions.snapshot` (PRESCRIPTION.md §6.2, with `snapshot_sha256` and `pad_snapshot`)
  is the only render source. Rendering never joins `catalog` (`App\Domain\Prescription\Render\PrescriptionRenderer`
  receives only the `PrescriptionSnapshot` DTO; the PHPStan rule `App\Support\PhpStan\NoCatalogModelsInRendering`
  (identifier `bp.catalogInRendering`, registered in `phpstan.neon`) fails the build on any reference to an
  `App\Models\Catalog\*` class — import, `new`, static call, `::class`, `instanceof`, parameter/return/property type —
  or on naming the `catalog`/`catalog_admin` connection (`DB::connection('catalog')`, `->connection('catalog')`,
  `Model::on('catalog')`, `$connection = 'catalog'`, `config('database.connections.catalog…')`) anywhere under
  `App\Domain\Prescription\Render`). The rule stops at that namespace on purpose:
  `App\Domain\Prescription\Services\SnapshotBuilder` runs at issue time and may read the catalog to build the snapshot;
  only *rendering* is forbidden to. Blade print views are not analysed by PHPStan, but they only ever receive the array
  `PrescriptionRenderer::data()` derives from the snapshot, so guarding the renderer classes guards them too.
* `catalog:reconcile` (nightly at 02:00, central context) — dispatches one `App\Domain\Catalog\Jobs\ReconcileCatalogReferences`
  (queue `default`, `TenantAware`, `WithoutOverlapping`) per active tenant, which scans the soft-reference
  columns listed in CATALOG.md §2/§6 against the catalog with `whereIntegerInRaw` chunks of 1000, writes rows to
  `public.catalog_reconciliation_reports` (SCHEMA.md §2.12) and raises `CatalogReconciliationCompleted`.
  Orphans are reported, never deleted.

### 8.4 Feature flags (Pennant) and plan limits

* `Tenant implements Laravel\Pennant\Contracts\FeatureScopeable` → `toFeatureIdentifier(string $driver): string` returns `"tenant:{$this->id}"`.
* Store: `public.feature_flags` (`config/pennant.php` → `'stores.database.table' => 'public.feature_flags'`, qualified because the tenant search path has no `public` fallback).
* `TenancyServiceProvider::boot()`: `Feature::resolveScopeUsing(fn () => Tenancy::current())` so
  `Feature::active('telemedicine')` inside a request/job is tenant-scoped; `Feature::discover('App\\Domain\\SaaS\\Features', app_path('Domain/SaaS/Features'))`
  (feature classes live with the SaaS module — SCHEMA.md §2.9).
* Feature classes: `Telemedicine`, `AiAssist`, `WhatsAppChannel`, `IvrChannel`, `SlotMode`, `HandwritingMode`,
  `MultiBranch`, `CommissionReports`, `PatientPortal`. Each resolves from the plan's `public.plan_features` rows
  (`feature_key` toggles such as `telemedicine`, `ai_assist`, `whatsapp`; SCHEMA.md §2.3) as overridden by
  `subscriptions.feature_overrides` (SCHEMA.md §2.4), unless overridden per tenant by super admin
  (`Feature::for($tenant)->activate('ai-assist')`). Route middleware `feature:telemedicine`; client reads `SharedProps.features`.
* Plan limits: the numeric `public.plan_features` rows (`branches`, `doctors`, `appointments_monthly`,
  `sms_credits_monthly`, `storage_bytes` — `limit_value`, `NULL` = unlimited) overridden by
  `subscriptions.feature_overrides`; there is no `plans.limits` column.
  `App\Domain\SaaS\Services\PlanLimits::assertCanAdd(Limit $limit, int $qty = 1): void` throws
  `PlanLimitExceeded` (DomainException, code `saas.limit.<name>`); counters are `public.usage_counters` rows keyed by
  `metric` (SCHEMA.md §2.10: `appointments`, `sms_credits`, `storage_bytes`, `doctors`, `branches`, …) updated by
  listeners (`AppointmentBooked` → `appointments`, `SmsSent` → `sms_credits`) with the `ON CONFLICT … DO UPDATE`
  protocol of SCHEMA.md §5.8 — no in-request `count(*)`. Enforced in Actions (`CreateDoctor`, `CreateBranch`, `BookSerial`,
  `SendSms`), and shown in the panel via `SharedProps.tenant.modules` + a `usage` deferred prop on the dashboard.

### 8.5 PDF rendering — Browsershot only

`App\Support\Pdf\PdfRenderer::fromView(string $view, array $data, PdfOptions $o): string`:

```php
Browsershot::html($html)
    ->setChromePath(config('services.chrome.path'))          // CHROME_PATH
    ->setNodeBinary(config('services.node.binary'))->setNpmBinary(config('services.node.npm'))
    ->noSandbox()->newHeadless()->emulateMedia('print')->showBackground()
    ->format($o->paper)                                       // 'A4' | 'A5'
    ->margins($o->top, $o->right, $o->bottom, $o->left, 'mm')
    ->waitUntilNetworkIdle()->timeout(60)
    ->pdf();
```

Templates: prescriptions per PRESCRIPTION.md §7.1 (`resources/views/print/prescription/**`, rendered by
`App\Domain\Prescription\Render\PrescriptionRenderer` from the snapshot DTO only); other documents under
`resources/views/print/{token-slip/{58mm,80mm,a5},invoice,receipt,report}.blade.php`. Templates inline
their CSS; Noto Sans Bengali resolves to the system-installed font in Chrome and is also declared with
`local()` plus a bundled woff2 fallback, Inter is served from `public/fonts/inter/*.woff2` (copied from
fontsource by `scripts/sync-fonts.sh`, committed) — referenced as `file://{{ public_path(...) }}` because
Browsershot loads the HTML from a temp file; logos are data URIs. Prescriptions render on the `pdf`
queue for delivery and synchronously for print (< 2 s budget). The verifiable copy is
`GET /rx/{code}` on the site surface; QR via `endroid/qr-code` (server) / `qrcode.react` (client).

### 8.6 Meilisearch index design (SCHEMA.md §5.6 is authoritative)

* Shared catalog indexes (owner: Catalog): `catalog_drugs` (one document per active `strengths` row,
  denormalising brand + generic + form + route, id `s{strength_id}`, plus one `g{generic_id}` document per
  generic) and `catalog_icd10`. They are **not** Scout model indexes: `App\Domain\Catalog\Search\CatalogSearchIndexer`
  builds them directly (`catalog:index-search`, zero-downtime swap). Settings live in `config/catalog.php['search']`
  (CATALOG.md §4); the indexer prefixes every uid with `config('scout.prefix')` so the test prefix applies to them too.
* Tenant indexes via `TenantModel::searchableAs()` → `t{tenantId}_patients`, `t{tenantId}_custom_brands`
  (no other tenant indexes: investigation and advice-snippet lookups are Postgres `ILIKE`, PRESCRIPTION.md §3.8).
  Patient documents contain no ENC fields and no address. `App\Domain\Catalog\Search\MeilisearchIndexes::ensureTenantIndexes()`
  creates them with `CustomBrandIndexSettings::array()` / patient settings at provisioning (`scout:sync-index-settings`
  cannot see dynamic uids); `tenants:sync-search-settings` re-applies settings, `tenants:reindex` rebuilds documents.
* Drug autocomplete is one federated multi-search (`Meilisearch\Client::multiSearch()` with `federation`,
  meilisearch-php 1.17) over `catalog_drugs` + `t{id}_custom_brands` (uids obtained from the indexer and from
  `(new CustomBrand)->searchableAs()`, never hard-coded), re-ranked so the doctor's `doctor_favourites` for the
  current diagnosis come first, then master, then custom (`source` field) — `App\Domain\Prescription\Services\DrugSearchService`.
* Scout indexing jobs run on queue `search`; they get tenancy from the payload hook (§4.6), so
  `Searchable` needs no per-model work. `SCOUT_PREFIX` is empty in every runtime environment; only the
  per-engineer test env sets `SCOUT_PREFIX=test{N}_` so parallel suites do not share indexes.

### 8.7 Storage disks (`config/filesystems.php`)

`local` (private, `storage/app/private`), `public`, `uploads` (S3-compatible, `AWS_ENDPOINT`,
`AWS_USE_PATH_STYLE_ENDPOINT=true`, bucket `bp-uploads`; patient documents, handwriting sheets,
logos), `pdfs` (S3, bucket `bp-pdfs`, lifecycle 90 days), `backups` (S3, bucket `bp-backups`,
separate credentials). All tenant object keys go through `App\Support\Storage\TenantPath::for(string $rel): string`
= `tenants/{id}/{rel}`; direct disk paths without it fail code review. Locally every S3 disk falls
back to `local` when `AWS_BUCKET` is empty (`FILESYSTEM_S3_FALLBACK=true`).

### 8.8 Backups and export

`tenants:backup`: registers a `public.tenant_backups` row (`type daily|manual`, status `running`), resolves the
encryption mode FIRST (a host with no `BP_BACKUP_KEY` fails here, before spending ten minutes on a dump it is not
allowed to upload), runs `pg_dump --format=custom -n "tenant_<id>" --no-owner --no-acl booking` via
`Symfony\Component\Process\Process` (env `PGPASSWORD`), streams the archive through `BackupCipher` (§8.2),
`writeStream`s it to the `backups` disk under `tenants/<id>/…​.dump[.enc]`, records `storage_path`, `encryption`,
`size_bytes` (of the stored OBJECT), `checksum_sha256` (of the PLAINTEXT archive — what `pg_restore` eats, and
what the restore verifies after decrypting), status `completed`, deletes the local copies; 30 daily copies kept.
`tenants:restore {backup} --force` streams the object back, decrypts it if its magic says so, verifies the
checksum, restores into a scratch schema `tenant_<id>_restore`, checks it has tables, then swaps the two schemas
inside one transaction (`ALTER SCHEMA … RENAME`), keeping the displaced one as `tenant_<id>_replaced_<ts>`.
`pg_restore --schema=X` is a FILTER, not a rename — pointed at the live database it recreates `tenant_<id>`
objects in `tenant_<id>` and calls every collision an ignorable "already exists" — and PostgreSQL has no
restore-under-another-name switch, so `TenantSchemaDumper` converts the archive to SQL (`pg_restore --file=-`),
rewrites the schema identifier on the way past (outside `COPY … FROM stdin` data blocks only) and feeds the
result to `psql --single-transaction -v ON_ERROR_STOP=1`; a failure therefore leaves neither a scratch schema nor
a displaced one, and the live clinic is never the restore target. `tenants:export` writes JSON (one file per
table) + CSV zip + all uploads for churn (unencrypted, by design — see §8.2).

---

## 9. Contracts of the spine modules (the parts that must be exactly right)

These are specified in full elsewhere; this section pins the architectural invariants and where
the code lives so that no engineer re-decides them.

### 9.1 Serial engine — SERIAL_ENGINE.md + SCHEMA.md §3.3/§5.1

* Identity is `session_instances` (one row per branch × doctor × session template × date, materialised
  ahead of time); display code `"{code}-{number:03}"`. Tables: `session_instances, serial_pools, serials,
  serial_events, serial_blocks, reception_devices, appointments, offline_events`.
* `App\Domain\Serials\Actions\AllocateSerial::__invoke(App\Domain\Serials\Data\AllocationRequest $r): Serial` — one transaction:
  lock the owner row (`serial_pools` `FOR UPDATE`, or a released `serial_blocks` row first for the counter
  pool, SERIAL_ENGINE.md §4.3), insert `serials`, advance `next_number`, event row, commit; **then** (after
  commit) the Queue module's `InvalidateQueueState` listener rebuilds the `QueueState` and broadcasts.
  `serials_session_number_uniq (session_instance_id, number)` is the last line of defence (SQLSTATE 23505
  → retry up to 5 → `AllocationRetryExhausted`). Pools/blocks are non-overlapping by `btree_gist`
  exclusion constraints; `CREATE EXTENSION IF NOT EXISTS btree_gist` is the first central migration.
* Pool layout is counter → online → buffer (counter numbers lowest, buffer appended last; SERIAL_ENGINE.md §3.1).
  Online never draws from `counter`/`buffer`; changing the split needs permission `serials.split.adjust`
  (Doctor, Super Admin). Reorder = `position bigint` midpoint insertion under the session lock, always
  with `serial_events` + `audit_logs`. Device blocks (at most 2 active per device per session) are leased
  from the `counter` pool while online; unissued numbers of a released block **are** reused in the same
  session, drained lowest-first before the pool cursor (SERIAL_ENGINE.md §3.5, OFFLINE.md §4.6).
* Idempotency key for every allocation path (online double-submit and offline replay): `client_event_id` ULID.

### 9.2 Reception offline — OFFLINE.md

Device identity §2, connection store §3, block lease API §4, Dexie schema §5, event log §6, replay
protocol §7 (`POST /api/reception/sync`, events applied in `sequence_no` order, each in its own
transaction, per-event result `accepted | conflict | rejected | pending`), conflict taxonomy §8
(surfaced, never auto-merged), service worker §11. Server side: `App\Domain\Reception\Services\SyncReplayer`
and `App\Domain\Reception\Handlers\*Handler`. Offline capabilities and restrictions are exactly BRIEF §F.1.

### 9.3 Live queue — REALTIME.md + SCHEMA.md §5.7

* The queue state is the `QueueState` JSON document (REALTIME.md §4.1) in Redis at
  `t:{tenantId}:qs:{sessionInstancePublicId}` with its integer `version` mirrored at `…:v`
  (`version` = `session_instances.version`, bumped inside every mutating transaction), rebuilt by
  `App\Domain\Queue\Services\QueueStateRepository::rebuild()` from the `InvalidateQueueState` listener
  **after commit** and broadcast on `tenant.{tenantId}.queue.{sessionInstancePublicId}` with the same version.
* Polling fallback (LOCKED path): `GET /queue/{doctorSlug}/state?session=` (`routes/site/queue.php`,
  `site.queue.state`, public) — `ETag` **is** the quoted integer version; `If-None-Match` → 304 at the
  cost of one Redis GET; `Cache-Control: no-cache, private`; `throttle:queue-state` (30/min per IP+session;
  a 5 s poll uses 12). Site routes `/q/{doctorSlug}/today` (`site.queue.page`) and the `queue.` vanity host
  (`/{doctorSlug}/today`, `site.queue.vanity`) resolve to the same controller (REALTIME.md §5.1).
* ETA = running average of the last completed consultations for the session (SERIAL_ENGINE.md §13);
  "3 ahead" trigger REALTIME.md §7; waiting-room display shares the same payload.

---

## 10. Running it

```
scripts/dev-services.sh start                                   # Dragonfly + Meilisearch
php artisan migrate && php artisan catalog:migrate --seed && php artisan catalog:index-search --fresh
php artisan tenants:create "Demo Hospital" --slug=demo --admin-email=admin@demo.test --admin-password=password --demo
php artisan octane:start --server=frankenphp --host=127.0.0.1 --port=8000 --watch
php artisan horizon
php artisan reverb:start --host=127.0.0.1 --port=8080
npm run dev
```

Production runs the same four processes under systemd (no Docker on the dev box; the production
VPS uses `compose.yaml` + `docker/` at the repo root — see `docs/DEPLOYMENT.md`).
