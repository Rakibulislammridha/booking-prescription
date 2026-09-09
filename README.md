# Booking to Prescription

Multi-tenant SaaS for the complete outpatient (OPD) journey of hospitals and clinics in Bangladesh:

**patient books → serial issued → reception checks in and collects the fee → vitals recorded → doctor writes the
prescription → printed / PDF / SMS delivered → follow-up auto-drafted.**

Two things carry it (docs/BRIEF.md §1): the **live queue** — a patient watches their serial move on a cheap Android
phone instead of sitting in a corridor — and the **prescription writer** — a doctor finishes a routine prescription
in under a minute, with Bangla typography that prints correctly on a preprinted pad. Around them: online and
counter booking with a serial engine proven under parallel load, an offline-capable reception PWA, a patient
mini-EMR, bKash/Nagad/SSLCommerz payments, SMS/WhatsApp/push notifications, a telemedicine add-on, reports, and a
SaaS control plane (plans, limits, subscriptions, custom domains, impersonation, encrypted per-tenant backups).

## Stack (locked — docs/BRIEF.md §2)

| Layer | Choice |
|---|---|
| Backend | PHP 8.4, Laravel 12, **Octane on FrankenPHP**, Horizon (7 queues), Reverb (WebSockets) with a polling fallback |
| Frontend | Inertia + React 19; MUI for the staff panel, Tailwind (preact-compat build, ≤ 95 KB gzip per route) for the public site; PWA for the reception desk (Dexie/IndexedDB, Workbox) |
| Data | PostgreSQL 16 — `booking` (schema-per-tenant: `public` + `tenant_<id>`) and `catalog` (shared DGDA drug/ICD reference, **SELECT-only at runtime**) |
| Search | Meilisearch (drug, ICD-10 and patient autocomplete) |
| Cache / queues / sessions | Redis protocol — Valkey in production, Dragonfly on the dev box |
| PDF | Browsershot + headless Chrome with Noto Sans/Serif Bengali. DomPDF is banned |
| Storage | S3-compatible (`uploads`, `pdfs`, `backups` disks); tenant dumps encrypted with libsodium before upload |
| Infra | VPS + Docker Compose (`compose.yaml`), Caddy edge with on-demand TLS, GitHub Actions |

## Local setup (about five minutes once the services are installed)

Generic path. (The dev box this was built on has two PHP installs and runs Dragonfly/Meilisearch from
`~/.local/bin` — see `scripts/dev-services.sh` and `docs/ARCHITECTURE.md` §0; that is a local quirk, not a
requirement.)

**Needs:** PHP 8.4 with `pdo_pgsql pgsql redis intl gd zip bcmath sodium pcntl posix mbstring opcache` (no imagick),
Composer 2, PostgreSQL 16, a Redis-compatible server (Redis 7 / Valkey 8 / Dragonfly), Meilisearch 1.x, Node 22+,
Google Chrome or Chromium, `pg_dump`/`pg_restore`/`psql` 16, `pdftotext` (poppler), and the Noto Sans Bengali +
Noto Serif Bengali fonts installed system-wide (`fonts-noto-core` on Debian/Ubuntu).

```bash
git clone <repo> booking-prescription && cd booking-prescription
composer install
npm ci                                   # PUPPETEER_SKIP_DOWNLOAD=1 npm ci if you have a system Chrome
cp .env.example .env && php artisan key:generate
# .env: DB_*/CATALOG_DB_* (create the two empty databases `booking` and `catalog` first), REDIS_*, MEILISEARCH_*,
#       CHROME_PATH, NODE_BINARY/NPM_BINARY (or `node`/`npm` for PATH)

php artisan migrate                      # central `public` schema
php artisan catalog:migrate --seed       # catalog database + the bundled development sample (via the real importer)
php artisan catalog:index-search --fresh # Meilisearch catalog indexes
php artisan db:seed                      # plans + super admin + the `demo` tenant with demo data (idempotent)

php artisan octane:start --server=frankenphp --host=127.0.0.1 --port=8000   # downloads the frankenphp binary on first run; add --watch only after `npm i -D chokidar`
php artisan horizon                      # queues
php artisan reverb:start --host=127.0.0.1 --port=8080                                # WebSockets
npm run dev                              # Vite (both surfaces from one dev server)
```

`*.localhost` resolves to 127.0.0.1 without touching `/etc/hosts`, so with `APP_CENTRAL_DOMAIN=bp.localhost`:

| URL | What |
|---|---|
| http://demo.bp.localhost:8000/panel | staff panel of the demo clinic |
| http://demo.bp.localhost:8000 | its public booking site; http://queue.demo.bp.localhost:8000/… the live queue |
| http://super.bp.localhost:8000 | platform console (Horizon at `/horizon`) |
| http://bp.localhost:8000 | central marketing / sign-up |

**Demo credentials** (seeders; development only):

| Account | Login | Password |
|---|---|---|
| Hospital admin, demo clinic | `admin@demo.test` | `password` |
| Doctor, demo clinic | `rahman@demo.test` | `password` |
| Super admin | `super@bp.localhost` | `password` — whether the console asks for a TOTP code is the `security.super_two_factor` platform setting (Platform settings tab; `required` / `optional` / `disabled`, SCHEMA §2.19). `SUPER_2FA_REQUIRED` in `.env` only seeds its default (`true` → required: first login holds you on the enrolment screen; scan the QR with any authenticator and keep the recovery codes). The local dev database ships with the policy `disabled` and the account un-enrolled, so the password alone signs in. If the account is enrolled, use that authenticator or clear `two_factor_*` on the row (docs/OPERATIONS.md §3.6) |

Same stack in containers: `docker compose -f compose.yaml -f compose.dev.yaml up -d` (image built from
`Dockerfile`; see docs/DEPLOYMENT.md §3).

## Tests and quality gates

Every engineer gets an isolated database pair (`booking_test_N` / `catalog_test_N`, N = 1–16), a Meilisearch
prefix and a Redis database (docs/CONVENTIONS.md §6):

```bash
scripts/test-agent.sh 1                                     # whole suite: Unit, Feature, Concurrency (real parallel processes)
scripts/test-agent.sh 1 --exclude-group=concurrency,search  # quick loop
scripts/test-agent.sh 1 --group=concurrency                 # serial engine, block leases, coupon/plan limits under load (~10 min)
npm run test                                                # Vitest (jsdom)
npm run typecheck                                           # tsc
vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G && php artisan lang:check
npm run build && scripts/check-site-deps.sh --require-build && scripts/check-panel-budget.sh --require-build
```

`.github/workflows/ci.yml` runs exactly that gate on every push and pull request (Postgres 16, Valkey and
Meilisearch as services; the concurrency group included). `.github/workflows/docker-image.yml` builds and pushes the
production image on `v*` tags.

## Documentation

| File | What it is |
|---|---|
| `docs/BRIEF.md` | the product brief: mission, locked decisions, modules, definition of done |
| `docs/ARCHITECTURE.md` | system shape, tenancy (schema-per-tenant), HTTP wiring, queues, scheduler, frontend, cross-cutting concerns |
| `docs/CONVENTIONS.md` | ownership map, migrations, PHP/JS conventions, test harness, definition of done, gates |
| `docs/SCHEMA.md` | every table and column, both databases |
| `docs/SERIAL_ENGINE.md` | the serial/queue-number engine and its invariants |
| `docs/REALTIME.md` | live queue, Reverb channels, polling fallback, rendering budgets |
| `docs/OFFLINE.md` | the reception PWA's offline model and replay |
| `docs/PRESCRIPTION.md` | the prescription writer, safety checks, rendering and PDF |
| `docs/CATALOG.md` | the drug/ICD catalog, read-only enforcement, Meilisearch indexes, DGDA import |
| `docs/modules/saas.md`, `docs/modules/telemedicine.md` | module notes where behaviour deviates from the docs above |
| `docs/DEPLOYMENT.md` | **production runbook**: image, compose, environment variables, DNS, TLS, Postgres roles, first boot, backups and restore drill, deploys, monitoring |
| `docs/OPERATIONS.md` | **day 2**: tenants, key rotation, stuck queues, re-indexing, the schedule and what a missed run costs, logs, known limitations |

## Layout

`app/Domain/<Module>` (actions, services, events), `app/Tenancy` (schema switching), `app/Http/Controllers/{Panel,Site,Api,Super,Central}`,
`resources/js/{panel,site,shared}`, `database/migrations/{,tenant,catalog}`, `routes/{panel,site,api,super,central}`,
`tests/{Unit,Feature,Concurrency}`, `docker/` + `compose*.yaml` + `Dockerfile` (production), `scripts/` (dev services, test isolation, budgets, deploy).
