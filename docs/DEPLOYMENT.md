# Deployment runbook

Production of Booking to Prescription on **one VPS with Docker Compose, S3-compatible object storage and per-tenant
daily database dumps** — the locked infrastructure decision (BRIEF §2). This is the operator's document: what to
provision, what every variable does, the order of first boot, how certificates, backups, deploys and supervision
work, and what to watch. Day-2 work (tenants, rotation, stuck queues, re-indexing) is in `OPERATIONS.md`.

**Honesty note.** The development box this was written on has no Docker and no root, so the container stack has
**not been booted here**. What *was* executed on this machine: every artisan command referenced below (`php artisan
list`, `help`), `composer validate` and `composer check-platform-reqs --no-dev`, `config:cache`/`event:cache` and
`route:cache` dry-runs into a scratch path, `php -l` on the TLS gate, `bash -n` on every script, a YAML parse of
every compose/workflow file, `docker/postgres/pg-roles.sql` run twice against the local PostgreSQL 16 with the
SELECT-only role proven, and the TLS gate served with PHP's built-in server against the local database. Every base
image tag and Debian/PGDG package named in the Dockerfile was checked to exist. The first `docker compose up` on a
real host is still the first time this stack runs — read §12 before trusting anything.

---

## 1. Topology

```
                     443/80 (TCP+UDP)
                            │
                     ┌──────▼──────┐   ask?domain=   ┌──────────┐
   internet ────────▶│    caddy    │────────────────▶│ tls-ask  │──▶ public.tenants / public.domains
                     │ on-demand   │                 └──────────┘
                     │ TLS + proxy │
                     └──┬──────┬───┘
       {central} · super. · {slug}. · custom domains      ws.{central}
                        │                                    │
                 ┌──────▼──────┐                      ┌──────▼──────┐
                 │     app     │ Octane/FrankenPHP    │   reverb    │ WebSockets
                 └──────┬──────┘ :8000                └─────────────┘ :8080
                        │
   ┌───────────┬────────┼────────────┬─────────────┐
   │ horizon   │ scheduler │  init   │  postgres 16 │ valkey 8 │ meilisearch │ minio (profile)
   │ 7 queues  │ schedule: │ one-shot│ booking +    │ cache/   │ catalog_* + │ uploads / pdfs /
   │           │ work      │ migrate │ catalog DBs  │ queue/   │ t{id}_*     │ backups buckets
   └───────────┴───────────┴─────────┴──────────────┴──────────┴─────────────┴─────────────────
```

Files: `Dockerfile` (one image), `docker/entrypoint.sh` (role dispatcher), `compose.yaml` (production topology),
`compose.dev.yaml` (local override), `docker/caddy/Caddyfile`, `docker/tls-ask/index.php`,
`docker/postgres/{pg-roles.sql,initdb/}`, `docker/.env.production.example`, `scripts/deploy/{deploy.sh,restore-drill.sh}`,
`.github/workflows/{ci.yml,docker-image.yml}`.

All six PHP processes (`app`, `horizon`, `scheduler`, `reverb`, `init`, `tls-ask`) run the **same image**; the
first argument to the entrypoint picks the role. There is exactly one `app` container in the default layout (Octane
runs N workers inside it — `OCTANE_WORKERS`); scaling out is a `--scale app=2` away but see §10.4 on logos.

---

## 2. Prerequisites

| Item | Requirement | Why |
|---|---|---|
| Host | x86-64 Linux VPS, 4 vCPU / 8 GB RAM / 80 GB SSD is comfortable for tens of clinics; 2 vCPU / 4 GB is the floor | Chromium renders every prescription PDF; Postgres and Meilisearch both want RAM |
| Docker | Docker Engine 24+ with Compose v2 (`docker compose version`) | `deploy.resources`, `service_completed_successfully`, `--wait` |
| Ports | 80/tcp, 443/tcp, 443/udp inbound. Nothing else. | Caddy; HTTP/3 on udp |
| DNS | control of the central domain's zone (§5) | wildcard record for tenant subdomains, TXT proofs for custom domains |
| Object storage | S3-compatible: the bundled MinIO (`COMPOSE_PROFILES=minio`) or an external bucket set (AWS S3, Wasabi, R2, Backblaze) | `uploads`, `pdfs`, `backups` disks (ARCHITECTURE §8.7) |
| Off-site copy | somewhere OUTSIDE the VPS for the `backups` bucket and the Postgres base backup (§9.5) | a VPS is one disk |
| SMTP | a transactional mail account | password resets, invoices, platform mail |
| Registry | GitHub Container Registry via `.github/workflows/docker-image.yml`, or build on the host (`deploy.sh --build`) | the image is ~1.5 GB with Chromium; build it once, pull it everywhere |
| Optional | SMS/WhatsApp gateway credentials are **tenant settings** (encrypted rows), not environment; payment gateways likewise, with an optional platform fallback (§4.9) | BRIEF §5.I/§5.J |

Time on the host must be correct (NTP): TOTP for the super console is a 30-second window (ARCHITECTURE §6.5).

---

## 3. The image

### 3.1 What is inside

Built from `Dockerfile` (multi-stage). Verified facts behind each choice:

* **Base** `dunglas/frankenphp:1.12.7-php8.4-bookworm` — the official FrankenPHP image (Octane's server here is
  FrankenPHP, `OCTANE_SERVER=frankenphp`; the dev box runs the same 1.12.7). PHP 8.4 (`composer.json` `^8.4`).
* **PHP extensions** — from `composer check-platform-reqs --no-dev` (ctype, curl, dom, fileinfo, filter, hash, iconv,
  json, libxml, mbstring, openssl, pcntl, pcre, posix, session, simplexml, tokenizer — all built into the base) plus
  what the code uses: `pdo_pgsql`/`pgsql` (both databases), `redis` (phpredis — `config/database.php` auto-detects
  it, predis is the fallback), `intl`, `gd`, `zip` (`tenants:export` uses `ZipArchive`), `bcmath`, `sodium`
  (`BackupCipher`; built in), `opcache`. **No imagick** — an audit confirmed nothing uses it. The build fails if any
  of these is missing from `php -m`.
* **Chromium** (`/usr/bin/chromium`, Debian package) for Browsershot (`App\Domain\Prescription\Render\PdfRenderer`
  already passes `--no-sandbox`, `--disable-dev-shm-usage`); **`fonts-noto-core`**, which ships
  `NotoSansBengali-{Regular,Bold}.ttf` and `NotoSerifBengali-{Regular,Bold}.ttf` (checked against the Debian file
  list); the build asserts both families are visible to `fc-list`. Inter is served from `public/fonts/inter` by the
  templates themselves.
* **PostgreSQL 16 client** from PGDG (`pg_dump`, `pg_restore`, `psql` — `TenantSchemaDumper` shells out to them;
  `pg_dump` must be ≥ the server major). **poppler-utils** (`pdftotext`, the local OCR path in `config/patients.php`).
* **Node 22 + npm** copied from the `node:22-bookworm-slim` stage, and the production `node_modules` (`npm prune
  --omit=dev`) because Browsershot runs `node vendor/spatie/browsershot/bin/browser.cjs`, which `require()`s
  `puppeteer` from the app's `node_modules`, and runs `npm root -g` to build `NODE_PATH`. `PUPPETEER_SKIP_DOWNLOAD=1`
  keeps puppeteer's own browser out of the image.
* **Assets are baked**: `npm run build` (the real script — the site pass, then the panel pass; vite.config.ts merges
  the manifests) runs in the `assets` stage and `public/build`, `public/sw.js`, `public/panel.webmanifest`,
  `public/workbox-*.js` are copied into the final image. Octane caches the Vite manifest in a static for the life of
  a worker, so assets must exist before the server starts and must never be mounted or refreshed underneath it —
  a new build is a new image.
* `composer install --no-dev` runs **inside the runtime image**, so platform requirements are checked against the
  real PHP. `bootstrap/cache/{packages,services}.php` are generated at build; `config`, `event` and `view` caches
  are generated at **container start** (they bake environment values).
* Non-root user `app` (uid 1000); Caddy's `/config` and `/data`, `storage/`, `bootstrap/cache` are owned by it.
  `public/storage → storage/app/public` is created with `ln` (what `storage:link` would do).
* Runtime defaults set as image ENV (overridable): `CHROME_PATH=/usr/bin/chromium`, `NODE_BINARY`/`NPM_BINARY`,
  `PATIENTS_PDFTOTEXT_BIN`, `LOG_CHANNEL=stderr`, `OCTANE_SERVER=frankenphp`,
  `OCTANE_STATE_FILE=/app/storage/framework/octane-server-state.json` (container-local, so two app containers never
  share a state file), `REVERB_SERVER_HOST=0.0.0.0`.

### 3.2 Building

* **CI**: pushing a tag `vX.Y.Z` runs `.github/workflows/docker-image.yml` → `ghcr.io/<owner>/<repo>:{X.Y.Z, X.Y, latest, sha-…}`.
  `workflow_dispatch` builds a `sha-` image from any branch for staging. Put the image name in `.env` as `BP_IMAGE`
  and the tag as `BP_TAG`. The registry package must be readable by the VPS (`docker login ghcr.io` with a PAT that
  has `read:packages`, or make the package public).
* **On the host**: `scripts/deploy/deploy.sh --build` (equivalent to `docker compose build --pull app`). Expect
  10–20 minutes the first time (Chromium, npm ci, two Vite passes) and a ~1.5 GB image.

### 3.3 Startup sequence (`docker/entrypoint.sh`)

Every long-running role: wait for its dependencies (`pg_isready` on both databases, a TCP probe on Valkey,
`/health` on Meilisearch where needed) → refuse to start without `APP_KEY` → `config:clear`, `config:cache`,
`event:cache`, `view:cache` → `exec` the process as PID 1.

The `init` role (one-shot service; `deploy.sh` runs it before touching `app`) does, under a host-wide `flock`:

1. `php artisan migrate --force` — central `public` schema (`public.migrations`).
2. `php artisan catalog:migrate` — `catalog` database on the **`catalog_admin`** connection (`catalog.public.migrations`).
3. `php artisan tenants:migrate --seed` — every servable tenant's schema (`tenant_<id>.migrations`) followed by
   `RolesAndPermissionsSeeder`, which upserts by natural key (CONVENTIONS §10: "runs on every deploy"). Selection is
   trial/active/past_due; a suspended tenant is skipped until `tenants:migrate --seed --tenant=<slug>` after
   reactivation — **`--seed` is not optional**, because a release adds *permissions* as well as tables and a
   permission a tenant has never heard of reads as "denied" with nothing in the log (OPERATIONS §2.2).
   The command continues past a failing tenant and exits non-zero at the end, which fails the deploy.
4. `php artisan tenants:sync-search-settings` — re-applies the `t{id}_patients` / `t{id}_custom_brands` index
   settings (idempotent PUT). `BP_INIT_SKIP_SEARCH=1` skips it (Meilisearch maintenance).

Re-running `init` is safe: each step records its work, and the lock serialises overlapping runs.

**`route:cache` is deliberately off** (`BP_ROUTE_CACHE=0`). `bootstrap/app.php` registers the central marketing
route files twice — once for `{central}` and once for `www.{central}` — under the same `name('central.')` prefix,
and Laravel refuses to serialise a route collection with duplicate names: on this box
`php artisan route:cache` fails with `Unable to prepare route [billing/invoice/{invoice}] for serialization. Another
route has already been assigned name [central.billing.invoice]`. Routes are compiled on boot instead (a few
milliseconds per worker start, not per request, under Octane). Fixing it is an application change (give the
`www.` group its own name prefix or fold the two hosts into one pattern) — not made here.

---

## 4. Environment variables

The canonical template is `docker/.env.production.example`; copy it to `.env` next to `compose.yaml`. **One file
feeds two readers**: Compose interpolates `${VAR}` in `compose.yaml` from it, and every app container loads it as
`env_file`. Laravel inside the container reads the process environment (no `.env` is shipped in the image), and
`config:cache` at start bakes it.

`[S]` = secret: keep it in a secret store / the `.env` with mode `0600`, never in the repo, never in a PR.
`(new)` = referenced by the code/config but absent from the repo's `.env.example` until this runbook; those are now
listed there too.

### 4.1 Compose-only

| Variable | Meaning |
|---|---|
| `BP_IMAGE`, `BP_TAG` | image reference for every app-image service |
| `COMPOSE_PROFILES` | `minio` to run the bundled object store; empty when using external S3 |
| `ACME_EMAIL` | Let's Encrypt account e-mail (Caddy) |
| `POSTGRES_PASSWORD` [S] | superuser of the bundled Postgres; used only by the initdb hook. The app never connects as it |

### 4.2 Application

| Variable | Meaning |
|---|---|
| `APP_NAME` | shown in the UI, prefixes Redis/Horizon keys (`bp_horizon:`) |
| `APP_ENV` | `production`. Anything but `local`/`testing` makes `tenants:backup` refuse to run unencrypted |
| `APP_KEY` [S] | 32 random bytes, `base64:` prefix. Encrypts every `encrypted` cast column (SCHEMA §5.5), sessions, signed URLs, telemedicine tokens. Losing it loses the encrypted columns |
| `APP_PREVIOUS_KEYS` (new) | comma list of retired `APP_KEY`s; decryption falls back to them during a rotation (OPERATIONS §3.1) |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://{central}` — used for absolute URLs, the `public` disk URL (logos), signed links |
| `APP_CENTRAL_DOMAIN` | the platform domain. Tenants are `{slug}.{central}`, the console `super.{central}`, Horizon `super.{central}/horizon`, WebSockets `ws.{central}` (§5) |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | `bn` / `en` |
| `TRUSTED_PROXIES` (new) | comma list of IPs/CIDRs whose `X-Forwarded-*` are believed, or `*`. Default none. With the compose stack: `172.28.0.0/16` (the fixed network subnet in `compose.yaml`). Required, otherwise every request looks like plain HTTP from the Caddy container's IP |
| `TRUSTED_PROXY_HOST_HEADER` (new) | `false`. The Host header selects the tenant; Caddy forwards the original Host, so `X-Forwarded-Host` must stay untrusted (`App\Http\Middleware\TrustProxies`) |
| `OCTANE_HTTPS` (new) | `true` behind TLS so generated URLs are https even if a header is missing |
| `OCTANE_WORKERS`, `OCTANE_MAX_REQUESTS`, `OCTANE_PORT`, `OCTANE_ADMIN_PORT`, `OCTANE_LOG_LEVEL` | entrypoint-only knobs (`auto`, 500, 8000, 2019, WARN) |
| `LOG_CHANNEL` | `stderr` in containers (`docker compose logs`). The `clinical` channel is always the daily file `storage/logs/clinical.log` on the `app-logs` volume (config/logging.php) |
| `LOG_LEVEL`, `LOG_DAILY_DAYS` (new) | `info`; retention of the daily files (90) |
| `SESSION_DRIVER`=`redis`, `SESSION_LIFETIME`, `SESSION_IDLE_TIMEOUT_MINUTES`, `SESSION_SECURE_COOKIE` (new) =`true`, `SESSION_ENCRYPT`, `SESSION_DOMAIN`=`null` | session store and cookie flags. The cookie name is per tenant host (`{TENANCY_SESSION_COOKIE_PREFIX}_{slug}_session`, default prefix `bp`) so `SESSION_DOMAIN` stays `null` |
| `SANCTUM_STATEFUL_DOMAINS` | `{central},*.{central}` — the reception PWA's same-origin cookie auth. Add custom domains that host the panel if any |
| `TENANCY_SESSION_COOKIE_PREFIX` (new) | cookie name prefix, default `bp` |

### 4.3 Databases (§7 for the roles)

| Variable | Meaning |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`=`booking`, `DB_USERNAME`, `DB_PASSWORD` [S], `DB_SSLMODE` (new) | the one application connection: owner of `booking` (public + every `tenant_<id>` schema); also the `pg_dump` identity for `tenants:backup` |
| `CATALOG_DB_HOST`, `CATALOG_DB_PORT`, `CATALOG_DB_DATABASE`=`catalog`, `CATALOG_DB_USERNAME`, `CATALOG_DB_PASSWORD` [S], `CATALOG_DB_SSLMODE` (new) | the **runtime** catalog connection — SELECT-only role (BRIEF §3) |
| `CATALOG_ADMIN_DB_USERNAME`, `CATALOG_ADMIN_DB_PASSWORD` [S] | the **write** role for `catalog:migrate`, `catalog:import`, custom-brand promotion (`catalog_admin` connection). Must be a different role from the runtime one |

### 4.4 Redis-compatible store

| Variable | Meaning |
|---|---|
| `REDIS_HOST`=`valkey`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_DB`=`0`, `REDIS_CACHE_DB` (new) =`1` | cache, sessions, queues, Horizon state, `QueueState` documents, host→tenant cache, scheduler `onOneServer` locks |
| `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER` | all `redis` |
| `REDIS_URL`, `REDIS_USERNAME`, `REDIS_PREFIX`, `REDIS_PERSISTENT`, `REDIS_CLIENT` (all new, optional) | connection URL form / ACL user / key prefix (default `<app-name>-database-`) / persistent connections / force `phpredis` or `predis` |
| `HORIZON_DOMAIN`, `HORIZON_PATH`, `HORIZON_PREFIX`, `HORIZON_NAME` (last three new) | dashboard host (default `super.{central}`), path (`horizon`), key prefix, instance name |

### 4.5 Search

| Variable | Meaning |
|---|---|
| `SCOUT_DRIVER`=`meilisearch`, `SCOUT_QUEUE`=`true`, `SCOUT_PREFIX`=`` (empty in every runtime env; only tests set it) | Scout for tenant models; catalog indexes are built directly by `catalog:index-search` |
| `MEILISEARCH_HOST`, `MEILISEARCH_KEY` [S] | the master key (`MEILI_MASTER_KEY` on the service is the same value) |

### 4.6 Realtime (Reverb)

| Variable | Meaning |
|---|---|
| `BROADCAST_CONNECTION`=`reverb` | |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` [S] | one app for all tenants; isolation is by channel authorisation (ARCHITECTURE §4.8). Generate fresh values; the ones in `.env.example` are dev values |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | what BROWSERS connect to — `ws.{central}`, `443`, `https`. Shared to the client at runtime via Inertia props (`HandleInertiaRequests` → `app.reverb`), so no rebuild is needed to change them; `VITE_REVERB_*` are only a build-time fallback |
| `REVERB_SERVER_HOST` (new), `REVERB_SERVER_PORT` (new) | where the reverb container listens (`0.0.0.0`, `8080`; Caddy proxies `ws.{central}` → `reverb:8080`) |
| `REVERB_APP_PING_INTERVAL`=30, `REVERB_APP_ACTIVITY_TIMEOUT`=30 | REALTIME §1 liveness |
| `REVERB_SCALING_ENABLED` (new) | `false` — one Reverb instance. Enabling it needs Redis pub/sub and more than one reverb container |
| `REVERB_APP_MAX_CONNECTIONS`, `REVERB_APP_MAX_MESSAGE_SIZE`, `REVERB_MAX_REQUEST_SIZE`, `REVERB_APP_RATE_LIMITING_*` (new) | Reverb limits; defaults are fine |

### 4.7 Object storage

| Variable | Meaning |
|---|---|
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` [S], `AWS_DEFAULT_REGION` | credentials for the `uploads`/`pdfs` disks; also MinIO's root user when the profile is on |
| `AWS_ENDPOINT` (new), `AWS_USE_PATH_STYLE_ENDPOINT`=`true` | S3-compatible endpoint (`http://minio:9000` or the provider's URL) |
| `AWS_BUCKET` | **the switch**: when non-empty, `uploads` and `pdfs` use S3; empty = local disk fallback (dev only) |
| `AWS_UPLOADS_BUCKET` (new) =`bp-uploads`, `AWS_PDFS_BUCKET` (new) =`bp-pdfs` | bucket names. PDFs carry a 90-day lifecycle (ARCHITECTURE §8.7; `minio-init` sets it on MinIO — set it yourself on an external bucket) |
| `AWS_BACKUPS_BUCKET` (new) =`bp-backups` | **the switch** for the `backups` disk; separate bucket, ideally a separate account with a write-only key |
| `AWS_BACKUPS_ACCESS_KEY_ID`, `AWS_BACKUPS_SECRET_ACCESS_KEY` [S] (new) | optional dedicated credentials for the backups bucket (fall back to the pair above) |
| `FILESYSTEM_DISK`=`local` | the framework default disk; tenant paths always go through named disks + `TenantPath` |

### 4.8 Backups, super console, push, mail

| Variable | Meaning |
|---|---|
| `BP_BACKUP_KEY` [S] | base64 of 32 raw bytes. XChaCha20-Poly1305 key for every `tenants:backup` object (`BackupCipher`). Platform-wide. Empty in production = backups fail with `status = failed` and the reason recorded on the `tenant_backups` row. **Losing it makes every dump written with it unreadable; there is no recovery path by design.** Store it beside `APP_KEY` and off the VPS |
| `SUPER_2FA_REQUIRED` | `true` (default). Seeds the DEFAULT of the console's `security.super_two_factor` platform setting (SCHEMA §2.19): `true` → `required` (TOTP enrolment forced before anything else is reachable), `false` → `optional` (enrolment possible, not compulsory). Once an operator has set the policy under Platform settings in the console — including `disabled` — the stored row wins and this variable is ignored; it never needs a restart to change (ARCHITECTURE §6.5, config/saas.php) |
| `SUPER_2FA_ISSUER` | name in the authenticator app; empty = `APP_NAME` |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` [S], `VAPID_SUBJECT` | one P-256 pair per deployment (`npx web-push generate-vapid-keys`). Empty = the push channel logs instead of sending. Rotating the pair invalidates every browser subscription (OPERATIONS §3.5) |
| `MAIL_MAILER`=`smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` [S], `MAIL_SCHEME`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | transactional mail |

### 4.9 Optional integrations (unset = off, safely)

| Variable | Meaning |
|---|---|
| `BILLING_GATEWAY_DRIVER` | **never set in production** (`log` fakes every gateway) |
| `BKASH_APP_KEY`, `BKASH_APP_SECRET` [S], `BKASH_USERNAME`, `BKASH_PASSWORD` [S], `BKASH_MODE` (`live` or sandbox), `BKASH_BASE_URL` | bKash platform fallback. Clinics with their own merchant account configure the panel setting rows instead (encrypted) |
| `NAGAD_MERCHANT_ID`, `NAGAD_MERCHANT_NUMBER`, `NAGAD_PUBLIC_KEY`, `NAGAD_PRIVATE_KEY` [S], `NAGAD_MODE`, `NAGAD_BASE_URL` | Nagad fallback |
| `SSLCOMMERZ_STORE_ID`, `SSLCOMMERZ_STORE_PASSWORD` [S], `SSLCOMMERZ_MODE`, `SSLCOMMERZ_BASE_URL` | SSLCommerz fallback |
| `TELEMEDICINE_PROVIDER` (`livekit`/`jitsi`/`agora`/unset), `TELEMEDICINE_LIVEKIT_URL`, `TELEMEDICINE_LIVEKIT_KEY`, `TELEMEDICINE_LIVEKIT_SECRET` [S], `TELEMEDICINE_JITSI_DOMAIN`, `TELEMEDICINE_JITSI_APP_ID`, `TELEMEDICINE_JITSI_APP_SECRET` [S], `TELEMEDICINE_AGORA_APP_ID`, `TELEMEDICINE_AGORA_APP_CERTIFICATE` [S], `TELEMEDICINE_AGORA_CUSTOMER_ID`, `TELEMEDICINE_AGORA_CUSTOMER_SECRET` [S], `TELEMEDICINE_AGORA_REST_BASE`, `TELEMEDICINE_TOKEN_TTL`, `TELEMEDICINE_MAX_MINUTES`, `TELEMEDICINE_RECORDING` | video add-on (docs/modules/telemedicine.md). Unset = the null/log driver mints real tokens and contacts nothing |
| `PATIENTS_OCR_DRIVER` (`google`/unset), `PATIENTS_OCR_KEY` [S], `PATIENTS_OCR_ENDPOINT`, `PATIENTS_OCR_TIMEOUT`, `PATIENTS_PDFTOTEXT_BIN`, `PATIENTS_PDFTOTEXT_TIMEOUT` | cloud OCR for photographed reports — a data-sharing decision; local `pdftotext` is always used for text-layer PDFs |
| `NOTIFICATIONS_FORCE_LOG_DRIVER` (new) | forces every channel to the log driver — local only |
| `PRESCRIPTION_CHROME_PATH`, `PRESCRIPTION_PDF_ON_ISSUE`, `PRESCRIPTION_CACHE_STORE` (new) | a different Chrome for prescriptions only; queue the PDF on issue (`true`); a separate cache store for per-doctor learning caches |
| `CHROME_PATH`, `NODE_BINARY`, `NPM_BINARY` | Browsershot binaries — set by the image; the dev-box values in `.env.example` are that box's paths |
| `OTP_FIXED_CODE` (new) | honoured in local/testing only; **must be unset in production** |
| `PENNANT_STORE`, `SANCTUM_TOKEN_PREFIX`, `QUEUE_FAILED_DRIVER`, `CACHE_PREFIX`, `APP_MAINTENANCE_STORE`, `INERTIA_*`, `LOG_STACK`, `LOG_SLACK_WEBHOOK_URL`, `POSTMARK_API_KEY`, `RESEND_API_KEY`, `SLACK_BOT_USER_OAUTH_TOKEN` (new, optional) | framework/package knobs present in `config/*`; defaults are correct for this stack |

---

## 5. DNS

With `APP_CENTRAL_DOMAIN=example.com` and the VPS at `203.0.113.10`:

| Record | Type | Value | Serves |
|---|---|---|---|
| `example.com` | A (+AAAA) | 203.0.113.10 | central marketing site, sign-up, invoice pay pages |
| `www.example.com` | A or CNAME → `example.com` | | same |
| `super.example.com` | A / CNAME | | super console + Horizon (`/horizon`) |
| `ws.example.com` | A / CNAME | | Reverb WebSockets (`REVERB_HOST`) |
| `*.example.com` | A (+AAAA) | 203.0.113.10 | every tenant `{slug}.example.com` **and** the vanity prefixes `queue.{slug}.`, `book.{slug}.`, `display.{slug}.` (`config/tenancy.php` `service_prefixes`). A single-level wildcard covers `demo.example.com`; `queue.demo.example.com` needs the wildcard to match two labels, which DNS wildcards do (`*.example.com` matches `queue.demo.example.com`) |

**Custom domains** (Pro plan+, docs/modules/saas.md §5): the clinic points `booking.hospital.com` at the VPS —
a `CNAME` to `example.com` for a subdomain, an `A` record for an apex (an apex cannot carry a CNAME) — and proves
ownership with a TXT record `bp-verify={token}` at `_bp-verify.booking.hospital.com` (the bare host is accepted
as a fallback). The token comes from the super console (tenant → Domains → add) or `--domain=` on
`tenants:create`. Verification runs hourly (`saas:verify-domains`) or on demand from the console; only a
`verified` row routes, and the sweep never demotes a live domain. `queue.hospital.com` resolves through the base
domain, so the clinic registers `hospital.com`, not the prefix.

**Cloudflare or another CDN in front:** run it in DNS-only mode (grey cloud) for `*.example.com` and custom
domains — proxied mode would terminate TLS at the CDN, and on-demand issuance (§6) needs the real client to reach
Caddy. If you must proxy, add the CDN's ranges to `TRUSTED_PROXIES` and to Caddy's `trusted_proxies`.

---

## 6. TLS (Caddy)

### 6.1 How it works

`docker/caddy/Caddyfile`. Named hosts (`example.com`, `www.`, `super.`, `ws.`) get ordinary Let's Encrypt
certificates (HTTP-01/TLS-ALPN-01, automatic renewal). **Everything else** — tenant subdomains, vanity prefixes,
custom domains — is served by the catch-all `https://` block with `tls { on_demand }`: the certificate is issued
during the first TLS handshake for that hostname, then cached in the `caddy-data` volume and renewed like any
other. Issuance is gated by the global `on_demand_tls { ask http://tls-ask:9100/ask }`.

### 6.2 The `ask` gate (`docker/tls-ask/index.php`)

Caddy calls `GET /ask?domain=<name>` before issuing; `200` allows, anything else refuses. The gate mirrors
`App\Tenancy\TenantResolver`: normalise (lowercase, strip port, syntactic hostname check), allow the four platform
hosts, strip one leading `queue.`/`book.`/`display.` label, then either `{slug}.{central}` → `public.tenants.slug`
(not soft-deleted, status ≠ cancelled — a *suspended* tenant still gets a certificate so its 402 page is served
over TLS) or otherwise `public.domains.domain` with `verification_status = 'verified'` joined to a live tenant.
It runs from the same image (`entrypoint.sh tls-ask`, PHP's built-in server, internal network only), read-only,
with the app's `DB_*` credentials. Validated on the dev box against the local database: `demo.<central>` → 200,
`queue.demo.<central>` → 200, `nope.<central>` → 403, `evil.example` → 403, `www.<central>` → 200.

Consequences: no wildcard certificate, no DNS API credentials on the VPS, and a hostname nobody registered never
costs an ACME issuance. Let's Encrypt's per-registered-domain limit is 50 new certificates per week — onboarding
more than ~45 clinics in one week trips it (Caddy falls back to ZeroSSL automatically, and retries); if that is
your launch shape use §6.3 instead.

### 6.3 Alternative: wildcard via DNS-01

One `*.example.com` certificate needs the DNS-01 challenge and therefore a Caddy build with your DNS provider's
module: `FROM caddy:2.10-builder AS b; RUN xcaddy build --with github.com/caddy-dns/<provider>` (Cloudflare, Route53,
DigitalOcean, … are all published), then a `*.{$APP_CENTRAL_DOMAIN}` site block with `tls { dns <provider> {env.TOKEN} }`.
Keep the on-demand catch-all for custom domains. This is the only change; the app is unaffected.

### 6.4 Behind the proxy

Caddy forwards the original `Host` (tenant resolution) and sets `X-Forwarded-For/Proto`. The app trusts those
only from `TRUSTED_PROXIES` (`172.28.0.0/16`, the compose network). `OCTANE_HTTPS=true` and
`SESSION_SECURE_COOKIE=true` complete the picture. The app port 8000 is never published.

---

## 7. PostgreSQL roles — `catalog` is SELECT-only at runtime

BRIEF §3: "the `catalog` connection must be granted `SELECT` only for the application runtime user. Writes to
`catalog` happen through a separate admin-only role." `config/database.php` has three connections: `pgsql`
(`DB_*`), `catalog` (`CATALOG_DB_*`, runtime) and `catalog_admin` (`CATALOG_ADMIN_DB_*`, falling back to the
runtime credentials when unset — **do not leave it unset in production**). Code-level enforcement
(`CatalogModel`, the `NoCatalogQueryBuilderWrites` PHPStan rule) exists because the dev box cannot enforce it;
production enforces it in the database as well.

`docker/postgres/pg-roles.sql` creates `bp_app` (owner of `booking`), `bp_catalog_admin` (owner of `catalog`) and
`bp_catalog_ro` (CONNECT + USAGE + SELECT on all tables and sequences, plus `ALTER DEFAULT PRIVILEGES FOR ROLE
bp_catalog_admin` so tables created by future `catalog:migrate` runs are readable without another GRANT). It is
idempotent and re-applies passwords, so it doubles as the rotation tool.

* **Bundled Postgres**: it runs automatically on the first start of an empty volume
  (`docker/postgres/initdb/01-roles-and-databases.sh`, fed by the `DB_*`/`CATALOG_*` values in `.env`). The hook
  refuses to run if the runtime and admin catalog roles are the same name or any of them equals the superuser.
* **External / managed Postgres**: run it once by hand —
  `psql -v ON_ERROR_STOP=1 -v booking_db=booking -v app_user=bp_app -v app_pass='…' -v catalog_db=catalog -v catalog_admin=bp_catalog_admin -v catalog_admin_pass='…' -v catalog_ro=bp_catalog_ro -v catalog_ro_pass='…' -h <host> -U <superuser> -d postgres -f docker/postgres/pg-roles.sql`
  (needs CREATEROLE + CREATEDB). Managed services that forbid `CREATE DATABASE` from SQL: create the two databases
  in their console with the owners above, then run the file — the guarded CREATEs are skipped.

Verify (done on the dev box's PostgreSQL 16.15 with throw-away names, twice for idempotency):

```
PGPASSWORD=… psql -h <host> -U bp_catalog_ro -d catalog -c 'select count(*) from generics'     # works
PGPASSWORD=… psql -h <host> -U bp_catalog_ro -d catalog -c "insert into generics(name) values('x')"  # ERROR: permission denied for table generics
PGPASSWORD=… psql -h <host> -U bp_catalog_ro -d catalog -c 'create table t(x int)'             # ERROR: permission denied for schema public
PGPASSWORD=… psql -h <host> -U bp_catalog_ro -d booking -c 'select 1'                          # FATAL: permission denied for database "booking"
```

Collation: the bundled image initialises with `en_US.utf8` on glibc. Restore dumps onto the same libc family
(Debian/Ubuntu images, not Alpine) — a collation change silently reorders indexes.

---

## 8. First boot

```bash
# 0. On the VPS
git clone <repo> /srv/bp && cd /srv/bp                  # or copy compose.yaml, docker/, scripts/deploy/, .env
cp docker/.env.production.example .env && chmod 600 .env
$EDITOR .env                                             # §4: every [S] value, APP_CENTRAL_DOMAIN, APP_URL, BP_IMAGE/BP_TAG, ACME_EMAIL
docker login ghcr.io                                     # unless the package is public or you --build

# 1. Data services, then the one-shot init (roles/DBs are created by the postgres initdb hook on this first start)
docker compose up -d --wait postgres valkey meilisearch
docker compose up -d --wait minio && docker compose run --rm minio-init     # only with COMPOSE_PROFILES=minio
docker compose run --rm init                             # migrate, catalog:migrate, tenants:migrate --seed (no tenants yet), search settings

# 2. Central seed: plans (needed before any tenant can be created)
docker compose run --rm --no-deps app artisan db:seed --class='Database\Seeders\Central\PlansSeeder' --force

# 3. The first super admin. There is NO dedicated artisan command for this; the seeder creates
#    super@bp.localhost / password, which is a dev credential. Create a real one through tinker (the `hashed`
#    cast hashes the password):
docker compose run --rm --no-deps app artisan tinker --execute="App\Models\Central\SuperAdmin::query()->create(['name' => 'Ops', 'email' => 'ops@example.com', 'password' => 'CHANGE-ME-NOW', 'is_active' => true, 'email_verified_at' => now()]);"

# 4. Catalog. Real data arrives through the DGDA importer (CATALOG.md §5); the bundled sample is for development.
docker compose run --rm --no-deps app artisan catalog:import /path/inside/container --source=dgda --full --catalog-version=2026.09 --reindex
#    …or, for a demo/staging box:
docker compose run --rm --no-deps app artisan catalog:seed
docker compose run --rm --no-deps app artisan catalog:index-search --fresh              # builds catalog_drugs + catalog_icd10 (zero-downtime swap)
#    (mount the DGDA files with `docker compose run --rm --no-deps -v /srv/dgda:/import app artisan catalog:import /import …`)

# 5. Everything else (app, horizon, scheduler, reverb, tls-ask, caddy)
docker compose up -d --wait
docker compose ps                                        # every service "healthy"; init "exited (0)"

# 6. First tenant
docker compose exec app php artisan tenants:create "Demo Hospital" --slug=demo --plan=pro \
    --admin-email=admin@demo.example.com --admin-password='…' --owner-name='…' --owner-mobile='+8801…'
#    prints the id, schema and panel URL; `--demo` adds doctors/schedules/patients; `--domain=` adds a custom domain (pending)
```

Then in a browser: `https://super.example.com/login` → the console holds you on the TOTP enrolment screen until
you scan the QR and confirm a code (keep the eight recovery codes it shows once). `https://super.example.com/horizon`
shows the three supervisors. `https://demo.example.com/panel` is the clinic; `https://demo.example.com` the booking
site; `https://queue.demo.example.com/...` the public queue. Certificates for the tenant hosts appear on first
visit (§6) — the first request on a new hostname takes a few seconds longer.

Self-service sign-up (`https://example.com/signup`, `routes/central/onboarding.php`) provisions tenants through the
same `ProvisionTenant` action.

---

## 9. Backups

### 9.1 What `tenants:backup` does (ARCHITECTURE §8.8; `App\Domain\SaaS\Actions\Tenants\BackupTenant`)

Per tenant: write a `public.tenant_backups` row (`status = running` first — a crash still leaves evidence) →
resolve the encryption mode (no `BP_BACKUP_KEY` in production = fail here, before the dump) → `pg_dump
--format=custom --schema=tenant_<id> --no-owner --no-acl` as `DB_USERNAME` (password via `PGPASSWORD`, never argv)
to a temp file → stream through `BackupCipher` (libsodium `crypto_secretstream_xchacha20poly1305`, `BPBACKUP`
magic + version + 24-byte header + 1 MiB chunks, FINAL tag) → `writeStream` to the `backups` disk under
`tenants/<id>/….dump.enc` → record `storage_path`, `encryption`, `size_bytes` (object), `checksum_sha256`
(plaintext archive), `expires_at` (30 days for daily), `status = completed` → delete local copies.

Scheduled: `tenants:backup --prune` daily at **01:00 Asia/Dhaka** (`app/Domain/SaaS/Schedule.php`, `onOneServer`,
`withoutOverlapping`); `--prune` deletes completed daily objects past `expires_at` and their rows. Manual:
`tenants:backup <slug> --manual` (kept until a super admin removes it), or the super console → tenant → Backups.

### 9.2 What it does NOT cover — you need a second backup

`tenants:backup` dumps **tenant schemas only**. The central `public` schema (tenants, plans, subscriptions,
invoices, domains, super admins, the `tenant_backups` registry itself, central audit log), the **`catalog`**
database, Valkey (queued jobs — transient) and Meilisearch (rebuildable, §OPERATIONS 5) are outside it. Take a
whole-cluster backup as well, from the host:

```bash
docker compose exec -T postgres pg_dumpall -U postgres --clean --if-exists | gzip > /backup/pg-$(date +%F).sql.gz
```

nightly (cron on the host), encrypt it with the same discipline (`age`/`gpg`, or store it in an encrypted
off-site bucket), and copy the `backups` bucket off the VPS (`mc mirror`/`rclone sync` to another provider).
Restore of the cluster: `gunzip -c pg-….sql.gz | docker compose exec -T postgres psql -U postgres -d postgres`
on a stopped app.

### 9.3 Restore drill (`tenants:restore`, `scripts/deploy/restore-drill.sh`)

`tenants:restore <tenant_backups.id> --force` streams the object back, decrypts it if its magic says so (plaintext
dumps from before encryption existed still restore), verifies the plaintext SHA-256, rebuilds the archive into a
scratch schema `tenant_<id>_restore` (via `pg_restore --file=- | rename schema | psql --single-transaction`, because
`pg_restore --schema` is a filter, not a rename), checks it has tables, and swaps the two schemas in one
transaction. The displaced live schema stays as `tenant_<id>_replaced_<ts>` until a human drops it. The live schema
is never the restore target, so a corrupt archive cannot destroy a clinic. Also available from the super console
(tenant → Backups → Restore). Every restore is a `restore` row in `public.audit_logs_central`.

Drill monthly, on a **staging stack** (same image, a copy of the bucket, the same `BP_BACKUP_KEY`) or on
production for a tenant you are prepared to swap (the `demo` tenant):

```bash
scripts/deploy/restore-drill.sh demo            # lists completed backups
scripts/deploy/restore-drill.sh demo 1234       # row counts before, tenants:restore 1234 --force, row counts after, displaced schema name
docker compose exec postgres psql -U postgres -d booking -c 'drop schema "tenant_1_replaced_20260909T010203" cascade'
```

The drill also proves the key: a restore with the wrong `BP_BACKUP_KEY` fails on the first chunk.

### 9.4 The key

`BP_BACKUP_KEY` is platform-wide, lives beside `APP_KEY`, and must exist somewhere that is not this VPS. Rotation
semantics are in `config/saas.php` and OPERATIONS §3.2: objects keep the key they were written with; rotate by
setting the new key and re-dumping; keep the retired key readable until every object written with it is past its
30-day retention.

### 9.5 Off-site

The VPS is one disk. Mirror `bp-backups` and the nightly `pg_dumpall` to a second provider/region daily; test a
restore from the mirror, not from the primary, at least once a quarter.

---

## 10. Supervision and deploys

### 10.1 Process supervision

Compose is the supervisor: `restart: unless-stopped` on every long-running service, healthchecks on all of them
(`/up` for app, `horizon:status` for Horizon — exit 0 running, 1 paused, 2 inactive —, Reverb's own `/up`,
`pgrep schedule:work`, `pg_isready`, `valkey-cli ping`, Meilisearch `/health`, MinIO `/minio/health/live`, Caddy's
admin API), memory/CPU limits (`deploy.resources`), `shm_size: 512m` where Chromium runs (app, horizon), and
`stop_grace_period` sized to each role (Horizon 180 s: the pdf supervisor's timeout is 150 s and a `pg_dump` on the
`backups` queue can take minutes). Docker restarts a container whose process dies; it does **not** restart one that
is merely unhealthy — that is what §11's alerting is for.

Horizon runs the three supervisors of `config/horizon.php` in one container: `supervisor-critical` (queue
`critical`), `supervisor-pdf` (`pdf`), `supervisor-default` (`default`, `notifications`, `search`, `reports`,
`backups`) with the `production` environment's process counts (4 / 2 / 6). Reverb is a single instance
(`REVERB_SCALING_ENABLED=false`). The scheduler is one `schedule:work` container; every entry that must not run
twice carries `onOneServer()`, which locks in Valkey, so a second scheduler container is harmless but pointless.

### 10.2 Releasing (`scripts/deploy/deploy.sh`)

```bash
cd /srv/bp && git pull                    # compose.yaml / docker/ / scripts/ may have changed with the release
BP_TAG=1.4.0 scripts/deploy/deploy.sh     # or edit BP_TAG in .env; `--build` to build here instead of pulling
```

1. pull (or build) the image;
2. `docker compose run --rm init` — migrations for public, catalog and every tenant, roles seeder, search
   settings (§3.3; idempotent, locked);
3. **app, rolling**: start a second `app` container from the new image (`--scale app=2 --no-recreate`), wait for
   its healthcheck, stop and remove the old one, scale back to 1. Caddy dials `app:8000` through Docker DNS on
   every request and holds requests for up to 20 s (`lb_try_duration`), so the switch is invisible to browsers.
   `--simple` does a plain recreate (a few seconds of held requests) if the rolling path misbehaves;
4. horizon, scheduler, reverb, tls-ask: `up -d` with the new image. Horizon receives SIGTERM, finishes running
   jobs, exits, and comes back on the new code — the equivalent of `horizon:terminate` + restart. WebSocket clients
   reconnect (the polling fallback covers the gap, REALTIME §6);
5. `octane:status`, `horizon:status`, `docker compose ps`, and a `GET https://{central}/up` through Caddy.

Env-only changes (no new image) still need a container restart because `config:cache` runs at start:
`docker compose up -d --no-deps app horizon scheduler reverb` (Horizon graceful as above). `docker compose exec app
php artisan octane:reload` restarts the workers inside the running container — useful after editing something under
a mounted volume, not for a new image, and it does **not** re-read `.env` (the config cache is on disk).

Migrations that must not run while the old code is live: there is no maintenance mode in this flow. For a breaking
schema change, `docker compose exec app php artisan down --secret=<token>` before `init`, `up` after; the
`APP_MAINTENANCE_STORE` is Redis so every container sees it.

### 10.3 Rollback

`BP_TAG=<previous> scripts/deploy/deploy.sh` — migrations are forward-only; roll back code, not schema, unless the
release notes say otherwise. `tenants:rollback --step=1` and `migrate:rollback` exist for the rare case and are
manual, per-schema decisions.

### 10.4 Scaling notes (single node)

Clinic logos are written to the `public` disk (`storage/app/public`, `App\Domain\Clinic\Services\ClinicUploads::brandingLogo`)
and served at `APP_URL/storage/…` by FrankenPHP — a **local** path, mounted as the `app-public` named volume. Two
app containers on one host share the volume; a second host would not. Moving logos to the `uploads` (S3) disk is an
application change. Everything else tenant-related already goes to S3 (`uploads`, `pdfs`, `backups`).

---

## 11. What to monitor

| Signal | Where | Threshold / action |
|---|---|---|
| App up | `GET https://{central}/up` (through Caddy) and `docker compose ps` health | 200; any container `unhealthy` for > 2 min pages someone |
| Horizon | `super.{central}/horizon`, `horizon:status` (0/1/2), Horizon's `LongWaitDetected` at 60 s on `redis:default` (`config/horizon.php` `waits`) | failed jobs list non-empty on `critical`/`backups`; wait time > 60 s |
| Tenant backups | `select tenant_id, status, error, completed_at from public.tenant_backups where started_at > now() - interval '1 day'` | every servable tenant has a `completed` row each morning; any `failed` (most often: empty `BP_BACKUP_KEY`, bucket credentials, disk full) |
| Tenancy leaks | log lines `tenancy.leak` / `tenancy.leaked_transaction` at **critical** (`ResetTenancy`, `AssertNoTenancy`) | must be zero — a leak means one request could see another clinic's schema (ARCHITECTURE §4.5) |
| Queue state / realtime | Reverb `/up` via `https://ws.{central}/up`; connected clients on the reception board's connection indicator | REALTIME §6 polling covers a Reverb outage but ETAs and "call next" lag |
| Catalog reconciliation | `public.catalog_reconciliation_reports` (nightly 02:00) | orphans are reported, never auto-fixed (BRIEF §3.3) |
| Valkey memory | `docker compose exec valkey valkey-cli info memory` vs `--maxmemory 384mb` (`noeviction`) | writes fail with OOM before anything is silently evicted — raise the limit |
| Postgres | disk, `log_min_duration_statement=500` lines in `docker compose logs postgres`, connection count vs `max_connections=200` (Octane workers + Horizon processes each hold one) | |
| Meilisearch | `/health`, disk under `meilisearch-data` | rebuild with OPERATIONS §5 if corrupt |
| Certificates | `docker compose logs caddy` for `obtain`/`renew` errors, rate-limit messages | ACME rate limits when many clinics onboard (§6.2) |
| Disk | `/var/lib/docker/volumes` (postgres-data, meilisearch-data, minio-data, app-logs) | |
| Clinical log | `storage/logs/clinical.log` on the `app-logs` volume (no PII by contract) | rotate: `LOG_DAILY_DAYS` |
| Scheduler | `docker compose logs scheduler` shows one `Running scheduled command` block per minute | silence = the container is dead or Valkey is unreachable |

Ship container stdout/stderr (JSON, rotated 5×20 MB by the compose logging options) to whatever you already use
(Loki, Vector, CloudWatch agent); nothing in the app assumes a particular collector.

---

## 12. Validation status of this stack

Executed on the dev box (no Docker, no root, PHP 8.4.1 CLI, PostgreSQL 16.15, Meilisearch 1.53.1, FrankenPHP 1.12.7):

* `composer validate` — valid; `composer check-platform-reqs --no-dev` — the extension list in §3.1.
* `php artisan list` / `help` for every command used by the entrypoint, scripts and this document; `octane:start`'s
  option set (which is why the entrypoint passes `--admin-port` but no `--admin-host`).
* `config:cache`, `event:cache` into a scratch path: OK. `route:cache`: **fails** (§3.3).
* `docker/postgres/pg-roles.sql`: run twice, SELECT-only role proven (§7), cleaned up.
* `docker/tls-ask/index.php`: `php -l`, then served with `php -S` against the local `booking` database and probed
  with curl (§6.2 results).
* `bash -n` on `docker/entrypoint.sh`, `docker/postgres/initdb/01-roles-and-databases.sh`, `scripts/deploy/*.sh`;
  `python3 -c 'import yaml…'` on `compose.yaml`, `compose.dev.yaml` and both workflows.
* Existence of every file the Dockerfile/compose reference; Docker Hub tags of every base image; Debian bookworm
  package pages for `chromium`, `poppler-utils`; the `fonts-noto-core` file list for the two Bengali families;
  the Meilisearch image's Dockerfile for `curl` (its healthcheck); the PGDG bookworm repository.
* `shellcheck` and `hadolint` are not installed here — not run.

Not executed anywhere yet: `docker build`, `docker compose up`, the GitHub workflows, `deploy.sh`, `restore-drill.sh`,
Caddy on-demand issuance. Treat the first run as a rehearsal on a staging VPS.
