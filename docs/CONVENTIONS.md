# CONVENTIONS — how ~10 engineers build modules in parallel in ONE working tree

Companion to `docs/ARCHITECTURE.md` (structure) and `docs/BRIEF.md` (product). These rules exist
so that engineers working simultaneously in `/home/laralink/www/booking-prescription` on `main`
never edit the same file, never collide on migration order, never trample each other's test
database, and ship modules that are verifiably done. No git worktrees (disk space); everyone
commits to short-lived branches off `main` and rebases often.

---

## 1. Directory layout (one line each)

```
app/
  Console/Commands/<Module>/        artisan commands owned by a module (foundation commands live in app/Tenancy/Console)
  Domain/<Module>/                  business logic: Actions, Services, Data, Events, Listeners, Jobs, Enums, Rules, Exceptions, Policies, Features, Notifications (+ module-private sub-namespaces, ARCHITECTURE §5.3)
  Domain/Shared/                    DomainException base, Money, Result, Actor, PhoneNumber value objects
  Http/Controllers/{Panel,Site,Api,Super,Central}/<Module>/   thin controllers per surface
  Http/Middleware/                  app-wide middleware (HandleInertiaRequests, SetActiveBranch, AssignRequestId)
  Http/Requests/{Panel,Site,Api,Super}/<Module>/               FormRequests
  Http/Resources/<Module>/          JSON resources for api + Inertia props
  Models/Central/                   public-schema models (extend CentralModel)
  Models/Tenant/                    tenant-schema models (extend TenantModel or use RequiresTenancy)
  Models/Catalog/                   catalog-db models (extend CatalogModel)
  Providers/                        AppServiceProvider, AuthServiceProvider, HorizonServiceProvider (foundation)
  Support/                          framework-level helpers with no domain meaning (Pdf, Storage, Scheduling, PhpStan rules)
  Tenancy/                          the tenancy kernel (see ARCHITECTURE §4)
bootstrap/app.php, bootstrap/providers.php      HTTP + provider wiring (foundation)
config/                             all config (foundation); modules request keys via PR to the foundation owner
database/migrations/                central (public schema) migrations
database/migrations/tenant/         tenant-schema migrations
database/migrations/catalog/        catalog-db migrations
database/factories/{Central,Tenant,Catalog}/   model factories mirroring app/Models
database/seeders/{Central,Tenant,Catalog}/     seeders; Tenant/RolesAndPermissionsSeeder is run on every deploy
docs/                               BRIEF.md, ARCHITECTURE.md, CONVENTIONS.md, docs/modules/<module>.md (per-module notes, optional)
public/                             index.php, build/ (vite), sw.js + panel.webmanifest (built, gitignored), fonts/, icons/
resources/css/{panel,site}.css      two stylesheets, two entrypoints
resources/js/{panel,site,shared}/   see ARCHITECTURE §7.1
resources/lang/{en,bn}.json         the only translation files
resources/views/{panel,site}.blade.php          Inertia root views
resources/views/print/**            Browsershot / print templates (prescription, token-slip, invoice, receipt, report)
resources/views/site/{rx,drug}/     the two non-Inertia public Blade pages of the site surface (PRESCRIPTION.md §7.4, §7.8)
routes/{panel,site,api,super,central}/<module>.php   route files, globbed by bootstrap/app.php
routes/channels.php, routes/console.php         foundation-owned (modules register schedules/channels via classes)
scripts/                            dev-services.sh, check-site-deps.sh, check-panel-budget.sh, sync-fonts.sh, test-agent.sh
tests/Unit/<Module>/                pure unit tests (no DB, extend PHPUnit\Framework\TestCase)
tests/Feature/<Module>/             HTTP + DB tests (extend Tests\TestCase, tenants provisioned)
tests/Concurrency/<Module>/         real-process concurrency tests (committed state, no transaction)
tests/Concerns/                     WithTenants, InteractsWithAudit, InteractsWithSearch traits
tests/Support/                      ProcessPool, fixtures, fake gateways
```

---

## 2. Ownership map

| Owner (engineer) | Module(s) | Owns exclusively |
|---|---|---|
| **F — Foundation** | Tenancy, Audit, Clinic (branches/departments/specialties/doctors/users/roles/pad designer/settings) | everything in §2.1 below, `app/Tenancy/**`, `app/Domain/{Tenancy,Audit,Clinic}/**`, `app/Models/{Central,Tenant,Catalog}/*Model.php` + `Concerns/`, `app/Models/Central/**`, `app/Models/Tenant/{User,Branch,Department,Specialty,Doctor,DoctorProfile,DoctorSpecialty,DoctorPadSetting,Setting,Role,Permission,PersonalAccessToken,AuditLog}.php`, `routes/api/tenancy.php` (`/api/ping`), `tests/TestCase.php`, `tests/Concerns/**`, `tests/Support/**`, `database/migrations/2026_01_01_*`, `database/migrations/tenant/2026_02_01_*` |
| **S — Scheduling+Serials** | Scheduling, Serials (incl. session lifecycle actions and `sessions:*` commands) | `app/Domain/{Scheduling,Serials}/**`, their models, `routes/*/{scheduling,serials}.php`, `database/migrations/tenant/2026_02_02_*`, `resources/js/panel/{Pages,Components}/{Scheduling,Serials}/**` |
| **R — Booking+Reception** | Booking, Reception (incl. offline sync, devices, blocks) | `app/Domain/{Booking,Reception}/**`, `routes/*/{booking,reception}.php`, `database/migrations/tenant/2026_02_03_*`, `resources/js/panel/{Pages,Components}/Reception/**`, `resources/js/site/Pages/Booking/**`, `resources/js/shared/offline/**` (delegated by F), `resources/js/panel/sw.ts` |
| **Q — Queue** | Queue (live queue, display, doctor screen, call-next, ETA, delay broadcast, channels, guards, `QueueState` store) | `app/Domain/Queue/**` (there is no `Realtime` module), `routes/*/queue.php`, `database/migrations/tenant/2026_02_04_*`, `resources/js/site/Pages/{Queue,Display}/**`, `resources/js/panel/Pages/Queue/**`, `resources/js/shared/realtime/**` (delegated by F) |
| **P — Prescription** | Prescription (writer, shorthand, templates, safety, output, amendments, `/rx`, `/drug`) | `app/Domain/Prescription/**`, `routes/*/prescription.php`, `database/migrations/tenant/2026_02_05_*`, `resources/js/panel/{Pages,Components}/Prescription/**`, `resources/js/panel/{hooks,lib}/prescription/**`, `resources/views/print/prescription/**`, `resources/views/site/{rx,drug}/**` |
| **E — Patients+Catalog** | Patients (mini-EMR, portal), Catalog (models, search, import, custom brands, reconcile) | `app/Domain/{Patients,Catalog}/**`, `app/Models/Catalog/**` (except base), `app/Models/Tenant/CustomBrand.php`, `routes/*/{patients,catalog}.php`, `database/migrations/tenant/2026_02_06_*`, `database/migrations/catalog/2026_03_01_*`, `database/seeders/Catalog/**`, `resources/js/site/Pages/Portal/**`, `resources/js/panel/Pages/Patients/**` |
| **B — Billing** | Billing (payments, gateways, invoices, commission) | `app/Domain/Billing/**`, `routes/*/billing.php`, `database/migrations/tenant/2026_02_07_*`, `resources/views/print/{invoice,receipt}.blade.php` |
| **N — Notifications** | Notifications (SMS/WhatsApp/push/email/IVR, templates, logs) | `app/Domain/Notifications/**`, `routes/*/notifications.php`, `database/migrations/tenant/2026_02_08_*` |
| **A — Reports+SaaS** | Reports, SaaS (plans, subscriptions, super admin, onboarding, marketing) | `app/Domain/{Reports,SaaS}/**`, `app/Http/Controllers/{Super,Central}/**`, `routes/{super,central}/*.php`, `routes/*/{reports,saas}.php`, `database/migrations/tenant/2026_02_09_*`, `resources/js/panel/Pages/{Reports,Super}/**`, `resources/js/site/Pages/Central/**` |
| **T — Telemedicine** | Telemedicine | `app/Domain/Telemedicine/**`, `routes/*/telemedicine.php`, `database/migrations/tenant/2026_02_10_*`, `resources/js/panel/Pages/Telemedicine/**` |

### 2.1 Shared files — edited ONLY by the foundation owner

`composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `bootstrap/app.php`,
`bootstrap/providers.php`, `config/*`, `phpunit.xml`, `phpstan.neon`, `pint.json`, `tsconfig.json`,
`vite.config.ts`, `.env.example`, `.gitignore`, `app/Http/Middleware/HandleInertiaRequests.php`,
`app/Providers/*`, `routes/console.php`, `routes/channels.php`, `resources/views/{panel,site}.blade.php`,
`resources/js/shared/**` (except the two delegated subtrees above — so `shared/connection/**`, `shared/ulid.ts`
and `shared/connection/ConnectionIndicator.tsx` are F-owned and implemented from OFFLINE.md §3/§9), `resources/js/{panel,site}/app.tsx`, `resources/js/panel/pwa.ts`,
`resources/js/{panel,site}/Layouts/**`, `resources/css/*`, `resources/lang/*.json` (see §7.5 for how
modules add keys), `tests/TestCase.php`, `tests/Concerns/**`, `database/seeders/DatabaseSeeder.php`,
`database/seeders/Tenant/TenantDatabaseSeeder.php`.

Rule: **other modules add new files only.** Need a config key, a composer/npm package, a middleware
alias, a provider registration, a shared-props field, a schedule entry, a broadcast channel, a
translation key? Open a PR/issue titled `[foundation] <module>: <need>`; the foundation owner
merges it within the day. Modules never "temporarily" edit a shared file.

Exceptions granted in advance (append-only, one line per module, no reordering):

* `bootstrap/providers.php` — a module appends `App\Domain\<Module>\<Module>ServiceProvider::class`.
* `resources/lang/{en,bn}.json` — a module adds keys under its own prefix (`serials.*`), keeps keys
  sorted within the prefix, never touches another prefix (§7.5).
* `resources/js/shared/types/models.d.ts` — a module appends its own `export interface` block
  between `// <module>:start` / `// <module>:end` markers.

---

## 3. Migrations

### 3.1 Timestamp allocation (never collide)

| Prefix | Directory | Module(s) |
|---|---|---|
| `2026_01_01_HHMMSS` | `database/migrations/` | central (public schema) — foundation, SaaS via foundation |
| `2026_02_01_HHMMSS` | `database/migrations/tenant/` | tenant foundation (Tenancy, Clinic, Audit, Spatie, Sanctum) |
| `2026_02_02_HHMMSS` | tenant | Scheduling + Serials |
| `2026_02_03_HHMMSS` | tenant | Booking + Reception/offline |
| `2026_02_04_HHMMSS` | tenant | Queue |
| `2026_02_05_HHMMSS` | tenant | Prescription |
| `2026_02_06_HHMMSS` | tenant | Patients / EMR |
| `2026_02_07_HHMMSS` | tenant | Billing |
| `2026_02_08_HHMMSS` | tenant | Notifications |
| `2026_02_09_HHMMSS` | tenant | Reports + SaaS (tenant-side tables) |
| `2026_02_10_HHMMSS` | tenant | Telemedicine |
| `2026_03_01_HHMMSS` | `database/migrations/catalog/` | Catalog |

* `HHMMSS` starts at `000100` and increases by 100 per file (`000100, 000200, …`). Later additive
  migrations by the same module reuse the module's prefix with a later `HHMMSS`
  (e.g. `2026_02_02_004500_add_position_to_serials_table.php`). Never use "today's date".
* Migrations run sorted by filename, so a module's migrations may only reference tables from
  modules with an **earlier** prefix (Serials may FK to Clinic; Queue may FK to Serials; Clinic
  may not FK to Serials). A later-prefix module that needs a column on an earlier module's table
  adds its own migration (`2026_02_04_000300_add_queue_columns_to_serials_table.php`) — it does
  not edit the earlier module's file.
* One table per `create_*` migration; name `create_<table>_table`, `add_<cols>_to_<table>_table`,
  `drop_<col>_from_<table>_table`. Anonymous migration classes; tenant/catalog migrations never
  set `$connection`; central migrations reference tables unqualified (they run with search path
  `public`).
* Never edit a migration that has been merged to `main`. Add a new one.

### 3.2 Schema conventions

* Tables `snake_case` plural, including join tables that carry their own `id` (`doctor_specialties`,
  `allergy_class_generics` — SCHEMA.md's names win); package pivots keep their published names (`model_has_roles`).
* Ids: `$table->id()` → `bigserial` (Laravel's Postgres grammar; `bigint NOT NULL DEFAULT nextval(...)`,
  not `GENERATED … AS IDENTITY`) on **every** table, central and tenant. Decision: tenant tables use
  **bigserial, not uuid**. Reasons: (a) a tenant schema is its
  own id space — there is no cross-tenant merging, so global uniqueness buys nothing; (b) 8-byte
  keys keep the hot indexes (`serials`, `serial_events`, `audit_logs`) small on cheap VPS RAM; (c)
  offline-created rows do not need client-generated PKs because they carry `client_event_id char(26)
  NOT NULL` (a ULID generated on the device by `resources/js/shared/ulid.ts`; unique per device — see
  OFFLINE.md §6) which is the idempotency key for replay; (d) rows that are exposed publicly get an
  opaque `public_id char(26)` ULID column (`$table->ulid('public_id')->unique()`, filled on `creating`
  by `App\Models\Concerns\HasPublicId` with `Str::ulid()`), e.g. `prescriptions.public_id` for the QR
  verification URL, `session_instances.public_id` in channel names. The bigint `id` is never exposed
  in URLs, channel names or API payloads. Do not use `HasUuids`/`HasVersion4Uuids`.
* FKs: `foreignId('doctor_id')->constrained()->cascadeOnDelete()` for owned children,
  `->restrictOnDelete()` for references; to central tables `->constrained('public.plans')`.
* Timestamps: `timestampsTz()` / `timestampTz('issued_at')`. Laravel's `timestampsTz()` creates
  `created_at`/`updated_at` as **nullable** `timestamptz` and Eloquent fills them; that is the convention
  (no `NOT NULL`, no database default) — so raw SQL inserts (`DB::table()->insert`, seed SQL, `COPY`)
  **must** set both to `now()` explicitly. All values are UTC (connection
  `timezone => 'UTC'`, `app.timezone => 'UTC'`). Dates that are clinic-local calendar dates
  (serial date, schedule day, holiday) are `date` columns holding the **Asia/Dhaka** calendar
  day, computed with `Clock::today()` (`App\Support\Clock`, uses `$tenant->timezone`, default `Asia/Dhaka`).
* Money: integer **paisa** (BDT minor unit) in `bigInteger` columns named `*_paisa`
  (`fee_paisa`, `amount_paisa`). `App\Domain\Shared\Money` (`Money::bdt(int $paisa)`, `->format()` →
  `৳1,250.00`). Never `decimal`, never floats.
* Enums: PHP string-backed enums in `app/Domain/<Module>/Enums` (class names, namespaces and values exactly as
  SCHEMA.md Appendix A lists them), column `varchar(32)` + `CHECK (col IN (...))` named `{table}_{column}_check`; no Postgres
  `ENUM` types. Adding a value = a migration that recreates the CHECK + the enum case + the SCHEMA row. Booleans `is_*`/`has_*`. Soft deletes only on the tables SCHEMA.md marks
  **soft delete: yes** — `tenants`, `super_admins`, `branches`, `users`, `doctors`, `patients`, `prescription_templates`,
  `custom_brands` (`softDeletesTz()`); clinical records are never deleted.
* Encrypted fields are `text`; JSON is `jsonb`; IPs are `inet` (`$table->ipAddress()`).
* Every tenant table that reception can create offline has `client_event_id char(26)` and
  `reception_device_id bigint nullable` with a partial `UNIQUE (reception_device_id, client_event_id) WHERE client_event_id IS NOT NULL`
  (`serials`, `appointments`, `payments`, `offline_events` — vitals are not offline-capable, OFFLINE.md §6.2).
* Extension objects in tenant migrations: **named** opclasses and functions live in `public` and are not
  on the tenant-only search path — write them qualified: `gin (name public.gin_trgm_ops)`,
  `public.similarity(...)`, `public.unaccent(...)`. Default opclasses (what `btree_gist` exclusion
  constraints use) need no qualification. An unqualified `gin_trgm_ops` fails `tenants:migrate`
  (ARCHITECTURE §4).
* Indexes: `{table}_{cols}_idx`, unique `{table}_{cols}_uniq`, partial `…_idx_p`, exclusion
  `{table}_{purpose}_excl`, checks `{table}_{column}_check` (SCHEMA.md §0.2); every FK gets a btree
  index (Postgres does not create one); exclusion constraints are added with `DB::statement()`.
* The column-level truth for every table is `docs/SCHEMA.md`; a migration that deviates from it must
  come with a SCHEMA.md change in the same commit.

---

## 4. PHP conventions

* PHP 8.4, `declare(strict_types=1);` in every file. `final` classes by default; `readonly` DTOs;
  constructor property promotion; typed properties and return types everywhere (PHPStan enforces).
* Namespaces mirror paths. Class naming: Actions are verbs (`AllocateSerial`, `IssuePrescription`,
  `CheckInSerial`), Services are nouns (`EtaCalculator`, `ShorthandParser`), Data is `<Verb>Data` or
  `<Noun>Request` (`AllocationRequest`), Events are past tense (`SerialBooked`, `PrescriptionIssued`),
  Listeners describe the effect (`InvalidateQueueState`, `CreateDraftFollowUpAppointment`), Jobs are verbs (`SendDayBeforeReminders`),
  Exceptions are conditions (`PoolExhausted`, `PlanLimitExceeded`), Enums are singular nouns
  (`SerialStatus`), Policies `<Model>Policy`, Requests `<Verb><Model>Request`, Resources `<Model>Resource`.
* Actions expose exactly one public method: `__invoke(...)` when the action takes one DTO and
  returns one model/value (the serial-engine convention, SERIAL_ENGINE.md §4), otherwise `handle(...)`.
  Never both. Actions are resolved from the container (constructor injection), never `new`-ed.
* Controllers: one per resource per surface, `__invoke` for single-action controllers, otherwise
  the 7 REST verbs only. A controller method is validate → Data → Action → respond, ≤ 25 lines.
* Models: relationships, casts, scopes, accessors only. No queries with business meaning outside
  `Actions/Services` (a scope like `scopeActive` is fine; "find the next serial to call" is not).
* Eloquent casts: enums via `'status' => SerialStatus::class`; money columns (`*_paisa`) are cast
  `'integer'` and wrapped in `App\Domain\Shared\Money` at the edges (there is no `MoneyCast`); timestamps
  `'issued_at' => 'immutable_datetime'` (use `CarbonImmutable` everywhere; `Date::use(CarbonImmutable::class)`
  is set in `AppServiceProvider`).
* Tenant context: never call `Tenancy::initialize()` in application code — only middleware, the queue
  hook, `tenants:*` commands and tests do. Code that must run for another tenant uses
  `Tenancy::run($tenant, fn () => ...)` and is restricted to `App\Domain\SaaS` and `App\Tenancy`.
* Clinical writes go through Eloquent `save()`/`delete()` on the model (Actions), never through
  `Model::query()->update()/insert()/upsert()/delete()`, `saveQuietly()` or `DB::table()`: only model events
  are audited (ARCHITECTURE §8.1) and only Eloquent is tenancy-guarded — `DB::table()` runs on whatever
  search path is active. A justified bulk write calls `AuditRecorder::record()` itself.
* A model instance belongs to the tenant it was loaded in: do not load in one tenant and save inside
  `Tenancy::run()` for another (`ModelTenantMismatch`); never write a `tenant_id` other than `Tenancy::id()`.
* Never `DB::statement("SET search_path ...")`, never `DB::reconnect()`, never `Model::unguard()`,
  never `withoutGlobalScopes()` on a `TenantModel` outside `App\Tenancy`.
* Central tables in raw SQL are always `public.xxx`; a tenant migration never references a central
  table unqualified (`->constrained('public.tenants')`).
* Errors: throw `DomainException` subclasses from Actions; controllers do not catch them (the global
  renderer in `bootstrap/app.php` maps them to 422 / Inertia errors). `abort(403)` only from policies/middleware.
* Events are dispatched **after commit** for anything that broadcasts or queues:
  `DB::afterCommit(fn () => event(new SerialBooked($serial)))` or `ShouldDispatchAfterCommit` on the
  event class. Queue jobs implement `ShouldQueue`, set `$queue` explicitly, use `TenantAware`, are
  idempotent, and set `$tries`/`$backoff`.
* Config access: `config('tenancy.central_domain')` — never `env()` outside `config/*`.
* Translations: PHP strings via `__('serials.pool_exhausted')`; no hard-coded user-facing English
  or Bangla in code.
* Formatting: `vendor/bin/pint` with `pint.json` `{"preset": "laravel", "rules": {"declare_strict_types": true, "final_class": true}}`.
  Run `vendor/bin/pint --dirty` before every commit; CI runs `vendor/bin/pint --test`.
* Static analysis: **Larastan level 6** (`composer require --dev larastan/larastan:^3` — foundation
  installs it; `phpstan.neon` is foundation-owned, `paths: [app, database, routes, tests]`,
  `level: 6`, custom rules under `app/Support/PhpStan`). `vendor/bin/phpstan analyse --memory-limit=1G`
  must be clean; baseline additions are not allowed for new code.

---

## 5. Route and HTTP conventions

* Route files: `routes/<surface>/<module>.php`, one per module per surface, `Route::` calls only
  (no closures — controllers are required so `route:cache` works). Names: `<surface>.<module>.<resource>.<action>`
  (`panel.serials.reorder`, `site.queue.state`, `api.reception.sync`, `super.tenants.impersonate`).
* URL segments are kebab-case; identifiers in URLs are `public_id` ULIDs or slugs — never bigint ids
  for any row that has a `public_id` (SCHEMA.md marks them **public_id: yes**: tenants, doctors, patients,
  serials, appointments, visits, prescriptions, invoices, payments, plus session instances, branches,
  reception devices and serial blocks once SCHEMA.md adds them). Route-model binding uses `{serial:public_id}`;
  `getRouteKeyName()` is not overridden globally so internal code keeps using ids. Staff-only configuration
  rows that SCHEMA.md gives no `public_id` (prescription templates, advice snippets, favourites, vitals,
  investigation catalog items, custom brands) use their bigint `id` in **panel** URLs only; they never
  appear in site/api URLs or channel names.
* Panel pages are Inertia; panel data mutations are Inertia form posts (`useForm`) returning redirects
  carrying a session flash: `return redirect()->route(…)->with('flash.success', __('module.flash.saved'))`
  (also `flash.error` / `flash.warning` / `flash.info`; `session()->now('flash.warning', …)` for the current
  request). `HandleInertiaRequests` exposes them as `SharedProps.flash` (ARCHITECTURE §7.4) and `PanelLayout`
  shows the first one as a snackbar — never `Inertia::flash()`. JSON endpoints live under `/api` only when consumed by the PWA, the queue
  page, autocomplete, or third parties; they return `JsonResource`s and use `api.` names. Documented
  exception: the prescription writer's own XHR endpoints (`PATCH …/draft`, `POST …/check`, the
  `/panel/search/*` autocomplete and quick-pick reads — PRESCRIPTION.md §3.8, §4.13) stay under `/panel`
  with `panel.prescription.*` names and return JSON; they require the staff session and are never called
  from the PWA or the site.
* Route names: the two public queue endpoints are `site.queue.state` (`GET /queue/{doctorSlug}/state`,
  LOCKED path) and `site.queue.page` (`GET /q/{doctorSlug}/today`); the reception device API is
  `api.reception.*` in `routes/api/reception.php`; the serial engine's JSON API is `api.serials.*` /
  `api.sessions.*` in `routes/api/serials.php`; public booking is `api.booking.*` in `routes/api/booking.php`;
  `GET /api/ping` is `api.tenancy.ping` in `routes/api/tenancy.php` (no auth).
* FormRequests: authorisation in `authorize()` via policies, rules in `rules()`, and a
  `toData(): <Data>` method. Validation messages come from `resources/lang/*.json` keys.
* Every clinical-record read endpoint calls `AuditLog::view()` (ARCHITECTURE §8.1) — reviewers grep
  for it in every `show`/`print`/`export`/`download` method.
* Rate limits are named limiters registered by the owning module's ServiceProvider
  (`RateLimiter::for('queue-state', ...)`, `otp`, `booking`, `api`).

---

## 6. Testing conventions

### 6.1 Runner and suites

PHPUnit 11 (no Pest). `phpunit.xml` (foundation-owned) defines suites `Unit`, `Feature`, `Concurrency`
and these env defaults — **without** `force="true"`, so a real environment variable wins:

```xml
<env name="APP_ENV" value="testing"/>
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_DATABASE" value="booking_test_1"/>
<env name="CATALOG_DB_DATABASE" value="catalog_test_1"/>
<env name="CACHE_STORE" value="array"/>
<env name="SESSION_DRIVER" value="array"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="BROADCAST_CONNECTION" value="log"/>
<env name="SCOUT_DRIVER" value="null"/>
<env name="MAIL_MAILER" value="array"/>
<env name="BCRYPT_ROUNDS" value="4"/>
<env name="OTP_FIXED_CODE" value="000000"/>
<env name="APP_CENTRAL_DOMAIN" value="bp.test"/>
```

Per-engineer isolation: engineer *N* (1–16, assigned in the ownership sheet) runs

```
DB_DATABASE=booking_test_N CATALOG_DB_DATABASE=catalog_test_N SCOUT_PREFIX=testN_ REDIS_DB=N php artisan test
```

`scripts/test-agent.sh N [phpunit args…]` exports exactly those four variables and execs
`php artisan test "$@"` (`REDIS_DB` selects the Dragonfly database index used by the `realtime`/`queue`
groups, which talk to Redis directly for `QueueState` keys; everything else stays on array/sync drivers).
Never run `php artisan test --parallel` (paratest is not installed and
would try to create `booking_test_N_test_M` databases); parallelism is per engineer, per database.
Never point tests at `booking`/`catalog` (the dev data). Redis (`scripts/dev-services.sh status`) is
required only by the `search`, `realtime` and `queue` groups; everything else runs with array/sync drivers.

### 6.2 Base classes

* `tests/Unit/**` extend `PHPUnit\Framework\TestCase` — no container, no DB (parsers, calculators, Money, enums).
* `tests/Feature/**` and `tests/Concurrency/**` extend `Tests\TestCase`:

```php
abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    use RefreshDatabase, WithTenants, InteractsWithAudit {
        WithTenants::migrateDatabases insteadof RefreshDatabase;      // one-time, committed provisioning of both tenant schemas
    }
    protected array $connectionsToTransact = ['pgsql', 'catalog'];   // honoured by RefreshDatabase::connectionsToTransact()
}
```

### 6.3 `Tests\Concerns\WithTenants` (foundation-owned)

Verified framework behaviour it relies on: `RefreshDatabase::refreshTestDatabase()` runs
`migrateDatabases()` once per process (guarded by `RefreshDatabaseState::$migrated`) and then
`beginDatabaseTransaction()` for every test; teardown rolls back **and disconnects** each transacted
connection; `InteractsWithTestCaseLifecycle::setUpTraits()` calls `setUp<Trait>()`/`tearDown<Trait>()`
for every used trait.

```php
trait WithTenants
{
    public const TENANT_A = 'tenant_test_a';   // public.tenants rows: id 9001, slug 'test-a', schema_name 'tenant_test_a'
    public const TENANT_B = 'tenant_test_b';   // id 9002, slug 'test-b', schema_name 'tenant_test_b'

    /** Replaces RefreshDatabase::migrateDatabases(). Runs ONCE per process, fully committed. */
    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', ['--seed' => false]);                          // public schema only (search path is 'public')
        $this->artisan('catalog:migrate', ['--fresh' => true, '--seed' => true]);       // catalog_test_N
        foreach ([self::TENANT_A, self::TENANT_B] as $schema) { DB::statement("drop schema if exists \"{$schema}\" cascade"); }
        foreach ([[9001, 'test-a', self::TENANT_A], [9002, 'test-b', self::TENANT_B]] as [$id, $slug, $schema]) {
            app(ProvisionTenant::class)->handle(ProvisionTenantData::forTests(id: $id, slug: $slug, schemaName: $schema));   // migrations + roles + one branch + admin user
        }
        DB::statement("select setval(pg_get_serial_sequence('public.tenants','id'), 10000)");
    }

    public function setUpWithTenants(): void   { Tenancy::check() && Tenancy::end(); }
    public function tearDownWithTenants(): void { Tenancy::check() && Tenancy::end(); }

    protected function tenant(string $which = 'a'): Tenant           { return Tenant::query()->findOrFail($which === 'a' ? 9001 : 9002); }
    protected function asTenant(string $which = 'a'): static         { Tenancy::check() && Tenancy::end(); Tenancy::initialize($this->tenant($which)); $this->withServerVariables(['HTTP_HOST' => "test-{$which}.bp.test"]); return $this; }
    protected function asCentral(): static                            { Tenancy::check() && Tenancy::end(); $this->withServerVariables(['HTTP_HOST' => 'super.bp.test']); return $this; }
    protected function actingAsStaff(Role|string $role = Role::Receptionist, ?Branch $branch = null): User   // creates a user in the CURRENT tenant, assigns the role, logs in on guard 'web', sets active branch
    protected function actingAsDoctor(array $profile = []): User      // staff user + Doctor row + schedule template
    protected function actingAsPatient(?Patient $patient = null): Patient  // guard 'patient'
    protected function actingAsSuper(): SuperAdmin                     // guard 'super', central host
    protected function actingAsDevice(?ReceptionDevice $device = null): ReceptionDevice   // Sanctum::actingAs($device, ['reception:*'], 'device')

    /** Isolation assertion: run $callback in tenant A, prove tenant B cannot see the rows it created. */
    protected function assertTenantIsolated(string $table, Closure $callback): void
    {
        $this->asTenant('a'); $callback();
        $this->assertSame(self::TENANT_A, DB::scalar('show search_path'));
        $countA = DB::table($table)->count();
        $this->asTenant('b');
        $this->assertSame(self::TENANT_B, DB::scalar('show search_path'));
        $this->assertSame(0, DB::table($table)->count(), "tenant_b can see {$countA} rows of {$table} written by tenant_a");
        Tenancy::end();
        $this->assertSame('public', DB::scalar('show search_path'));
        // No public fallback: a bare tenant table name must not resolve. Run it in a nested transaction (savepoint)
        // so the failed statement does not abort the test's outer transaction.
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table($table)->count()), QueryException::class);
    }
}
```

Because every test runs inside one transaction on the `pgsql` connection, writes to `public` and to
either tenant schema roll back together; switching tenants inside a test is just `SET search_path`
(transaction-scoped, restored by the rollback). Requests made with `$this->get('/panel/...')` go through
`ResolveTenant`, which re-initialises tenancy from `HTTP_HOST` — that is why `asTenant()` sets the host.

### 6.4 Helpers you must use

* `assertAudited(AuditAction $action, Model $subject, ?array $contextSubset = null)` and
  `assertNotAudited(...)` from `Tests\Concerns\InteractsWithAudit`.
* `assertTenantIsolated('serials', fn () => ...)` in **every** module's feature suite, for each table the
  module writes.
* `Event::fake([...])` / `Bus::fake()` for broadcasts and jobs; `Notification::fake()`; the SMS/WhatsApp/
  payment gateways have `Tests\Support\Fake*Gateway` implementations bound in `setUp`.
* `#[Group('search')]` tests use the real Meilisearch with `SCOUT_DRIVER=meilisearch` set by the group's
  own `setUp` (they skip when `curl :7700/health` fails); `#[Group('realtime')]` tests assert broadcast
  payloads via `Event::fake` — they never start Reverb.
* `travelTo()` with `Asia/Dhaka` wall-clock helpers `Clock::freeze('2026-03-01 09:00')`.
* Inertia responses: `$this->get(route('panel.reception.board'))->assertInertia(fn (AssertableInertia $p) => $p->component('Reception/Board')->has('sessions', 2))`.

### 6.5 Concurrency tests (`tests/Concurrency/**`, `#[Group('concurrency')]`)

The transaction wrapper cannot prove locking, so these tests use **committed** state and real parallel
processes:

```php
final class AllocateSerialConcurrencyTest extends TestCase
{
    protected array $connectionsToTransact = [];     // no transaction: everything this test writes is committed

    protected function setUp(): void { parent::setUp(); $this->asTenant('a'); $this->session = SessionInstanceFactory::new()->openToday()->create(); }
    protected function tearDown(): void { $this->truncateTenantTables('a', ['serial_events', 'serials', 'serial_blocks', 'serial_pools', 'appointments', 'session_instances', 'audit_logs']); parent::tearDown(); }

    public function test_parallel_bookers_never_share_a_number(): void
    {
        $results = ProcessPool::run(workers: 8, command: ['php', 'artisan', 'serials:hammer', '--tenant=9001', "--session={$this->session->id}", '--count=25', '--pool=online'], timeoutSeconds: 120);
        $numbers = Serial::query()->where('session_instance_id', $this->session->id)->pluck('number');
        $this->assertCount(200, $numbers); $this->assertSame($numbers->unique()->count(), $numbers->count());
        $this->assertSame(0, $results->failed());
    }
}
```

* `Tests\Support\ProcessPool::run()` spawns `Symfony\Component\Process\Process` workers (not `pcntl_fork`:
  forking a booted Laravel app shares PDO handles) with the **same** `DB_DATABASE`, `CATALOG_DB_DATABASE`,
  `APP_ENV=testing` environment as the test process, so every worker sees the committed schema state.
* `serials:hammer` (`App\Console\Commands\Serials\HammerCommand`, dev/testing only, refuses to run when
  `app()->isProduction()`) runs `Tenancy::run($tenant, ...)` and calls `AllocateSerial` in a loop with
  random 0–5 ms jitter, printing one JSON line per result; failures are counted by the pool.
* Cleanup is the test's job (`truncateTenantTables()` issues `TRUNCATE … RESTART IDENTITY CASCADE` inside
  `Tenancy::run`); the next test process's `migrateDatabases()` drops and re-provisions both schemas anyway.
* Also required (SCHEMA.md §5.1): a second test disables the owner-row lock by setting
  `config(['serials.testing_skip_owner_lock' => true])` before spawning the workers (`AllocateSerial::lockOwnerRow()`
  honours the flag only when `app()->environment('testing')`; there is no static switch) and proves that
  `serials_session_number_uniq` still rejects duplicates (SERIAL_ENGINE.md §18.1).
* Same pattern for `LeaseBlock` (six devices leasing in parallel), `SyncReplayer::replay()` (two devices
  replaying interleaved logs) and `CheckInSerial` (double check-in from two desks). Every parallel-process
  test lives in `tests/Concurrency/<Module>/` — never in `tests/Feature`.

### 6.6 Running subsets

```
scripts/test-agent.sh N                                    # whole suite
scripts/test-agent.sh N --testsuite=Feature --filter=Serials
scripts/test-agent.sh N tests/Feature/Serials/AllocateSerialTest.php
scripts/test-agent.sh N --group=concurrency                # real processes, slow
scripts/test-agent.sh N --exclude-group=concurrency,search # quick loop
```

A module is not "done" until `scripts/test-agent.sh N` (the **full** suite, including concurrency and,
with services up, search/realtime groups) is green on a fresh database — see §9.

---

## 7. Frontend conventions

### 7.1 Files and naming

* `PascalCase.tsx` for components and pages, `camelCase.ts` for hooks/utils/stores, one component per
  file, default export for pages only (`export default function Board()`), named exports elsewhere.
* Pages: `resources/js/<surface>/Pages/<Module>/<Page>.tsx`; the component name is the file name; the
  server renders `Inertia::render('<Module>/<Page>')` (surface-relative, ARCHITECTURE §7.3). Super pages
  live in `panel/Pages/Super/**`. Every page assigns its layout statically:
  `Board.layout = (page: ReactNode) => <PanelLayout title="reception.board.title">{page}</PanelLayout>`.
* Module components: `resources/js/<surface>/Components/<Module>/…`; cross-module UI goes to
  `shared/components` **only via the foundation owner**. Hooks in `<surface>/hooks/<module>/`.
* Types: server-shaped types in `shared/types/models.d.ts` (module-marked blocks), page props typed as
  `PageProps<{ sessions: SessionSummary[] }>` from `shared/types/inertia.ts`. No `any`; `unknown` + narrowing.
  `PageProps<T>` is `Omit<SharedProps, keyof T> & T` — a page prop whose name collides with a shared one (say
  `branches`) types as the **page's** own shape, which is what the server sends; the shared value is then
  unreachable on that page, so read it with `useSharedProps()` if both are needed.
* Ziggy: `route('panel.serials.reorder', { serial: s.public_id })` from `@shared/routes`; never string URLs.
  After adding a route: `php artisan ziggy:generate --types-only resources/js/shared/types/ziggy.d.ts` and commit.

### 7.2 Where network calls live

* Inertia navigation and form submission: `router.visit`/`useForm` **inside pages/components** is fine.
* Every `fetch`/axios call lives in `resources/js/<surface>/api/<module>.ts` (e.g. `panel/api/patients.ts`,
  `site/api/booking.ts`; one file per module per surface, created by the module) as a typed function
  (`export async function syncOutbox(body: SyncRequest): Promise<SyncResponse>`) using the shared axios
  instance from `@shared/http`. No `axios` import outside `api/*.ts` and `shared/http.ts`.
* Network state: components never read `navigator.onLine`, never hold their own "is connected" state,
  never bind to Echo directly. They use `useConnection(selectIsLive)` etc. from
  `@shared/connection/store` and, from `@shared/realtime`, `useQueueState()` (React wrapper over
  `subscribeQueue()` in `liveQueue.ts`, REALTIME.md §6) for the public queue and `useChannel()` for the
  private reception/doctor/display/prescription channels. The store is the single source of truth
  (OFFLINE.md §3); a PR that adds a second one is rejected.
* Money is displayed via `formatBdt(paisa)`; dates via `formatDhaka(iso, 'D MMM, h:mm a')`; serial codes via
  `serialCode(code, number)` — all from `@shared/format`. No inline `Intl`/`dayjs` formatting in components.

### 7.3 Panel (MUI) rules

* Import from `@mui/material/<Component>` (per-component paths) and `@mui/icons-material/<Icon>`, never
  the barrel. Theme tokens only (`sx={{ p: 2, color: 'text.secondary' }}`), no hard-coded colours.
* Data grids: MUI `Table` + our `DataTable` component in `panel/Components/Shared`; the Pro grid is not licensed.
* Drag-and-drop: `@dnd-kit` only in `panel/Components/Serials/QueueList.tsx` (owned by S) and the pad
  designer (F). Charts: `recharts` only in `panel/Components/Charts/**` (except `LazyChart.tsx`, which pages
  import statically) and `panel/Components/Patients/VitalsTrendCharts.tsx` (BRIEF §5.H's vitals trend). **No page
  imports recharts.** A chart is a component in `Components/Charts/`, reached through `React.lazy` and wrapped in
  `<LazyChart height={…} empty={rows.length === 0}>`: the ~97 KB gzip chunk is fetched after first paint and, for
  a range with no rows, not at all — the card says so in words instead.
* **Panel payload budget.** `scripts/check-panel-budget.sh` (`npm run check:panel`, `composer check-panel`) is the
  sibling of the site's guard and enforces both halves of the rules above: the barrel-import / recharts / dnd-kit /
  date-picker rules by walking `resources/js/panel`, and a first-load budget per route read from
  `public/build/manifest.json` (entry closure + the heavier panel BASE locale chunk + the route's MODULE locale
  chunk, ARCHITECTURE §7.5 + the route's page chunk). Two numbers:
  **≤ 355 KB gzip for any panel route** and **≤ 345 KB gzip for the clinical routes** — `Reception/*`,
  `Prescription/*`, `Queue/*`, `Patients/*`, `Dashboard/*` — which are the screens BRIEF §8 means by "usable on a
  low-end device". Both are set from measured reality with headroom; lowering them is welcome, raising one needs a
  reason in the PR. Anything a route does not need at first paint (dialogs, the drawing canvas, the handwriting
  pad, charts, the token-slip printer) goes behind `React.lazy` — the service worker precaches every built chunk,
  so a lazily loaded dialog still opens on an offline desk (OFFLINE.md §11).
* `@mui/x-date-pickers` is **not** mounted in `panel/app.tsx`: no page uses a picker, and its `LocalizationProvider`
  cost every route ~5 KB gzip. A page that needs one wraps itself (`LocalizationProvider` + `AdapterDayjs`) inside
  its own chunk.
* Keyboard-first: every dialog has an Enter/Esc binding; the prescription writer's shortcuts are
  PRESCRIPTION.md §1.4 and are registered through `panel/hooks/useHotkeys.ts`.

### 7.4 Site (Tailwind, low-end Android) rules

* **No MUI, Emotion, recharts, dnd-kit, date-pickers or @fontsource/inter in `resources/js/site`** —
  `scripts/check-site-deps.sh` (`npm run check:site`, `composer check-site`) fails if any is imported. It walks
  the real import graph from `site/app.tsx` **and every page** (the pages arrive through `import.meta.glob`), so a
  panel dependency added to a `resources/js/shared/**` file the site reaches is caught too — which is how one
  actually gets in. Allowed deps: react / react-dom (aliased to preact/compat in the site build),
  @inertiajs/react + @inertiajs/core, laravel-vite-plugin/inertia-helpers, zustand, laravel-echo, pusher-js,
  i18next, react-i18next, dayjs (the site build resolves `@shared/format/date` to the Intl implementation),
  qrcode.react, ziggy-js, dexie (portal cache only), axios, @fontsource/noto-sans-bengali. The list lives in the
  script; changing it means changing this line in the same PR.
* One package is allowed **only behind `import()`**: `livekit-client`, the video SDK of the telemedicine room
  (BRIEF §5.K). At ~90 KB gzip it is larger than the whole per-route budget below, so it is reached from
  `site/Pages/Telemedicine/core/videoClient.ts` and nowhere else, always dynamically — a patient who never
  presses "Join" never downloads it, and a clinic on Jitsi or on no provider at all never downloads it either.
  `check-site-deps.sh` fails a STATIC import of it (`LAZY_ONLY`), and the budget below is the other half of the
  guarantee: if it ever lands in a first load, the route's number says so.
* Budget per route: **≤ 95 KB gzip of first-load JS** (REALTIME.md §8), also enforced by
  `scripts/check-site-deps.sh` — after `npm run build` it reads `public/build/manifest.json` and adds the entry's
  static-import closure + the route's page chunk + the (heavier) locale chunk, which is what a visitor downloads
  before first paint; dynamic imports (the realtime chunk, other routes' pages) are not counted. Without a build
  it prints SKIPPED and passes, so the pre-push sequence stays fast; CI runs it as
  `npm run build && scripts/check-site-deps.sh --require-build`. Plus ≤ 150 KB gzipped JS+CSS and ≤ 2 requests
  before first paint overall; `npx vite-bundle-visualizer` (dev-only tool) to find out where it went.
* Tailwind utilities only; colours via the tenant tokens `bg-primary`, `text-primary`, `bg-accent`
  (mapped in `resources/css/site.css` `@theme` to `--tenant-*` variables). No `style={{}}` colours.
* Every site page must render usefully without JavaScript-driven fonts (system font fallback) and with
  WebSocket blocked (polling), and must be tested at 360×640 with CPU 4× slowdown in DevTools.
* The waiting-room display (`site/Pages/Display/Board.tsx`) uses `rem`-based large type, auto-scales the
  doctor grid, and never shows patient names — codes only.

### 7.5 Translations

* Keys: `module.screen.element[.state]` (`reception.board.title`, `serials.status.no_show`,
  `common.actions.save`). `common.*` is foundation-owned. Both `en.json` and `bn.json` get every key in the
  same commit; missing Bangla is a review blocker, not a TODO.
* Add keys by appending inside your module's alphabetised block: keys must be sorted **within** their
  first-segment block (`patients.*`, `catalog.*`, …); blocks themselves may be appended in any order (a global
  sort is *not* required). Run `php artisan lang:check` (foundation command: verifies both files parse, have
  identical key sets, are sorted within each block, contain no empty value, and that every literal
  `t('…')` / `__('…')` / `trans('…')` / `@lang('…')` key under `resources/js`, `app`, `routes`, `database`,
  `resources/views` exists — dynamic keys and comment lines are skipped). The Vitest `i18n.test.ts` parity test
  applies the same rule.
* Bangla digits: `formatBn()` converts numbers on display; never store Bangla digits.
* The two JSON files stay flat and complete; the **build** splits them per surface and per locale
  (ARCHITECTURE §7.5). A site page may only use keys whose prefix is listed in
  `resources/js/shared/lang/surfaces.ts` — `bundles.test.ts` fails the build otherwise, since an
  unlisted key renders as the raw key in production while looking fine in tests. Adding a prefix there
  is a foundation change, like any other shared file (§2.1).

### 7.6 Making a panel page offline-capable (reception only)

1. The page's data must be loadable from Dexie: add a table to `shared/offline/db.ts` (OFFLINE.md §5) and a
   `bootstrap` slice served by `GET /api/reception/bootstrap`.
2. Render from a `useOfflineFirst(query)` hook: Dexie first, network refresh when `mode !== 'offline'`.
3. Every mutation on the page goes through `eventLog.append({ type, payload, dependsOn? })` from
   `@shared/offline/eventLog` (OFFLINE.md §6 — it assigns `clientEventId` and `sequenceNo`), never a direct
   API call, and has a server replay handler `App\Domain\Reception\Handlers\<Type>Handler` (OFFLINE.md §7.2).
4. Register the page's navigation URL in `resources/js/panel/sw.ts` (`NetworkFirst` shell route) and its read
   APIs in the SW route table (OFFLINE.md §11); mutations stay `NetworkOnly`.
5. Add the page to `tests/Feature/Reception/OfflineReplayTest` (replay of its events) and an OFFLINE.md §12 case.
6. Only the reception desk is offline-capable. Prescription, billing refunds and online-pool serials are
   explicitly **not** (BRIEF §F.1).

---

## 8. Git workflow for a shared working tree

* Branch per task: `<owner-letter>/<module>-<short-desc>` (`s/serials-allocate-action`). Rebase on `main`
  at least daily; PRs are small (≤ 600 lines diff excluding generated files) and merge within a day.
* Commits touch only files you own plus the append-only exceptions in §2.1. A PR that edits a
  foundation-owned file without the foundation owner as reviewer is not merged.
* Generated files that are committed: `resources/js/shared/types/ziggy.d.ts`, `public/fonts/**`,
  `composer.lock`, `package-lock.json`. Never commit `public/build`, `public/sw.js`, `public/panel.webmanifest`,
  `storage/**`, `.env`.
* Migrations, seeders and factories for a table ship in the same PR as the model.
* Every PR description lists the DoD commands run and their result (§9).

---

## 9. Definition of done (per module)

Copied from BRIEF §8 and expanded with the exact verification. A module is done only when every line
below is true and the PR shows the commands' output.

| # | Requirement (BRIEF) | How it is verified |
|---|---|---|
| 1 | Concurrency-sensitive paths have tests proving correctness under parallel load (serial allocation especially) | `scripts/test-agent.sh N --group=concurrency` green; the module's `tests/Concurrency/**` uses `ProcessPool` with ≥ 8 workers; for serials the no-lock variant proves the unique index holds |
| 2 | Every clinical write produces an audit log entry | Feature tests call `assertAudited()` for every Action that writes a clinical model; `grep -rn "AuditLog::view" app/Http/Controllers` covers every clinical `show/print/export/download` |
| 3 | Usable on a low-end Android device over a poor connection | Site pages: bundle ≤ 150 KB gz (`npm run build` output), tested at 360×640 + CPU 4× + "Slow 3G" in DevTools, screenshots attached; polling fallback exercised by blocking `ws://` |
| 4 | Bangla renders correctly in UI, print and SMS | `bn.json` complete (`php artisan lang:check`); Browsershot PDF sample with Bangla advice attached (`php artisan pdf:sample prescription --lang=bn`); SMS template test asserts UCS-2 encoding + segment count |
| 5 | Tenant isolation verified by test — no query can reach another tenant's schema | `assertTenantIsolated()` for every table the module writes; the module's `TenantModel`s throw `TenancyNotInitialized` without tenancy (`tests/Feature/<Module>/IsolationTest.php`); `SHOW search_path` assertions in place |
| 6 | No prescription rendering path joins live to `catalog` | `vendor/bin/phpstan analyse` clean (rule `NoCatalogModelsInRendering`); `grep -rn "Models\\\\Catalog" app/Domain/Prescription/Render` returns nothing |
| 7 | Schema matches `docs/SCHEMA.md` | migrations reviewed against SCHEMA.md; `php artisan tenants:migrate --tenant=9001 --pretend` shows no drift; enum values equal the CHECK lists |
| 8 | Full test suite passes on a fresh database | `scripts/test-agent.sh N` (all suites, all groups, services up) — exit 0 — run **after** the final rebase |
| 9 | Code quality gates | `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G`, `npm run typecheck`, `scripts/check-site-deps.sh`, `scripts/check-panel-budget.sh` all exit 0 |
| 10 | Routes, types and translations regenerated | `php artisan ziggy:generate --types-only resources/js/shared/types/ziggy.d.ts` committed; `php artisan route:list --name=<module>` reviewed for naming |
| 11 | Octane-safe | No static mutable state; new singletons that hold request/tenant state are listed in `config/octane.php` `flush` (via the foundation owner); `php artisan octane:start --workers=2 --max-requests=50` + a 200-request smoke script (`scripts/octane-smoke.sh`) shows no `tenancy.leak` log line |
| 12 | Queue-safe | Jobs use `TenantAware`, set `$queue`, are idempotent; `tests` dispatch them with `Bus::fake()` assertions and one real `sync` execution inside `asTenant('a')` |
| 13 | Documentation | Module notes in `docs/modules/<module>.md` if behaviour deviates from BRIEF/SCHEMA/this doc; SCHEMA.md updated in the same PR for any column change |

Quick pre-push sequence (copy/paste):

```
vendor/bin/pint --dirty && vendor/bin/phpstan analyse --memory-limit=1G && npm run typecheck && scripts/check-site-deps.sh && scripts/check-panel-budget.sh \
&& php artisan lang:check && scripts/test-agent.sh N --exclude-group=concurrency,search,realtime
# before declaring done:
scripts/dev-services.sh start && scripts/test-agent.sh N
```

---

## 10. Seeders, factories and demo data

* Factories mirror models: `database/factories/Tenant/SerialFactory.php` → `Database\Factories\Tenant\SerialFactory`;
  models declare `protected static string $factory = SerialFactory::class` (no name-guessing across the
  three namespaces). Factories for `TenantModel`s assume tenancy is active and never create a tenant.
* Factory states are named for domain situations (`->openToday()`, `->checkedIn()`, `->issued()`), never for
  columns. Related rows are created lazily (`Serial::factory()->for(SessionInstance::factory()->openToday())`).
* Seeders: `Database\Seeders\Central\*` (plans, a super admin), `Database\Seeders\Tenant\*`
  (`RolesAndPermissionsSeeder` — idempotent, runs on every deploy; `DemoDataSeeder` — only via
  `tenants:create --demo`), `Database\Seeders\Catalog\CatalogSampleSeeder` (dev sample only; real
  DGDA data arrives through `catalog:import`). Seeders never call `truncate()`; they upsert by natural keys.
* Demo data is Bangla-first: names, addresses and advice snippets are realistic Bangla strings so
  rendering problems surface in development, not in a clinic.

---

## 11. Config keys, logging and secrets

* New config keys go in the owning module's file `config/<module>.php` (created by the foundation
  owner on request, e.g. `config/serials.php` → `serials.block_size`, `serials.buffer_default`). Access is
  `config('serials.block_size')`; `.env.example` gets the variable with a safe default in the same PR.
  A module never ships its own `config.php` under `app/Domain/**` for its provider to `mergeConfigFrom`: it is
  outside `config/`, so `env()` is not allowed there and the value cannot come from the environment at all.
  Module config files today: `config/{patients,prescription,notifications,billing,catalog,tenancy}.php`.
* Tenant-editable settings (SMS gateway, pad defaults, fee rules) are **rows**, not config; encrypted
  where SCHEMA.md marks them ENC.
* Logging: `Log::withContext()` is set by `AssignRequestId` (`request_id`, `tenant_id`, `actor`) once per
  request/job; modules log with `Log::channel('clinical')` for clinical events and the default channel
  otherwise. **No PII in logs**: never log patient names, mobiles, prescriptions or OTP codes (the
  `Tests\Support\NoPiiInLogs` assertion runs in the Feature suite base `tearDown`).
* Secrets only via `.env`/environment; `php artisan config:show` output must not be pasted into PRs.
* Metrics: counters through `App\Support\Metrics::increment('serials.allocated', ['pool' => …])` (Redis
  backed, scraped by the super dashboard); no ad-hoc `Cache::increment` for metrics.

---

## 12. Starting a module — skeleton checklist

1. Read BRIEF (your module), SCHEMA.md (your tables), and the sibling spec (SERIAL_ENGINE/REALTIME/OFFLINE/
   PRESCRIPTION) if one exists. Disagreements go to the foundation owner before code.
2. Create `app/Domain/<Module>/<Module>ServiceProvider.php` and append it to `bootstrap/providers.php`.
3. Migrations under your prefix (§3.1); models + factories + `RolesAndPermissionsSeeder` permissions
   (send the new `Permission` enum cases to the foundation owner — the enum is F-owned).
4. Actions/Data/Events/Exceptions; then controllers + FormRequests + route files per surface; then pages.
5. Tests first for the invariants (isolation, audit, concurrency where applicable), then features.
6. Translations (`en.json` + `bn.json`), `ziggy.d.ts`, `models.d.ts` block, `docs/modules/<module>.md` if needed.
7. Run the §9 sequence; open the PR with the outputs.

---

## 13. API and JSON conventions (`/api`, Inertia props)

* Shapes come from `JsonResource`s in `app/Http/Resources/<Module>/`; Inertia page props use the same
  resources (`SessionResource::collection($sessions)`), so the client types in `models.d.ts` describe one
  shape per model. Keys are `snake_case` on the wire (Laravel default); the client does not rename them.
* Identifiers on the wire are `public_id` (ULID) and slugs; timestamps are ISO-8601 UTC (`…Z`); money
  is `{ "paisa": 50000, "formatted": "৳500.00" }` via `MoneyResource`; enums are their string values.
* Lists are paginated with `->paginate(50)` (cursor pagination for timelines/audit); the queue snapshot and
  reception bootstrap are the only unpaginated documents and are size-bounded by design.
* Errors: validation → 422 `{ "message": …, "errors": { field: [msg] } }` (Laravel default); domain
  failures → 422 `{ "message": …, "code": "serials.pool_exhausted" }` (ARCHITECTURE §2 renderer);
  auth → 401/403; unknown tenant/route → 404. Clients branch on `code`, never on message text.
* Idempotent mutations accept `client_event_id` (ULID) and return the existing result on replay; the
  reception sync protocol is OFFLINE.md §7. Webhook endpoints (`api/webhooks/*`) verify gateway signatures
  and are CSRF-exempt (`bootstrap/app.php`).
* No API versioning prefix: the only consumers are first-party (panel, PWA, site). Breaking a wire shape
  requires updating `models.d.ts`, the resource, and any Dexie table that caches it (bump `db.version()`).

---

## 14. Glossary (use these words, not synonyms)

| Term | Meaning |
|---|---|
| tenant | one clinic/hospital = one `public.tenants` row = one `tenant_<id>` schema |
| central | the `public` schema / no-tenant context (`super`, marketing) |
| surface | one of `panel`, `site`, `api`, `super`, `central` (route dir + controller namespace + root view) |
| session instance | a concrete doctor session on a date (`session_instances`); "session" alone is ambiguous with HTTP sessions — say *session instance* in code and docs |
| serial | a token number inside a session instance; display code `A-042` |
| pool | `online` / `counter` / `buffer` number range of a session instance |
| block | a lease of counter numbers to one reception device for offline issuing |
| QueueState | the Redis/WebSocket/polling queue document (REALTIME.md §4.1) — never "queue snapshot" |
| snapshot | `prescriptions.snapshot` (the frozen prescription document) — only this meaning |
| device | a registered reception tablet or display TV (`reception_devices`), authenticated by the `device` guard |
| actor | the human behind a request or replayed event (`X-Actor-User`); in code `App\Domain\Shared\Actor` |
| event log | the device's ordered offline event log (`shared/offline/eventLog.ts`, `offline_events`) — never "outbox" |
| public id | the ULID exposed in URLs/channels/APIs; the bigint `id` is internal |

---

## 15. Glossary of canonical names (one spelling; use these everywhere)

When a sibling spec says it differently, this table wins and the spec is out of date.

| Concept | Canonical name |
|---|---|
| **Modules** | `Tenancy, Clinic, Patients, Scheduling, Serials, Booking, Queue, Reception, Prescription, Catalog, Billing, Notifications, Reports, SaaS, Telemedicine, Audit` (+ `Shared`); **no** `Realtime` module — realtime lives in `App\Domain\Queue` |
| Enums | `App\Domain\<Module>\Enums\<Name>` (class names/values from SCHEMA.md Appendix A, e.g. `App\Domain\Serials\Enums\{SerialStatus,SerialPool,SerialSource,SerialPriority}`); never `App\Enums` |
| Pennant | feature classes `App\Domain\SaaS\Features\*`; table `public.feature_flags`; scope = `Tenant` |
| Guards | `web` (staff `users`), `patient` (`Patient` model, OTP — not a Spatie role), `super` (`SuperAdmin`), `device` (Sanctum, provider `reception_devices`), `sanctum` (bearer-or-session on `/api`) |
| Roles | spatie roles (guard `web`, snake_case): `hospital_admin`, `doctor`, `receptionist`, `compounder`, `accountant`; **`compounder` is its own role, never a synonym for `receptionist`** (which is what this table used to say) — the doctors it may act for come from the `doctor_compounder` pivot, read through `App\Domain\Clinic\Services\DoctorScope` (ARCHITECTURE §6.2); new cases are appended to `App\Domain\Clinic\Enums\Role`; Patient is a guard, not a role; Super Admin is central (`super` guard), not a spatie role |
| Permissions | `<module>.<resource>.<action>` from `App\Domain\Clinic\Enums\Permission`: `serials.split.adjust`, `queue.call-next`, `reception.devices.register`, `prescriptions.write` … (ARCHITECTURE §6.2) |
| Actor DTO | `App\Domain\Shared\Actor` |
| Domain exception | `App\Domain\Shared\Exceptions\DomainException` with `code()` (`<module>.<condition>`) and `status()` (422; 409 for conflicts) |
| Serial engine | `App\Domain\Serials\Actions\AllocateSerial::__invoke(App\Domain\Serials\Data\AllocationRequest): Serial`; `App\Domain\Serials\Services\{SerialTransition,PositionService,DisplayCode,CapacityService,CountsRecalculator,SerialEventWriter}`; session lifecycle actions `App\Domain\Serials\Actions\{StartSession,PauseSession,ResumeSession,CloseSession,CancelSession,DelaySession,ExtendSessionCapacity,ChangePoolSplit,ReleaseOnlineToCounter,CallNext,CallSerial,CompleteConsultation,…}` |
| Materialisation | `App\Domain\Scheduling\Services\SessionMaterialiser` (`ensureDay/ensure/materialiseRange/resync`); job `App\Domain\Scheduling\Jobs\MaterialiseTenantSessions`; commands `sessions:materialise`, `sessions:close-stale` |
| ETA | `App\Domain\Queue\Services\EtaCalculator`; scheduler command `queue:refresh-eta` (per minute via `tenants:run`) |
| Queue state | `App\Domain\Queue\Services\QueueStateBuilder::build()` + `QueueStateRepository::{rebuild,snapshot,version}`; listener `App\Domain\Queue\Listeners\InvalidateQueueState`; document type `QueueState` (`shared/realtime/types.ts`) |
| Channels | `App\Domain\Queue\TenantChannel::{queue,reception,doctor,display,prescription}()`; guards `App\Domain\Queue\ChannelGuards`, `App\Domain\Prescription\Services\PrescriptionChannelGuard`; names `tenant.{tenantPublicId}.queue.{sessionInstancePublicId}`, `….reception.{branchPublicId}`, `….doctor.{doctorPublicId}`, `….display.{branchPublicId}`, `….prescription.{prescriptionPublicId}` |
| Broadcast events | `App\Domain\Queue\Events\{SerialCalled,SerialCalledPrivate,SerialStatusChanged,QueueStateUpdated,SessionDelayed,SessionCancelled,DoctorArrived,BoardUpdated,CallNext,SerialApproaching}`; wire names `serial.called`, `serial.status_changed`, `queue.state`, `session.delayed`, `session.cancelled`, `doctor.arrived`, `board.updated`, `call.next`; `App\Domain\Prescription\Events\PdfReady` |
| Broadcast auth | `POST /broadcasting/auth` (`web`+`tenant`) and `POST /api/device/broadcasting/auth` (`auth:device`+`tenant`) |
| Offline / sync | `App\Domain\Reception\Actions\{RegisterReceptionDevice,LeaseBlock,ReleaseBlock,RevokeBlock,AllocateFromBlock}` (`handle()`), `App\Domain\Reception\Services\SyncReplayer::{replay,resolve}`, `App\Domain\Reception\Handlers\{RegisterPatient,IssueSerial,CheckIn,CollectCash,PrintToken,VoidLocal}Handler`, middleware `App\Domain\Reception\Http\Middleware\AuthenticateReceptionDevice`; device token abilities `reception:offline, reception:sync, reception:blocks, reception:read`; header `X-Actor-User` |
| Connection store | `resources/js/shared/connection/store.ts` → `useConnection` (`mode: online\|degraded\|offline`, `wsState`, `setBrowserOnline`, `setWsState`, `heartbeatResult`, `setTabVisible`, `setSyncFacts`), `constants.ts`, `heartbeat.ts`, `echoBridge.ts`, `ConnectionIndicator.tsx`; selectors `selectIsLive`, `selectIsPolling`, `selectCanUseServer` |
| Realtime client | `resources/js/shared/realtime/{echo.ts (createEcho), liveQueue.ts (subscribeQueue), types.ts (QueueState), useQueueState.ts, useChannel.ts}` |
| Offline client | `resources/js/shared/offline/{db.ts (ReceptionDB), eventLog.ts (append/flush bookkeeping), blocks.ts (BlockIssuer), sync.ts (flush)}`; `resources/js/shared/ulid.ts`; service worker `resources/js/panel/sw.ts`, registration `resources/js/panel/pwa.ts` |
| Prescription | `App\Domain\Prescription\Actions\{CreateDraftPrescription,SaveDraft,IssuePrescription,AmendPrescription,VoidPrescription}`, `Shorthand\ShorthandParser`, `Safety\SafetyPipeline` (+ `Enums\{SafetyStage,SafetySeverity}`), `Render\{PrescriptionRenderer,DrawingSvgRenderer,QrCodeRenderer}`, `Services\{DrugSearchService,SnapshotBuilder,PrescriptionAuditor}`, `Jobs\GeneratePrescriptionPdf`; templates `resources/views/print/prescription/**`; client `resources/js/panel/{Pages,Components}/Prescription/**`, `resources/js/panel/lib/prescription/{shorthand,store,types}/**` |
| Catalog | `App\Models\Catalog\CatalogModel` (not Searchable), `App\Domain\Catalog\Services\{CatalogWriteContext (isOpen/run), CatalogCache}`, `App\Domain\Catalog\Exceptions\CatalogIsReadOnly`, `App\Domain\Catalog\Rules\{CatalogIdExists,StrengthBelongsToBrand}`, `App\Domain\Catalog\Search\{CatalogSearchIndexer,MeilisearchIndexes,CustomBrandIndexSettings}`, `App\Domain\Catalog\Import\CatalogImporter`, `App\Domain\Catalog\Jobs\ReconcileCatalogReferences`; commands `catalog:migrate`, `catalog:seed`, `catalog:import`, `catalog:index-search`, `catalog:reconcile`, `tenants:sync-search-settings`, `tenants:reindex` |
| Meilisearch indexes | `catalog_drugs`, `catalog_icd10` (shared), `t{tenantId}_patients`, `t{tenantId}_custom_brands` (via `TenantModel::searchableAs()`); every uid is prefixed with `config('scout.prefix')` — empty at runtime, `test{N}_` in tests |
| Redis keys | tenant-scoped `t:{tenantId}:…` with the **bigint** tenant id: `t:{tenantId}:qs:{sessionInstancePublicId}` (+`:v`), `t:{tenantId}:cap:{sessionInstancePublicId}`, `t:{tenantId}:settings`, `t:{tenantId}:doctor:{doctorId}:{top50\|usage\|icd}`, `t:{tenantId}:doctor:{doctorId}:fav:{icd}`; platform `tenancy:host:{host}`, `otp:{tenantId}:{mobile}`, `catalog:{version}:…`, `catalog:current_version`, `catalog:exists:{entity}:{id}`; locks `qs-build:{sessionInstancePublicId}`, `sync:{tenantId}:{deviceId}` |
| Horizon queues | `critical, default, notifications, pdf, search, reports, backups` — nothing else (ARCHITECTURE §4.6) |
| Route prefixes | `panel.` (`/panel`, guard `web`), `site.` (`/`, public or `patient`), `api.` (`/api`, `sanctum`/`device`), `super.` (`super.{central}`), `central.` (bare central host); file `routes/<surface>/<module>.php` |
| Public queue URLs | `GET /q/{doctorSlug}/today` (`site.queue.page`), vanity `queue.{host}/{doctorSlug}/today` (`site.queue.vanity`), `GET /queue/{doctorSlug}/state` (`site.queue.state`, ETag = quoted integer version), `GET /queue/{doctorSlug}/sessions`, `GET /q/resolve/{localId}`, `GET /display/{branchSlug}` (`site.queue.display`) |
| Public Rx URLs | `GET /rx/{code}` (`site.prescription.verify`), `GET /drug/{slug}` (`site.prescription.drug`) in `routes/site/prescription.php` |
| Ids | bigint identity PKs; `public_id` = 26-char ULID via `App\Models\Concerns\HasPublicId`; `client_event_id` = device ULID; no UUIDs anywhere; sample ids in specs (`ser_01J…`) are abbreviations — real values are bare ULIDs |
| Tests | `Tests\TestCase` (+ `WithTenants`: `tenant_test_a`/`tenant_test_b`, ids 9001/9002), `tests/{Unit,Feature,Concurrency}/<Module>/`, `Tests\Support\ProcessPool`, `serials:hammer`, Vitest for TS |
