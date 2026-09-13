# Operations (day 2)

Everything after `DEPLOYMENT.md`. Commands are shown for the compose stack (`docker compose exec app php artisan …`);
on a non-Docker host they are the same `php artisan` commands. `super.{central}` is the platform console; most
tenant operations exist there as buttons **and** as artisan commands, and both write `public.audit_logs_central`.

Nothing here was executed inside a container on the dev box (no Docker there). Every artisan command and its
options were checked against `php artisan help`; the SQL was run against the local PostgreSQL 16.

---

## 1. Routine

| When | Do |
|---|---|
| Every morning | `select tenant_id, status, error from public.tenant_backups where started_at > now() - interval '1 day' and status <> 'completed'` — must be empty; Horizon dashboard: failed jobs on `critical`/`backups`/`notifications`; `docker compose ps` all healthy |
| Weekly | disk on the Docker volumes; `docker compose logs caddy | grep -i -E 'error|rate'`; Let's Encrypt renewals are automatic but a stuck one shows here |
| Monthly | restore drill (`scripts/deploy/restore-drill.sh`, DEPLOYMENT §9.3); confirm the off-site mirror of `bp-backups` and the nightly `pg_dumpall` actually contain yesterday |
| Per release | `scripts/deploy/deploy.sh` (DEPLOYMENT §10.2) |
| Per release that touches the service worker | reception tablets do not update themselves the moment the server does — §10 |
| After a DGDA bulletin | §5.1 catalog import |

---

## 2. Tenants

### 2.1 Add a clinic

Console: `super.{central}` → Tenants → Create (or the public sign-up at `https://{central}/signup`). CLI:

```bash
docker compose exec app php artisan tenants:create "Nurjahan Clinic" --slug=nurjahan --plan=basic \
    --admin-email=admin@nurjahan.example --admin-password='…' --owner-name='…' --owner-mobile='+8801…' \
    [--domain=booking.nurjahan.com] [--demo]
```

`ProvisionTenant` (ARCHITECTURE §4.4) inserts the `public.tenants` row (status `trial`), the `{slug}.{central}`
domain (verified), the plan subscription, `CREATE SCHEMA tenant_<id>`, runs the tenant migrations and
`RolesAndPermissionsSeeder`, creates the first branch and the Hospital Admin user, and creates the
`t{id}_patients` / `t{id}_custom_brands` Meilisearch indexes. On any failure after the schema exists it drops the
schema and the rows. Slugs are DNS labels (2–63 chars, `[a-z0-9-]`) and may not be one of the reserved ones
(`super www queue book display api admin app mail static cdn`, `config/tenancy.php`). The panel is reachable at
`https://{slug}.{central}/panel` as soon as the command prints — the TLS certificate is issued on the first visit.

`tenants:list` shows id, slug, schema, status, plan, domains, migration batch and schema size.

### 2.2 Suspend, reactivate, cancel

Console: Tenants → the clinic → **Suspend** / **Reactivate** / **Cancel** (`routes/super/tenants.php`,
`LifecycleController`). Suspension is also automatic: `saas:dun` (09:00 Dhaka) suspends a tenant whose platform
invoice is past the grace period. What it means (`EnsureTenantIsActive`): `trial|active|past_due` are served
(past_due with a dunning banner), `suspended` answers **402** with the Suspended page on every panel and site
request (JSON callers get `{"code":"tenancy.suspended"}`), `cancelled` answers 404. The schema and queued data are
untouched; one payment (or a click) reverses it.

There is no `tenants:suspend` artisan command. From a shell, use the actions the console uses:

```bash
docker compose exec app php artisan tinker --execute="
\$t = App\Models\Central\Tenant::query()->where('slug','nurjahan')->firstOrFail();
app(App\Domain\SaaS\Actions\Subscriptions\SuspendTenant::class)->handle(\$t, 'non-payment, ticket #123');"
# reactivate:
docker compose exec app php artisan tinker --execute="
\$t = App\Models\Central\Tenant::query()->where('slug','nurjahan')->firstOrFail();
app(App\Domain\SaaS\Actions\Subscriptions\ReactivateTenant::class)->handle(\$t, 'paid by bank transfer');"
```

**After reactivating**, run `php artisan tenants:migrate --seed --tenant=nurjahan`: the deploy-time
`tenants:migrate` selects only servable tenants, so a clinic suspended across a release is behind on schema until
you do — and **`--seed` is not optional any more**. A release may add a *permission* as well as a table
(`RolesAndPermissionsSeeder`, which `--seed` runs through `TenantDatabaseSeeder`, is idempotent and upserts by
name), and a permission the tenant has never heard of is not an inert gap: Spatie's `Gate::before` swallows
`PermissionDoesNotExist` and answers **false**, so the missing row reads as "denied" with nothing in the log. The
live example is `serials.check-in`, which shipped with the compounder role and is the gate on every check-in POST:
a tenant that came back from suspension with migrations but no seeder run has a front desk that can postpone a
patient but cannot mark them arrived, and no error anywhere to say why. Run the seeder before handing the clinic
back, and if a staff member reports a 403 on something they did yesterday, run it before looking anywhere else.

Suspended tenants keep receiving nightly backups (`tenants:backup` covers every provisioned tenant); their
scheduled work (`tenants:run …`) does not run because `tenants:run` iterates active tenants only.

### 2.3 Custom domains

Console → tenant → Domains → add `booking.hospital.com`; the console shows the TXT proof
(`_bp-verify.booking.hospital.com  TXT  bp-verify=<token>`). The clinic also points the name at the VPS (CNAME
to `{central}`, or an A record for an apex). Verify from the console or wait for the hourly `saas:verify-domains`
(`--all` re-checks verified rows too). Only `verified` routes; the sweep never demotes a live domain. The
certificate is issued on the first HTTPS visit after verification — the `tls-ask` gate answers 200 only for
verified rows (DEPLOYMENT §6.2). "Primary" chooses which host the panel links use.

### 2.4 Plan, limits, features

Console → tenant → Plan / Limits / Features (`EntitlementController`). No proration, by design (docs/modules/saas.md):
entitlements change immediately. Plan limits are enforced live (`saas:limit-hammer` exists only for the
concurrency suite). `saas:recount-usage [--tenant=…]` recomputes the doctors/branches/storage gauges from the schemas
if a counter looks wrong.

### 2.5 Impersonation

Console → tenant → Impersonate: a single-use 60-second token, the operator lands in the clinic's panel with a
persistent banner, and every audit row written meanwhile carries `impersonator_super_admin_id`. Leaving logs out.
`saas:prune-impersonation-tokens` (04:00) deletes tokens older than a day.

### 2.6 Churn export (`tenants:export`)

`php artisan tenants:export <slug>` (or console → tenant → Export) builds JSON (one file per table) + CSV + all
uploads as a zip on the `backups` disk, registered in `public.tenant_backups` with `type = export`, expiring after
30 days, and stamps `tenants.data_export_requested_at`. It is **unencrypted by design** (ARCHITECTURE §8.2): the
archive is handed to a departing clinic and must open without us. Treat the download link as the sensitive thing
— send it through a channel the clinic controls, and let the 30-day expiry delete it.

---

## 3. Rotating keys and secrets

Every rotation ends with `docker compose up -d --no-deps app horizon scheduler reverb tls-ask` (containers bake
config at start) — Horizon drains gracefully.

### 3.1 `APP_KEY`

Laravel's graceful rotation: put the old key(s) in `APP_PREVIOUS_KEYS` (comma-separated), set the new `APP_KEY`,
restart. Existing sessions and every `encrypted` column keep decrypting with the previous key; values are
re-encrypted with the new key when they are next written. There is no bulk re-encrypt command in this codebase —
plan on leaving the previous key in place indefinitely (a patient note encrypted three years ago is still readable
only through the key that wrote it) or write a one-off re-save job. Never drop a key from `APP_PREVIOUS_KEYS`
unless you have proven nothing is encrypted with it.

### 3.2 `BP_BACKUP_KEY` (the semantics in `config/saas.php`)

* It is a **platform** key, not per-tenant; every dump is encrypted with whatever key was configured at the moment
  it was written, and `tenant_backups.encryption` records `none` or `xchacha20poly1305` per row.
* Rotation is **by re-dumping, not by re-encrypting**: set the new key, restart, run
  `php artisan tenants:backup --manual` (every tenant) so a copy under the new key exists today.
* Old objects keep the old key. Keep the retired key stored and readable for as long as objects encrypted with it
  are inside their retention window — daily dumps expire after 30 days, **manual** dumps never expire on their own,
  so either delete manual dumps written with the old key or keep that key forever.
* A restore needs the key that wrote the object: `tenants:restore` reads `BP_BACKUP_KEY` from the environment, so
  to restore an old dump after a rotation, temporarily run a one-off container with the old value:
  `docker compose run --rm --no-deps -e BP_BACKUP_KEY=<old> app artisan tenants:restore <id> --force`.
* Losing the key loses every dump written with it. There is no recovery path — by design.

### 3.3 Database passwords

Re-run `docker/postgres/pg-roles.sql` with the new `*_pass` values (it `ALTER ROLE … PASSWORD`s on every run;
DEPLOYMENT §7), update `.env`, restart the app containers. Do the runtime catalog role and the admin role
separately if you want to prove neither can be confused with the other. Postgres keeps existing connections
open across a password change, so there is no downtime; the restart is for the new config.

### 3.4 Reverb, Meilisearch, S3

* **Reverb** `REVERB_APP_KEY`/`REVERB_APP_SECRET`: change both in `.env`, restart `reverb` **and** `app` (the
  browsers get the key from Inertia props on their next page load; open sockets reconnect with the new key —
  a minute of "reconnecting" on desks, covered by polling).
* **`MEILISEARCH_KEY`**: it is Meilisearch's master key. Change `.env` (the service reads it as `MEILI_MASTER_KEY`),
  `docker compose up -d meilisearch app horizon scheduler`. Indexes are unaffected.
* **Object storage credentials**: rotate at the provider (or in MinIO: `mc admin user`), update `AWS_*`, restart.
  A separate write-only credential for `bp-backups` (`AWS_BACKUPS_*`) is the recommended shape.

### 3.5 VAPID pair

Rotating `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY` invalidates every browser push subscription (the subscription is
bound to the public key). The app prunes a subscription after 5 failed pushes (`config/notifications.php`) and the
PWA re-subscribes on its next visit — expect a gap in push reminders, not an outage. Do not rotate casually.

### 3.6 Super console two-factor

Lost phone: use one of the eight recovery codes (single-use) at the challenge, then disable and re-enrol
(disabling re-asks the password). Lost codes too: another super admin cannot reset it from the console; clear the
factor from a shell —

```bash
docker compose exec app php artisan tinker --execute="
App\Models\Central\SuperAdmin::query()->where('email','ops@example.com')->firstOrFail()
  ->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();"
```

— the next login is held on the enrolment screen again (policy `required`). Audit rows
`two_factor_enabled|disabled|failed|recovery_used` in `public.audit_logs_central` tell you what happened.

Whether the console asks for a code at all is the platform setting `security.super_two_factor` (`required` |
`optional` | `disabled`, SCHEMA §2.19), switched from **Platform settings** in the console (re-asks the operator's
password, audited as `settings_change`) and read on every request — no restart. `disabled` keeps every enrolment
intact; switching back restores it. An operator held on forced enrolment can still open Platform settings. From a
shell, audited as the system:

```bash
docker compose exec app php artisan tinker --execute="
app(App\Domain\SaaS\Actions\Settings\UpdatePlatformSetting::class)->handle('security.super_two_factor', 'disabled');"
```

---

## 4. Queues (Horizon)

Dashboard: `https://super.{central}/horizon` (super guard). Three supervisors, seven queues (ARCHITECTURE §4.6):
`critical` (queue-state pushes, call-next broadcasts), `default`, `notifications`, `pdf` (Browsershot, 2
processes, 150 s timeout), `search` (Scout), `reports`, `backups`. Jobs carry `tenant:<id>` tags.

### 4.1 A queue is not draining

1. `docker compose exec horizon php artisan horizon:status` — `Horizon is inactive` (exit 2) means the master
   supervisor died: `docker compose logs --tail=200 horizon`, then `docker compose restart horizon`. `paused` (exit 1):
   `horizon:continue`.
2. Wait times on the dashboard: `LongWaitDetected` fires past 60 s (`config/horizon.php` `waits`). A wall of
   `pdf` jobs waiting means Chromium is slow or hung — check `docker compose top horizon` for orphaned `chromium`
   processes; Browsershot kills its own after `prescription.pdf.timeout` (60 s), Horizon kills the worker at 150 s.
3. Memory: each supervisor restarts a worker that exceeds its `memory` (128/256 MB). A worker restarting in a loop
   shows as jobs retried with `tries = 1` → failed. Look at the failed job's exception.
4. Valkey full: `valkey-cli info memory` near `maxmemory` (384 MB, `noeviction`) → pushes fail with `OOM`. Raise
   the limit in `compose.yaml` (and the container's memory limit) rather than enabling eviction — an evicted key is
   a lost job or a desk logged out.
5. A `tenancy.leak` / `tenancy.leaked_transaction` line at `critical` in the Horizon logs is a bug, not an
   operational condition: capture the log and the job class, restart Horizon, escalate.

### 4.2 Failed jobs

`queue:failed` lists them (also the dashboard's Failed tab), `queue:retry <id>` / `queue:retry all` re-queues,
`queue:forget <id>` / `queue:flush` discards, `horizon:clear --queue=<queue>` empties a queue's pending jobs (last resort:
pending jobs are real work — a `notifications` job is a patient's reminder). Failed rows live in
`public.failed_jobs` for 7 days (`trim.failed`). Notification jobs retry on their own ladder (1 min, 5 min, 15 min;
a permanent provider rejection dead-letters at once — `config/notifications.php`).

### 4.3 Restarting workers

`docker compose exec horizon php artisan horizon:terminate` finishes running jobs and exits; compose restarts the
container (`restart: unless-stopped`). `docker compose up -d --no-deps horizon` does the same with a new image.
Never `docker kill` Horizon during the backup window (01:00–01:30 Dhaka) — a killed `pg_dump` leaves a `running`
row that the next morning's check flags.

---

## 5. Search (Meilisearch)

Two kinds of index: the shared **catalog** indexes `catalog_drugs` and `catalog_icd10`, built directly by the
Catalog module (not Scout), and per-tenant Scout indexes `t{id}_patients`, `t{id}_custom_brands`.

### 5.1 Catalog: after a DGDA release, or to rebuild

```bash
docker compose run --rm --no-deps -v /srv/dgda-2026-09:/import app artisan catalog:import /import --source=dgda --full \
    --catalog-version=2026.09 --release-ref='DGDA bulletin …' --reindex        # --dry-run first
docker compose exec app php artisan catalog:index-search --fresh              # or --changed-since=<version>
```

`--full` marks rows absent from the file inactive (never deleted); `--reindex` runs the index build after commit.
`catalog:index-search --fresh` builds `_next` indexes and swaps them (zero downtime, CATALOG.md §4.4). Unknown
generics/forms/strengths land in `catalog_import_issues` — review them in the console before the next release.

### 5.2 Tenants: rebuild documents or settings

```bash
docker compose exec app php artisan tenants:sync-search-settings [--tenant=slug]   # re-apply index settings (also runs at every deploy)
docker compose exec app php artisan tenants:reindex [--tenant=slug] [--model=App\\Models\\Tenant\\Patient]   # scout:import inside each tenant
```

Reindex runs on the `search` queue in chunks — watch Horizon. Patient documents carry no encrypted fields and no
address (ARCHITECTURE §8.6).

### 5.3 Meilisearch lost or corrupt

Stop `app`/`horizon`, remove the `meilisearch-data` volume, `docker compose up -d --wait meilisearch`, then §5.1's
`catalog:index-search --fresh`, `tenants:sync-search-settings`, `tenants:reindex`. Drug autocomplete is the hot
path for prescription writing — do this out of clinic hours.

---

## 6. Scheduled commands and what a missed run costs

The `scheduler` container runs `schedule:work`; entries come from `routes/console.php` plus every
`app/Domain/<Module>/Schedule.php`. Times are **Asia/Dhaka** unless noted; `onOneServer` = a Valkey lock.

| Time (Dhaka) | Command | If it does not run |
|---|---|---|
| every minute | `tenants:run queue:refresh-eta` | ETAs on queue pages and the reception board go stale; call-next still works. Self-heals on the next run |
| every minute | `tenants:run notifications:send-reminders --option=window=due` | scheduled follow-up reminders and rows stuck in `queued` go out late, not never — the next run picks them up (`dedupe_key` prevents duplicates) |
| every 5 min | `tenants:run booking:expire-holds` | unpaid advance-payment holds keep their serial number past the hold window; the number returns to the online pool on the next run |
| hourly | `tenants:run notifications:send-reminders --option=window=day-before` / `…=morning` | the window is gated on the clinic-local hour; a run missed during that hour means that day's reminder for that window is **skipped** (the next hour's run is a different hour). Send it by hand: `tenants:run notifications:send-reminders --option=window=morning --option=force=1` |
| hourly | `saas:verify-domains` | pending custom domains are verified later; nothing live is affected |
| 00:10 | `sessions:materialise --days=14` | session instances exist 14 days ahead; missing a night costs one day of the window. Run it by hand if a clinic reports "no sessions to book" beyond day 13 |
| 01:00 | `tenants:backup --prune` | a night without dumps and without pruning. Run `tenants:backup --prune` by hand; check the morning query in §1 |
| 01:30 | `tenants:run prescriptions:recompute-favourites` | doctors' favourite-drug ranking is a day stale; harmless |
| 02:00 | `catalog:reconcile` | no orphan report that night (`public.catalog_reconciliation_reports`); it is report-only |
| 02:00 | `saas:renew-subscriptions` | trials end and renewal invoices are issued a day late; dunning dates shift accordingly |
| 03:00 | `saas:recount-usage` | usage gauges (doctors, branches, storage) a day stale; limits are enforced live anyway |
| 03:30 | `tenants:run patients:prune-otp` | the `patient_otp_codes` table grows for a day |
| 04:00 | `saas:prune-impersonation-tokens` | the table grows; tokens are single-use and 60 s regardless |
| 09:00 | `saas:dun` | dunning reminders and auto-suspensions happen a day later — the customer-friendly failure |
| 23:55 | `tenants:run sessions:close-stale` | yesterday's sessions stay open: remaining serials are not auto-no-showed and blocks are not released until the next run, and the reception board keeps yesterday's session in its list. Run it by hand in the morning |

`docker compose logs --since=2h scheduler` shows every run; `php artisan schedule:list` prints the resolved table.

---

## 7. Logs and where to look

| What | Where |
|---|---|
| Application log (`LOG_CHANNEL=stderr`) | `docker compose logs -f app` / `horizon` / `scheduler` / `reverb` — JSON lines carry `request_id`, `tenant_id`, `actor` (`AssignRequestId`). **No PII by contract** (CONVENTIONS §11) |
| Clinical events (`Log::channel('clinical')`) | `storage/logs/clinical-YYYY-MM-DD.log` on the `app-logs` volume: `docker compose exec app tail -f storage/logs/clinical.log`; rotated by `LOG_DAILY_DAYS` |
| Audit — the legal record | `public.audit_logs_central` (console actions: login, impersonation, suspend, restore, 2FA…) and `tenant_<id>.audit_logs` (every clinical view/edit, with IP and impersonator) — SCHEMA §2.13/§3.7. These are tables, not files; export them through the console or SQL |
| HTTP access | `docker compose logs caddy` (JSON; Caddy access log) and Octane's own request log in `app` |
| Postgres | `docker compose logs postgres` — statements over 500 ms are logged (`log_min_duration_statement`) |
| Horizon | dashboard + `docker compose logs horizon` |
| Backups registry | `public.tenant_backups` (status, error, size, checksum, encryption, expiry) |
| Certificates | `docker compose logs caddy`; the certificate store is the `caddy-data` volume |
| Search | `docker compose logs meilisearch` |
| Octane state | `/app/storage/framework/octane-server-state.json` inside the app container |

Retention: container logs 5 × 20 MB per service (compose `logging`); ship them if you need more.

---

## 8. Backups and restore — quick reference

DEPLOYMENT §9 has the full story. Short form:

```bash
docker compose exec app php artisan tenants:backup                    # every tenant, daily type
docker compose exec app php artisan tenants:backup nurjahan --manual  # one tenant, kept until removed
docker compose exec app php artisan tenants:backup --prune            # + delete expired daily objects
scripts/deploy/restore-drill.sh nurjahan                              # list completed backups
scripts/deploy/restore-drill.sh nurjahan <id>                         # restore (swap) with confirmation
docker compose exec -T postgres pg_dumpall -U postgres | gzip > …      # the central schema + catalog, which tenants:backup does not cover
```

---

## 9. Known, deliberate limitations

These are documented decisions or recorded gaps, with their source. They are not bugs to "fix" in operations.

1. **The churn export is unencrypted.** `tenants:export` writes a plain zip (`type = export`) while every
   `tenants:backup` object is XChaCha20-Poly1305. ARCHITECTURE §8.2: the export "is handed to a departing clinic
   and must open without us." Mitigation is procedural (§2.6): the 30-day expiry and a controlled download channel.
2. **No Agora browser client.** The Agora *provider* exists server-side (`AgoraProvider`, `AgoraAccessToken` —
   AccessToken2 `007` tokens, kick via the account-level REST credential; docs/modules/telemedicine.md), but the
   only browser transport wired is LiveKit (`livekit-client`, loaded through `import()` from `core/videoClient.ts`);
   Jitsi and Agora rooms are joined by their own hosted UIs. In addition, `SettingsRegistry` does not yet list
   `agora` among `telemedicine.provider` options, so a clinic cannot pick it from the settings screen —
   `TELEMEDICINE_PROVIDER=agora` (platform default) or a directly written settings row works (telemedicine.md,
   gap 8). Both are recorded as pending foundation work, not omissions of this deployment.
3. **Two Bengali font weights on the public site.** `resources/js/site/app.tsx` loads exactly
   `@fontsource/noto-sans-bengali/bengali-400.css` and `bengali-600.css` — the Bengali unicode-range subsets only —
   and uses `system-ui` for Latin (no Inter on the site; ARCHITECTURE §7.6, CONVENTIONS §7.4). REALTIME §8's font
   line is "Noto Sans Bengali subset ≤ 70 KB woff2, `font-display: swap`" and ARCHITECTURE §7.6 records that the two
   weights are 44 + 48 KB, "so a Bangla page is over §8's ≤ 70 KB font line whenever both load." The docs record
   that state and the mitigation (`font-display: swap`: numbers render in the system font before the Bangla font
   arrives; every site page must render usefully on the system font fallback) rather than a plan to drop to one
   weight. Do not "optimise" by removing a weight without reading those sections — the JS budget
   (`scripts/check-site-deps.sh`) is the enforced one; the font line is a target the docs acknowledge is exceeded.
4. **`route:cache` is off** (DEPLOYMENT §3.3) until the `www.` central route group gets its own name prefix.
5. **Clinic logos are on local disk** (`public` disk, `app-public` volume): single-host only until
   `ClinicUploads::brandingLogo` moves to the `uploads` disk (DEPLOYMENT §10.4).
6. **No super-admin creation command**; first boot uses tinker (DEPLOYMENT §8). `SuperAdminSeeder` creates the
   dev credential `super@bp.localhost` / `password` and must not be run in production.
7. **One Reverb instance.** `REVERB_SCALING_ENABLED=false`; horizontal scaling needs Redis pub/sub and a second
   container behind `ws.{central}`.
8. **`tenants:backup` covers tenant schemas only**; the central schema and the catalog database need the host-level
   `pg_dumpall` (DEPLOYMENT §9.2).
9. **Payments in production require gateway credentials** — per clinic in the panel (encrypted rows) or the
   platform fallback (`config/billing.php`). With none, online payment is simply not offered; `BILLING_GATEWAY_DRIVER=log`
   must never be set in production (it would accept a checkout nobody charged).

---

## 10. Reception tablets (the desk PWA)

The reception desk is an installed PWA ("Clinic Desk"), and a front desk runs it as a kiosk: one page, opened in
the morning, never closed, never reloaded. That changes what "deployed" means — the server is new the moment
`deploy.sh` finishes, and the tablet is not. Everything below is about the tablet.

### 10.1 The release that removed the cached board (September 2026)

The old service worker cached the **rendered, authenticated** reception board under a cache named `shell-v1`,
keyed by URL alone. A worker cannot tell who is asking, so on a dead or slow network it served the previous
user's board — names, fees, their `can` flags — to whoever picked the tablet up next. That cache and a second
one (`api-patients`: names, mobiles, visit history) are gone, and are now deleted on sight. In their place, an
unreachable navigation lands on a **data-free bilingual page** that says the desk needs one connection and that
saved work is safe (OFFLINE.md §11.1).

**Tell the front desk this, before they report it as a fault:** opening the desk with no connection now shows a
teal "ডেস্ক এখন অফলাইন / The desk is offline" page with a Retry button. That is the new correct behaviour.
Nothing has been lost — serials issued offline are still on the tablet and upload themselves when the line
returns. The old behaviour (the board appearing offline from a cold start) is not coming back until the device
PIN of OFFLINE.md §2.2 is built.

### 10.2 What happens by itself

Nothing here needs an operator on a tablet that is opened online at least once and then left alone:

- The page **deletes `shell-v1` and `api-patients` the moment it loads**, whichever worker is in control.
- The registration **polls for a new worker hourly** — a kiosked tablet never navigates, so nothing else asks.
- The new worker **deletes both caches again as soon as it downloads**, while the old one is still in control.
- The update **applies itself** once the desk is idle: no pending events, and either the screen is off/the tab is
  backgrounded or nobody has touched the glass for five minutes. It reloads the page when it does.

So the normal path is: leave the tablet on, online, through one tea break. Expect the swap within about an hour.

### 10.3 Forcing it now, per tablet (~20 seconds)

Worth doing on every tablet for this release rather than waiting, because the thing being replaced is the leak.

1. **Check there is nothing to lose first.** The connection indicator in the desk header shows the pending-event
   count. It must read zero (and the indicator green) before you do anything that reloads or closes the app.
2. If the "Update ready — apply when the desk is idle" toast is showing, tap **Apply**. Done.
3. Otherwise close the app completely — Android recents, swipe the Clinic Desk window away, and close any
   ordinary browser tab on the same clinic host — wait five seconds, and reopen it. A waiting worker activates
   only when every client of the old one is gone, which is why the app has to be *closed*, not just reloaded.
4. Verify: `chrome://inspect` from a laptop on the same network, or Chrome DevTools remote debugging →
   **Application → Service Workers** shows one activated worker and **Cache Storage** lists `workbox-precache-*`,
   `static-v1`, `api-bootstrap`, `print-templates` — and **not** `shell-v1` or `api-patients`.

### 10.4 A tablet that cannot be brought online

It keeps running the old worker, and its `shell-v1` still holds whatever board was last loaded on it. There is
no remote way to reach it — a service worker is only replaced by the browser that runs it. Treat it as a device,
not a cache:

- If the tablet is lost, stolen or being handed to another clinic: revoke it (`POST /api/reception/devices/{device}/revoke`,
  or the button in the panel). That kills its token and its serial blocks (OFFLINE.md §4.5). The stale board on
  its disk stops mattering the moment the device cannot authenticate.
- **Do not "just clear site data" to be safe.** Clearing storage for the clinic host destroys the Dexie database:
  the device token *and every unsynced serial, check-in and cash collection on that tablet*. Only clear after the
  pending count has reached zero, and expect to re-register the device afterwards (OFFLINE.md §2.1).

### 10.5 Verifying the deploy actually shipped the shell

On the server, after `deploy.sh`:

```bash
# the shell is a real file and a precache entry, and it is the ONLY html in the precache
test -f public/offline.html && curl -sf -o /dev/null -w '%{http_code}\n' https://<clinic-host>/offline.html   # 200
grep -o '"url":"[^"]*\.html"' public/sw.js | sort -u                                                          # "url":"offline.html"
```

If `offline.html` is not in `public/sw.js`, the desk will not open offline: the glob in `vite.config.ts`
(`injectManifest.globPatterns`) did not match, and the page's own check logs
`[pwa] precache landed without /offline.html` in the browser console.
