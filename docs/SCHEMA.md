# SCHEMA.md — Physical Data Model

> Authoritative physical data model for the "Booking to Prescription" clinic SaaS.
> Companion to `docs/BRIEF.md` (the build brief; its LOCKED decisions are honoured
> here and must not be changed). Engineers write migrations and Eloquent models
> directly from this file. If this file and a migration disagree, this file wins
> until it is amended by the data architect.
>
> Target platform verified on the dev box: PostgreSQL 16.15, Laravel 12,
> spatie/laravel-permission 8.3, laravel/pennant 1.26, laravel/sanctum 4.3,
> laravel/scout 11.6 + meilisearch-php 1.17.
>
> Reconciled on 2026-09-06 against ARCHITECTURE.md, SERIAL_ENGINE.md, OFFLINE.md, REALTIME.md,
> PRESCRIPTION.md and CATALOG.md: every schema addition those specs requested is absorbed below,
> tenant settings keys are registered in Appendix B, and the cross-document decisions taken are
> recorded in Appendix C. This document is authoritative for the names of tables and columns;
> CONVENTIONS.md §15 is authoritative for the names of classes, routes and keys.

---

## 0. How to read this document

### 0.1 Databases, schemas, connections

| Connection | Database | Schema(s) | Runtime access | Content |
|---|---|---|---|---|
| `pgsql` | `booking` | `public` | read/write | SaaS control plane (Section 2) |
| `pgsql` | `booking` | one schema per row in `public.tenants`, named by `tenants.schema_name` (default `tenant_{id}`) | read/write | Everything clinic-specific (Section 3) |
| `catalog` | `catalog` | `public` | **SELECT only** for the app role | Shared clinical reference (Section 4) |

- The schema name is the column `tenants.schema_name` (`varchar(63)`): `tenant_{id}` with the **bigint `id`** from
  `public.tenants` (e.g. `tenant_42`) for provisioned tenants; the test suite provisions
  `tenant_test_a` / `tenant_test_b` for ids 9001 / 9002 (CONVENTIONS.md §6). Code never derives
  the name from the id — it reads the column.
- Tenant context is switched by setting `search_path` on the `pgsql` connection to
  **exactly** `tenants.schema_name` (`$tenant->schema_name`; no `public` fallback — a missing tenant table must error,
  never silently read a central table). Central models therefore always use
  **schema-qualified** table names (`public.tenants`), and packages that read a central table
  while a tenant is active are configured with qualified names: `pennant.stores.database.table =
  'public.feature_flags'`, `queue.failed.table = 'public.failed_jobs'`, `queue.batching.table =
  'public.job_batches'`, `auth.passwords.super_admins.table = 'public.password_reset_tokens'`
  (ARCHITECTURE.md §3.1). Tenant models use bare names.
- `booking → catalog` foreign keys are impossible. Tenant tables keep **soft references**
  (`generic_id`, `brand_id`, `strength_id`, `icd10_code`, `allergy_class_id`) plus
  **snapshot text columns**; validation of soft references happens in application code
  before commit; a nightly reconciliation job reports orphans (`public.catalog_reconciliation_reports`).
- **No rendering path ever joins to `catalog`.** The prescription render source is
  `prescriptions.snapshot` (Section 5.3).

### 0.2 Column conventions (apply to every table unless the table says otherwise)

| Convention | Rule |
|---|---|
| Primary key | `id bigserial PRIMARY KEY` (`bigint NOT NULL DEFAULT nextval('<table>_id_seq')`, what Laravel's `$table->id()` creates on Postgres — not `GENERATED … AS IDENTITY`). Listed as **PK** `id`; not repeated in column tables. |
| `public_id` | `char(26)` **ULID** — never uuid v4/v7 (`HasUuids` is forbidden) — `NOT NULL`, `UNIQUE`; Laravel `$table->ulid('public_id')->unique()`. Assigned in the model's `creating` event by trait `App\Models\Concerns\HasPublicId` (`Str::ulid()`). Present only on tables that say **public_id: yes**. The bigint `id` never appears in a URL, route parameter, channel name, QR/SMS link or unauthenticated payload; tables that have `public_id` bind routes on it (`{serial:public_id}`). Integer ids nested inside authenticated JSON bodies are acceptable only for tables that have no `public_id` (e.g. `prescription_items.id`). |
| Timestamps | `created_at timestamptz NULL`, `updated_at timestamptz NULL` — Laravel's `$table->timestampsTz()` (nullable, no default; Eloquent fills them). **Raw SQL inserts must set both to `now()`.** All timestamps are `timestamptz` stored in UTC; app timezone for display is `tenants.timezone` (default `Asia/Dhaka`). Tables that say **timestamps: none** or **created_at only** deviate. |
| Soft deletes | `deleted_at timestamptz NULL` — `$table->softDeletesTz()`. Only on tables that say **soft delete: yes**. |
| Money | `bigint` in **paisa** (BDT minor unit, 1 BDT = 100 paisa); column names end in `_paisa`. Never `numeric`/`float` for money. |
| Enum-like | `varchar(32)` (longer where stated) + `CHECK (col IN (...))` named `{table}_{column}_check`. Allowed values are listed per column; PHP string-backed enums in `App\Domain\<Module>\Enums\*` mirror them exactly (Appendix A). |
| FK columns | `bigint`; `NOT NULL` unless marked nullable. **Every FK column gets a btree index** (Laravel does not add one automatically on Postgres — add `->index()` or `foreignId()->constrained()->index()`). Only *additional* indexes are listed under **Indexes**. |
| Booleans | `boolean NOT NULL DEFAULT false` unless stated. |
| JSON | `jsonb`. The exact shape is given under **JSON** for every jsonb column. Default `'{}'::jsonb` or `'[]'::jsonb` as stated. |
| Text | `varchar(n)` where a bound is meaningful; otherwise `text`. Bangla is stored as UTF-8 in the same columns (no separate encoding). |
| Encrypted | Marked **ENC** in the Meaning column. Stored as `text`, written via Laravel cast `encrypted` (or `encrypted:array` / `encrypted:json`). ENC columns can never be indexed, searched, or used in `WHERE`. See Section 5.5. |
| Inet | Client addresses are `inet`. |
| Offline-capable | Tables reception can create offline (`serials`, `appointments`, `payments`, `offline_events`) carry `client_event_id char(26) NULL` (device ULID) and `reception_device_id bigint NULL` with a partial unique `(reception_device_id, client_event_id) WHERE client_event_id IS NOT NULL` — the replay idempotency key (OFFLINE.md §6–§7, CONVENTIONS.md §3.2). `serials` additionally carries the session-scoped variant (§3.3). |
| Dates / times | `date` for calendar dates; `time` (without tz) for wall-clock schedule times interpreted in the tenant timezone. |
| Naming | snake_case, plural table names, singular FK `xxx_id`. Index names `{table}_{cols}_idx`, unique `{table}_{cols}_uniq`, partial indexes carry a `_p` suffix, exclusion constraints `{table}_{purpose}_excl`. |

### 0.3 Model base classes

| Base class | Connection | Table naming | Notes |
|---|---|---|---|
| `App\Models\Central\CentralModel` | `pgsql` | `protected $table = 'public.xxx'` (schema-qualified) | Uses `HasPublicId` where the table has `public_id`. |
| `App\Models\Tenant\TenantModel` | `pgsql` | bare table name (resolved by `search_path`) | Applies a global `TenantAssertionScope` where a `tenant_id` column exists (Section 5.9). |
| `App\Models\Catalog\CatalogModel` | `catalog` (switches to `catalog_admin` inside `CatalogWriteContext::run()`) | bare table name | Read-only at runtime: the `creating/updating/deleting/saving` model events throw `CatalogIsReadOnly` unless `CatalogWriteContext::isOpen()` (ARCHITECTURE.md §5.1, CATALOG.md §1.2). `$timestamps = true`. |

Package models that are re-pointed: `Spatie\Permission\Models\Role/Permission` are subclassed as `App\Models\Tenant\Role` / `App\Models\Tenant\Permission` (set in `config/permission.php`); Sanctum's token model is subclassed as `App\Models\Tenant\PersonalAccessToken` and `App\Models\Central\PersonalAccessToken` (`Sanctum::usePersonalAccessTokenModel()` is swapped in `Tenancy::initialize()/end()`, ARCHITECTURE.md §5.1).

Auth guards (ARCHITECTURE.md §6.1): `web` → `users`; `patient` → `patients`; `super` → `public.super_admins`; `sanctum` (bearer token, else the stateful `web` cookie); and **`device`** (driver `sanctum`, provider `reception_devices`) → `App\Models\Tenant\ReceptionDevice` with `HasApiTokens`. Staff and devices share the tenant `personal_access_tokens` table through the `tokenable` morph — there is no separate device-token table and `reception_devices.device_secret_hash` is unused.

### 0.4 Database-level objects beyond tables

| Object | Where | Why |
|---|---|---|
| `CREATE EXTENSION btree_gist` | `booking` DB (once, extensions are database-scoped, visible from every tenant schema) | Exclusion constraints on `int4range` + `bigint` equality for serial pools/blocks (Section 5.1). Verified creatable by the app role on the dev box. |
| `CREATE EXTENSION pg_trgm` | `booking` and `catalog` DBs | GIN trigram indexes for name/mobile prefix search fallback when Meilisearch is unavailable. |
| Function `public.fn_prescription_guard()` + triggers `prescriptions_immutable_trg`, `prescription_children_immutable_trg` | function once in `public`; triggers created per tenant schema | Enforces prescription immutability at the database level (Section 5.3). |
| Sequences `patient_code_seq`, `invoice_number_seq`, `receipt_number_seq` | per tenant schema | Human-readable business numbers (`P-000123`, `INV-2026-000045`, `RCT-2026-000101`). |
| Redis (Dragonfly) | not a table | Sessions, cache, queues (Horizon), Reverb scaling, the tenant settings cache, the catalog read-through cache (`catalog:{ver}:*`, CATALOG.md/PRESCRIPTION.md §5.6), the capacity cache and the **QueueState snapshot** for ETag polling (Section 5.7, REALTIME.md §4). |

Laravel default tables **not created**: `sessions`, `cache`, `cache_locks`, `jobs` (all on Redis). Remove them from `0001_01_01_*` migrations; keep `failed_jobs` and `job_batches` in `public` only (config `queue.failed.table = 'public.failed_jobs'`, `queue.batching.table = 'public.job_batches'`), and `password_reset_tokens` in both `public` (super admins; broker table `public.password_reset_tokens`) and each tenant schema (staff users; bare name).

### 0.5 Key design decisions (details in Section 5)

1. **Serial uniqueness** — `UNIQUE (session_instance_id, number)` on `serials` is the ultimate guard; the *owner row* (a `serial_pools` row, or a `serial_blocks` row for device blocks and the released-number free-list) is the `FOR UPDATE` lock target; pools and blocks are non-overlapping by **exclusion constraints** (`btree_gist`, half-open `int4range` so an empty zero-quota pool is legal). Pool layout is counter → online → buffer; released block numbers **are reused** through the free-list. §5.1, SERIAL_ENGINE.md §3–§4, OFFLINE.md §4
2. **Reordering** — `serials.position bigint` with a gap of 1,000,000, midpoint insertion, renormalisation inside the session lock; every reorder writes `serial_events` and `audit_logs`. Auto no-show is driven by `serials.passed_count`. §5.2
3. **Prescription immutability** — a row with `status = 'issued'` is frozen by trigger; amendment inserts a new row (`version + 1`, `supersedes_prescription_id`, same `root_prescription_id`); `snapshot` jsonb is the only render source. §5.3
4. **Patient identity** — `mobile` (E.164, plain) + `name_normalized` + `COALESCE(dob, '0001-01-01')` unique per tenant; dependents grouped through `patient_relations`. §5.4
5. **Encryption** — narrative/secret columns via Laravel `encrypted` casts; identity and coded clinical fields stay plain; `prescriptions.snapshot` stays plain jsonb (deliberate; reasoning in §5.5). `mobile` is **not** encrypted, so there is no `mobile_hash`.
6. **Meilisearch** — `catalog_drugs` (one document per `strengths` row), `catalog_icd10`, per-tenant `t{tenant_id}_patients` and `t{tenant_id}_custom_brands`. §5.6
7. **Queue state** — Redis, **not** a table; keys, ETag and channel names are REALTIME.md §4–§5. §5.7
8. **usage_counters** — one row per (tenant, metric, period); gauges use `period = 'current'`. §5.8
9. **Fees / free follow-up** — rules on `doctor_profiles` (+ per-schedule overrides); `appointments` snapshots `fee_paisa`, `list_fee_paisa`, `fee_rule`, `fee_rule_reason`. §5.10
10. **Pennant** store is `public.feature_flags` (`pennant.stores.database.table = 'public.feature_flags'`, published migration renamed); feature classes live in `App\Domain\SaaS\Features` and are tenant-scoped (`Tenant::toFeatureIdentifier()` = `tenant:{id}`). The BRIEF's `doctor_sessions` is realised as `session_instances`.
11. **Identifiers & enums** — `public_id` is always a ULID; enum classes live in `App\Domain\<Module>\Enums` (Appendix A); tenant settings keys form a closed registry (Appendix B).

---

## 1. Table index

**public (booking DB):** tenants, plans, plan_features, subscriptions, subscription_invoices, subscription_payments, domains, super_admins, feature_flags, usage_counters, tenant_backups, catalog_reconciliation_reports, custom_brand_promotions, impersonation_tokens, audit_logs_central, platform_settings, personal_access_tokens, password_reset_tokens, failed_jobs, job_batches.

**tenant_{id} (booking DB):**
- Setup & identity: branches, departments, specialties, users, password_reset_tokens, personal_access_tokens, permissions, roles, model_has_permissions, model_has_roles, role_has_permissions, doctors, doctor_profiles, doctor_specialties, doctor_pad_settings, holidays, doctor_leaves, settings
- Patients: patients, patient_relations, patient_allergies, patient_conditions, patient_medications, patient_documents, patient_consents, patient_otp_codes
- Schedule & serial engine: doctor_schedules, schedule_overrides, session_instances, serial_pools, serial_blocks, reception_devices, serials, serial_events, appointments, offline_events, (queue_snapshots → Redis)
- Clinical: visits, vitals, prescriptions, prescription_items, prescription_investigations, prescription_advice, prescription_referrals, prescription_templates, prescription_template_items, doctor_favourites, doctor_drug_usage, advice_snippets, investigation_catalog, external_diagnostic_centres, custom_brands, ai_suggestions
- Billing: invoices, invoice_items, payments, refunds, discounts, coupons, coupon_redemptions, doctor_revenue_shares, cash_shifts
- Notifications: notification_templates, notifications, notification_logs, sms_gateway_settings, push_subscriptions
- Security: audit_logs
- Telemedicine: telemedicine_rooms, telemedicine_sessions

**catalog DB:** generics, brands, strengths, dosage_forms, routes, icd10_codes, drug_interactions, allergy_classes, allergy_class_generics, pregnancy_categories, renal_cautions, hepatic_cautions, max_daily_doses, drug_information, catalog_versions, catalog_import_issues.

---

## 2. `public` schema — SaaS control plane (Module M, owner: Platform team)

All models extend `App\Models\Central\CentralModel` with `$table = 'public.<name>'`.

### 2.1 `tenants`
**Purpose.** One row per clinic/hospital customer. Its `id` names the tenant schema. — **public_id: yes · soft delete: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| name | varchar(160) | no | | Legal/display name of the clinic |
| slug | varchar(80) | no | | URL-safe handle; default subdomain `{slug}.{platform_domain}` |
| schema_name | varchar(63) | no | | Postgres schema holding the tenant's tables (`$tenant->schema_name`; 63 = the Postgres identifier limit): `tenant_{id}` for provisioned tenants (written by `ProvisionTenant` right after the insert, then immutable); `tenant_test_a` / `tenant_test_b` for the test tenants 9001 / 9002 |
| status | varchar(32) | no | `'trial'` | `trial`, `active`, `past_due`, `suspended`, `cancelled` |
| timezone | varchar(64) | no | `'Asia/Dhaka'` | IANA zone used for schedule/day boundaries |
| locale | varchar(5) | no | `'bn'` | Default UI language `bn` or `en` |
| currency | char(3) | no | `'BDT'` | Only BDT supported at launch |
| owner_name | varchar(160) | no | | Primary contact |
| owner_email | varchar(255) | no | | Primary contact email (billing, dunning) |
| owner_mobile | varchar(20) | no | | E.164 |
| current_subscription_id | bigint | yes | | Denormalised pointer to the live `subscriptions` row |
| trial_ends_at | timestamptz | yes | | |
| suspended_at | timestamptz | yes | | Set on auto-suspend (dunning) or manual suspend |
| suspension_reason | varchar(255) | yes | | |
| onboarding | jsonb | no | `'{}'` | Wizard progress |
| branding | jsonb | no | `'{}'` | Public-site theme |
| platform_notes | text | yes | | Operator notes kept by the super console (Edit clinic); never sent to the clinic's own surfaces |
| provisioned_at | timestamptz | yes | | Schema created + migrated + seeded |
| last_backup_at | timestamptz | yes | | |
| data_export_requested_at | timestamptz | yes | | Churn export (Module N) |

**PK** id. **FK** current_subscription_id → public.subscriptions(id) ON DELETE SET NULL (added after `subscriptions` exists). **Unique** public_id; slug; schema_name. **Indexes** (status); (trial_ends_at) WHERE status='trial' `_p` (trial expiry sweep). **Checks** status list; `slug ~ '^[a-z0-9][a-z0-9-]{1,78}$'`; `schema_name ~ '^tenant_[a-z0-9_]{1,56}$'` (the `tenant_{id}` default is an application rule so that the test tenants can use named schemas).
**JSON** `onboarding`: `{"step":"branches|doctors|schedule|done","demo_seeded":bool,"completed_at":ts|null}`. `branding`: `{"primary_color":"#hex","logo_path":str|null,"favicon_path":str|null,"tagline_bn":str|null,"tagline_en":str|null}`.
**Model** `App\Models\Central\Tenant` (implements `Laravel\Pennant\Contracts\FeatureScopeable`; `toFeatureIdentifier()` returns `tenant:{id}`).

### 2.2 `plans`
**Purpose.** Sellable plan tiers (Free trial, Basic, Pro, Enterprise, Telemedicine add-on).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| code | varchar(40) | no | | Stable key e.g. `basic`, `pro` |
| name | varchar(80) | no | | |
| description | text | yes | | Marketing copy |
| price_monthly_paisa | bigint | no | `0` | |
| price_yearly_paisa | bigint | no | `0` | |
| trial_days | smallint | no | `14` | |
| is_public | boolean | no | `true` | Shown on pricing page |
| is_addon | boolean | no | `false` | e.g. telemedicine add-on, combinable with a base plan |
| sort_order | smallint | no | `0` | |
| archived_at | timestamptz | yes | | Hidden from new sign-ups; existing subscriptions keep it |

**PK** id. **Unique** code. **Checks** price columns `>= 0`; `trial_days >= 0`.
**Model** `App\Models\Central\Plan`.

### 2.3 `plan_features`
**Purpose.** Limits and module toggles per plan. Enforced by comparing against `usage_counters` (§5.8).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| plan_id | bigint | no | | |
| feature_key | varchar(64) | no | | `branches`, `doctors`, `appointments_monthly`, `sms_credits_monthly`, `storage_bytes`, `telemedicine`, `ai_assist`, `whatsapp`, `ivr`, `custom_domain`, `reports_export`, `waiting_room_display`, `handwriting_mode` |
| limit_value | bigint | yes | | Numeric cap; `NULL` = unlimited; `0` = disabled. Boolean toggles use `enabled`. |
| enabled | boolean | no | `true` | Module toggle |

**PK** id. **FK** plan_id → public.plans(id) ON DELETE CASCADE. **Unique** (plan_id, feature_key). **Checks** `limit_value IS NULL OR limit_value >= 0`.
**Model** `App\Models\Central\PlanFeature`.

### 2.4 `subscriptions`
**Purpose.** A tenant's subscription lifecycle (trial → active → past_due → suspended/cancelled). One `active`-ish row per tenant per base plan; add-ons are separate rows.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| tenant_id | bigint | no | | |
| plan_id | bigint | no | | |
| status | varchar(32) | no | `'trialing'` | `trialing`, `active`, `past_due`, `suspended`, `cancelled`, `expired` |
| billing_cycle | varchar(16) | no | `'monthly'` | `monthly`, `yearly` |
| price_paisa | bigint | no | | Price locked at subscription time |
| current_period_start | timestamptz | no | | |
| current_period_end | timestamptz | no | | |
| trial_ends_at | timestamptz | yes | | |
| grace_until | timestamptz | yes | | Dunning grace deadline; auto-suspend after |
| auto_renew | boolean | no | `true` | |
| cancel_at_period_end | boolean | no | `false` | |
| cancelled_at | timestamptz | yes | | |
| cancel_reason | varchar(255) | yes | | |
| feature_overrides | jsonb | no | `'{}'` | Per-tenant negotiated limits, same keys as plan_features |

**PK** id. **FK** tenant_id → public.tenants(id) ON DELETE CASCADE; plan_id → public.plans(id) ON DELETE RESTRICT. **Unique** (tenant_id, plan_id) WHERE status IN ('trialing','active','past_due','suspended') `_p` (one live subscription per plan per tenant). **Indexes** (status, current_period_end) (renewal/dunning sweep); (grace_until) WHERE status='past_due' `_p`. **Checks** status, billing_cycle lists; `current_period_end > current_period_start`.
**JSON** `feature_overrides`: `{"<feature_key>": {"limit_value": int|null, "enabled": bool}, ...}` — overrides win over `plan_features`.
**Model** `App\Models\Central\Subscription`.

### 2.5 `subscription_invoices`
**Purpose.** Platform invoices billed to tenants. — **public_id: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| tenant_id | bigint | no | | |
| subscription_id | bigint | yes | | Null for one-off charges (SMS top-up) |
| number | varchar(32) | no | | `SI-2026-000123` from sequence `public.subscription_invoice_seq` |
| status | varchar(32) | no | `'draft'` | `draft`, `issued`, `paid`, `overdue`, `void` |
| period_start | date | yes | | Billing period covered |
| period_end | date | yes | | |
| subtotal_paisa | bigint | no | `0` | |
| discount_paisa | bigint | no | `0` | |
| tax_paisa | bigint | no | `0` | VAT if applicable |
| total_paisa | bigint | no | `0` | subtotal − discount + tax |
| paid_paisa | bigint | no | `0` | |
| line_items | jsonb | no | `'[]'` | |
| issued_at | timestamptz | yes | | |
| due_at | timestamptz | yes | | |
| paid_at | timestamptz | yes | | |
| voided_at | timestamptz | yes | | |
| dunning_step | smallint | no | `0` | Number of reminders sent |
| pdf_path | varchar(255) | yes | | S3 key |

**PK** id. **FK** tenant_id → public.tenants(id) ON DELETE RESTRICT; subscription_id → public.subscriptions(id) ON DELETE SET NULL. **Unique** public_id; number. **Indexes** (tenant_id, issued_at DESC); (status, due_at) (overdue sweep). **Checks** status list; all `_paisa >= 0`; `paid_paisa <= total_paisa`.
**JSON** `line_items`: `[{"description":str,"quantity":int,"unit_paisa":int,"total_paisa":int,"feature_key":str|null}]`.
**Model** `App\Models\Central\SubscriptionInvoice`.

### 2.6 `subscription_payments`
**Purpose.** Payments received against platform invoices. — **public_id: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| tenant_id | bigint | no | | |
| subscription_invoice_id | bigint | no | | |
| method | varchar(32) | no | | `bkash`, `nagad`, `sslcommerz`, `bank_transfer`, `cash`, `manual` |
| status | varchar(32) | no | `'pending'` | `pending`, `succeeded`, `failed`, `refunded` |
| amount_paisa | bigint | no | | |
| gateway_txn_id | varchar(128) | yes | | Provider transaction id |
| gateway_payload | jsonb | no | `'{}'` | Raw provider response (no card data) |
| idempotency_key | varchar(64) | yes | | Gateway callback dedupe |
| paid_at | timestamptz | yes | | |
| recorded_by_super_admin_id | bigint | yes | | For manual/bank entries |

**PK** id. **FK** tenant_id → public.tenants(id) ON DELETE RESTRICT; subscription_invoice_id → public.subscription_invoices(id) ON DELETE RESTRICT; recorded_by_super_admin_id → public.super_admins(id) ON DELETE SET NULL. **Unique** public_id; idempotency_key (partial WHERE NOT NULL); (method, gateway_txn_id) WHERE gateway_txn_id IS NOT NULL `_p`. **Checks** method, status lists; `amount_paisa > 0`.
**Model** `App\Models\Central\SubscriptionPayment`.

### 2.7 `domains`
**Purpose.** Hostnames routed to a tenant (platform subdomain and verified custom domains for the booking site and queue pages).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| tenant_id | bigint | no | | |
| domain | varchar(253) | no | | Lower-case FQDN, e.g. `hospital.com` or `booking.hospital.com` — **never** a service label (see below) |
| type | varchar(16) | no | | `subdomain`, `custom` |
| is_primary | boolean | no | `false` | Canonical host for links in SMS/PDF |
| verification_status | varchar(16) | no | `'pending'` | `pending`, `verified`, `failed` |
| verification_token | varchar(64) | no | | Expected TXT record value `bp-verify={token}` |
| verified_at | timestamptz | yes | | |
| last_checked_at | timestamptz | yes | | |
| ssl_status | varchar(16) | no | `'none'` | `none`, `pending`, `issued`, `failed` |
| ssl_expires_at | timestamptz | yes | | |

**PK** id. **FK** tenant_id → public.tenants(id) ON DELETE CASCADE. **Unique** domain; (tenant_id) WHERE is_primary `_p`. **Indexes** (verification_status, last_checked_at) (re-verify sweep). **Checks** type, verification_status, ssl_status lists; `domain = lower(domain)`.
**Model** `App\Models\Central\Domain`.

**A row here is the BARE host, not a vanity host.** `App\Tenancy\TenantResolver` strips a leading service label
(`config('tenancy.service_prefixes')` = `queue`, `book`, `display`) from the request host *before* it looks the
domain up, and remembers it as the surface hint. So `queue.hospital.com` reaches the tenant that owns the row
`hospital.com` and lands on the live-queue surface; a row literally spelled `queue.hospital.com` would only ever
match the host `queue.queue.hospital.com` and is therefore useless. Register the host the clinic actually owns —
`hospital.com`, or `booking.hospital.com` if that is where they point the CNAME — and the three vanity prefixes
come for free. `HostResolutionTest` pins the stripping behaviour.

### 2.8 `super_admins`
**Purpose.** Platform operators (guard `super`). — **soft delete: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| name | varchar(160) | no | | |
| email | varchar(255) | no | | |
| email_verified_at | timestamptz | yes | | |
| password | varchar(255) | no | | bcrypt/argon hash |
| two_factor_secret | text | yes | | **ENC** TOTP secret |
| two_factor_recovery_codes | text | yes | | **ENC** JSON array of SHA-256 digests, one per unused single-use code (cast `encrypted:array`) |
| two_factor_confirmed_at | timestamptz | yes | | |
| remember_token | varchar(100) | yes | | |
| is_active | boolean | no | `true` | |
| last_login_at | timestamptz | yes | | |
| last_login_ip | inet | yes | | |

**PK** id. **Unique** email. **Model** `App\Models\Central\SuperAdmin extends Illuminate\Foundation\Auth\User` (with `$connection='pgsql'`, `$table='public.super_admins'`).

### 2.9 `feature_flags` (Laravel Pennant store)
**Purpose.** Pennant database driver store, used for per-tenant feature flags (scope = tenant) and global rollouts. Shape is exactly Pennant 1.26's `features` migration, renamed via `config('pennant.stores.database.table') = 'public.feature_flags'` (schema-qualified because the tenant search path has no `public` fallback). — **timestamps: Laravel `timestamps()` — engineers must change these to `timestampsTz()` in the published migration for consistency; Pennant does not care.**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| name | varchar(255) | no | | Feature class name or string key |
| scope | varchar(255) | no | | Pennant scope serialisation: `tenant:42` (`Tenant::toFeatureIdentifier()`) or `__laravel_null` for global flags |
| value | text | no | | JSON-encoded resolved value |

**PK** id. **Unique** (name, scope). **Model** none (Pennant driver). Feature classes live in `App\Domain\SaaS\Features\*` (discovered with `Feature::discover()`); the default scope is the current tenant (`Feature::resolveScopeUsing`), so `Feature::active('ai-assist')` inside a request or job is tenant-scoped; super admins override per tenant with `Feature::for($tenant)->activate(...)`.

### 2.10 `usage_counters`
**Purpose.** Metered usage for plan-limit enforcement and dashboards. See §5.8 for the write protocol. — **timestamps: updated_at only** (plus `created_at`).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| tenant_id | bigint | no | | |
| metric | varchar(48) | no | | `appointments`, `sms_credits`, `whatsapp_messages`, `storage_bytes`, `doctors`, `branches`, `prescriptions`, `telemedicine_minutes`, `ai_requests` |
| period | varchar(7) | no | `'current'` | `YYYY-MM` (tenant-timezone month) for monthly meters; `current` for gauges (`storage_bytes`, `doctors`, `branches`) |
| value | bigint | no | `0` | |
| limit_snapshot | bigint | yes | | Limit in force when last written (for the dashboard; not authoritative) |

**PK** id. **FK** tenant_id → public.tenants(id) ON DELETE CASCADE. **Unique** (tenant_id, metric, period). **Checks** `value >= 0`; `period = 'current' OR period ~ '^\d{4}-(0[1-9]|1[0-2])$'`.
**Model** `App\Models\Central\UsageCounter`.

### 2.11 `tenant_backups`
**Purpose.** Registry of per-tenant schema dumps (daily job), manual dumps and churn exports.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| tenant_id | bigint | no | | |
| type | varchar(16) | no | | `daily`, `manual`, `export` |
| status | varchar(16) | no | `'pending'` | `pending`, `running`, `completed`, `failed` |
| storage_disk | varchar(32) | no | `'backups'` | Laravel disk name |
| storage_path | varchar(255) | yes | | Object key of the `.dump` (pg_dump custom format, `-n tenant_{id}`); `.dump.enc` when encrypted, `.zip` for `type = export` |
| encryption | varchar(32) | no | `'none'` | `none`, `xchacha20poly1305` — how the object at `storage_path` is protected at rest (ARCHITECTURE §8.2). `none` is only ever written in local/testing, and is the honest backfill for rows taken before encryption existed |
| size_bytes | bigint | yes | | Size of the stored OBJECT (ciphertext when encrypted) — the bytes in the bucket |
| checksum_sha256 | char(64) | yes | | SHA-256 of the PLAINTEXT archive, i.e. what `pg_restore` consumes; `RestoreTenantBackup` verifies it after decrypting |
| started_at | timestamptz | yes | | |
| completed_at | timestamptz | yes | | |
| expires_at | timestamptz | yes | | Retention cut-off (daily: 30 days) |
| error | text | yes | | |
| requested_by_super_admin_id | bigint | yes | | |

**PK** id. **FK** tenant_id → public.tenants(id) ON DELETE CASCADE; requested_by_super_admin_id → public.super_admins(id) ON DELETE SET NULL. **Indexes** (tenant_id, completed_at DESC); (expires_at) WHERE status='completed' `_p`. **Checks** type, status, encryption lists.
**Model** `App\Models\Central\TenantBackup`.

### 2.12 `catalog_reconciliation_reports`
**Purpose.** Output of the nightly job that checks every soft reference in every tenant schema against `catalog`. Orphans are reported, never deleted. — **timestamps: created_at only**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| run_id | char(26) | no | | ULID grouping all rows of one nightly run |
| tenant_id | bigint | no | | |
| catalog_version_id | bigint | yes | | Soft ref to catalog.catalog_versions(id) in force during the run |
| table_name | varchar(64) | no | | e.g. `prescription_items` |
| column_name | varchar(64) | no | | e.g. `generic_id` |
| checked_count | integer | no | `0` | Rows examined |
| orphan_count | integer | no | `0` | Rows whose reference no longer resolves |
| sample_ids | jsonb | no | `'[]'` | Up to 50 tenant row ids for triage |
| details | jsonb | no | `'{}'` | Per finding kind, the affected catalog ids and row counts (CATALOG.md §6) |
| status | varchar(16) | no | | `clean`, `orphans_found`, `inactive_found`, `renamed_found` (worst finding wins: orphan > inactive > renamed) |
| resolved_at | timestamptz | yes | | Super admin marked reviewed |
| resolved_by_super_admin_id | bigint | yes | | |

**PK** id. **FK** tenant_id → public.tenants(id) ON DELETE CASCADE; resolved_by_super_admin_id → public.super_admins(id) ON DELETE SET NULL. **Indexes** (run_id); (tenant_id, created_at DESC); (status) WHERE resolved_at IS NULL `_p`. **Checks** status list.
**JSON** `sample_ids`: `[123, 456, ...]` (bigint ids in `table_name`). `details`: `{"orphan":{"<ref_id>":row_count},"inactive":{...},"renamed":{...},"triple_mismatch":{...}}` — a kind is absent when it has no findings.
**Model** `App\Models\Central\CatalogReconciliationReport`.

### 2.13 `audit_logs_central`
**Purpose.** Super-admin actions (impersonation, suspensions, plan changes, catalog promotions, exports). Append-only. — **timestamps: none** (has `occurred_at`).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| super_admin_id | bigint | yes | | Null for system jobs |
| tenant_id | bigint | yes | | Affected tenant if any |
| action | varchar(32) | no | | `login`, `logout`, `impersonate`, `impersonate_end`, `create`, `update`, `delete`, `suspend`, `reactivate`, `plan_change`, `export`, `restore`, `catalog_promote`, `settings_change`, `view`, `two_factor_enabled`, `two_factor_disabled`, `two_factor_failed`, `two_factor_recovery_used`, `deactivate`, `two_factor_reset`, `password_change`, `session_revoke` |
| auditable_type | varchar(160) | yes | | Morph class |
| auditable_id | bigint | yes | | |
| before | jsonb | yes | | Changed attributes before |
| after | jsonb | yes | | Changed attributes after |
| ip | inet | yes | | |
| user_agent | text | yes | | |
| request_id | char(26) | yes | | Correlates with app logs |
| occurred_at | timestamptz | no | `now()` | |

**PK** id. **FK** super_admin_id → public.super_admins(id) ON DELETE SET NULL; tenant_id → public.tenants(id) ON DELETE SET NULL. **Indexes** (tenant_id, occurred_at DESC); (super_admin_id, occurred_at DESC); (auditable_type, auditable_id); BRIN (occurred_at). **Checks** action list. No UPDATE/DELETE grants for the app role.
**JSON** `before`/`after`: flat `{"column": value}` of changed attributes only.
**Model** `App\Models\Central\AuditLogCentral`.
The CHECK on `action` is rebuilt from `App\Domain\Audit\Enums\CentralAuditAction::values()` by
`2026_01_02_000100_extend_audit_logs_central_actions`, so the enum is the single source and the two cannot drift.
The four `two_factor_*` actions are the super console's second factor (ARCHITECTURE §6.5): enrolment, disablement,
every failed challenge and every recovery code spent. `deactivate` (paired with `reactivate`), `two_factor_reset`
(a colleague clearing an operator's enrolment — the "locked out of the authenticator" path), `password_change`
(own change, a colleague's, or a mailed set-password link) and `session_revoke` are the console's account
management (`super.admins.*`, `super.profile.*`); `2026_01_02_000300_extend_audit_logs_central_actions_for_admins`
rebuilds the CHECK from the enum.

### 2.14 `personal_access_tokens` (Sanctum, central)
**Purpose.** API tokens for super admins and platform integrations. Exact Sanctum 4.3 shape.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| tokenable_type | varchar(255) | no | | `$table->morphs('tokenable')` |
| tokenable_id | bigint | no | | |
| name | text | no | | |
| token | varchar(64) | no | | SHA-256 of the plain token |
| abilities | text | yes | | JSON array |
| last_used_at | timestamptz | yes | | |
| expires_at | timestamptz | yes | | |

**PK** id. **Unique** token. **Indexes** (tokenable_type, tokenable_id) (from `morphs`); (expires_at). **Model** `App\Models\Central\PersonalAccessToken extends Laravel\Sanctum\PersonalAccessToken`.

### 2.15 `password_reset_tokens` (central)
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| email | varchar(255) | no | | **PK** |
| token | varchar(255) | no | | |
| created_at | timestamptz | yes | | |

Laravel default shape; no model.

### 2.16 `failed_jobs`, `job_batches`
Laravel 12 default shapes, unchanged (`failed_jobs`: id, uuid unique, connection text, queue text, payload text, exception text, failed_at timestamptz default now(); `job_batches`: id varchar PK, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids text, options text null, cancelled_at int null, created_at int, finished_at int null). Both live only in `public`; Horizon (Redis) is the queue store. No model (framework-internal). Tenant jobs carry `tenant_id` in the payload (`Queue::createPayloadUsing`) and tenancy is initialised from it at `JobProcessing`, before the job is unserialised (ARCHITECTURE.md §4.6).

### 2.17 `impersonation_tokens`
**Purpose.** Single-use, short-lived handoff tokens a super admin mints to enter a tenant panel as one of its staff users (ARCHITECTURE.md §6.5). — **timestamps: created_at only**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| super_admin_id | bigint | no | | |
| tenant_id | bigint | no | | |
| user_id | bigint | no | | Target `users.id` **inside that tenant's schema** — soft reference (cross-schema FKs are not declared) |
| token_hash | char(64) | no | | sha256 of the plain token carried in the redirect URL `/panel/impersonate/{token}` |
| expires_at | timestamptz | no | | `created_at + 60 s` |
| consumed_at | timestamptz | yes | | Set on first use; a consumed or expired token is rejected |
| ip | inet | yes | | Minting super admin's address |

**PK** id. **FK** super_admin_id → public.super_admins(id) ON DELETE CASCADE; tenant_id → public.tenants(id) ON DELETE CASCADE. **Unique** token_hash. **Indexes** (expires_at) WHERE consumed_at IS NULL `_p` (sweep). Rows older than 24 h are pruned. Every `audit_logs` row written during the impersonated session carries `impersonator_super_admin_id`; the central side logs `audit_logs_central.action = 'impersonate'` / `'impersonate_end'`. **Model** `App\Models\Central\ImpersonationToken`.

### 2.18 `custom_brand_promotions`
**Purpose.** Cross-tenant review queue for tenant `custom_brands` (CATALOG.md §8): one row per submitted brand, so super admins never scan tenant schemas. Written by the tenant-side `CustomBrandCreated` listener; the decision is applied back to the tenant row by `PromoteCustomBrand`. — **public_id: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| tenant_id | bigint | no | | |
| custom_brand_id | bigint | no | | `custom_brands.id` **in that tenant's schema** — soft reference |
| brand_name | varchar(160) | no | | Proposed brand name (denormalised for listing and trigram similarity against `catalog.brands.name`) |
| manufacturer | varchar(160) | yes | | |
| generic_id | bigint | no | | Proposed molecule — soft ref catalog.generics(id) |
| generic_name | varchar(160) | no | | Snapshot |
| snapshot | jsonb | no | | Full custom-brand row at submission |
| status | varchar(10) | no | `'pending'` | `pending`, `approved`, `rejected`, `promoted` (mirrors `custom_brands.review_status`) |
| submitted_at | timestamptz | no | `now()` | |
| reviewed_by_super_admin_id | bigint | yes | | |
| reviewed_at | timestamptz | yes | | |
| decision | jsonb | yes | | Outcome written at review |

**PK** id. **FK** tenant_id → public.tenants(id) ON DELETE CASCADE; reviewed_by_super_admin_id → public.super_admins(id) ON DELETE SET NULL. **Unique** public_id; (tenant_id, custom_brand_id). **Indexes** (status, submitted_at); GIN (brand_name gin_trgm_ops) `_trgm` (similar-brand lookup). **Checks** status list; `(status <> 'pending') = (reviewed_at IS NOT NULL)`. Approving one row auto-resolves other tenants' identical pending rows as `map` (CATALOG.md §8).
**JSON** `snapshot`: `{"strength":str|null,"dosage_form_id":int|null,"form":str|null,"route_id":int|null,"route":str|null,"use_count":int,"created_by_user_public_id":str|null}`. `decision`: `{"mode":"map|create","brand_id":int|null,"strength_id":int|null,"catalog_version_id":int|null,"reason":str|null}` — the master ids after approval; `reason` for rejections.
**Model** `App\Models\Central\CustomBrandPromotion`.

### 2.19 `platform_settings`
**Purpose.** Platform-wide key/value configuration for the control plane itself — the counterpart of the tenant `settings` table (§3.1, Appendix B) for what is not per-clinic. First key: whether the super console demands a second factor (ARCHITECTURE §6.5). Keys are the closed registry `App\Domain\SaaS\Support\PlatformSettingsRegistry`; a missing row means the registry default, which may itself be derived from config so that a deploy-time env value seeds the console toggle instead of competing with it.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| key | varchar(96) | no | | Dotted key from the closed registry below; `PlatformSettings::set()` rejects unknown keys and type-checks the value |
| value | jsonb | no | | Typed per the registry; a `secret` key holds ciphertext (same contract as Appendix B: encrypted by `set()`, masked by `all()`, kept by a blank submit, `[redacted]` in audit) |
| updated_by_super_admin_id | bigint | yes | | Null when a command or seeder wrote it — "the system" |

**PK** id. **FK** updated_by_super_admin_id → public.super_admins(id) ON DELETE SET NULL. **Unique** key. **Checks** `platform_settings_key_check` — `key ~ '^[a-z0-9_]+(\.[a-z0-9_]+)+$'` (the closed list is validated in code; the CHECK only stops a stray row shape). **Model** `App\Models\Central\PlatformSetting`. Read only through `App\Domain\SaaS\Services\PlatformSettings` — cached platform-wide in Redis under `bp:platform:settings`, invalidated on every write, and read at **request time**, never at boot, so a change takes effect on the next request of every worker without a restart. Every write through `App\Domain\SaaS\Actions\Settings\UpdatePlatformSetting` is a `public.audit_logs_central` row (`settings_change`, §2.13) with `{key, value}` before and after. The console renders the registry as the **Platform settings** screen (`super.settings.index`, `super.settings.update`); a key marked 🔑 (`reauth`) re-asks the operator's **current password** — the password, not a TOTP code, because the first such key is the switch that turns the second factor off.

**Registry** (`PlatformSettingsRegistry::all()`; label, description and option copy live under `super.settings.<key>.*` in both language files and a test asserts they exist):

| Key | Type | Default | Meaning | Owner |
|---|---|---|---|---|
| `security.super_two_factor` 🔑 | string: `required`, `optional`, `disabled` (`App\Domain\SaaS\Enums\SuperTwoFactorPolicy`) | `required` when `config('saas.two_factor.required')` (env `SUPER_2FA_REQUIRED`, default `true`) is on, else `optional` | The super console's second-factor policy: `required` forces enrolment and challenges every operator; `optional` challenges only enrolled operators; `disabled` challenges nobody and keeps every enrolment (secret, recovery codes) intact so switching back restores it | ARCHITECTURE §6.5 |

---

## 3. `tenant_{id}` schema — one per clinic

All models extend `App\Models\Tenant\TenantModel` unless stated. Tables in this section never reference another tenant schema; FKs to the control plane are written `public.tenants(id)`.

### 3.1 Setup & identity (Module A, owner: Tenant Setup team)

#### `branches`
**Purpose.** Physical locations of the clinic; the first axis of the serial scope. `public_id` is the `branchPublicId` of Reverb channel names and of device registration (REALTIME.md §2, OFFLINE.md §2.1). — **public_id: yes · soft delete: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| name | varchar(160) | no | | |
| code | varchar(8) | no | | Short code used in token slips/invoice numbers e.g. `DHK` |
| slug | varchar(80) | no | | Public-site URL segment |
| address | text | yes | | |
| phone | varchar(20) | yes | | |
| email | varchar(255) | yes | | |
| is_main | boolean | no | `false` | |
| is_active | boolean | no | `true` | |
| geo | jsonb | yes | | `{"lat":num,"lng":num}` for the public site map |
| settings | jsonb | no | `'{}'` | `{"token_slip_width_mm":58\|80\|148,"display_mode":{"voice":bool,"languages":["bn","en"]}}` |

**PK** id. **Unique** public_id; code; slug. **Model** `App\Models\Tenant\Branch`.

#### `departments`
**Purpose.** Organisational units (Medicine, Surgery…) shown on the site and in reports.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| branch_id | bigint | yes | | Null = clinic-wide |
| name | varchar(120) | no | | English |
| name_bn | varchar(160) | yes | | Bangla (added by `2026_02_01_900100_add_name_bn_to_departments_table`) |
| slug | varchar(80) | no | | |
| sort_order | smallint | no | `0` | |
| is_active | boolean | no | `true` | |

**PK** id. **FK** branch_id → branches(id) ON DELETE SET NULL. **Unique** slug. **Model** `App\Models\Tenant\Department`.

#### `specialties`
**Purpose.** Clinical specialties for doctor search (Cardiology, ENT…).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| name | varchar(120) | no | | English |
| name_bn | varchar(160) | yes | | Bangla |
| slug | varchar(80) | no | | |
| icon | varchar(64) | yes | | MUI icon name |
| sort_order | smallint | no | `0` | |
| is_active | boolean | no | `true` | |

**PK** id. **Unique** slug. **Model** `App\Models\Tenant\Specialty`.

#### `users`
**Purpose.** Staff accounts (Hospital Admin, Doctor, Receptionist, Accountant) for guard `web`. Patients do **not** get a `users` row (§3.2 `patients` authenticates on guard `patient`). `public_id` is the `X-Actor-User` value a reception device sends with every request (OFFLINE.md §2.2). — **public_id: yes · soft delete: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| tenant_id | bigint | no | | Isolation assertion (§5.9) |
| name | varchar(160) | no | | |
| email | varchar(255) | no | | Login id |
| mobile | varchar(20) | yes | | E.164 |
| password | varchar(255) | no | | |
| email_verified_at | timestamptz | yes | | |
| remember_token | varchar(100) | yes | | |
| default_branch_id | bigint | yes | | Branch pre-selected at login |
| locale | varchar(5) | no | `'bn'` | |
| avatar_path | varchar(255) | yes | | |
| is_active | boolean | no | `true` | Deactivation blocks login without deleting history |
| must_change_password | boolean | no | `false` | |
| two_factor_secret | text | yes | | **ENC** |
| two_factor_recovery_codes | text | yes | | **ENC** |
| two_factor_confirmed_at | timestamptz | yes | | |
| last_login_at | timestamptz | yes | | |
| last_login_ip | inet | yes | | |
| session_timeout_minutes | smallint | yes | | Overrides `settings.security.session_timeout_minutes` |

**PK** id. **FK** tenant_id → public.tenants(id) ON DELETE RESTRICT; default_branch_id → branches(id) ON DELETE SET NULL. **Unique** public_id; email; (mobile) WHERE mobile IS NOT NULL `_p`. **Model** `App\Models\Tenant\User extends Illuminate\Foundation\Auth\User` (uses `HasRoles`, `HasApiTokens`, `HasPublicId`, `SoftDeletes`; `$connection='pgsql'`).

#### `password_reset_tokens` (tenant)
Same shape as §2.15 (email PK, token, created_at). No model.

#### `personal_access_tokens` (tenant, Sanctum)
Same shape as §2.14. `tokenable_type` is `App\Models\Tenant\User` (staff API tokens, guard `sanctum`; optional Flutter app) or `App\Models\Tenant\ReceptionDevice` (reception PWAs and waiting-room display boxes, guard `device`; token name `device:{public_id}`, abilities `reception:offline`, `reception:sync`, `reception:blocks`, `reception:read`, 90-day `expires_at`; re-registration rotates the token — OFFLINE.md §2). **Model** `App\Models\Tenant\PersonalAccessToken extends Laravel\Sanctum\PersonalAccessToken`.

#### `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` (spatie/laravel-permission 8.3, teams **disabled**)
Reproduce the published `create_permission_tables.php` exactly with `permission.teams = false`; the only local change is `timestampsTz()`.

| Table | Columns | PK / constraints |
|---|---|---|
| permissions | id; name varchar(255); guard_name varchar(255); created_at; updated_at | PK id; UNIQUE (name, guard_name) |
| roles | id; name varchar(255); guard_name varchar(255); created_at; updated_at | PK id; UNIQUE (name, guard_name) |
| model_has_permissions | permission_id bigint; model_type varchar(255); model_id bigint | PK (permission_id, model_id, model_type) — Postgres names it `model_has_permissions_pkey` (the Postgres grammar ignores the index name passed to `$table->primary()`); FK permission_id → permissions(id) ON DELETE CASCADE; INDEX (model_id, model_type) named `model_has_permissions_model_id_model_type_index` |
| model_has_roles | role_id bigint; model_type varchar(255); model_id bigint | PK (role_id, model_id, model_type) — `model_has_roles_pkey`; FK role_id → roles(id) ON DELETE CASCADE; INDEX (model_id, model_type) named `model_has_roles_model_id_model_type_index` |
| role_has_permissions | permission_id bigint; role_id bigint | PK (permission_id, role_id) — `role_has_permissions_pkey`; FKs to permissions(id) and roles(id) ON DELETE CASCADE |

Seeded roles per tenant (guard `web`): `hospital_admin`, `doctor`, `receptionist` (a.k.a. compounder), `accountant` — enum `App\Domain\Clinic\Enums\Role`, values snake_case; permission names follow the `<module>.<resource>.<action>` grammar of `App\Domain\Clinic\Enums\Permission` (ARCHITECTURE.md §6.2, the single source of truth — permission strings quoted elsewhere in this document are illustrative); plus `patient` on guard `patient` (assigned to `App\Models\Tenant\Patient`, so `model_type` may be a Patient class). Super Admin is **not** a spatie role — it is the `super` guard on `public.super_admins`. Permission cache key must include the tenant id (`permission.cache.key = "spatie.permission.cache.tenant_{id}"`, set by the tenancy bootstrapper).
**Models** `App\Models\Tenant\Role extends Spatie\Permission\Models\Role`, `App\Models\Tenant\Permission extends Spatie\Permission\Models\Permission`.

#### `doctors`
**Purpose.** A practising doctor at the clinic (the second axis of the serial scope). May exist without a login (`user_id NULL`) — visiting doctors whose serials are handled by reception. — **public_id: yes · soft delete: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| user_id | bigint | yes | | Login account, if any |
| name | varchar(160) | no | | Display name e.g. `Dr. Rahman` |
| name_bn | varchar(200) | yes | | |
| slug | varchar(80) | no | | Public URL: `/{slug}` and `queue.host/{slug}/today` |
| code | varchar(8) | no | | Short code for slips e.g. `RAH` |
| gender | varchar(8) | yes | | `male`, `female`, `other` |
| mobile | varchar(20) | yes | | |
| email | varchar(255) | yes | | |
| department_id | bigint | yes | | |
| photo_path | varchar(255) | yes | | |
| is_active | boolean | no | `true` | |
| accepts_online_booking | boolean | no | `true` | |
| accepts_telemedicine | boolean | no | `false` | |
| sort_order | smallint | no | `0` | |
| room_label | varchar(40) | yes | | Chamber/room shown on the display and spoken in call-outs (`"Room 3"`; REALTIME.md §3.1) |

**PK** id. **FK** user_id → users(id) ON DELETE SET NULL; department_id → departments(id) ON DELETE SET NULL. **Unique** public_id; slug; code; (user_id) WHERE user_id IS NOT NULL `_p`. **Checks** gender list. **Model** `App\Models\Tenant\Doctor`.

#### `doctor_profiles`
**Purpose.** 1:1 presentation and **fee/follow-up rules** for a doctor (§5.10).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| doctor_id | bigint | no | | |
| degrees | varchar(255) | yes | | `MBBS, FCPS (Medicine)` — printed on the pad |
| degrees_bn | varchar(255) | yes | | |
| bmdc_reg_no | varchar(32) | yes | | BMDC registration number, printed |
| designation | varchar(160) | yes | | |
| bio | text | yes | | Public-site biography |
| bio_bn | text | yes | | |
| experience_years | smallint | yes | | |
| languages | jsonb | no | `'["bn","en"]'` | Spoken languages |
| new_fee_paisa | bigint | no | `0` | Consultation fee, new patient |
| followup_fee_paisa | bigint | no | `0` | Paid follow-up fee |
| free_followup_within_days | smallint | no | `0` | Free revisit window; `0` = disabled |
| followup_within_days | smallint | no | `30` | Beyond this a revisit is billed as `new` |
| report_visit_free | boolean | no | `true` | Visit only to show reports within the free window is free |
| telemedicine_fee_paisa | bigint | yes | | Null = not offered |
| online_booking_fee_delta_paisa | bigint | no | `0` | Added/subtracted for online channel (may be negative) |
| advance_payment_required | boolean | no | `false` | Online booking must pay before serial confirmation |
| chamber_notes | text | yes | | Free text shown on site (e.g. "Bring old reports") |
| prefs | jsonb | no | `'{}'` | Prescription-writer preferences (PRESCRIPTION.md §1.2, §2.5, §2.14, §4.10) |

**PK** id. **FK** doctor_id → doctors(id) ON DELETE CASCADE. **Unique** doctor_id. **Checks** fees `>= 0`; `free_followup_within_days <= followup_within_days`.
**JSON** `languages`: `["bn","en"]`. `prefs`: `{"default_duration_days":int,"cont_days":int,"cheatsheet_seen_count":int,"dictation_lang":"bn-BD|en-US"}` (defaults 5 / 30 / 0 / `bn-BD` when a key is absent). Print language and signature live on `doctor_pad_settings` (moved there so that `pad_snapshot` is self-contained — Appendix C). **Model** `App\Models\Tenant\DoctorProfile`.

#### `doctor_specialties`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| doctor_id | bigint | no | | |
| specialty_id | bigint | no | | |
| is_primary | boolean | no | `false` | |

**PK** id. **FK** doctor_id → doctors(id) ON DELETE CASCADE; specialty_id → specialties(id) ON DELETE CASCADE. **Unique** (doctor_id, specialty_id); (doctor_id) WHERE is_primary `_p`. **timestamps: created_at only**. **Model** `App\Models\Tenant\DoctorSpecialty`.

#### `doctor_pad_settings`
**Purpose.** Per-doctor prescription pad designer output (Module A). Snapshotted into `prescriptions.pad_snapshot` at issue.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| doctor_id | bigint | no | | |
| paper_size | varchar(4) | no | `'A5'` | `A4`, `A5` |
| orientation | varchar(10) | no | `'portrait'` | `portrait`, `landscape` |
| letterhead_enabled | boolean | no | `true` | |
| preprinted_mode | boolean | no | `false` | Leave header/footer areas blank |
| logo_path | varchar(255) | yes | | |
| header_html | text | yes | | Legacy free-HTML letterhead. **No longer rendered by any print path** (superseded by `letterhead`); kept for one release so nothing a doctor typed is lost |
| footer_html | text | yes | | Legacy free-HTML footer; no longer rendered — see `header_html` |
| margins | jsonb | no | `'{"top":20,"right":15,"bottom":20,"left":15}'` | mm |
| header_height_mm | smallint | no | `35` | Reserved band in preprinted mode |
| footer_height_mm | smallint | no | `20` | |
| font_family | varchar(64) | no | `'Noto Sans Bengali'` | |
| font_size_pt | numeric(4,1) | no | `10.5` | |
| show_qr | boolean | no | `true` | Verification QR |
| show_vitals | boolean | no | `true` | |
| show_drug_info_url | boolean | no | `true` | |
| layout | jsonb | no | `'{}'` | Section order/visibility |
| letterhead | jsonb | no | `'{}'` | The structured letterhead: three palette hexes, header lines, footer columns (PRESCRIPTION.md §7.2). `{}` = never designed; the renderer then builds it from the doctor's profile and the clinic |
| sample_path | varchar(255) | yes | | Photo or PDF of the clinic's existing pad, used by the designer as a tracing underlay. **Never printed** and never copied into `pad_snapshot` |
| token_slip_template | varchar(32) | no | `'thermal_58'` | `thermal_58`, `thermal_80`, `a5` |
| default_language | varchar(5) | no | `'both'` | Print language `bn`, `en`, `both`; seeds `prescriptions.language` for new drafts |
| signature_path | varchar(255) | yes | | Scanned signature image; inlined as a data URI into the snapshot at issue |

**PK** id. **FK** doctor_id → doctors(id) ON DELETE CASCADE. **Unique** doctor_id. **Checks** paper_size, orientation, token_slip_template, default_language lists.
**JSON** `margins`: `{"top":int,"right":int,"bottom":int,"left":int}` (mm). `layout`: `{"sections":[{"key":"vitals|complaints|examination|diagnosis|rx|investigations|advice|followup|referral|signature","visible":bool}],"columns":1|2,"rx_font_size_pt":num|null,"flags":{"icd_codes":bool,"investigation_prices":bool,"generic_names":bool}}` — `flags` default to `true` when absent (PRESCRIPTION.md §4.4, §4.5, §7.2).
**Model** `App\Models\Tenant\DoctorPadSetting`.

#### `holidays`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| branch_id | bigint | yes | | Null = all branches |
| holiday_date | date | no | | |
| name | varchar(120) | no | | |
| name_bn | varchar(160) | yes | | |
| created_by_user_id | bigint | yes | | |

**PK** id. **FK** branch_id → branches(id) ON DELETE CASCADE; created_by_user_id → users(id) ON DELETE SET NULL. **Unique** (holiday_date, COALESCE(branch_id, 0)) — expression unique index `holidays_date_branch_uniq`. **Model** `App\Models\Tenant\Holiday`.

#### `doctor_leaves`
**Purpose.** Planned leave and emergency cancellation ranges; materialisation skips these days and existing `session_instances` in range are cancelled with notifications.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| doctor_id | bigint | no | | |
| branch_id | bigint | yes | | Null = all branches |
| starts_on | date | no | | |
| ends_on | date | no | | Inclusive |
| type | varchar(16) | no | `'planned'` | `planned`, `emergency` |
| reason | varchar(255) | yes | | |
| notify_patients | boolean | no | `true` | |
| notified_at | timestamptz | yes | | |
| is_cancelled | boolean | no | `false` | Leave withdrawn |
| created_by_user_id | bigint | yes | | |

**PK** id. **FK** doctor_id → doctors(id) ON DELETE CASCADE; branch_id → branches(id) ON DELETE CASCADE; created_by_user_id → users(id) ON DELETE SET NULL. **Indexes** (doctor_id, starts_on, ends_on). **Checks** `ends_on >= starts_on`; type list. **Model** `App\Models\Tenant\DoctorLeave`.

#### `settings`
**Purpose.** Tenant key/value configuration (non-secret). Secrets live in `sms_gateway_settings.credentials` or `.env`, never here.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| key | varchar(96) | no | | Dotted key from the closed registry in **Appendix B** (e.g. `queue.auto_noshow_after`, `serial.default_block_size`); `Settings::set()` rejects unknown keys and type-checks the value |
| value | jsonb | no | | Any JSON scalar/object |
| updated_by_user_id | bigint | yes | | |

**PK** id. **FK** updated_by_user_id → users(id) ON DELETE SET NULL. **Unique** key. **Model** `App\Models\Tenant\Setting`. Cached per tenant in Redis (`t:{tenantId}:settings`, bigint tenant id — CONVENTIONS.md §15), invalidated on write. A missing row means the Appendix B default; the desk bootstrap (`GET /api/reception/bootstrap`, OFFLINE.md §5.1) ships the `serial.*`, `queue.*`, `kiosk.*` and `reception.*` keys to the PWA.

---

### 3.2 Patients / mini-EMR (Module H, owner: Patient Record team)

#### `patients`
**Purpose.** Patient master; identity is the mobile number (§5.4). Authenticates on guard `patient` via OTP. — **public_id: yes · soft delete: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| tenant_id | bigint | no | | Isolation assertion (§5.9) |
| patient_code | varchar(16) | no | | `P-000123` from `patient_code_seq`; printed on slips |
| name | varchar(160) | no | | As printed on prescriptions |
| name_normalized | varchar(160) | no | | `GENERATED ALWAYS AS (lower(btrim(name))) STORED` |
| mobile | varchar(20) | no | | E.164 `+8801XXXXXXXXX`; **plain, not encrypted** (§5.5). Shared by a whole family. |
| is_mobile_owner | boolean | no | `true` | False for dependents registered under someone else's phone |
| gender | varchar(8) | yes | | `male`, `female`, `other` |
| dob | date | yes | | |
| dob_is_estimated | boolean | no | `false` | Derived from a stated age |
| blood_group | varchar(3) | yes | | `A+`,`A-`,`B+`,`B-`,`AB+`,`AB-`,`O+`,`O-` |
| email | varchar(255) | yes | | |
| address | text | yes | | |
| district | varchar(64) | yes | | |
| national_id | text | yes | | **ENC** NID/birth-cert number |
| guardian_name | varchar(160) | yes | | For minors |
| photo_path | varchar(255) | yes | | |
| preferred_language | varchar(5) | no | `'bn'` | `bn`, `en` |
| notes | text | yes | | **ENC** reception notes |
| tags | jsonb | no | `'[]'` | `["vip","staff_family"]` |
| registered_branch_id | bigint | yes | | |
| registered_by_user_id | bigint | yes | | Null when self-registered online/kiosk |
| source | varchar(16) | no | `'counter'` | `online`, `counter`, `kiosk`, `import`, `walkin` |
| is_active | boolean | no | `true` | |
| last_visit_at | timestamptz | yes | | Denormalised for search results |
| visit_count | integer | no | `0` | Denormalised |

**PK** id. **FK** tenant_id → public.tenants(id) ON DELETE RESTRICT; registered_branch_id → branches(id) ON DELETE SET NULL; registered_by_user_id → users(id) ON DELETE SET NULL. **Unique** public_id; patient_code; **identity**: expression index `patients_identity_uniq ON (mobile, name_normalized, COALESCE(dob, DATE '0001-01-01')) WHERE deleted_at IS NULL`. **Indexes** (mobile) non-unique btree (household lookup — the most common query; a family shares one number, so uniqueness is the identity index above, never the mobile alone); GIN (name_normalized gin_trgm_ops) `_trgm` (fallback name search); (last_visit_at DESC). **Checks** gender, blood_group, preferred_language, source lists; `mobile ~ '^\+8801[3-9]\d{8}$'`; `dob IS NULL OR dob <= CURRENT_DATE`.
**Model** `App\Models\Tenant\Patient extends Illuminate\Foundation\Auth\User` (`Searchable`, `HasRoles`, `SoftDeletes`; no password column — OTP only).

#### `patient_relations`
**Purpose.** Family grouping: links a dependent to the primary (mobile-owning) patient. A dependent has at most one primary.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| primary_patient_id | bigint | no | | The phone owner |
| dependent_patient_id | bigint | no | | |
| relation | varchar(16) | no | | Dependent is the primary's `spouse`, `child`, `parent`, `sibling`, `guardian_of`, `other` |

**PK** id. **FK** both → patients(id) ON DELETE CASCADE. **Unique** dependent_patient_id. **Checks** relation list; `primary_patient_id <> dependent_patient_id`. **Model** `App\Models\Tenant\PatientRelation`.

#### `patient_allergies`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| patient_id | bigint | no | | |
| allergen_type | varchar(16) | no | | `generic`, `allergy_class`, `food`, `environmental`, `other` |
| generic_id | bigint | yes | | Soft ref catalog.generics — required when type=`generic` |
| allergy_class_id | bigint | yes | | Soft ref catalog.allergy_classes — required when type=`allergy_class` |
| allergen_name | varchar(160) | no | | Snapshot / free text |
| reaction | varchar(255) | yes | | |
| severity | varchar(16) | no | `'unknown'` | `mild`, `moderate`, `severe`, `unknown` |
| notes | text | yes | | **ENC** |
| is_active | boolean | no | `true` | |
| recorded_by_user_id | bigint | yes | | |
| verified_by_doctor_id | bigint | yes | | |

**PK** id. **FK** patient_id → patients(id) ON DELETE CASCADE; recorded_by_user_id → users(id) SET NULL; verified_by_doctor_id → doctors(id) SET NULL. **Indexes** (patient_id) WHERE is_active `_p`. **Checks** allergen_type, severity lists; `(allergen_type='generic') = (generic_id IS NOT NULL)`; `(allergen_type='allergy_class') = (allergy_class_id IS NOT NULL)`. **Model** `App\Models\Tenant\PatientAllergy`.

#### `patient_conditions`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| patient_id | bigint | no | | |
| icd10_code | varchar(8) | yes | | Soft ref catalog.icd10_codes.code |
| condition_name | varchar(200) | no | | Snapshot |
| status | varchar(16) | no | `'active'` | `active`, `chronic`, `resolved` |
| onset_date | date | yes | | |
| resolved_date | date | yes | | |
| notes | text | yes | | **ENC** |
| recorded_by_user_id | bigint | yes | | |

**PK** id. **FK** patient_id → patients(id) CASCADE; recorded_by_user_id → users(id) SET NULL. **Indexes** (patient_id, status). **Checks** status list. **Model** `App\Models\Tenant\PatientCondition`.

#### `patient_medications`
**Purpose.** Current long-term medication list (feeds interaction/duplicate checks).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| patient_id | bigint | no | | |
| generic_id | bigint | yes | | Soft ref |
| brand_id | bigint | yes | | Soft ref |
| custom_brand_id | bigint | yes | | |
| generic_name | varchar(160) | no | | Snapshot |
| brand_name | varchar(160) | yes | | Snapshot |
| dose_text | varchar(120) | yes | | e.g. `1+0+1` |
| source | varchar(16) | no | `'reported'` | `prescription`, `reported` |
| prescription_item_id | bigint | yes | | When source=`prescription` |
| started_on | date | yes | | |
| ended_on | date | yes | | |
| is_active | boolean | no | `true` | |
| notes | text | yes | | **ENC** |

**PK** id. **FK** patient_id → patients(id) CASCADE; custom_brand_id → custom_brands(id) SET NULL; prescription_item_id → prescription_items(id) SET NULL. **Indexes** (patient_id) WHERE is_active `_p`. **Checks** source list. **Model** `App\Models\Tenant\PatientMedication`.

#### `patient_documents`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| patient_id | bigint | no | | |
| visit_id | bigint | yes | | |
| type | varchar(24) | no | `'other'` | `lab_report`, `imaging`, `external_prescription`, `discharge_summary`, `identity`, `other` |
| title | varchar(200) | no | | OCR-suggested, user-editable |
| document_date | date | yes | | Date on the report |
| original_filename | varchar(255) | no | | |
| storage_disk | varchar(32) | no | `'s3'` | |
| storage_path | varchar(255) | no | | Tenant-prefixed object key `tenants/{tenant_id}/patients/{public_id}/...`, produced only by `App\Support\Storage\TenantPath::for()` (ARCHITECTURE.md §8.7) |
| mime_type | varchar(96) | no | | |
| size_bytes | bigint | no | | Counted in `usage_counters.storage_bytes` |
| ocr_status | varchar(16) | no | `'pending'` | `pending`, `done`, `failed`, `skipped` |
| ocr_text | text | yes | | **ENC** |
| uploaded_by_type | varchar(160) | no | | Morph: User or Patient class |
| uploaded_by_id | bigint | no | | |

**PK** id. **FK** patient_id → patients(id) CASCADE; visit_id → visits(id) SET NULL. **Indexes** (patient_id, document_date DESC); (uploaded_by_type, uploaded_by_id). **Checks** type, ocr_status lists; `size_bytes > 0`. **Model** `App\Models\Tenant\PatientDocument`.

#### `patient_consents`
**Purpose.** Consent and data-sharing log (Module N). Append-only; revocation is a new row.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| patient_id | bigint | no | | |
| type | varchar(24) | no | | `data_processing`, `data_sharing`, `sms`, `whatsapp`, `telemedicine`, `research` |
| status | varchar(8) | no | | `granted`, `revoked` |
| policy_version | varchar(16) | no | | |
| channel | varchar(16) | no | | `counter`, `online`, `kiosk`, `app`, `phone` |
| captured_by_user_id | bigint | yes | | |
| ip | inet | yes | | |
| user_agent | text | yes | | |
| signature_data | text | yes | | **ENC** base64 PNG of the signature pad |
| evidence | jsonb | no | `'{}'` | `{"otp_verified":bool,"text_shown":str}` |
| occurred_at | timestamptz | no | `now()` | |

**PK** id. **FK** patient_id → patients(id) CASCADE; captured_by_user_id → users(id) SET NULL. **Indexes** (patient_id, type, occurred_at DESC). **Checks** type, status, channel lists. **timestamps: created_at only.** **Model** `App\Models\Tenant\PatientConsent`.

#### `patient_otp_codes`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| mobile | varchar(20) | no | | Target number (may not yet be a patient) |
| patient_id | bigint | yes | | |
| purpose | varchar(16) | no | | `login`, `booking`, `verify_mobile`, `consent` |
| code_hash | varchar(255) | no | | bcrypt of the 6-digit code |
| channel | varchar(8) | no | `'sms'` | `sms`, `ivr`, `whatsapp` |
| attempts | smallint | no | `0` | |
| expires_at | timestamptz | no | | |
| consumed_at | timestamptz | yes | | |
| ip | inet | yes | | |

**PK** id. **FK** patient_id → patients(id) CASCADE. **Indexes** (mobile, purpose, created_at DESC). **Checks** purpose, channel lists; `attempts <= 5`. **timestamps: created_at only.** Rows older than 24 h are pruned. **Model** `App\Models\Tenant\PatientOtpCode`.

### 3.3 Schedule & serial engine (Modules B, C, D, F, owner: Serial Engine team)

#### `doctor_schedules`
**Purpose.** Weekly recurring template: one row per (doctor, branch, weekday, session_code). Materialised into `session_instances`.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| doctor_id | bigint | no | | |
| branch_id | bigint | no | | |
| weekday | smallint | no | | 0 = Sunday … 6 = Saturday |
| session_code | char(1) | no | | `A` (morning), `B` (evening), `C`…; regex `^[A-Z]$` |
| session_label | varchar(32) | yes | | "Morning" |
| start_time | time | no | | Tenant-local wall clock |
| end_time | time | no | | |
| mode | varchar(8) | no | `'serial'` | `serial`, `slot` |
| slot_minutes | smallint | yes | | Required when mode=`slot` |
| max_serials | smallint | no | | Total capacity = online + counter + buffer |
| online_quota | smallint | no | | |
| counter_quota | smallint | no | | |
| buffer_quota | smallint | no | `4` | Walk-in / VIP / emergency reserve |
| avg_consult_minutes | smallint | no | `6` | Seed for the running average |
| fee_new_paisa | bigint | yes | | Override of `doctor_profiles.new_fee_paisa` for this session |
| fee_followup_paisa | bigint | yes | | Override |
| auto_noshow_after | smallint | yes | | Overrides `settings.queue.auto_noshow_after` |
| works_on_holidays | boolean | no | `false` | Materialise sessions on `holidays` too (an explicit `schedule_overrides` row still wins either way; SERIAL_ENGINE.md §2.1) |
| effective_from | date | no | | |
| effective_to | date | yes | | Null = open-ended |
| is_active | boolean | no | `true` | |

**PK** id. **FK** doctor_id → doctors(id) CASCADE; branch_id → branches(id) CASCADE. **Unique** (doctor_id, branch_id, weekday, session_code, effective_from). **Indexes** (doctor_id, weekday) WHERE is_active `_p` (materialiser). **Checks** `weekday BETWEEN 0 AND 6`; `session_code ~ '^[A-Z]$'`; mode list; `end_time > start_time`; `max_serials = online_quota + counter_quota + buffer_quota`; all quotas `>= 0`; `mode <> 'slot' OR slot_minutes > 0`. **Model** `App\Models\Tenant\DoctorSchedule`.

#### `schedule_overrides`
**Purpose.** Per-date deviations applied when a `session_instance` is materialised or, if it already exists, immediately.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| doctor_id | bigint | no | | |
| branch_id | bigint | no | | |
| override_date | date | no | | |
| session_code | char(1) | yes | | Null = every session that day |
| type | varchar(16) | no | | `late_start`, `cut_short`, `cancelled`, `capacity_change`, `time_change`, `extra_session` |
| delay_minutes | smallint | yes | | late_start |
| new_start_time | time | yes | | time_change / extra_session |
| new_end_time | time | yes | | cut_short / time_change / extra_session |
| new_max_serials | smallint | yes | | capacity_change |
| new_online_quota | smallint | yes | | |
| new_counter_quota | smallint | yes | | |
| new_buffer_quota | smallint | yes | | |
| reason | varchar(255) | yes | | |
| notify_patients | boolean | no | `true` | |
| applied_at | timestamptz | yes | | When the session_instance absorbed it |
| created_by_user_id | bigint | yes | | |

**PK** id. **FK** doctor_id → doctors(id) CASCADE; branch_id → branches(id) CASCADE; created_by_user_id → users(id) SET NULL. **Indexes** (doctor_id, branch_id, override_date). **Checks** type list; `session_code IS NULL OR session_code ~ '^[A-Z]$'`; `new_max_serials IS NULL OR new_max_serials = new_online_quota + new_counter_quota + new_buffer_quota`. **Model** `App\Models\Tenant\ScheduleOverride`.

#### `session_instances`
**Purpose.** The materialised **(branch, doctor, date, session_code)** — the LOCKED serial scope. Created by the nightly materialiser (D+14, `sessions:materialise`) or on demand with an idempotent `INSERT … ON CONFLICT DO NOTHING` (SERIAL_ENGINE.md §2.3); holds live queue state and counters. `doctor_sessions` in BRIEF §4 is this table. `public_id` is the `sessionInstancePublicId` of channel names, Redis keys and the `?session=` query parameter (REALTIME.md §2, §4.2, §5.2). — **public_id: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| branch_id | bigint | no | | |
| doctor_id | bigint | no | | |
| session_date | date | no | | Tenant-local date |
| session_code | char(1) | no | | |
| doctor_schedule_id | bigint | yes | | Template it came from; null for `extra_session` |
| mode | varchar(8) | no | | `serial`, `slot` |
| slot_minutes | smallint | yes | | |
| status | varchar(16) | no | `'scheduled'` | `scheduled`, `running`, `paused`, `closed`, `cancelled` — `scheduled → running ⇄ paused → closed`; any non-terminal → `cancelled` (SERIAL_ENGINE.md §2.5) |
| planned_start_at | timestamptz | no | | |
| planned_end_at | timestamptz | no | | |
| actual_start_at | timestamptz | yes | | First "call next" |
| actual_end_at | timestamptz | yes | | Closed |
| pause_seconds | integer | no | `0` | Accumulated paused time (`paused → running` adds the interval); ETA is suppressed while paused |
| delay_minutes | smallint | no | `0` | Broadcast delay (absolute, not additive; `DelaySession`); ETA adds this until `actual_start_at` |
| max_serials | smallint | no | | Effective capacity after overrides |
| online_quota | smallint | no | | |
| counter_quota | smallint | no | | |
| buffer_quota | smallint | no | | |
| avg_consult_seconds | integer | no | | Running average; seeded from `avg_consult_minutes*60` |
| consult_samples | integer | no | `0` | Completed consultations included in the average |
| now_serving_serial_id | bigint | yes | | |
| last_called_at | timestamptz | yes | | |
| booked_count | smallint | no | `0` | Serials in `booked`. All seven `*_count` columns are **recalculated** from `serials` by `CountsRecalculator` on every transition (one `count(*) FILTER` aggregate), never incremented (SERIAL_ENGINE.md §6.4) |
| checked_in_count | smallint | no | `0` | In `checked_in` (waiting) |
| in_consultation_count | smallint | no | `0` | |
| completed_count | smallint | no | `0` | |
| no_show_count | smallint | no | `0` | |
| cancelled_count | smallint | no | `0` | |
| postponed_count | smallint | no | `0` | |
| auto_noshow_after | smallint | no | `3` | Calls passed (`serials.passed_count`) before a `booked` serial is auto no-showed; copied from the template or `queue.auto_noshow_after`; `0` disables |
| fee_new_paisa | bigint | no | | Snapshot of the fee rule in force (§5.10) |
| fee_followup_paisa | bigint | no | | |
| version | integer | no | `1` | Bumped inside every mutating transaction (transition, reorder, priority insert, delay, extend, pause/resume, pool change, ETA refresh); **is** the queue ETag (§5.7, REALTIME.md §4.2) |
| cancel_reason | varchar(255) | yes | | |
| closed_by_user_id | bigint | yes | | |
| notes | varchar(255) | yes | | |

**PK** id. **FK** branch_id → branches(id) RESTRICT; doctor_id → doctors(id) RESTRICT; doctor_schedule_id → doctor_schedules(id) SET NULL; now_serving_serial_id → serials(id) ON DELETE SET NULL (**added in a later migration**, after `serials`); closed_by_user_id → users(id) SET NULL. **Unique** public_id; (branch_id, doctor_id, session_date, session_code) — the serial-scope invariant and the `ON CONFLICT` target of idempotent materialisation. **Indexes** (session_date, branch_id) (today's board); (doctor_id, session_date) (queue page, public site, `sessions:close-stale`); (status) WHERE status IN ('running','paused') `_p` (ETA refresh scheduler). **Checks** status, mode lists; `session_code ~ '^[A-Z]$'`; `max_serials = online_quota + counter_quota + buffer_quota`; counters and `pause_seconds` `>= 0`; `planned_end_at > planned_start_at`. **Model** `App\Models\Tenant\SessionInstance`.

#### `serial_pools`
**Purpose.** Per session_instance, the three disjoint number ranges. **This row is the `SELECT … FOR UPDATE` lock target** for allocation (§5.1). — **timestamps: updated_at only** (+ created_at).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| session_instance_id | bigint | no | | |
| pool | varchar(8) | no | | `online`, `counter`, `buffer` |
| range_start | integer | no | | Inclusive. Layout with C/O/B = counter/online/buffer quota: **counter `[1, C]`, online `[C+1, C+O]`, buffer `[C+O+1, C+O+B]`** — counter first (SERIAL_ENGINE.md §3.1; supersedes the online-first sketch this document once carried) |
| range_end | integer | no | | Inclusive. A zero-quota pool still gets a row as the **empty range** `range_end = range_start - 1` (`next_number = range_start`) so lookups never miss |
| next_number | integer | no | | Next unissued number; `> range_end` means exhausted. **Only ever increases** — returned numbers come back through `serial_blocks` released rows (the free-list, §5.1.3), never by moving this cursor backwards |
| issued_count | integer | no | `0` | Numbers handed out (incl. block leases) |
| lock_version | integer | no | `0` | Bumped on every allocation (diagnostics) |

**PK** id. **FK** session_instance_id → session_instances(id) ON DELETE CASCADE. **Unique** (session_instance_id, pool). **Exclusion** `serial_pools_range_excl EXCLUDE USING gist (session_instance_id WITH =, int4range(range_start, range_end + 1) WITH &&)` — requires `btree_gist`. The range is **half-open** `[start, end + 1)` so an empty pool is `[n, n)` = `empty` and never conflicts (verified on the dev PostgreSQL 16: `int4range(5, 4, '[]')` raises "range lower bound must be less than or equal to range upper bound", whereas `int4range(5, 5)` is `empty` and `int4range(1, 11) && int4range(11, 21)` is false). **Checks** pool list; `range_start >= 1`; `range_end >= range_start - 1`; `next_number BETWEEN range_start AND range_end + 1`; `issued_count >= 0`. **Model** `App\Models\Tenant\SerialPool`.

#### `reception_devices`
**Purpose.** Registered reception PWA installs and waiting-room display boxes. A device is the Sanctum tokenable of guard `device` (driver `sanctum`, provider `reception_devices`); its tokens live in the tenant `personal_access_tokens` table with `tokenable_type = App\Models\Tenant\ReceptionDevice`. Reception devices lease offline serial blocks; display devices only subscribe to the private display channel (REALTIME.md §2, §9). — **public_id: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| branch_id | bigint | no | | |
| number | smallint | no | | Small per-branch integer; the offline receipt prefix `D{number}-000123` (OFFLINE.md §6.1) |
| name | varchar(80) | no | | "Front desk 1" |
| kind | varchar(10) | no | `'reception'` | `reception`, `display` |
| device_fingerprint | varchar(128) | no | | Stable client-generated id (`sha256(userAgent + screen + platform)`); re-registering the same fingerprint rotates the token |
| device_secret_hash | varchar(255) | yes | | **Unused / reserved** — device identity is the Sanctum token (OFFLINE.md §2); kept nullable so no design depends on it |
| app_version | varchar(20) | yes | | PWA build reported on sync / heartbeat (throttled to once a minute) |
| registered_by_user_id | bigint | yes | | |
| status | varchar(8) | no | `'active'` | `active`, `revoked` |
| block_size | smallint | no | `5` | Serials leased per block; `LeaseBlock` clamps to `min(requested, block_size, serial.default_block_size, 30, remaining)` |
| last_seen_at | timestamptz | yes | | |
| last_sync_at | timestamptz | yes | | Last successful replay |
| last_ip | inet | yes | | |
| user_agent | text | yes | | |
| revoked_at | timestamptz | yes | | Revocation deletes the device's tokens and revokes its active blocks (OFFLINE.md §4.5) |

**PK** id. **FK** branch_id → branches(id) CASCADE; registered_by_user_id → users(id) SET NULL. **Unique** public_id; device_fingerprint; (branch_id, number). **Indexes** (branch_id, kind) WHERE status = 'active' `_p` (display-channel and board guards). **Checks** status, kind lists; `block_size BETWEEN 1 AND 50`; `number >= 1`. **Model** `App\Models\Tenant\ReceptionDevice` (`HasApiTokens`, `HasPublicId`; token abilities `reception:offline`, `reception:sync`, `reception:blocks`, `reception:read`; tokens expire after 90 days).

#### `serial_blocks`
**Purpose.** Contiguous number ranges carved out of a pool, with their own cursor. Two kinds share the table: (a) **device leases** from the `counter` pool for offline issuing, and (b) **desk-owned released ranges** (`reception_device_id IS NULL`) — the free-list of numbers returned by a released/revoked block or handed from the `online` pool to the counter (SERIAL_ENGINE.md §3.5/§3.6, OFFLINE.md §4). Every unissued number of a session belongs to exactly one owner row (a pool or a block) at any instant (invariant I-OWNER). — **public_id: yes · timestamps: updated_at only** (+ created_at).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| session_instance_id | bigint | no | | |
| serial_pool_id | bigint | no | | Pool the range was carved from: the `counter` pool for device leases; the `online` pool for an online → counter release (`ReleaseOnlineToCounter`) |
| reception_device_id | bigint | yes | | Leasing device; **NULL = desk-owned released range** |
| range_start | integer | no | | Inclusive |
| range_end | integer | no | | Inclusive |
| next_number | integer | no | | Cursor: next unissued number of this range, advanced on replay and when the free-list is drained; `> range_end` = nothing left |
| status | varchar(10) | no | `'active'` | `active` (the device may issue offline), `released` (unissued remainder is a reusable free-list), `exhausted` (nothing left) |
| leased_at | timestamptz | no | `now()` | |
| leased_by_user_id | bigint | yes | | |
| expires_at | timestamptz | yes | | `planned_end_at + 2 h`; advisory for the client only — a block is never expired by time while its session is open |
| released_at | timestamptz | yes | | Session close, device logout/revoke, manual return, or online release |
| revoked_at | timestamptz | yes | | Set by `RevokeBlock` (device lost / replaced); "revoked" = `status = 'released' AND revoked_at IS NOT NULL` — no second row is created |
| returned_count | smallint | no | `0` | Unused numbers at release (`range_end - next_number + 1`). They are **reusable**: the released row is drained lowest-range-first by both `LeaseBlock` and counter allocation (§5.1.3); the shift report shows them as returned, not wasted |

**PK** id. **FK** session_instance_id → session_instances(id) CASCADE; serial_pool_id → serial_pools(id) CASCADE; reception_device_id → reception_devices(id) RESTRICT; leased_by_user_id → users(id) SET NULL. **Unique** public_id. **Exclusion** `serial_blocks_range_excl EXCLUDE USING gist (session_instance_id WITH =, int4range(range_start, range_end + 1) WITH &&)` — half-open like the pools, so a row shrunk to nothing is `empty` and never conflicts; splitting a released row around a replayed number (OFFLINE.md §8.2) yields two disjoint rows and keeps the constraint satisfied. **Indexes** (session_instance_id, range_start) WHERE status = 'released' `_p` (free-list drain: `… ORDER BY range_start FOR UPDATE SKIP LOCKED LIMIT 1`); (reception_device_id, session_instance_id, status) (active-block count and `GET /api/reception/blocks`). **Checks** status list; `range_end >= range_start - 1`; `next_number BETWEEN range_start AND range_end + 1`; `status <> 'active' OR reception_device_id IS NOT NULL` (an active lease always has a device); `revoked_at IS NULL OR status <> 'active'`; `returned_count >= 0`.
**Application rule, not a DB constraint.** A device holds at most `serial.max_active_blocks_per_device` (default 2, Appendix B) `active` blocks per session; the former one-active-block partial unique index is **dropped**. `LeaseBlock` counts the device's active blocks while holding the counter-pool `FOR UPDATE` lock — which serialises every lease and desk allocation for that session — so the limit cannot be raced, and a count-limited unique cannot be expressed declaratively anyway. The DB guards that remain are the exclusion constraint and the CHECKs above; `serials_session_number_uniq` is the backstop for everything else.
**Model** `App\Models\Tenant\SerialBlock`.

#### `serials`
**Purpose.** One issued token in a session. Its `number` is unique per session by DB constraint. — **public_id: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| session_instance_id | bigint | no | | |
| number | integer | no | | Identity within the session; never changes. May be lower than numbers issued earlier when it was reused from the free-list (§5.1.3) |
| display_code | varchar(8) | no | | `A-042` = `session_code \|\| '-' \|\| lpad(number, 3, '0')` (never truncated: `B-1204`); written by the app and stored so old events print identically |
| position | bigint | no | | Calling order (§5.2): `max(number × 1 000 000, current max position + 1 000 000)` at issue, `slot_index × 1 000 000` in slot mode; changed by reorder / priority insert / reinstate / skip |
| pool | varchar(8) | no | | `online`, `counter`, `buffer` — which range it came from |
| status | varchar(16) | no | `'booked'` | `booked`, `checked_in`, `in_consultation`, `completed`, `no_show`, `cancelled`, `postponed` — state machine SERIAL_ENGINE.md §6; terminal: `completed`, `cancelled`, `postponed`; the extra edge `cancelled → checked_in` exists only through the offline-sync resolution `reinstate` (OFFLINE.md §8.4) |
| priority | varchar(10) | no | `'normal'` | `normal`, `elderly`, `emergency`, `vip` (position rules SERIAL_ENGINE.md §7.3; `vip` can be disabled per tenant) |
| source | varchar(10) | no | | `online`, `counter`, `walkin`, `kiosk`, `followup`, `offline` |
| appointment_id | bigint | yes | | Set for every booked serial except bare walk-ins pending registration |
| patient_id | bigint | yes | | Null only for an offline walk-in slip not yet matched |
| serial_block_id | bigint | yes | | When issued from a device block |
| reception_device_id | bigint | yes | | Device that issued it |
| client_event_id | char(26) | yes | | Idempotency ULID: the device event id on offline replay, a browser-generated ULID on online/kiosk booking, `postpone:{old.public_id}` / `transfer:{old.public_id}` on retried postpones/transfers |
| transferred_from_serial_id | bigint | yes | | Set on the new serial created by a transfer |
| transferred_to_serial_id | bigint | yes | | Set on the old (now `cancelled`) serial |
| postponed_to_serial_id | bigint | yes | | Serial in the next session |
| issued_by_user_id | bigint | yes | | Null for online/kiosk |
| slot_start_at | timestamptz | yes | | Slot mode: the booked slot (unique per session); NULL for walk-ins that fill gaps |
| booked_at | timestamptz | no | `now()` | |
| checked_in_at | timestamptz | yes | | |
| called_at | timestamptz | yes | | Last "call" |
| consultation_started_at | timestamptz | yes | | |
| completed_at | timestamptz | yes | | |
| no_show_at | timestamptz | yes | | |
| cancelled_at | timestamptz | yes | | |
| postponed_at | timestamptz | yes | | |
| cancel_reason_code | varchar(24) | yes | | `patient_request`, `doctor_unavailable`, `duplicate`, `transferred`, `no_payment`, `session_cancelled`, `other` |
| reinstated_at | timestamptz | yes | | No-show reversed |
| t3_notified_at | timestamptz | yes | | "3 ahead" notification sent — the `IS NULL` predicate inside REALTIME.md §7's single `UPDATE … RETURNING` is the dedupe; never reset |
| passed_count | smallint | no | `0` | Times "call next" advanced past this `booked` serial without a check-in; reaching `session_instances.auto_noshow_after` (after the grace period) makes it `no_show`; reset to 0 on reinstate (SERIAL_ENGINE.md §8) |
| skip_count | smallint | no | `0` | Times returned from `in_consultation` to the queue (`SkipCalled` / `ReturnToQueue`, SERIAL_ENGINE.md §14) |
| estimated_call_at | timestamptz | yes | | Cached ETA for slips/SMS |
| notes | varchar(255) | yes | | |

**PK** id. **FK** session_instance_id → session_instances(id) RESTRICT; appointment_id → appointments(id) SET NULL (**added after `appointments`**); patient_id → patients(id) RESTRICT; serial_block_id → serial_blocks(id) SET NULL; reception_device_id → reception_devices(id) SET NULL; transferred_from_serial_id / transferred_to_serial_id / postponed_to_serial_id → serials(id) SET NULL; issued_by_user_id → users(id) SET NULL.
**Unique** public_id; **`serials_session_number_uniq (session_instance_id, number)`** — the duplicate-serial guard; `serials_session_client_event_uniq (session_instance_id, client_event_id) WHERE client_event_id IS NOT NULL` `_p` (idempotent allocation for every channel — `AllocateSerial` checks it before taking the lock; covers online/kiosk double-submits where `reception_device_id` is NULL); (reception_device_id, client_event_id) WHERE client_event_id IS NOT NULL `_p` (per-device replay, CONVENTIONS.md §3.2); (session_instance_id, slot_start_at) WHERE slot_start_at IS NOT NULL `_p` (slot mode: a double-booked slot is `SlotUnavailable`, not retried); (appointment_id) WHERE appointment_id IS NOT NULL `_p`.
**Indexes** (session_instance_id, position, number) (queue order); (session_instance_id, status) (counts recalculation); (session_instance_id, position) WHERE status IN ('booked','checked_in','in_consultation') `_p` (active queue: QueueState build, call-next, 3-ahead, auto no-show sweep); (patient_id, booked_at DESC) (patient history). **Checks** pool, status, priority, source, cancel_reason_code lists; `number >= 1`; `position >= 0`; `passed_count >= 0`; `skip_count >= 0`; `serial_block_id IS NULL OR source = 'offline'` (a block-issued serial is always offline; an offline-sourced serial may come from the pool after a conflict resolution — OFFLINE.md §8.2 `reissue`, §8.3 `move_to_session`).
**Model** `App\Models\Tenant\Serial`.

#### `serial_events`
**Purpose.** Immutable log of every serial transition, reorder, priority change and transfer (every reorder must land here **and** in `audit_logs`). — **timestamps: none** (`occurred_at`).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| serial_id | bigint | yes | | NULL for session-level rows (see the CHECK below) |
| session_instance_id | bigint | no | | Denormalised for per-session replay |
| type | varchar(32) | no | | Serial-level: `booked`, `checked_in`, `called`, `consultation_started`, `completed`, `no_show`, `reinstated`, `reinstated_after_cancel`, `cancelled`, `postponed`, `reordered`, `priority_changed`, `skipped`, `transferred_out`, `transferred_in`, `patient_assigned`, `note_added`, `printed`, `recorded_post_close`. Session-level (`serial_id` NULL): `number_skipped`, `renormalised`, `capacity_extended`, `online_released`, `split_changed`, `block_leased`, `block_released`, `block_revoked`, `delayed`, `void_local`. SERIAL_ENGINE.md's dotted names (`serial.allocated`, `pool.online_released`, `block.leased`, `session.delayed` …) map onto these per its §19.7 |
| from_status | varchar(16) | yes | | |
| to_status | varchar(16) | yes | | |
| from_position | bigint | yes | | reordered |
| to_position | bigint | yes | | |
| from_priority | varchar(10) | yes | | |
| to_priority | varchar(10) | yes | | |
| actor_type | varchar(8) | no | | `user`, `patient`, `device`, `system` |
| actor_user_id | bigint | yes | | |
| actor_patient_id | bigint | yes | | Online self-service |
| reception_device_id | bigint | yes | | |
| client_event_id | char(26) | yes | | |
| reason | varchar(255) | yes | | |
| meta | jsonb | no | `'{}'` | Type-specific details — the "payload" of SERIAL_ENGINE.md/OFFLINE.md goes here. reordered `{"after":"A-012","before":"A-019"}`; priority_changed `{"priority":str}`; no_show `{"auto":bool,"reason":"auto\|manual\|session_closed","passed":int}`; postponed `{"to_serial":str,"to_session":str}`; transferred_* `{"related_serial_id":int,"target_doctor_id":int}`; number_skipped `{"number":int,"reason":"unique_violation"}`; renormalised `{"before":{"<serial_id>":pos},"after":{...}}`; capacity_extended `{"by":int,"reason":str}`; online_released `{"range":[start,end],"released_block_id":int}`; block_* `{"block_id":int,"range":[start,end],"returned":int}`; delayed `{"delay_minutes":int,"message":str}`; printed `{"format":"58\|80\|a5","copies":int}`; void_local `{"voided_client_event_id":str,"reason":str}`; recorded_post_close `{"client_occurred_at":ts}`; plus `{"source":"web\|api\|offline_replay\|system"}` from the `Actor` |
| ip | inet | yes | | |
| user_agent | text | yes | | |
| occurred_at | timestamptz | no | `now()` | |

**PK** id. **FK** serial_id → serials(id) CASCADE; session_instance_id → session_instances(id) CASCADE; actor_user_id → users(id) SET NULL; actor_patient_id → patients(id) SET NULL; reception_device_id → reception_devices(id) SET NULL. **Indexes** (serial_id, occurred_at); (session_instance_id, occurred_at); (session_instance_id, type) WHERE serial_id IS NULL `_p` (session-level audit). **Checks** type, actor_type lists; `serial_id IS NOT NULL OR type IN ('number_skipped','renormalised','capacity_extended','online_released','split_changed','block_leased','block_released','block_revoked','delayed','void_local')`. No UPDATE/DELETE grants. **Model** `App\Models\Tenant\SerialEvent`.

#### `appointments`
**Purpose.** The booking (all five channels) — links patient, doctor, session and serial; snapshots the fee decision (§5.10). — **public_id: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| tenant_id | bigint | no | | Isolation assertion (§5.9) |
| patient_id | bigint | no | | |
| doctor_id | bigint | no | | |
| branch_id | bigint | no | | |
| session_instance_id | bigint | yes | | Null only while `draft` (auto follow-up without a date) |
| serial_id | bigint | yes | | |
| type | varchar(10) | no | | `new`, `followup` |
| channel | varchar(16) | no | | `online`, `phone`, `counter`, `walkin`, `kiosk`, `followup`, `telemedicine`, `offline` (created by offline replay) |
| status | varchar(16) | no | `'pending'` | `draft`, `pending`, `confirmed`, `checked_in`, `in_consultation`, `completed`, `no_show`, `cancelled`, `postponed` |
| scheduled_date | date | yes | | Mirrors session_instances.session_date; set for drafts too |
| slot_start_at | timestamptz | yes | | Slot mode |
| list_fee_paisa | bigint | no | | Fee before rules (`new_fee_paisa` of the session) |
| fee_paisa | bigint | no | | Fee actually charged |
| fee_rule | varchar(20) | no | | `new`, `followup_paid`, `followup_free`, `schedule_override`, `telemedicine`, `manual`, `waived` |
| fee_rule_reason | varchar(255) | yes | | Human-readable e.g. "Free follow-up: last visit 2026-08-30 (day 7 of 15)" |
| payment_status | varchar(10) | no | `'unpaid'` | `unpaid`, `partial`, `paid`, `refunded` |
| invoice_id | bigint | yes | | |
| follow_up_of_visit_id | bigint | yes | | The visit that produced this follow-up |
| booked_by_user_id | bigint | yes | | Staff who booked; null for self-service |
| booked_by_patient | boolean | no | `false` | Self-service online/kiosk |
| is_telemedicine | boolean | no | `false` | |
| notes | varchar(500) | yes | | Booking notes (reason for visit) |
| idempotency_key | varchar(64) | yes | | Online double-submit guard |
| client_event_id | char(26) | yes | | Offline `issue_serial` event id / online booking ULID — the same value as the serial's |
| reception_device_id | bigint | yes | | Device that created it offline |
| cancel_reason_code | varchar(24) | yes | | Same list as serials |
| cancelled_at | timestamptz | yes | | |
| cancelled_by_user_id | bigint | yes | | |
| confirmed_at | timestamptz | yes | | |
| reminder_day_before_sent_at | timestamptz | yes | | |
| reminder_morning_sent_at | timestamptz | yes | | |

**PK** id. **FK** tenant_id → public.tenants(id) RESTRICT; patient_id → patients(id) RESTRICT; doctor_id → doctors(id) RESTRICT; branch_id → branches(id) RESTRICT; session_instance_id → session_instances(id) RESTRICT; serial_id → serials(id) SET NULL; reception_device_id → reception_devices(id) SET NULL; invoice_id → invoices(id) SET NULL (**added after `invoices`**); follow_up_of_visit_id → visits(id) SET NULL (**added after `visits`**); booked_by_user_id, cancelled_by_user_id → users(id) SET NULL. **Unique** public_id; (serial_id) WHERE serial_id IS NOT NULL `_p`; (idempotency_key) WHERE NOT NULL `_p`; (reception_device_id, client_event_id) WHERE client_event_id IS NOT NULL `_p`; (patient_id, session_instance_id) WHERE status NOT IN ('cancelled','no_show') `_p` (one live booking per patient per session). **Indexes** (session_instance_id, status); (patient_id, scheduled_date DESC); (doctor_id, scheduled_date); (status, scheduled_date) WHERE status='draft' `_p` (follow-up drafts due). **Checks** type, channel, status, fee_rule, payment_status lists; `fee_paisa >= 0`; `status='draft' OR session_instance_id IS NOT NULL`; `(type='followup') = (follow_up_of_visit_id IS NOT NULL)`. `serials.appointment_id` and `appointments.serial_id` are written in the same transaction and must agree. **Model** `App\Models\Tenant\Appointment`.

#### `offline_events`
**Purpose.** Server-side replay log of a device's ordered offline event queue (OFFLINE.md §6–§8). One row per client event, **exactly once**: a re-sent event whose row is already `accepted|conflict|rejected` returns the stored `server_result` verbatim; a `pending` row (previous attempt died mid-flight) is processed again through idempotent handlers. Never silently merged — a `conflict` waits for a receptionist's `resolution`. — **timestamps: none** (`received_at` is the insert time; the model sets `CREATED_AT = 'received_at'`, `UPDATED_AT = null`).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| reception_device_id | bigint | no | | |
| client_event_id | char(26) | no | | Device ULID |
| sequence_no | integer | no | | Device-local monotonic order; a batch must arrive ascending (else 422) |
| type | varchar(24) | no | | `register_patient`, `issue_serial`, `check_in`, `collect_cash`, `print_token`, `void_local`. Reserved, unused by OFFLINE.md v1 but kept in the CHECK: `mark_arrived`, `cancel_serial`, `assign_patient` |
| depends_on | char(26) | yes | | `client_event_id` of an earlier event this one needs (`issue_serial` → `register_patient`; `check_in`/`collect_cash`/`print_token` → `issue_serial`); an unresolved dependency leaves this row `pending` with `conflict_reason = 'dependency_unresolved'` |
| actor_user_id | bigint | yes | | Person who performed the action on the device (`X-Actor-User`), re-validated on replay |
| session_instance_id | bigint | yes | | |
| serial_block_id | bigint | yes | | |
| payload | jsonb | no | | Client's view of the action, stored verbatim (camelCase device keys; shapes below) |
| status | varchar(10) | no | `'pending'` | `pending`, `accepted`, `conflict`, `rejected` |
| conflict_reason | varchar(64) | yes | | `duplicate_patient`, `patient_mismatch`, `serial_already_used`, `session_closed`, `status_regression`, `already_paid`, `dependency_unresolved`, `block_released`, `unknown_serial`. Rejection codes are not conflicts: they go in `server_result.rejection` (`block_not_owned`, `block_unknown`, `actor_not_permitted`, `serial_not_found`, `session_not_found`, `payload_invalid`, `number_out_of_block_range`) |
| server_result | jsonb | yes | | What the server did, or the conflicting server state (returned verbatim on re-send) |
| resolution | varchar(24) | yes | | `link_patient`, `family_member`, `reissue`, `move_to_session`, `record_in_closed`, `reinstate`, `refund_cash`, `credit`, `discard` |
| resolution_params | jsonb | yes | | Parameters of the chosen resolution: `{"patient":"pat_…"}`, `{"holder_patient":"pat_…"}`, `{"session":"ses_…"}`, `{"reason":str}` |
| resolved_by_user_id | bigint | yes | | Receptionist / Hospital Admin who resolved the conflict (Hospital Admin PIN required for `record_in_closed` and cash `discard`) |
| attempts | smallint | no | `0` | Handler executions (replay passes + resolve passes) |
| client_occurred_at | timestamptz | no | | Device clock (advisory; drives `completed_at`/`paid_at` for post-close records and cash) |
| received_at | timestamptz | no | `now()` | |
| processed_at | timestamptz | yes | | Last handler outcome persisted |

**PK** id. **FK** reception_device_id → reception_devices(id) CASCADE; actor_user_id, resolved_by_user_id → users(id) SET NULL; session_instance_id → session_instances(id) SET NULL; serial_block_id → serial_blocks(id) SET NULL. **Unique** (reception_device_id, client_event_id) — the `INSERT … ON CONFLICT DO NOTHING` idempotency target. **Indexes** (reception_device_id, sequence_no); (reception_device_id, status); (status) WHERE status IN ('pending','conflict') `_p`; (depends_on) WHERE depends_on IS NOT NULL `_p`. **Checks** type, status, conflict_reason, resolution lists; `status <> 'conflict' OR conflict_reason IS NOT NULL`; `(resolution IS NULL) = (resolved_by_user_id IS NULL)`; `attempts >= 0`.
**JSON** `payload` by type (OFFLINE.md §6.1) — `register_patient`: `{"localId":str,"mobile":str,"name":str,"sex":"m|f|o"|null,"ageYears":int|null,"dob":date|null,"relationToHolder":str|null}`; `issue_serial`: `{"sessionId":str,"blockId":str,"number":int,"displayCode":str,"patientRef":"pat_…|local:…","priority":str,"walkIn":bool,"appointmentType":"new|followup","feeSnapshot":{"amount":int,"currency":"BDT"}}`; `check_in`: `{"serialRef":"ser_…|local:…"}`; `collect_cash`: `{"serialRef":str,"amount":int,"currency":"BDT","receiptNo":"D{device.number}-000123","note":str|null}`; `print_token`: `{"serialRef":str,"format":"58|80|a5","copies":int}`; `void_local`: `{"voidedClientEventId":str,"reason":str}`. `server_result` — accepted: `{"serial":{…SerialResource},"patient":{"public_id":str,"name":str},"payment":{"public_id":str,"receipt_no":str},"noop":bool,"reinstated":bool,"warning":"block_released"|null}` (keys by type); conflict: the per-reason shapes of OFFLINE.md §8 (`{"candidates":[…],"stub":{…}}`, `{"taken_by":{…},"suggested_next":int,"block_status":str}`, `{"session_status":str,"closed_at":ts,"alternatives":[…]}`, `{"cancelled_at":ts,"cancelled_by":str,"cancel_reason_code":str,"refund":{…}}`, `{"online_payment":{…},"cash":{…}}`); dependency: `{"depends_on":str}`; rejection: `{"rejection":code,"message":str}`.
**Model** `App\Models\Tenant\OfflineEvent`.

#### `queue_snapshots` — **not a table**
Decision: queue state for ETag polling lives in **Redis**, not Postgres (§5.7, REALTIME.md §4). No migration, no model. `App\Domain\Queue\Services\QueueStateRepository` is the only accessor.

---

### 3.4 Clinical / prescription (Module G, owner: Prescription team)

#### `visits`
**Purpose.** One clinical encounter (OPD or telemedicine) — the mutable working record the doctor edits until the prescription is issued. — **public_id: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| appointment_id | bigint | yes | | Null for an ad-hoc visit created from a bare serial |
| serial_id | bigint | yes | | |
| session_instance_id | bigint | yes | | |
| patient_id | bigint | no | | |
| doctor_id | bigint | no | | |
| branch_id | bigint | no | | |
| type | varchar(16) | no | `'opd'` | `opd`, `followup`, `telemedicine` |
| status | varchar(10) | no | `'open'` | `open`, `closed`, `cancelled` |
| started_at | timestamptz | no | `now()` | |
| ended_at | timestamptz | yes | | |
| chief_complaints | jsonb | no | `'[]'` | Structured; printed on the prescription |
| examination_findings | text | yes | | On-examination narrative; printed |
| diagnoses | jsonb | no | `'[]'` | ICD-10 coded, plain (analytics: top diagnoses) |
| private_notes | text | yes | | **ENC** doctor-only notes; never printed |
| follow_up_on | date | yes | | Drives auto-draft appointment + reminder |
| follow_up_note | varchar(255) | yes | | |
| current_prescription_id | bigint | yes | | Latest issued/draft prescription for this visit |
| closed_by_user_id | bigint | yes | | |

**PK** id. **FK** appointment_id → appointments(id) SET NULL; serial_id → serials(id) SET NULL; session_instance_id → session_instances(id) SET NULL; patient_id → patients(id) RESTRICT; doctor_id → doctors(id) RESTRICT; branch_id → branches(id) RESTRICT; current_prescription_id → prescriptions(id) SET NULL (**added after `prescriptions`**); closed_by_user_id → users(id) SET NULL. **Unique** public_id; (appointment_id) WHERE appointment_id IS NOT NULL `_p`. **Indexes** (patient_id, started_at DESC) (timeline); (doctor_id, started_at DESC); GIN (diagnoses jsonb_path_ops) (top-diagnosis reports). **Checks** type, status lists.
**JSON** `chief_complaints`: `[{"text":str,"text_bn":str|null,"duration":str|null,"sort":int}]`. `diagnoses`: `[{"icd10_code":str|null,"title":str,"kind":"provisional"|"final","sort":int}]` — `title` is a snapshot.
**Model** `App\Models\Tenant\Visit`.

#### `vitals`
**Purpose.** Vitals recorded by the compounder before the doctor; multiple sets per visit allowed (re-check). BMI computed in app.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| visit_id | bigint | no | | |
| patient_id | bigint | no | | Denormalised for trend charts |
| recorded_by_user_id | bigint | yes | | |
| recorded_at | timestamptz | no | `now()` | |
| bp_systolic | smallint | yes | | mmHg |
| bp_diastolic | smallint | yes | | |
| pulse_bpm | smallint | yes | | |
| temperature_c | numeric(4,1) | yes | | °C — the canonical clinical unit. Entered and displayed in °F at every human boundary (`App\Domain\Prescription\Support\Temperature`, PRESCRIPTION.md §4.2); never store °F |
| spo2_percent | smallint | yes | | |
| respiratory_rate | smallint | yes | | |
| weight_kg | numeric(5,2) | yes | | Drives pediatric mg/kg |
| height_cm | numeric(5,1) | yes | | |
| bmi | numeric(4,1) | yes | | `weight / (height/100)^2`, app-computed |
| blood_glucose_mgdl | smallint | yes | | |
| notes | varchar(255) | yes | | |
| edited_by_doctor | boolean | no | `false` | Doctor amended compounder entry |
| reviewed_by_doctor_at | timestamptz | yes | | Doctor ticked "Reviewed" (or made the first doctor edit); copied into `snapshot.visit.vitals` (PRESCRIPTION.md §4.2) |

**PK** id. **FK** visit_id → visits(id) CASCADE; patient_id → patients(id) CASCADE; recorded_by_user_id → users(id) SET NULL. **Indexes** (patient_id, recorded_at DESC). **Checks** `bp_systolic BETWEEN 40 AND 300`, `bp_diastolic BETWEEN 20 AND 200`, `pulse_bpm BETWEEN 20 AND 300`, `temperature_c BETWEEN 30 AND 45`, `spo2_percent BETWEEN 30 AND 100`, `weight_kg > 0`, `height_cm > 0` (each `OR IS NULL`). **Model** `App\Models\Tenant\Vital`.

#### `prescriptions`
**Purpose.** A versioned, immutable-once-issued prescription. `snapshot` is the **only** render source (§5.3). — **public_id: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| visit_id | bigint | no | | |
| patient_id | bigint | no | | |
| doctor_id | bigint | no | | |
| branch_id | bigint | no | | |
| tenant_id | bigint | no | | Isolation assertion (§5.9) |
| version | smallint | no | `1` | 1 for the original; +1 per amendment |
| root_prescription_id | bigint | yes | | Version 1's id (self for v1 — set after insert); groups the chain |
| supersedes_prescription_id | bigint | yes | | Immediate previous version |
| status | varchar(10) | no | `'draft'` | `draft`, `issued`, `amended` (superseded), `voided` |
| language | varchar(5) | no | `'both'` | `bn`, `en`, `both` |
| issued_at | timestamptz | yes | | Freeze moment |
| issued_by_user_id | bigint | yes | | |
| snapshot | jsonb | yes | | Complete render document; written once at issue (plain jsonb, §5.5) |
| snapshot_sha256 | char(64) | yes | | Hash of canonical `snapshot` JSON; printed in the footer with the QR |
| pad_snapshot | jsonb | yes | | Copy of `doctor_pad_settings` at issue |
| verification_code | varchar(12) | yes | | Crockford base32, in the QR URL `/rx/{verification_code}`; set at issue |
| handwriting_image_path | varchar(255) | yes | | Tablet handwriting PNG (S3 key) |
| drawing_json | jsonb | yes | | Vector strokes of the annotation area |
| drawing_image_path | varchar(255) | yes | | Rasterised drawing for PDF |
| pdf_path | varchar(255) | yes | | Rendered PDF (Browsershot); regenerated from `snapshot` only |
| pdf_generated_at | timestamptz | yes | | |
| amend_reason | varchar(255) | yes | | On the *new* version |
| voided_at | timestamptz | yes | | |
| voided_by_user_id | bigint | yes | | |
| void_reason | varchar(255) | yes | | |
| printed_count | smallint | no | `0` | |
| last_printed_at | timestamptz | yes | | |
| delivered_channels | jsonb | no | `'[]'` | `["sms","whatsapp","email"]` once sent |

**PK** id. **FK** visit_id → visits(id) RESTRICT; patient_id → patients(id) RESTRICT; doctor_id → doctors(id) RESTRICT; branch_id → branches(id) RESTRICT; tenant_id → public.tenants(id) RESTRICT; root_prescription_id, supersedes_prescription_id → prescriptions(id) RESTRICT; issued_by_user_id, voided_by_user_id → users(id) SET NULL. **Unique** public_id; (verification_code) WHERE NOT NULL `_p`; (root_prescription_id, version) WHERE root_prescription_id IS NOT NULL `_p`; (supersedes_prescription_id) WHERE NOT NULL `_p` (a version can be amended once); (visit_id) WHERE status='draft' `_p` (one draft per visit). **Indexes** (patient_id, issued_at DESC); (doctor_id, issued_at DESC); (visit_id). **Checks** status, language lists; `status='draft' OR (issued_at IS NOT NULL AND snapshot IS NOT NULL AND verification_code IS NOT NULL)`; `version >= 1`; `(version = 1) = (supersedes_prescription_id IS NULL)`.
**Trigger** `prescriptions_immutable_trg` (§5.3).
**JSON** `snapshot`: the base shape of §5.3.2 plus the additive `＋` keys of PRESCRIPTION.md §6.2 (row ids, `catalog_version`, `mode`, bilingual `display` strings per item, `investigations_total_paisa`, `follow_up.days/label`, `handwriting_pages`, `drawing_json`, `safety` alerts/overrides, `qr`, `rendered_by`); `snapshot_sha256` is sha256 of the canonical JSON (sorted keys, no whitespace). `pad_snapshot`: the `doctor_pad_settings` row at issue (§3.1), including `default_language`, `signature_path` and `layout.flags`. `drawing_json`: `{"canvas":{"w":int,"h":int,"template":"blank|dental_adult|dental_child|eye_pair|skeleton_front|body_front_back|spine|abdomen"},"strokes":[{"tool":"pen|marker|eraser","color":"#hex","width":num,"points":[[x,y,pressure],...]}],"texts":[{"x":num,"y":num,"text":str,"size":num}]}` — `texts` optional (PRESCRIPTION.md §4.11).
**Model** `App\Models\Tenant\Prescription`.

#### `prescription_items`
**Purpose.** Rx lines. Snapshot text columns are the print source; soft ids exist for analytics and safety re-checks only. Immutable once the parent is issued.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| prescription_id | bigint | no | | |
| sort_order | smallint | no | | |
| generic_id | bigint | yes | | Soft ref catalog.generics |
| brand_id | bigint | yes | | Soft ref catalog.brands |
| strength_id | bigint | yes | | Soft ref catalog.strengths |
| custom_brand_id | bigint | yes | | Tenant custom brand (exclusive with brand_id) |
| generic_name | varchar(160) | no | | Snapshot |
| brand_name | varchar(160) | yes | | Snapshot; null = prescribed by generic |
| strength | varchar(64) | yes | | Snapshot `500 mg` |
| form | varchar(48) | yes | | Snapshot `Tablet` |
| route | varchar(48) | yes | | Snapshot `Oral` |
| dose_schedule | varchar(32) | yes | | Shorthand `1+0+1`, `1+1+1+1`, `SOS` |
| dose_json | jsonb | no | `'{}'` | Parsed dose |
| duration_days | smallint | yes | | |
| duration_text | varchar(48) | yes | | `10 days`, `continue`, `১০ দিন` |
| quantity | numeric(8,2) | yes | | To dispense |
| quantity_unit | varchar(24) | yes | | `tab`, `cap`, `ml`, `bottle` |
| timing | varchar(8) | no | `'any'` | `before`, `after`, `with`, `any` |
| instruction | varchar(255) | yes | | Custom line (English) |
| instruction_bn | varchar(255) | yes | | Bangla line |
| info_url_slug | varchar(120) | yes | | Snapshot of `drug_information.public_slug` |
| is_continued | boolean | no | `false` | Long-term medication; feeds `patient_medications` |
| safety_overrides | jsonb | no | `'[]'` | Alerts the doctor acknowledged for this line |

**PK** id. **FK** prescription_id → prescriptions(id) CASCADE; custom_brand_id → custom_brands(id) RESTRICT. **Unique** (prescription_id, sort_order). **Indexes** (generic_id) (top-drug reports, reconcile); (brand_id) and (strength_id) (nightly `catalog:reconcile` scans, CATALOG.md §6); (custom_brand_id). **Checks** timing list; `NOT (brand_id IS NOT NULL AND custom_brand_id IS NOT NULL)`; `quantity IS NULL OR quantity > 0`.
**Trigger** `prescription_children_immutable_trg` (§5.3).
**JSON** `dose_json` is the complete **`ParsedLine`** of PRESCRIPTION.md §2.11 (v1) — the shorthand parser's output, produced identically by the PHP and TS parsers; the scalar columns `dose_schedule`, `duration_days`, `duration_text`, `quantity`, `quantity_unit`, `timing`, `route`, `instruction` are server-written projections of it. Top-level keys: `{"v":1,"raw":str,"normalized":str,"unit":"tab|cap|ml|tsp|tbsp|drop|puff|spray|sachet|amp|vial|unit|app|supp|neb|pessary","unit_inferred":bool,"schedule":{"type":"slots|frequency|interval|stat|sos",...}|null,"daily_total":num|null,"duration":{"type":"days|continuous|till_finish",...}|null,"timing":"before|after|with|any","timing_code":"af|bf|wf|em|hs"|null,"route_code":str|null,"quantity":{"value":num|null,"unit":str,"source":"auto|override|none","basis":str|null},"instruction":str|null,"issues":[{"code":str,"severity":"error|warning|info","token":str|null,"span":[int,int]|null,"message":str,"message_bn":str,"suggestion":str|null}]}`. `safety_overrides`: `[{"fingerprint":str,"kind":"interaction|allergy|duplicate|pediatric|max_dose|pregnancy|renal|hepatic|custom_brand|catalog","severity":"info|warning|critical","reason":str,"overridden_by_user_id":int,"overridden_at":ts}]` — keyed by the alert fingerprint; copied into `snapshot.safety.overrides` at issue (PRESCRIPTION.md §5.2).
**Model** `App\Models\Tenant\PrescriptionItem`.

#### `prescription_investigations`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| prescription_id | bigint | no | | |
| sort_order | smallint | no | | |
| investigation_catalog_id | bigint | yes | | Clinic test; null for free-text |
| name | varchar(200) | no | | Snapshot |
| name_bn | varchar(200) | yes | | |
| price_paisa | bigint | yes | | Snapshot of clinic price |
| external_diagnostic_centre_id | bigint | yes | | Referred out |
| referral_note | varchar(500) | yes | | |
| is_urgent | boolean | no | `false` | |

**PK** id. **FK** prescription_id → prescriptions(id) CASCADE; investigation_catalog_id → investigation_catalog(id) SET NULL; external_diagnostic_centre_id → external_diagnostic_centres(id) SET NULL. **Unique** (prescription_id, sort_order). **Trigger** `prescription_children_immutable_trg`. **Model** `App\Models\Tenant\PrescriptionInvestigation`.

#### `prescription_advice`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| prescription_id | bigint | no | | |
| sort_order | smallint | no | | |
| advice_snippet_id | bigint | yes | | |
| text | text | no | | Snapshot (English or as typed) |
| text_bn | text | yes | | |

**PK** id. **FK** prescription_id → prescriptions(id) CASCADE; advice_snippet_id → advice_snippets(id) SET NULL. **Unique** (prescription_id, sort_order). **Trigger** `prescription_children_immutable_trg`. **Model** `App\Models\Tenant\PrescriptionAdvice` (`$table='prescription_advice'`).

#### `prescription_referrals`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| prescription_id | bigint | no | | |
| type | varchar(20) | no | | `doctor`, `hospital`, `diagnostic_centre` |
| referred_to_doctor_id | bigint | yes | | Internal doctor |
| external_diagnostic_centre_id | bigint | yes | | |
| referred_to_name | varchar(200) | no | | Snapshot / external name |
| referred_to_specialty | varchar(120) | yes | | |
| note | text | yes | | Referral note (printed) |
| is_urgent | boolean | no | `false` | |

**PK** id. **FK** prescription_id → prescriptions(id) CASCADE; referred_to_doctor_id → doctors(id) SET NULL; external_diagnostic_centre_id → external_diagnostic_centres(id) SET NULL. **Checks** type list. **Trigger** `prescription_children_immutable_trg`. **Model** `App\Models\Tenant\PrescriptionReferral`.

#### `prescription_templates`
**Purpose.** Whole-prescription templates ("Common cold – adult"), per doctor or clinic-shared. — **soft delete: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| doctor_id | bigint | yes | | Null = clinic-shared |
| name | varchar(120) | no | | |
| shorthand | varchar(24) | yes | | Typed trigger e.g. `/cold` |
| icd10_code | varchar(8) | yes | | Suggested diagnosis |
| diagnosis_title | varchar(200) | yes | | |
| is_shared | boolean | no | `false` | Visible to all doctors |
| body | jsonb | no | `'{}'` | Non-Rx parts |
| use_count | integer | no | `0` | |
| last_used_at | timestamptz | yes | | |
| created_by_user_id | bigint | yes | | |

**PK** id. **FK** doctor_id → doctors(id) CASCADE; created_by_user_id → users(id) SET NULL. **Unique** (COALESCE(doctor_id,0), lower(name)) expression index `prescription_templates_owner_name_uniq` WHERE deleted_at IS NULL; (doctor_id, shorthand) WHERE shorthand IS NOT NULL `_p`.
**JSON** `body`: `{"chief_complaints":[...as visits],"examination_findings":str|null,"advice":[{"snippet_id":int|null,"text":str,"text_bn":str|null}],"investigations":[{"investigation_catalog_id":int|null,"name":str}],"follow_up_days":int|null}`.
**Model** `App\Models\Tenant\PrescriptionTemplate`.

#### `prescription_template_items`
Same columns as `prescription_items` **minus** `prescription_id`, `info_url_slug`, `safety_overrides`, plus `prescription_template_id bigint NOT NULL` (FK → prescription_templates(id) CASCADE). **Unique** (prescription_template_id, sort_order). Not immutable. `dose_json` is the same `ParsedLine`; on apply the line is re-parsed from `dose_json.normalized` against today's presentation and its catalog ids are re-validated (PRESCRIPTION.md §3.6). **Model** `App\Models\Tenant\PrescriptionTemplateItem`.

#### `doctor_favourites`
**Purpose.** Ranked quick-pick list per doctor per diagnosis (top-50), part pinned by the doctor and part learned from `doctor_drug_usage` (nightly recompute keeps pinned rows, rewrites `is_pinned=false` rows).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| doctor_id | bigint | no | | |
| icd10_code | varchar(8) | yes | | Null = global favourites |
| generic_id | bigint | no | | Soft ref (identity of the favourite) |
| brand_id | bigint | yes | | Soft ref preferred brand |
| custom_brand_id | bigint | yes | | |
| strength_id | bigint | yes | | Soft ref |
| label | varchar(200) | no | | Display snapshot `Napa 500 mg Tab` |
| default_dose | jsonb | no | `'{}'` | `{"dose_schedule":str,"duration_days":int\|null,"timing":str,"instruction":str\|null,"shorthand":str}` — `shorthand` = `dose_json.normalized`, offered as the ghost suggestion after the drug chip (PRESCRIPTION.md §3.3, §3.5) |
| is_pinned | boolean | no | `false` | Doctor-curated |
| use_count | integer | no | `0` | |
| rank | smallint | no | `0` | 1 = top |
| last_used_at | timestamptz | yes | | |

**PK** id. **FK** doctor_id → doctors(id) CASCADE; custom_brand_id → custom_brands(id) CASCADE. **Unique** expression index (doctor_id, COALESCE(icd10_code,''), generic_id, COALESCE(brand_id,0), COALESCE(custom_brand_id,0)) `doctor_favourites_identity_uniq`. **Indexes** (doctor_id, icd10_code, rank). **Model** `App\Models\Tenant\DoctorFavourite`.

#### `doctor_drug_usage`
**Purpose.** Learning table: monthly counts of what each doctor prescribes per diagnosis. Incremented at issue. — **timestamps: updated_at only** (+ created_at).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| doctor_id | bigint | no | | |
| icd10_code | varchar(8) | yes | | Primary final diagnosis of the visit; null if none |
| generic_id | bigint | no | | |
| brand_id | bigint | yes | | |
| custom_brand_id | bigint | yes | | |
| strength_id | bigint | yes | | |
| period_month | date | no | | First day of month (tenant tz) |
| use_count | integer | no | `0` | |
| last_dose | jsonb | no | `'{}'` | Same shape as `doctor_favourites.default_dose` (incl. `shorthand`) |
| last_used_at | timestamptz | no | | |

**PK** id. **FK** doctor_id → doctors(id) CASCADE; custom_brand_id → custom_brands(id) CASCADE. **Unique** expression index (doctor_id, COALESCE(icd10_code,''), generic_id, COALESCE(brand_id,0), COALESCE(custom_brand_id,0), COALESCE(strength_id,0), period_month) `doctor_drug_usage_identity_uniq`. **Indexes** (doctor_id, period_month). **Model** `App\Models\Tenant\DoctorDrugUsage`.

#### `advice_snippets`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| doctor_id | bigint | yes | | Null = clinic library |
| shorthand | varchar(24) | yes | | e.g. `/rest` |
| category | varchar(32) | yes | | `diet`, `lifestyle`, `warning`, `followup`, `general`, `finding` (O/E quick-pick), `complaint` (C/C quick-pick) — PRESCRIPTION.md §4.1, §4.3 |
| text | text | no | | English |
| text_bn | text | yes | | Bangla |
| is_shared | boolean | no | `false` | |
| use_count | integer | no | `0` | |
| is_active | boolean | no | `true` | |

**PK** id. **FK** doctor_id → doctors(id) CASCADE. **Unique** (COALESCE(doctor_id,0), shorthand) expression index WHERE shorthand IS NOT NULL. **Checks** category list. **Model** `App\Models\Tenant\AdviceSnippet`.

#### `investigation_catalog`
**Purpose.** The clinic's own priced test/imaging list (no lab workflow).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| branch_id | bigint | yes | | Null = all branches |
| code | varchar(24) | yes | | |
| name | varchar(200) | no | | |
| name_bn | varchar(200) | yes | | |
| category | varchar(16) | no | `'lab'` | `lab`, `imaging`, `procedure`, `other` |
| price_paisa | bigint | no | `0` | |
| prep_instructions | varchar(500) | yes | | "12 h fasting" |
| prep_instructions_bn | varchar(500) | yes | | |
| is_active | boolean | no | `true` | |
| sort_order | smallint | no | `0` | |

**PK** id. **FK** branch_id → branches(id) CASCADE. **Unique** (COALESCE(branch_id,0), lower(name)) expression index. **Checks** category list; `price_paisa >= 0`. **Model** `App\Models\Tenant\InvestigationCatalogItem` (`$table='investigation_catalog'`).

#### `external_diagnostic_centres`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| name | varchar(200) | no | | |
| address | text | yes | | |
| phone | varchar(20) | yes | | |
| contact_person | varchar(120) | yes | | |
| notes | varchar(255) | yes | | |
| is_active | boolean | no | `true` | |

**PK** id. **Model** `App\Models\Tenant\ExternalDiagnosticCentre`.

#### `custom_brands`
**Purpose.** Tenant-added brands missing from the master catalog. **`generic_id` is NOT NULL** — a brand without a molecule is unrepresentable and therefore unusable in a prescription. — **soft delete: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| generic_id | bigint | no | | Soft ref catalog.generics — validated to exist before insert |
| generic_name | varchar(160) | no | | Snapshot |
| brand_name | varchar(160) | no | | |
| manufacturer | varchar(160) | yes | | |
| strength | varchar(64) | yes | | `500 mg` |
| dosage_form_id | bigint | yes | | Soft ref |
| form | varchar(48) | yes | | Snapshot |
| route_id | bigint | yes | | Soft ref |
| route | varchar(48) | yes | | Snapshot |
| review_status | varchar(10) | no | `'pending'` | `pending`, `approved`, `rejected`, `promoted` |
| promoted_to_master | boolean | no | `false` | |
| master_brand_id | bigint | yes | | Soft ref catalog.brands after promotion |
| master_strength_id | bigint | yes | | |
| review_note | varchar(255) | yes | | Super-admin note |
| reviewed_at | timestamptz | yes | | |
| created_by_user_id | bigint | yes | | |
| use_count | integer | no | `0` | |
| is_active | boolean | no | `true` | |

**PK** id. **FK** created_by_user_id → users(id) SET NULL. **Unique** (lower(brand_name), COALESCE(strength,''), COALESCE(form,'')) expression index WHERE deleted_at IS NULL. **Indexes** (generic_id); (review_status) WHERE review_status='pending' `_p`. **Checks** review_status list; `promoted_to_master = (review_status = 'promoted')`; `NOT promoted_to_master OR master_brand_id IS NOT NULL`. Creating a row is the submission: the `CustomBrandCreated` listener writes the cross-tenant queue row `public.custom_brand_promotions` (§2.18); the super-admin decision is written back here (`review_status`, `master_brand_id`, `master_strength_id`, `reviewed_at`, `review_note`) and the search document re-indexed (CATALOG.md §8). The nightly reconcile flips `is_active = false` with `review_note = 'generic missing in catalog {version}'` when the generic vanished. **Model** `App\Models\Tenant\CustomBrand` (`Searchable`).

#### `ai_suggestions`
**Purpose.** Every AI-assist call and whether the doctor accepted it. Advisory only; never auto-inserted. — **timestamps: created_at only**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| visit_id | bigint | no | | |
| doctor_id | bigint | no | | |
| patient_id | bigint | no | | |
| type | varchar(24) | no | | `history_summary`, `differential`, `advice`, `other` |
| provider | varchar(32) | no | | e.g. `anthropic` |
| model | varchar(64) | no | | Model id used |
| prompt | text | no | | **ENC** full prompt incl. patient context |
| response | text | no | | **ENC** |
| accepted | boolean | yes | | Null until the doctor acts |
| accepted_at | timestamptz | yes | | |
| accepted_fragment | text | yes | | **ENC** the part actually used, if partial |
| input_tokens | integer | yes | | |
| output_tokens | integer | yes | | |
| latency_ms | integer | yes | | |
| request_id | varchar(64) | yes | | Provider request id |

**PK** id. **FK** visit_id → visits(id) CASCADE; doctor_id → doctors(id) CASCADE; patient_id → patients(id) CASCADE. **Indexes** (doctor_id, created_at DESC). **Checks** type list. Counted in `usage_counters.ai_requests`. **Model** `App\Models\Tenant\AiSuggestion`.

---

### 3.5 Billing (Module I, owner: Billing team)

#### `invoices`
**Purpose.** Patient-facing bill for an appointment/visit (consultation + investigations). — **public_id: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| tenant_id | bigint | no | | Isolation assertion (§5.9) |
| number | varchar(24) | no | | `INV-2026-000045` from `invoice_number_seq` |
| patient_id | bigint | no | | |
| appointment_id | bigint | yes | | |
| visit_id | bigint | yes | | |
| doctor_id | bigint | yes | | Primary doctor for revenue share |
| branch_id | bigint | no | | |
| status | varchar(16) | no | `'draft'` | `draft`, `issued`, `partially_paid`, `paid`, `void`, `refunded` |
| subtotal_paisa | bigint | no | `0` | Sum of line totals |
| discount_paisa | bigint | no | `0` | Sum of `discounts` rows |
| coupon_discount_paisa | bigint | no | `0` | From `coupon_redemptions` |
| vat_paisa | bigint | no | `0` | |
| total_paisa | bigint | no | `0` | subtotal − discount − coupon + vat |
| paid_paisa | bigint | no | `0` | Sum of succeeded payments − refunds |
| due_paisa | bigint | no | | `GENERATED ALWAYS AS (total_paisa - paid_paisa) STORED` |
| issued_at | timestamptz | yes | | |
| due_at | timestamptz | yes | | |
| paid_at | timestamptz | yes | | Fully paid |
| voided_at | timestamptz | yes | | |
| void_reason | varchar(255) | yes | | |
| created_by_user_id | bigint | yes | | |
| cash_shift_id | bigint | yes | | Shift open when issued |
| notes | varchar(255) | yes | | |

**PK** id. **FK** tenant_id → public.tenants(id) RESTRICT; patient_id → patients(id) RESTRICT; appointment_id → appointments(id) SET NULL; visit_id → visits(id) SET NULL; doctor_id → doctors(id) SET NULL; branch_id → branches(id) RESTRICT; created_by_user_id → users(id) SET NULL; cash_shift_id → cash_shifts(id) SET NULL. **Unique** public_id; number; (appointment_id) WHERE appointment_id IS NOT NULL AND status <> 'void' `_p`. **Indexes** (patient_id, issued_at DESC); (branch_id, issued_at); (doctor_id, issued_at); (status) WHERE due_paisa > 0 `_p` (dues report). **Checks** status list; all `_paisa >= 0`; `paid_paisa <= total_paisa`. **Model** `App\Models\Tenant\Invoice`.

#### `invoice_items`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| invoice_id | bigint | no | | |
| sort_order | smallint | no | | |
| type | varchar(16) | no | | `consultation`, `followup`, `investigation`, `telemedicine`, `other` |
| description | varchar(200) | no | | Printed |
| reference_type | varchar(160) | yes | | Morph: InvestigationCatalogItem / Appointment |
| reference_id | bigint | yes | | |
| doctor_id | bigint | yes | | Earning doctor for this line |
| quantity | smallint | no | `1` | |
| unit_price_paisa | bigint | no | | |
| line_total_paisa | bigint | no | | quantity × unit price |
| doctor_revenue_share_id | bigint | yes | | Rule applied |
| doctor_share_paisa | bigint | no | `0` | Snapshot of the split |
| clinic_share_paisa | bigint | no | `0` | |

**PK** id. **FK** invoice_id → invoices(id) CASCADE; doctor_id → doctors(id) SET NULL; doctor_revenue_share_id → doctor_revenue_shares(id) SET NULL. **Unique** (invoice_id, sort_order). **Indexes** (doctor_id) (share reports). **Checks** type list; `quantity > 0`; `line_total_paisa = quantity * unit_price_paisa`; `doctor_share_paisa + clinic_share_paisa <= line_total_paisa`. **Model** `App\Models\Tenant\InvoiceItem`.

#### `payments`
**Purpose.** Money received against an invoice. — **public_id: yes**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| tenant_id | bigint | no | | Isolation assertion (§5.9) |
| invoice_id | bigint | no | | |
| patient_id | bigint | no | | |
| receipt_number | varchar(24) | yes | | `RCT-2026-000101` from `receipt_number_seq`, set when succeeded. Offline cash replays keep the device-printed `D{device.number}-000123` (OFFLINE.md §6.1, unique clinic-wide by construction) and do not consume the sequence |
| method | varchar(16) | no | | `cash`, `card`, `bkash`, `nagad`, `sslcommerz`, `other` |
| status | varchar(20) | no | `'pending'` | `pending`, `succeeded`, `failed`, `cancelled`, `refunded`, `partially_refunded` |
| amount_paisa | bigint | no | | |
| refunded_paisa | bigint | no | `0` | |
| gateway | varchar(16) | yes | | `bkash`, `nagad`, `sslcommerz` |
| gateway_txn_id | varchar(128) | yes | | |
| gateway_payment_ref | varchar(128) | yes | | Merchant reference sent to the gateway |
| gateway_payload | jsonb | no | `'{}'` | Callback/verify response |
| idempotency_key | varchar(64) | no | | Client-generated; guards double posting (incl. offline replay) |
| client_event_id | char(26) | yes | | Offline `collect_cash` event id; the replay also sets `idempotency_key` to it |
| received_by_user_id | bigint | yes | | Counter staff |
| reception_device_id | bigint | yes | | |
| cash_shift_id | bigint | yes | | |
| paid_at | timestamptz | yes | | |
| failed_reason | varchar(255) | yes | | |

**PK** id. **FK** tenant_id → public.tenants(id) RESTRICT; invoice_id → invoices(id) RESTRICT; patient_id → patients(id) RESTRICT; received_by_user_id → users(id) SET NULL; reception_device_id → reception_devices(id) SET NULL; cash_shift_id → cash_shifts(id) SET NULL. **Unique** public_id; idempotency_key; (reception_device_id, client_event_id) WHERE client_event_id IS NOT NULL `_p`; (receipt_number) WHERE NOT NULL `_p`; (gateway, gateway_txn_id) WHERE gateway_txn_id IS NOT NULL `_p`. **Indexes** (invoice_id); (cash_shift_id); (paid_at) (daily collection). **Checks** method, status, gateway lists; `amount_paisa > 0`; `refunded_paisa BETWEEN 0 AND amount_paisa`; `(method IN ('bkash','nagad','sslcommerz')) = (gateway IS NOT NULL)`. **Model** `App\Models\Tenant\Payment`.

#### `refunds`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| payment_id | bigint | no | | |
| invoice_id | bigint | no | | |
| amount_paisa | bigint | no | | |
| method | varchar(16) | no | | Same list as payments.method |
| status | varchar(12) | no | `'pending'` | `pending`, `approved`, `processed`, `rejected`, `failed` |
| reason_code | varchar(24) | no | | `doctor_absent`, `patient_cancelled`, `duplicate`, `service_not_rendered`, `goodwill`, `other` |
| reason_note | varchar(255) | yes | | |
| gateway_refund_id | varchar(128) | yes | | |
| gateway_payload | jsonb | no | `'{}'` | |
| requested_by_user_id | bigint | yes | | |
| approved_by_user_id | bigint | yes | | |
| cash_shift_id | bigint | yes | | Cash refunds reduce the shift |
| processed_at | timestamptz | yes | | |

**PK** id. **FK** payment_id → payments(id) RESTRICT; invoice_id → invoices(id) RESTRICT; requested_by_user_id, approved_by_user_id → users(id) SET NULL; cash_shift_id → cash_shifts(id) SET NULL. **Indexes** (invoice_id); (status) WHERE status='pending' `_p`. **Checks** status, reason_code, method lists; `amount_paisa > 0`. Offline devices may never create refunds (enforced in app). **Model** `App\Models\Tenant\Refund`.

#### `discounts`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| invoice_id | bigint | no | | |
| type | varchar(12) | no | | `percentage`, `fixed` |
| value | numeric(10,2) | no | | Percent or BDT-paisa as entered |
| amount_paisa | bigint | no | | Resolved amount |
| reason_code | varchar(24) | no | | `staff`, `poor_fund`, `followup`, `doctor_waiver`, `promo`, `other` |
| note | varchar(255) | yes | | |
| applied_by_user_id | bigint | yes | | |
| approved_by_user_id | bigint | yes | | Required above `settings.billing.discount_approval_threshold_paisa` |

**PK** id. **FK** invoice_id → invoices(id) CASCADE; applied_by_user_id, approved_by_user_id → users(id) SET NULL. **Checks** type, reason_code lists; `amount_paisa >= 0`. **Model** `App\Models\Tenant\Discount`.

#### `coupons`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| code | varchar(32) | no | | Upper-case |
| name | varchar(120) | no | | |
| type | varchar(12) | no | | `percentage`, `fixed` |
| value | numeric(10,2) | no | | |
| max_discount_paisa | bigint | yes | | Cap for percentage coupons |
| min_invoice_paisa | bigint | no | `0` | |
| max_uses | integer | yes | | Null = unlimited |
| max_uses_per_patient | smallint | no | `1` | |
| uses_count | integer | no | `0` | |
| applies_to | jsonb | no | `'{}'` | `{"doctor_ids":[int],"branch_ids":[int],"item_types":["consultation",...],"channels":["online",...]}` — empty arrays = any |
| valid_from | timestamptz | yes | | |
| valid_until | timestamptz | yes | | |
| is_active | boolean | no | `true` | |
| created_by_user_id | bigint | yes | | |

**PK** id. **FK** created_by_user_id → users(id) SET NULL. **Unique** code. **Checks** type list; `code = upper(code)`. **Model** `App\Models\Tenant\Coupon`.

#### `coupon_redemptions`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| coupon_id | bigint | no | | |
| invoice_id | bigint | no | | |
| patient_id | bigint | no | | |
| amount_paisa | bigint | no | | |
| coupon_use_seq | integer | no | | 1-based ordinal of this redemption within the coupon |
| patient_use_seq | integer | no | | 1-based ordinal within (coupon, patient) |

**PK** id. **FK** coupon_id → coupons(id) RESTRICT; invoice_id → invoices(id) CASCADE; patient_id → patients(id) CASCADE. **Unique** invoice_id; (coupon_id, coupon_use_seq); (coupon_id, patient_id, patient_use_seq). **Indexes** (coupon_id, patient_id). **Checks** `coupon_use_seq >= 1`; `patient_use_seq >= 1`. **timestamps: created_at only.** **Model** `App\Models\Tenant\CouponRedemption`.

The two ordinal uniques are what actually enforce `coupons.max_uses` and `max_uses_per_patient`. `ApplyCoupon` locks the `coupons` row `FOR UPDATE` (the owner row, SERIAL_ENGINE §4 invariant I-OWNER), counts the existing rows inside that lock, and writes `count + 1` — only when it is still within the cap. Unique ordinals therefore bound the row count by the cap even if the lock were lost, and `coupons.uses_count` is assigned the ordinal rather than incremented, so the cached counter cannot lose an update. `coupon_redemptions_invoice_id_uniq` guarantees one coupon per bill and nothing about the caps.

#### `doctor_revenue_shares`
**Purpose.** Commission rules; the applicable rule is snapshotted onto `invoice_items` at issue.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| doctor_id | bigint | no | | |
| branch_id | bigint | yes | | Null = all branches |
| item_type | varchar(16) | no | `'all'` | `consultation`, `followup`, `investigation`, `telemedicine`, `all` |
| share_type | varchar(12) | no | | `percentage`, `fixed` |
| share_value | numeric(10,2) | no | | Doctor's percent or fixed paisa per item |
| effective_from | date | no | | |
| effective_to | date | yes | | |
| is_active | boolean | no | `true` | |
| created_by_user_id | bigint | yes | | |

**PK** id. **FK** doctor_id → doctors(id) CASCADE; branch_id → branches(id) CASCADE; created_by_user_id → users(id) SET NULL. **Indexes** (doctor_id, item_type, effective_from). **Checks** item_type, share_type lists; `share_type <> 'percentage' OR share_value BETWEEN 0 AND 100`. Rule resolution: most specific (`branch_id` set, exact `item_type`) wins; newest `effective_from` on ties. **Model** `App\Models\Tenant\DoctorRevenueShare`.

#### `cash_shifts`
**Purpose.** Cash-drawer shift per receptionist; close report compares expected vs collected.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| user_id | bigint | no | | Receptionist |
| branch_id | bigint | no | | |
| status | varchar(8) | no | `'open'` | `open`, `closed` |
| opened_at | timestamptz | no | `now()` | |
| closed_at | timestamptz | yes | | |
| opening_float_paisa | bigint | no | `0` | |
| expected_cash_paisa | bigint | yes | | Computed at close: float + cash payments − cash refunds |
| counted_cash_paisa | bigint | yes | | Entered by receptionist |
| variance_paisa | bigint | yes | | counted − expected |
| card_total_paisa | bigint | yes | | Informational totals at close |
| mobile_money_total_paisa | bigint | yes | | |
| closing_note | varchar(255) | yes | | |
| closed_by_user_id | bigint | yes | | Supervisor if different |

**PK** id. **FK** user_id → users(id) RESTRICT; branch_id → branches(id) RESTRICT; closed_by_user_id → users(id) SET NULL. **Unique** (user_id) WHERE status='open' `_p`. **Indexes** (branch_id, opened_at DESC). **Checks** status list. **Model** `App\Models\Tenant\CashShift`.

### 3.6 Notifications (Module J, owner: Notifications team)

#### `notification_templates`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| event_key | varchar(32) | no | | `booking_confirmed`, `reminder_day_before`, `reminder_morning`, `three_ahead`, `doctor_delayed`, `doctor_cancelled`, `prescription_ready`, `followup_due`, `otp`, `payment_receipt`, `serial_transferred`, `serial_postponed`, `telemedicine_invite` |
| channel | varchar(10) | no | | `sms`, `whatsapp`, `push`, `email`, `ivr` |
| locale | varchar(5) | no | | `bn`, `en` |
| subject | varchar(160) | yes | | email/push title |
| body | text | no | | Placeholders `{{patient_name}}`, `{{serial}}`, `{{doctor}}`, `{{eta}}`, `{{link}}` … (Bangla Unicode allowed) |
| provider_template_id | varchar(64) | yes | | WhatsApp approved template name / IVR flow id |
| is_active | boolean | no | `true` | |
| updated_by_user_id | bigint | yes | | |

**PK** id. **FK** updated_by_user_id → users(id) SET NULL. **Unique** (event_key, channel, locale). **Checks** event_key, channel, locale lists. **Model** `App\Models\Tenant\NotificationTemplate`.

#### `notifications`
**Purpose.** Outbound message queue/ledger (one row per recipient per channel per event).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| event_key | varchar(32) | no | | Same list as templates |
| channel | varchar(10) | no | | |
| patient_id | bigint | yes | | |
| user_id | bigint | yes | | Staff recipient (e.g. doctor delay ack) |
| notifiable_type | varchar(160) | yes | | Morph: Appointment / Prescription / SessionInstance / Invoice |
| notifiable_id | bigint | yes | | |
| serial_id | bigint | yes | | Serial the message concerns (`three_ahead`, `doctor_delayed`, `serial_transferred`, `serial_postponed`, `telemedicine_invite`); drives per-serial dedupe |
| notification_template_id | bigint | yes | | |
| recipient | varchar(255) | no | | E.164 / email / push endpoint id |
| locale | varchar(5) | no | | |
| subject | varchar(160) | yes | | |
| body | text | no | | Rendered |
| payload | jsonb | no | `'{}'` | Channel extras: `{"link":str,"template_params":[...],"ivr_flow":str}` |
| status | varchar(10) | no | `'queued'` | `queued`, `scheduled`, `sending`, `sent`, `delivered`, `failed`, `cancelled` |
| scheduled_for | timestamptz | yes | | Reminders |
| sent_at | timestamptz | yes | | |
| delivered_at | timestamptz | yes | | Provider DLR |
| attempts | smallint | no | `0` | |
| last_error | varchar(255) | yes | | |
| dedupe_key | varchar(120) | yes | | e.g. `three_ahead:{serial_id}`, `doctor_delayed:{serial_id}:{delay bucket of queue.delay_notify_min_change minutes}` — prevents duplicate sends (REALTIME.md §7, §10) |
| segments | smallint | yes | | SMS parts (Bangla = 70 chars/segment) — billed to `usage_counters.sms_credits` |
| cost_paisa | bigint | yes | | |

**PK** id. **FK** patient_id → patients(id) SET NULL; user_id → users(id) SET NULL; serial_id → serials(id) SET NULL; notification_template_id → notification_templates(id) SET NULL. **Unique** (dedupe_key) WHERE NOT NULL `_p`. **Indexes** (status, scheduled_for) WHERE status IN ('queued','scheduled') `_p` (dispatcher); (patient_id, created_at DESC); (notifiable_type, notifiable_id); (serial_id, event_key, created_at DESC) (delay-notification bucket dedupe, REALTIME.md §10 — "did this serial get a `doctor_delayed` in the last `queue.delay_notify_min_change` minutes?"). **Checks** event_key, channel, status lists. **Model** `App\Models\Tenant\Notification` (not Laravel's `DatabaseNotification`).

#### `notification_logs`
**Purpose.** One row per delivery attempt with the provider exchange. Per-serial dedupe queries go to `notifications (serial_id, event_key, created_at)`, **not** here — this table has no serial or template columns. — **timestamps: created_at only**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| notification_id | bigint | no | | |
| attempt_no | smallint | no | | |
| provider | varchar(32) | no | | Gateway key used |
| provider_message_id | varchar(128) | yes | | For DLR matching |
| status | varchar(10) | no | | `sent`, `delivered`, `failed`, `rejected` |
| request | jsonb | yes | | Sanitised outbound payload (no credentials) |
| response | jsonb | yes | | Raw provider response |
| error_code | varchar(64) | yes | | |
| latency_ms | integer | yes | | |

**PK** id. **FK** notification_id → notifications(id) CASCADE. **Unique** (notification_id, attempt_no); (provider, provider_message_id) WHERE provider_message_id IS NOT NULL `_p`. **Checks** status list. **Model** `App\Models\Tenant\NotificationLog`.

#### `sms_gateway_settings`
**Purpose.** Per-tenant provider credentials for SMS / WhatsApp / IVR.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| channel | varchar(10) | no | `'sms'` | `sms`, `whatsapp`, `ivr` |
| provider | varchar(32) | no | | `ssl_wireless`, `bulksmsbd`, `grameenphone`, `banglalink`, `robi`, `infobip`, `twilio`, `whatsapp_cloud`, `custom_http` |
| name | varchar(80) | no | | Label |
| sender_id | varchar(20) | yes | | Masking / phone number id |
| credentials | text | no | | **ENC** JSON `{"api_key":str,"secret":str,"username":str,"url":str,...}` (`encrypted:array`) |
| options | jsonb | no | `'{}'` | Non-secret: `{"unicode":true,"dlr_callback":bool,"rate_limit_per_sec":int}` |
| priority | smallint | no | `10` | Lower = preferred |
| is_default | boolean | no | `false` | |
| is_active | boolean | no | `true` | |
| balance_paisa | bigint | yes | | Last known provider balance |
| balance_checked_at | timestamptz | yes | | |
| updated_by_user_id | bigint | yes | | |

**PK** id. **FK** updated_by_user_id → users(id) SET NULL. **Unique** (channel) WHERE is_default `_p`. **Checks** channel, provider lists. **Model** `App\Models\Tenant\SmsGatewaySetting`.

#### `push_subscriptions`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| subscriber_type | varchar(160) | no | | Morph: User / Patient |
| subscriber_id | bigint | no | | |
| endpoint | text | no | | Web Push endpoint |
| endpoint_hash | char(64) | no | | sha256(endpoint) for uniqueness |
| keys | text | no | | **ENC** JSON `{"p256dh":str,"auth":str}` |
| content_encoding | varchar(16) | no | `'aes128gcm'` | |
| user_agent | text | yes | | |
| last_used_at | timestamptz | yes | | |
| failed_count | smallint | no | `0` | Pruned after 5 consecutive failures |

**PK** id. **Unique** endpoint_hash. **Indexes** (subscriber_type, subscriber_id). **Model** `App\Models\Tenant\PushSubscription`.

### 3.7 Security & audit (Module N, owner: Platform team)

#### `audit_logs`
**Purpose.** Every clinical record view/edit/print/export, every serial reorder, every login. Append-only (app role has no UPDATE/DELETE). — **timestamps: none** (`occurred_at`).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| tenant_id | bigint | no | | Isolation assertion (§5.9) |
| actor_type | varchar(12) | no | | `user`, `patient`, `device`, `system`, `super_admin` |
| actor_id | bigint | yes | | id in the actor's table (super_admin = public.super_admins.id during impersonation) |
| impersonator_super_admin_id | bigint | yes | | Set when a super admin acts as a tenant user |
| action | varchar(16) | no | | `view`, `create`, `update`, `delete`, `print`, `export`, `reorder`, `issue`, `amend`, `void`, `check_in`, `transfer`, `login`, `logout`, `download`, `share`, `refund` |
| auditable_type | varchar(160) | no | | Morph class |
| auditable_id | bigint | no | | |
| patient_id | bigint | yes | | Denormalised: "who accessed this patient's data" |
| before | jsonb | yes | | Changed attributes before (ENC columns logged as `"[encrypted]"`) |
| after | jsonb | yes | | |
| context | jsonb | no | `'{}'` | `{"route":str,"reason":str\|null,"serial_id":int\|null}` |
| ip | inet | yes | | |
| user_agent | text | yes | | |
| request_id | char(26) | yes | | |
| occurred_at | timestamptz | no | `now()` | |

**PK** id. **FK** tenant_id → public.tenants(id) RESTRICT; patient_id → patients(id) SET NULL. No FK on actor_id (polymorphic). **Indexes** (auditable_type, auditable_id, occurred_at DESC); (patient_id, occurred_at DESC); (actor_type, actor_id, occurred_at DESC); BRIN (occurred_at). **Checks** actor_type, action lists. Plan: convert to monthly range partitions on `occurred_at` when a tenant exceeds ~20 M rows; the API is unchanged. **Model** `App\Models\Tenant\AuditLog`.

### 3.8 Telemedicine (Module K, owner: Telemedicine team)

#### `telemedicine_rooms`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| appointment_id | bigint | no | | |
| provider | varchar(10) | no | | `agora`, `livekit`, `jitsi` |
| room_name | varchar(120) | no | | Provider room id; never guessable (`t{id}-{ulid}`) |
| status | varchar(10) | no | `'scheduled'` | `scheduled`, `open`, `ended`, `cancelled` |
| scheduled_at | timestamptz | no | | |
| opened_at | timestamptz | yes | | |
| ended_at | timestamptz | yes | | |
| doctor_join_url_expires_at | timestamptz | yes | | Tokens are minted on demand, never stored |
| patient_join_url_expires_at | timestamptz | yes | | |
| settings | jsonb | no | `'{}'` | `{"recording":bool,"max_minutes":int}` |

**PK** id. **FK** appointment_id → appointments(id) CASCADE. **Unique** appointment_id; room_name. **Checks** provider, status lists. **Model** `App\Models\Tenant\TelemedicineRoom`.

#### `telemedicine_sessions`
**Purpose.** One row per actual call attempt inside a room (reconnects create new rows). Ends in the normal `visits`/`prescriptions` flow.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| telemedicine_room_id | bigint | no | | |
| visit_id | bigint | yes | | |
| started_at | timestamptz | no | | |
| ended_at | timestamptz | yes | | |
| duration_seconds | integer | yes | | Billed to `usage_counters.telemedicine_minutes` |
| end_reason | varchar(10) | yes | | `completed`, `dropped`, `no_show`, `cancelled` |
| participants | jsonb | no | `'[]'` | `[{"role":"doctor\|patient","joined_at":ts,"left_at":ts\|null,"device":str}]` |
| quality | jsonb | yes | | Provider stats `{"avg_bitrate_kbps":int,"packet_loss_pct":num}` |
| recording_path | text | yes | | **ENC** storage key of the recording, if consented |
| provider_session_id | varchar(128) | yes | | |

**PK** id. **FK** telemedicine_room_id → telemedicine_rooms(id) CASCADE; visit_id → visits(id) SET NULL. **Indexes** (telemedicine_room_id, started_at). **Checks** end_reason list. **Model** `App\Models\Tenant\TelemedicineSession`.

---

## 4. `catalog` database — shared clinical reference (owner: Catalog team)

All models extend `App\Models\Catalog\CatalogModel` (`$connection = 'catalog'`, read-only at runtime). Every table carries `catalog_version_id bigint NULL` (FK → catalog_versions(id) SET NULL, the DGDA release that last touched the row) and `is_active boolean NOT NULL DEFAULT true`; both are omitted from the column tables below. Rows are **never deleted** (soft references from tenants must keep resolving); deactivate instead. Every table has `created_at`/`updated_at` (`timestampsTz()`, written by the importer under the `catalog_admin` role — `CatalogModel::$timestamps = true`; CATALOG.md's idempotency test compares `updated_at`). Lookup keys (`slug`, `code`) are `varchar` with `lower()`/plain unique indexes — `citext` is **not** used. Writes happen only inside `CatalogWriteContext::run()` on the `catalog_admin` connection (CATALOG.md §1); migrations live in `database/migrations/catalog/` and are recorded in the catalog database's own `public.migrations`. Extensions: `pg_trgm`.

#### `generics`
**Purpose.** Molecule/salt. **All safety logic keys on `generics.id`.**

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| name | varchar(160) | no | | INN, e.g. `Paracetamol` |
| slug | varchar(160) | no | | |
| atc_code | varchar(8) | yes | | WHO ATC |
| aliases | jsonb | no | `'[]'` | `["Acetaminophen"]` — searchable |
| therapeutic_class | varchar(120) | yes | | |
| is_controlled | boolean | no | `false` | Narcotic/psychotropic flag (printed warning) |
| is_pediatric_weight_based | boolean | no | `false` | Show mg/kg calculator |
| name_bn | varchar(200) | yes | | Bangla name; never overwritten by DGDA rows |
| components | jsonb | yes | | Combination products: `[{"generic_id":int,"mg":num\|null}]`; NULL for single molecules. Duplicate-therapy and max-dose checks expand through it (PRESCRIPTION.md §5.3) |
| needs_review | boolean | no | `false` | Auto-created by the importer from unknown generic text (paired with a `catalog_import_issues` row); excluded from search until reviewed |

**PK** id. **Unique** slug; lower(name). **Indexes** GIN (name gin_trgm_ops); (needs_review) WHERE needs_review `_p`. **Checks** `components IS NULL OR jsonb_typeof(components) = 'array'`. **Model** `App\Models\Catalog\Generic`.

#### `brands`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| generic_id | bigint | no | | |
| name | varchar(160) | no | | `Napa` |
| slug | varchar(160) | no | | |
| manufacturer | varchar(160) | yes | | `Beximco` |
| dar_number | varchar(32) | yes | | DGDA registration no. |
| popularity | integer | no | `0` | Ranking signal for search; never overwritten by DGDA rows |
| aliases | jsonb | no | `'[]'` | `["নাপা"]` — searchable as `brand_aliases`; never overwritten by DGDA rows |
| discontinued_at | timestamptz | yes | | Set together with `is_active = false` by a `--full` import (CATALOG.md §5.5) |

**PK** id. **FK** generic_id → generics(id) RESTRICT. **Unique** slug (`{brand}-{generic}`, `-2` suffix on collision); (lower(name), generic_id) — the importer's canonical key. **Indexes** (generic_id); GIN (name gin_trgm_ops) (admin search and promotion-queue similarity). **Model** `App\Models\Catalog\Brand`.

#### `strengths`
**Purpose.** Brand + strength + form (+ route) — the unit a doctor actually picks; one Meilisearch document each.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| brand_id | bigint | no | | |
| generic_id | bigint | no | | Denormalised = brands.generic_id (CHECK via trigger-free convention; validated by importer) |
| dosage_form_id | bigint | no | | |
| route_id | bigint | yes | | Default route |
| strength_label | varchar(64) | no | | `500 mg`, `120 mg/5 ml` |
| strength_value | numeric(12,4) | yes | | Numeric part for dose maths |
| strength_unit | varchar(16) | yes | | `mg`, `mg/ml`, `IU`, `%` |
| per_volume_ml | numeric(8,2) | yes | | For syrups (`5`) |
| pack_size | varchar(48) | yes | | `10x10` |
| unit_price_paisa | bigint | yes | | MRP per unit, informational |
| strength_mg | numeric(12,4) | yes | | mg (or IU) per counting unit — importer-computed from `strength_label` (`500 mg` → 500) |
| per_ml | numeric(12,4) | yes | | mg (or IU) per ml for liquids (`120 mg/5 ml` → 24; `100 IU/ml` → 100) |
| pack_size_value | numeric(10,2) | yes | | Numeric part of `pack_size` in `pack_unit` (`100 ml` → 100; `200 doses` → 200) |
| pack_unit | varchar(16) | yes | | `ml`, `actuation`, `unit`, `g`, `tab` … |

**PK** id. **FK** brand_id → brands(id) RESTRICT; generic_id → generics(id) RESTRICT; dosage_form_id → dosage_forms(id) RESTRICT; route_id → routes(id) SET NULL. **Unique** (brand_id, dosage_form_id, strength_label) — the importer's canonical key. **Indexes** (generic_id); (brand_id) (reconcile scans). The safety maths and the quantity calculator read `strength_mg` / `per_ml` / `pack_size_value` and **never parse labels at runtime** (CATALOG.md §10.3). **Model** `App\Models\Catalog\Strength`.

#### `dosage_forms`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| name | varchar(48) | no | | `Tablet` |
| name_bn | varchar(64) | yes | | |
| abbreviation | varchar(8) | no | | `Tab` — printed prefix |
| default_unit | varchar(16) | no | | `tab`, `ml`, `drop` … (feeds `dose_json.unit`) |
| default_route_id | bigint | yes | | |
| code | varchar(16) | no | | The shorthand grammar's closed `form_code` vocabulary (PRESCRIPTION.md §2.4): `tab`, `cap`, `syr`, `susp`, `sol`, `oral_drop`, `eye_drop`, `ear_drop`, `nasal_drop`, `nasal_spray`, `inh_mdi`, `inh_dpi`, `neb`, `inj`, `insulin`, `cream`, `oint`, `gel`, `lotion`, `powder`, `shampoo`, `mouthwash`, `paint`, `supp`, `pessary`, `sachet` |
| is_liquid | boolean | no | `false` | Dose maths in ml/tsp (`syr`, `susp`, `sol`, `oral_drop`) |
| pack_unit | varchar(16) | yes | | Default pack unit: `bottle`, `tube`, `inhaler`, `vial`, `pen`, `pack`, `respule` |

**PK** id. **FK** default_route_id → routes(id) SET NULL. **Unique** lower(name); code. **Checks** code list. **Model** `App\Models\Catalog\DosageForm`.

#### `routes`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| name | varchar(48) | no | | `Oral`, `IV`, `Topical` |
| name_bn | varchar(64) | yes | | |
| abbreviation | varchar(8) | no | | |
| code | varchar(8) | no | | The grammar's closed `route_code` vocabulary (PRESCRIPTION.md §2.7): `po`, `sl`, `buccal`, `pr`, `pv`, `top`, `iv`, `im`, `sc`, `id`, `inh`, `neb`, `ng`, `le`, `re`, `be`, `lear`, `rear`, `bear`, `nasal` (`od` is never a route) |
| is_systemic | boolean | no | `true` | False for topical / ocular / otic / nasal routes — duplicate-therapy severity and interaction relevance |

**PK** id. **Unique** lower(name); code. **Checks** code list. **Model** `App\Models\Catalog\Route`.

#### `icd10_codes`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| code | varchar(8) | no | | `J06.9` — the soft-reference key used by tenants |
| title | varchar(255) | no | | Official title |
| title_bn | varchar(255) | yes | | |
| chapter | varchar(8) | yes | | `X` |
| block | varchar(16) | yes | | `J00-J06` |
| parent_code | varchar(8) | yes | | |
| aliases | jsonb | no | `'[]'` | Plain-language: `["common cold","URTI","সর্দি"]` |
| is_billable | boolean | no | `true` | Leaf code |

**PK** id. **FK** parent_code → icd10_codes(code) SET NULL. **Unique** code. **Indexes** GIN (title gin_trgm_ops); (parent_code). **Model** `App\Models\Catalog\Icd10Code`.

#### `drug_interactions`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| generic_a_id | bigint | no | | Lower id of the pair |
| generic_b_id | bigint | no | | Higher id |
| severity | varchar(16) | no | | `minor`, `moderate`, `major`, `contraindicated` |
| mechanism | text | yes | | |
| effect | text | no | | What happens (shown in the alert) |
| management | text | yes | | What to do |
| evidence_level | varchar(16) | yes | | `established`, `probable`, `theoretical` |
| source | varchar(120) | yes | | |

**PK** id. **FK** both → generics(id) RESTRICT. **Unique** (generic_a_id, generic_b_id). **Indexes** (generic_b_id). **Checks** severity, evidence_level lists; `generic_a_id < generic_b_id` (canonical order; lookups query both columns with the pair sorted). **Model** `App\Models\Catalog\DrugInteraction`.

#### `allergy_classes`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| name | varchar(120) | no | | `Penicillins` |
| slug | varchar(120) | no | | |
| description | text | yes | | |
| cross_reacts_with | jsonb | no | `'[]'` | `[{"allergy_class_id":int,"probability_pct":int}]` e.g. cephalosporins |

**PK** id. **Unique** slug. **Model** `App\Models\Catalog\AllergyClass`.

#### `allergy_class_generics`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| allergy_class_id | bigint | no | | |
| generic_id | bigint | no | | |

**PK** id. **FK** allergy_class_id → allergy_classes(id) CASCADE; generic_id → generics(id) CASCADE. **Unique** (allergy_class_id, generic_id). **Indexes** (generic_id). **timestamps: created_at only.** **Model** `App\Models\Catalog\AllergyClassGeneric`.

#### `pregnancy_categories`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| generic_id | bigint | no | | |
| trimester | smallint | yes | | Null = all; 1–3 for trimester-specific rows |
| category | char(1) | no | | `A`, `B`, `C`, `D`, `X`, `N` (not classified) |
| lactation | varchar(8) | no | `'unknown'` | `safe`, `caution`, `avoid`, `unknown` |
| notes | text | yes | | |

**PK** id. **FK** generic_id → generics(id) CASCADE. **Unique** (generic_id, COALESCE(trimester,0)) expression index. **Checks** category, lactation lists; `trimester IS NULL OR trimester BETWEEN 1 AND 3`. **Model** `App\Models\Catalog\PregnancyCategory`.

#### `renal_cautions`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| generic_id | bigint | no | | |
| egfr_below | smallint | yes | | Threshold ml/min; null = any impairment |
| level | varchar(12) | no | | `caution`, `adjust_dose`, `avoid` |
| advice | text | no | | |

**PK** id. **FK** generic_id → generics(id) CASCADE. **Indexes** (generic_id). **Checks** level list. **Model** `App\Models\Catalog\RenalCaution`.

#### `hepatic_cautions`
Same shape as `renal_cautions` with `child_pugh_class char(1) NULL` (`A`, `B`, `C`) in place of `egfr_below`. **Model** `App\Models\Catalog\HepaticCaution`.

#### `max_daily_doses`
| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| generic_id | bigint | no | | |
| route_id | bigint | yes | | Null = any route |
| population | varchar(10) | no | `'adult'` | `adult`, `pediatric`, `elderly` |
| max_mg_per_day | numeric(12,3) | yes | | Absolute cap |
| max_mg_per_kg_per_day | numeric(8,3) | yes | | Pediatric weight-based |
| max_mg_per_dose | numeric(12,3) | yes | | |
| min_age_months | smallint | yes | | |
| max_age_months | smallint | yes | | |
| notes | text | yes | | |

**PK** id. **FK** generic_id → generics(id) CASCADE; route_id → routes(id) SET NULL. **Unique** (generic_id, COALESCE(route_id,0), population, COALESCE(min_age_months,-1)) expression index. **Checks** population list; at least one of the max columns NOT NULL. **Model** `App\Models\Catalog\MaxDailyDose`.

#### `drug_information`
**Purpose.** Patient-facing information behind the "click here for more information" link on printed prescriptions.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| generic_id | bigint | no | | |
| public_slug | varchar(120) | no | | URL `https://{platform}/drug/{public_slug}`; snapshotted into `prescription_items.info_url_slug` and **never changed** once published |
| indications | text | yes | | |
| indications_bn | text | yes | | |
| side_effects | text | yes | | |
| side_effects_bn | text | yes | | |
| contraindications | text | yes | | |
| precautions | text | yes | | |
| patient_advice_bn | text | yes | | Plain-language Bangla guidance |
| published_at | timestamptz | yes | | Null = link resolves to a generic placeholder |

**PK** id. **FK** generic_id → generics(id) CASCADE. **Unique** generic_id; public_slug. **Model** `App\Models\Catalog\DrugInformation` (`$table='drug_information'`).

#### `catalog_versions`
**Purpose.** DGDA release tracking; every import runs under one version.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| version | varchar(32) | no | | Semver-ish `2026.09.1` |
| dgda_release_ref | varchar(120) | yes | | Source bulletin/gazette reference |
| status | varchar(12) | no | `'draft'` | `draft`, `released`, `applied`, `superseded` |
| released_at | timestamptz | yes | | |
| applied_at | timestamptz | yes | | Imported into this database |
| row_counts | jsonb | no | `'{}'` | `{"generics":int,"brands":int,"strengths":int,...}` |
| checksum_sha256 | char(64) | yes | | Of the import bundle — the file fingerprint the importer checks before re-running (CATALOG.md §5.1) |
| notes | text | yes | | |
| applied_by | varchar(120) | yes | | Super admin email |

**PK** id. **Unique** version. **Checks** status list. Has no `catalog_version_id`/`is_active` columns itself. "Current" = the latest row with `status = 'applied'` (cached as `catalog:current_version`). **Model** `App\Models\Catalog\CatalogVersion`.

#### `catalog_import_issues`
**Purpose.** Rows the importer could not map cleanly (CATALOG.md §5.3); reviewed by super admins. Has `catalog_version_id` (**NOT NULL** here) but no `is_active`.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| catalog_version_id | bigint | no | | Import run that raised it |
| kind | varchar(24) | no | | `unknown_generic`, `unparsable_strength`, `unknown_form`, `duplicate_brand` |
| source_row | integer | yes | | Line number in the import file |
| payload | jsonb | no | | The mapped `ImportRow` plus the offending text: `{"manufacturer":str,"brand":str,"generic_text":str,"strength_label":str,"form_text":str,"route_text":str,"pack_size":str,"dar_number":str,"created_generic_id":int\|null}` |
| resolved_at | timestamptz | yes | | |
| resolved_by | varchar(120) | yes | | Super admin email |
| resolution | jsonb | yes | | `{"action":"mapped\|created\|ignored","generic_id":int\|null,"dosage_form_id":int\|null,"note":str\|null}` |

**PK** id. **FK** catalog_version_id → catalog_versions(id) CASCADE. **Indexes** (catalog_version_id, kind); (kind) WHERE resolved_at IS NULL `_p` (open-issues dashboard). **Checks** kind list. **Model** `App\Models\Catalog\CatalogImportIssue`.

---

## 5. Cross-cutting design

### 5.1 Serial allocation — exact sequence

Two independent guards make a duplicate serial impossible: the **row lock on the owner row** (a `serial_pools` row, or a `serial_blocks` row for device blocks and the released-number free-list) serialises allocators, and **`serials_session_number_uniq (session_instance_id, number)`** rejects any duplicate that slips past a buggy allocator. The concurrency test in Module D must prove both (hammer with parallel processes, then also disable the lock and assert the unique constraint still holds the line — SERIAL_ENGINE.md §18). SERIAL_ENGINE.md §3–§4 owns the algorithm; this section states the schema contract it relies on.

#### 5.1.1 Pools and the allocation transaction (`READ COMMITTED` is sufficient)
Pool layout at materialisation, with C/O/B = `counter_quota` / `online_quota` / `buffer_quota`: **`counter = [1, C]`, `online = [C+1, C+O]`, `buffer = [C+O+1, C+O+B]`** — counter first, so offline blocks and paper slips carry the small numbers and the buffer can always be extended by appending above `max_serials` (SERIAL_ENGINE.md §3.1; supersedes the online-first sketch this section once carried). A zero quota still gets a pool row as the empty range `range_end = range_start - 1`. Channel → pool: `online`/`kiosk` → online; `counter` → counter; `walkin` → buffer; `followup` → counter when staff act, online when the patient acts; `offline` → the device's block. Spill-over is never automatic — every cross-pool movement is an explicit, role-checked, audited action.

1. **Lock the owner row first.** Pool `counter`: `SELECT id, next_number, range_end FROM serial_blocks WHERE session_instance_id = ? AND status = 'released' AND next_number <= range_end ORDER BY range_start FOR UPDATE SKIP LOCKED LIMIT 1` (free-list, §5.1.3); if none, `SELECT … FROM serial_pools WHERE session_instance_id = ? AND pool = ? FOR UPDATE`. Pools `online`/`buffer`: the pool row only. `source = 'offline'` replay: the device's `serial_blocks` row (`status = 'active'`, `FOR UPDATE`).
2. **Session guard.** Plain `SELECT` of `session_instances.status` — must be `scheduled|running|paused` (`CloseSession`/`CancelSession` lock all three pools before they change status, so a plain read is race-free).
3. If `next_number > range_end` → `PoolExhausted` (HTTP 409 `pool_exhausted`, body carries the other pools' remaining counts) and rollback.
4. **Advance the cursor, then insert.** `UPDATE <owner> SET next_number = next_number + 1` (a block whose cursor passes `range_end` becomes `exhausted`), then `INSERT INTO serials (public_id, session_instance_id, number, display_code, position, pool, status, priority, source, appointment_id, patient_id, serial_block_id, reception_device_id, client_event_id, transferred_from_serial_id, slot_start_at, booked_at, issued_by_user_id, created_at, updated_at)` inside a savepoint. A `serials_session_number_uniq` violation (drift — must never happen) is recorded as `serial_events.type = 'number_skipped'` (`serial_id` NULL), reported, and the loop retries with the next number at most 5 times (`AllocationRetryExhausted`); a `(session_instance_id, slot_start_at)` violation is `SlotUnavailable` and is not retried.
5. `serial_events (type = 'booked')`; `CountsRecalculator` rewrites the seven `*_count` columns from `serials` and bumps `session_instances.version`; insert/attach `appointments` (`serials.appointment_id` and `appointments.serial_id` are written in the same transaction and must agree). A non-`normal` priority runs `PriorityInsert` after the insert, in the same transaction.
6. `COMMIT`. After commit only: queue-state rebuild, Reverb broadcast, notifications (`ShouldDispatchAfterCommit`). Never inside the transaction; no non-database I/O while the lock is held.

Idempotency: `client_event_id` is checked against `serials_session_client_event_uniq` **before** the lock; the same id in the same session returns the existing row. Lock order everywhere (allocation, lease, release, close, cancel, extend): owner rows first — released block, then pools by name ascending (`buffer`, `counter`, `online`) — then the `session_instances` row, then `serials`; deadlocks are limited to the `attempts: 3` retry. Under Octane the tenancy middleware sets `search_path` on every request and a `RequestTerminated` listener asserts `transactionLevel() === 0` (a leaked transaction would hold pool locks clinic-wide).

**Capacity extension** (`ExtendSessionCapacity`; Doctor / Hospital Admin / Super Admin, receptionists up to `serial.receptionist_extension_limit`): lock the `buffer` pool, `range_end += k`, `session_instances.max_serials += k`, event `capacity_extended` (`meta.by = k`). **Split changes** (Doctor and Super Admin only — the split-adjust permission of `App\Domain\Clinic\Enums\Permission`): at template level (future instances); on an untouched instance (`ChangePoolSplit`: every pool at `next_number = range_start` and no `serial_blocks`, else 409 `split_locked`) rewriting the three ranges with event `split_changed`; on a live instance only the one-way **online → counter release** (`ReleaseOnlineToCounter`): lock the online and counter pools, `online.range_end = new_end`, insert a desk-owned `serial_blocks` row (`reception_device_id NULL`, `serial_pool_id` = the online pool, `[new_end + 1, old_end]`, `status = 'released'`, `released_at = now()`), event `online_released`. The reverse (counter → online) is not provided.

#### 5.1.2 Non-overlap invariant
Decision: **exclusion constraints with `btree_gist`**, not CHECK constraints — a CHECK cannot see sibling rows. `serial_pools_range_excl` guarantees no two pools of one session share a number; `serial_blocks_range_excl` guarantees no two blocks of one session share a number. Both use the **half-open** expression `int4range(range_start, range_end + 1)` so that an empty range (zero quota, fully drained free-list row) is `empty` and legal. Containment of a block inside its pool cannot be expressed declaratively across tables; it is enforced by the lease procedure while holding the pool lock, and any failure is still caught by `serials_session_number_uniq`. Migration requirement: `DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist')` in the very first `booking` migration; exclusion constraints are added with `DB::statement()` since the schema builder has no API for them (operator-class lookup ignores `search_path`, so they create fine under a tenant-only path).

#### 5.1.3 Device blocks and the released-number free-list (OFFLINE.md §4 is authoritative)
- **Lease** (`LeaseBlock`; device online, session open): size = `min(requested, reception_devices.block_size, serial.default_block_size, 30, remaining)`. Lock order as in §5.1.1: first carve from a released row (`FOR UPDATE SKIP LOCKED`, lowest `range_start` first — its `next_number` advances and it becomes `exhausted` when drained), else take `[next_number, next_number + size - 1]` from the locked `counter` pool and advance the pool cursor by `size`. Insert `serial_blocks` (`public_id`, `serial_pool_id` = counter pool, `status = 'active'`, `expires_at = planned_end_at + 2 h`, `leased_by_user_id`), event `block_leased` (`serial_id` NULL). At most `serial.max_active_blocks_per_device` (2) active blocks per device per session, counted under the same lock (§3.3 `serial_blocks`). The device caches `[range_start, range_end]` in IndexedDB.
- **Offline issue**: the device takes the next number from its block locally, prints the slip, and appends an `issue_serial` event (`client_event_id` ULID, `sequence_no`) to its log.
- **Replay** (`AllocateFromBlock` → `AllocateSerial`, source `offline`): the block must be `active`, owned by the device, and `next_number <= number <= range_end`; the server issues *that* number and sets `next_number = number + 1` (numbers the device skipped — a voided slip — stay unissued on the block until release). Idempotent through `serials_session_client_event_uniq`. A revoked/released block, a taken number or a closed session is an `offline_events` conflict (`serial_already_used`, `session_closed`, …) — never auto-merged; a still-free number on a revoked block is accepted by splitting the released row around it (two disjoint rows, exclusion constraint intact).
- **Release / revoke** (`ReleaseBlock` on session close, device logout, manual return; `RevokeBlock` additionally sets `revoked_at`): lock the block, `status = 'released'` (or `exhausted` when nothing remains), `released_at`, `returned_count = range_end - next_number + 1`. **Returned numbers are reused in the same session**: the released row *is* the free-list, drained lowest-range-first by both `LeaseBlock` and counter allocation before the pool cursor advances (OFFLINE.md §4.6 — supersedes the earlier "returned numbers become gaps" rule of this section). Uniqueness never depended on monotonic numbering; pool cursors still never move backwards. A late reused number joins the **tail** of the queue via `position = max(number × 10⁶, max_position + 10⁶)`, so nobody is skipped and the shift report shows `issued`, `returned`, `wasted = 0`.
- **Exhausted**: when `next_number > range_end` the block is `exhausted`; the device tops up while online at `remaining <= serial.block_topup_threshold` (3) and, offline, falls back to its second block, then stops issuing (check-in continues).
- **Capacity reporting** (no locks, SERIAL_ENGINE.md §12): counter remaining on the board = pool remaining + released free-list; `counter_in_blocks` (active leases) is shown separately as "on devices"; the public calendar shows `online` remaining only.

#### 5.1.4 Transfer and postpone
Transfer to another doctor (`TransferSerial`) = allocate a new serial in the target session through §5.1.1 (pool: online → online, everything else → counter; `transferred_from_serial_id` set; `client_event_id = "transfer:{old.public_id}"` so a retry is idempotent), then cancel the old one (`cancel_reason_code = 'transferred'`, `transferred_to_serial_id`), two `serial_events` (`transferred_out`, `transferred_in`), and move the `appointment` (update `session_instance_id`, `doctor_id`, `serial_id`; fee is re-evaluated only if the target doctor's fee differs — record `fee_rule = 'manual'` with reason; the engine reports `old_fee_snapshot` and the target fee, billing reacts). Postpone to the doctor's next session (`PostponeSerial`) follows the same shape with `postponed_to_serial_id`, status `postponed` and `client_event_id = "postpone:{old.public_id}"`. If the target pool is exhausted the whole transaction rolls back (`pool_exhausted` with target details) — extend the target session first.

### 5.2 Reordering — `position bigint` with gaps
Decision: **bigint with a 1,000,000 gap**, midpoint insertion on drag/priority insert, renormalisation when the gap between neighbours drops below 2. Initial value `max(number × 1 000 000, current max position + 1 000 000)` (serial mode — the `max()` only matters for a reused free-list number, which must join the tail) or `slot_index × 1 000 000` (slot mode). Rationale: integer comparison is index-friendly and deterministic; `numeric` fractions grow without bound and complicate the queue diff; a session has at most a few hundred serials, so renormalising (`UPDATE serials SET position = rank × 1 000 000 …` over the non-terminal serials ordered by `position, number`) is cheap and rare — needed only after ~20 consecutive inserts at the same spot. Queue order is `ORDER BY position, number`. Procedure: `SELECT … FOR UPDATE` on the `session_instances` row (the reorder lock; taken *after* the serial row lock and only by position-changing actions — allocation does not need it), compute the new position, update the serial, insert `serial_events (type = 'reordered', from_position, to_position, meta.after/before, reason)`, insert `audit_logs (action = 'reorder')`, bump `session_instances.version`. A renormalisation writes one `renormalised` event (`serial_id` NULL, before/after map in `meta`) inside the same transaction. **Reorder never changes `number` or `display_code`.** Requests carry the neighbours' `public_id`s; a stale neighbour is 409 `reorder_stale`.

Priority insert (`PriorityInsert`, event `priority_changed`; SERIAL_ENGINE.md §7.3): `emergency` → immediately after `now_serving`; `vip` → after `now_serving` and any waiting `emergency` (disable per tenant with `serial.vip_enabled`; reason mandatory); `elderly` → after the next `serial.elderly_skip` (2) waiting serials; back to `normal` → `number × 10⁶` (or the tail if that would jump ahead). The same rule table drives `ReinstateSerial` (elderly rule) and `SkipCalled`/`ReturnToQueue` (after the next 2 waiting; `skip_count + 1`, event `skipped`).

Auto no-show (SERIAL_ENGINE.md §8): on every call of serial S, `UPDATE serials SET passed_count = passed_count + 1 WHERE session_instance_id = ? AND status = 'booked' AND position < S.position RETURNING id, passed_count`; rows whose `passed_count >= session_instances.auto_noshow_after` (N; `0` disables) become `no_show` through `SerialTransition` (`serial_events.meta = {"auto": true, "passed": N}`), never before `planned_start_at + delay + queue.auto_noshow_grace_minutes`, and never for `checked_in` serials — they are present. In slot mode a serial is additionally no-showed at `slot_start_at + slot_minutes` if not checked in. Reinstating (`no_show → checked_in|booked`) resets `passed_count = 0` and positions by the elderly rule (`reinstated` + `reordered` events); it may happen any number of times while the session is open.

### 5.3 Prescription immutability and amendment

#### 5.3.1 Lifecycle
- A `draft` row (one per visit) is freely edited together with its child rows.
- **Issue**: in one transaction set `status='issued'`, `issued_at`, `issued_by_user_id`, `verification_code`, `root_prescription_id` (= own id for v1), build `snapshot` from the current row + children + patient + doctor + pad settings + vitals, compute `snapshot_sha256`, copy `doctor_pad_settings` into `pad_snapshot`, write `doctor_drug_usage`, `patient_medications` (`is_continued` items), `audit_logs (action='issue')`. Queue PDF rendering from `snapshot` only.
- **Amend**: insert a **new row** with `version = old.version + 1`, `root_prescription_id = old.root_prescription_id`, `supersedes_prescription_id = old.id`, `status='draft'`, `amend_reason`, and cloned child rows; when the new row is issued, the old row's `status` becomes `amended` (its only permitted change) and `visits.current_prescription_id` moves. The original stays retrievable and printable forever at its own `public_id`/`verification_code`; its render shows "Superseded by v{n}" from the chain, not from a change to the row.
- **Void**: `status='voided'`, `voided_at`, `voided_by_user_id`, `void_reason`; nothing else changes.

#### 5.3.2 `snapshot` shape (render source of truth; all text pre-resolved, no ids required to print)
```json
{"schema":1,"prescription":{"public_id":str,"version":int,"verification_code":str,"issued_at":ts,"language":"bn|en|both","verify_url":str},
 "clinic":{"name":str,"branch":{"name":str,"address":str,"phone":str}},
 "doctor":{"name":str,"name_bn":str|null,"degrees":str,"bmdc_reg_no":str,"designation":str|null,"specialties":[str],"signature_path":str|null},
 "patient":{"public_id":str,"patient_code":str,"name":str,"age_text":str,"gender":str|null,"mobile_masked":str,"weight_kg":num|null},
 "visit":{"date":date,"serial":str|null,"type":str,"chief_complaints":[...],"examination_findings":str|null,"diagnoses":[...],"vitals":{...latest vitals row or null}},
 "items":[{"sort":int,"generic_name":str,"brand_name":str|null,"strength":str|null,"form":str|null,"route":str|null,"dose_schedule":str|null,"dose_json":{...},"duration_text":str|null,"quantity":num|null,"quantity_unit":str|null,"timing":str,"instruction":str|null,"instruction_bn":str|null,"info_url":str|null}],
 "investigations":[{"name":str,"name_bn":str|null,"price_paisa":int|null,"external_centre":str|null,"referral_note":str|null,"is_urgent":bool}],
 "advice":[{"text":str,"text_bn":str|null}],
 "referrals":[{"type":str,"to":str,"specialty":str|null,"note":str|null}],
 "follow_up":{"on":date|null,"note":str|null},
 "handwriting_image_path":str|null,"drawing_image_path":str|null,
 "pad":{...pad_snapshot...},"allergies":[str]}
```
The keys above are the base contract; PRESCRIPTION.md §6.2 adds the `＋` keys (row ids, `catalog_version`, `mode`, bilingual `display` strings per item, `investigations_total_paisa`, `follow_up.days/label`, `handwriting_pages`, `drawing_json`, `safety` alerts and overrides, `qr.svg_data_uri`, `rendered_by`) — additive only, so this contract still holds. `doctor.signature_path` (and `＋signature_data_uri`) and `pad` come from `doctor_pad_settings`; `visit.vitals` includes `reviewed_by_doctor_at`. Pharmacy view = `items[].{brand_name|generic_name, strength, form, quantity, quantity_unit}` from the same document. The public `/rx/{verification_code}` page renders the same document and shows the version chain.

#### 5.3.3 Triggers (created per tenant schema; function once in `public`)
```sql
CREATE OR REPLACE FUNCTION public.fn_prescription_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_status text;
BEGIN
  IF TG_TABLE_NAME = 'prescriptions' THEN
    IF OLD.status <> 'draft' THEN
      -- allowed post-issue changes only
      IF NEW.status NOT IN ('issued','amended','voided') OR NEW.snapshot IS DISTINCT FROM OLD.snapshot
         OR NEW.snapshot_sha256 IS DISTINCT FROM OLD.snapshot_sha256 OR NEW.issued_at IS DISTINCT FROM OLD.issued_at
         OR NEW.verification_code IS DISTINCT FROM OLD.verification_code OR NEW.version <> OLD.version
         OR NEW.visit_id <> OLD.visit_id OR NEW.patient_id <> OLD.patient_id OR NEW.doctor_id <> OLD.doctor_id
         OR NEW.handwriting_image_path IS DISTINCT FROM OLD.handwriting_image_path
         OR NEW.drawing_json IS DISTINCT FROM OLD.drawing_json THEN
        RAISE EXCEPTION 'prescription % is immutable (status %)', OLD.id, OLD.status USING ERRCODE = 'check_violation';
      END IF;
    END IF;
    RETURN NEW;
  END IF;
  -- child tables: prescription_items, prescription_investigations, prescription_advice, prescription_referrals
  SELECT status INTO parent_status FROM prescriptions WHERE id = COALESCE(NEW.prescription_id, OLD.prescription_id);
  IF parent_status IS DISTINCT FROM 'draft' THEN
    RAISE EXCEPTION 'prescription % children are immutable', COALESCE(NEW.prescription_id, OLD.prescription_id) USING ERRCODE = 'check_violation';
  END IF;
  RETURN COALESCE(NEW, OLD);
END $$;
CREATE TRIGGER prescriptions_immutable_trg BEFORE UPDATE ON prescriptions FOR EACH ROW EXECUTE FUNCTION public.fn_prescription_guard();
CREATE TRIGGER prescription_children_immutable_trg BEFORE INSERT OR UPDATE OR DELETE ON prescription_items FOR EACH ROW EXECUTE FUNCTION public.fn_prescription_guard();
-- repeat the child trigger for prescription_investigations, prescription_advice, prescription_referrals
```
Post-issue columns that **may** change: `status` (per lifecycle), `pdf_path`, `pdf_generated_at`, `printed_count`, `last_printed_at`, `delivered_channels`, `drawing_image_path`, `voided_*`, `updated_at`. Deleting an issued prescription is impossible (`DELETE` is not granted to the app role on `prescriptions`; `ON DELETE RESTRICT` from children). The child trigger resolves the parent through the tenant `search_path`, so the function body must not schema-qualify `prescriptions`.

### 5.4 Patient identity and family grouping
- **Identity key**: `mobile` (E.164, normalised by the app from any of `017…`, `+88017…`, `88017…`) is the primary lookup; a household shares one mobile. A person is unique within a tenant by `(mobile, name_normalized, COALESCE(dob, '0001-01-01'))` (`patients_identity_uniq`). Decision: `dob` is part of the key so a father and son with the same name on one phone are distinct; when `dob` is unknown on both, the second registration must differ in name (UI suggests "Md Rahim (Jr)"). Duplicate detection at booking: same mobile → show the household list first, create only on explicit "new family member".
- **Household**: the first registrant on a mobile is `is_mobile_owner = true`; every later person on that mobile gets a `patient_relations` row pointing at that primary. Merging duplicates is a Super Admin / Hospital Admin action that repoints FKs and soft-deletes the loser (audit-logged); no automatic merge.
- **Patient portal**: OTP to `mobile` signs in the owner, who then picks the family member (`patient_relations`) to act for. `patients` is the authenticatable model for guard `patient`; there is no `users` row.

### 5.5 Encryption at rest — what is encrypted and what stays plain
Layer 1 (mandatory): full-volume encryption on the Postgres host and encrypted backups (`tenant_backups` dumps are encrypted with libsodium `crypto_secretstream_xchacha20poly1305` before upload — `App\Domain\SaaS\Services\BackupCipher`, key `BP_BACKUP_KEY`; the mode is recorded per row in `tenant_backups.encryption`, ARCHITECTURE §8.2). Layer 2 (this section): Laravel `encrypted` casts on columns marked **ENC**. ENC columns are `text`, never indexed, never used in `WHERE`, never returned by Meilisearch, logged as `"[encrypted]"` in `audit_logs`. Key rotation uses `APP_PREVIOUS_KEYS`.

| Encrypted (ENC) | Plain — and why |
|---|---|
| `patients.national_id`, `patients.notes` | `patients.mobile`, `name`, `dob`, `gender`, `blood_group`: identity/lookup keys, printed on every slip, required for SMS; encrypting them would break search, uniqueness and household lookup. Decision: **`mobile` stays plain, no `mobile_hash`**. |
| `patient_allergies.notes`, `patient_conditions.notes`, `patient_medications.notes` | `allergen_name`, `generic_id`, `icd10_code`, `condition_name`: needed for safety checks and analytics. |
| `visits.private_notes` (doctor-only) | `visits.chief_complaints`, `examination_findings`, `diagnoses`: printed on the prescription and analysed (top diagnoses). |
| `ai_suggestions.prompt`, `response`, `accepted_fragment` | — |
| `patient_documents.ocr_text`, `patient_consents.signature_data` | `patient_documents.title`, paths: object keys are opaque; the objects themselves sit in an encrypted S3 bucket. |
| `users.two_factor_*`, `super_admins.two_factor_*` | — |
| `sms_gateway_settings.credentials`, `push_subscriptions.keys`, `telemedicine_sessions.recording_path` | — |
| — | `prescriptions.snapshot`, `handwriting_image_path`, `drawing_json`, `pdf_path`, all `prescription_*` snapshot text: **deliberately plain jsonb**. It is the legal document handed to the patient, must be verifiable at `/rx/{code}` without a doctor session, must feed the pharmacy view, and must be indexable for the version chain; column encryption would add nothing over Layer 1 while breaking those paths. Handwriting/drawing images live in the encrypted bucket. |

### 5.6 Meilisearch indexes (Scout driver `meilisearch`; autocomplete never hits `catalog` directly)

| Index | Source | Document id | Searchable (ranked order) | Filterable | Sortable / displayed |
|---|---|---|---|---|---|
| `catalog_drugs` | one `presentation` document per active `strengths` row (denormalising brand + generic + form + route) **plus** one `generic` document per generic (`doc_type = generic`, "prescribe by generic" — CATALOG.md §4.1, PRESCRIPTION.md §3.1) | `s{strength_id}` / `g{generic_id}` | `brand_name`, `generic_name`, `generic_aliases`, `brand_aliases`, `strength_label`, `form`, `manufacturer`, `label` | `source` (= `master`), `doc_type`, `generic_id`, `brand_id`, `dosage_form_id`, `route_id`, `form_code`, `route_code`, `strength_mg`, `is_active`, `is_controlled`, `therapeutic_class` | sort `popularity:desc`; displayed: `label` ("Napa 500 mg Tab"), `info_slug`, `default_unit`, `strength_value`, `strength_unit`, `strength_mg`, `per_ml`, `form_code`, `route_code`, `pack_size_value`, `pack_unit` |
| `t{tenant_id}_custom_brands` | `custom_brands` where `is_active`, `review_status <> 'rejected'` and not soft-deleted (Scout `shouldBeSearchable`) | `c{id}` | `brand_name`, `generic_name`, `strength`, `form`, `manufacturer` | `source` (= `custom`), `doc_type`, `generic_id`, `dosage_form_id`, `route_id`, `form_code`, `route_code`, `strength_mg`, `is_active`, `review_status`, `promoted_to_master` | sort `use_count:desc`; displayed as `catalog_drugs` plus `custom_brand_id`, and `brand_id`/`strength_id` once promoted |
| `catalog_icd10` | `icd10_codes` (active) | `code` | `code`, `title`, `aliases`, `title_bn` | `chapter`, `is_billable` | displayed: `code`, `title`, `title_bn` |
| `t{tenant_id}_patients` | `patients` (not deleted) | `id` | `name`, `mobile`, `mobile_local` (`017…` form), `patient_code` | `gender`, `registered_branch_id`, `is_active` | sort `last_visit_at:desc`; displayed: `public_id`, `name`, `mobile`, `age_text`, `patient_code`, `last_visit_at` — **no ENC fields, no address** |

Drug autocomplete is one federated multi-search over `catalog_drugs` + `t{id}_custom_brands` (Meilisearch ≥ 1.10 federation; available in meilisearch-php 1.17), re-ranked in the app so the doctor's `doctor_favourites` for the current diagnosis come first, then master, then custom (visually distinguished by `source`). Typo tolerance on; `mobile` fields are `exact`-boosted. `searchableAs()` on tenant models returns the tenant-prefixed name; `SCOUT_PREFIX` is empty in every runtime environment (only the per-engineer test env sets `test{N}_`). Index settings are owned by CATALOG.md §4 (`config/catalog.php['search']`, applied by `catalog:index-search` for the shared indexes — rebuilt by the import job through an atomic index swap — and by `scout:sync-index-settings` per tenant via `tenants:sync-search-settings`); tenant indexes sync via the Scout queue. No other tenant indexes exist — investigations and advice snippets are searched in Postgres with `ILIKE` (PRESCRIPTION.md §3.8).

### 5.7 Queue state — Redis, not a table (REALTIME.md §4–§5 is authoritative)
Keys `t:{tenantId}:qs:{sessionPublicId}` (the `QueueState` JSON document) and `t:{tenantId}:qs:{sessionPublicId}:v` (mirror of `session_instances.version`) — `tenantId` is the **bigint** `Tenancy::id()` in every Redis key, whereas channel names carry the tenant's ULID `public_id` (CONVENTIONS.md §15) — TTL `max(2 h, planned_end_at + 2 h − now)`. The version is bumped **inside** every mutating transaction (SERIAL_ENGINE.md §6.4, `CountsRecalculator::bumpVersion()`), so it is monotonic, transactional and survives a Redis flush; Redis only mirrors it for the cheap 304 path. `App\Domain\Queue\Services\QueueStateRepository::rebuild()` runs synchronously **after commit** (listener `InvalidateQueueState`, one call per domain event) under lock `qs-build:{sessionPublicId}`, re-reads the row and never overwrites a higher version; a cold cache rebuilds inline from one indexed query over the active serials (`serials (session_instance_id, position) WHERE status IN ('booked','checked_in','in_consultation')`). Broadcast on the public channel `tenant.{tenantPublicId}.queue.{sessionPublicId}` (`queue.state`, coalesced; `serial.called` never coalesced) and the private channels `tenant.{t}.reception.{branchPublicId}`, `tenant.{t}.doctor.{doctorPublicId}`, `tenant.{t}.display.{branchPublicId}` — every id in a channel name is a ULID `public_id`, never a bigint. Polling fallback (LOCKED path): `GET /queue/{doctorSlug}/state?session={sessionPublicId}` returns the document with `ETag: "{version}"` (the bare version, strong and quoted), `Cache-Control: no-cache, private` and `X-Queue-Session`; an `If-None-Match` hit returns 304 at the cost of one Redis GET; `throttle:queue-state` = 30/min per IP + session. Shape: REALTIME.md §4.1 (short keys `id/c/n/p/s/pr/eta/ahead`, `counts` incl. `postponed` and `waiting`, `eta_confidence`, `truncated`) — codes and statuses only, never patient identifiers; the waiting-room display and the patient page share this one payload. `eta` per serial = `base + remaining-of-current + (checked-in ahead + round(booked ahead × serial.expected_show_rate)) × avg_consult_seconds`, suppressed while paused (SERIAL_ENGINE.md §13); `queue:refresh-eta` (REALTIME.md §4.3) bumps `version` every minute for running sessions so ETAs keep moving. Companion keys: capacity cache `t:{tenantId}:cap:{sessionPublicId}` (10 s, SERIAL_ENGINE.md §12) and the settings cache `t:{tenantId}:settings` (§3.1). The earlier `t{id}:queue:{id}` key, the `"{version}-{unix_ms}"` ETag and the `tenant.{id}.session.{id}` channel once described here are **withdrawn**.

### 5.8 `usage_counters` write protocol
- Monthly meters (`appointments`, `sms_credits`, `whatsapp_messages`, `prescriptions`, `telemedicine_minutes`, `ai_requests`): `INSERT … ON CONFLICT (tenant_id, metric, period) DO UPDATE SET value = usage_counters.value + EXCLUDED.value` inside the same transaction as the metered write (appointment insert, notification sent, etc.). `period` = tenant-timezone `YYYY-MM`.
- Gauges (`doctors`, `branches`, `storage_bytes`): `period = 'current'`; recomputed by the tenant's nightly job (`COUNT(*)` of active rows, `SUM(size_bytes)` over documents + prescription assets) and adjusted incrementally on create/delete.
- Enforcement: `PlanLimits::check($tenant, $metric)` compares `value` (or `value + 1` before an insert) against `subscriptions.feature_overrides[metric] ?? plan_features.limit_value`; `NULL` limit = unlimited. Appointments over the limit raise a 402-style domain error; SMS over the limit are stored as `notifications.status='failed', last_error='sms_credits_exhausted'`.

### 5.9 `tenant_id` columns in tenant tables
Present on `users`, `patients`, `appointments`, `prescriptions`, `invoices`, `payments`, `audit_logs` only. They are **not** how isolation works (the schema is); they are a belt-and-braces assertion: `TenantModel` adds a global scope `WHERE tenant_id = current_tenant_id()` on these tables and the `creating` event fills the column. The tenant-isolation test suite asserts that (a) `search_path` never contains another tenant schema and (b) a row with a foreign `tenant_id` can never be inserted while a tenant context is active. The FK to `public.tenants(id)` is `ON DELETE RESTRICT` — a tenant is deleted only after its schema is dropped. Since the tenant id is known when tenant migrations run (`Tenancy::id()` inside `Tenancy::run()`), the foundation tables also carry a per-schema `CHECK (tenant_id = <id>)` — `users_tenant_id_check`, `audit_logs_tenant_id_check` — so no write path (Eloquent, query builder or raw SQL) can move a row to a foreign tenant; later modules add the same constraint on their `tenant_id` tables.

### 5.10 Fees and the free follow-up window
Rules live on `doctor_profiles` (`new_fee_paisa`, `followup_fee_paisa`, `free_followup_within_days`, `followup_within_days`, `report_visit_free`, `telemedicine_fee_paisa`, `online_booking_fee_delta_paisa`), optionally overridden per session by `doctor_schedules.fee_new_paisa/fee_followup_paisa`; the effective pair is snapshotted onto `session_instances` at materialisation. At booking, `FeeResolver` decides and **snapshots** onto `appointments`:

| Situation (last *completed* visit with the same doctor = L, days since = d) | `type` | `fee_rule` | `fee_paisa` |
|---|---|---|---|
| No L, or `d > followup_within_days` | `new` | `new` | session `fee_new_paisa` |
| `d <= free_followup_within_days` (and window > 0) | `followup` | `followup_free` | `0` |
| `free_followup_within_days < d <= followup_within_days` | `followup` | `followup_paid` | session `fee_followup_paisa` |
| Telemedicine channel | as above | `telemedicine` | `telemedicine_fee_paisa` (± online delta) |
| Staff override (the fee-override permission of `App\Domain\Clinic\Enums\Permission`, ARCHITECTURE.md §6.2) | as chosen | `manual` / `waived` | as entered |

`list_fee_paisa` always holds the session `fee_new_paisa`; `fee_rule_reason` holds a sentence for the receipt (e.g. "Free follow-up: last visit 2026-08-30, day 7 of 15"); `follow_up_of_visit_id = L.id` whenever `type='followup'`. Online channel adds `online_booking_fee_delta_paisa` to the resolved fee (never below 0). The invoice's consultation `invoice_items` row copies `fee_paisa`; if the fee changes later (transfer, manual), a new appointment fee snapshot is written and the draft invoice line is replaced — issued invoices are adjusted only through `discounts`/`refunds`.

### 5.11 Migration ordering (circular references)
Create tables in this order inside a tenant schema and add the marked FKs afterwards: branches → departments → specialties → users → permission tables → doctors → doctor_profiles/doctor_specialties/doctor_pad_settings → holidays → doctor_leaves → settings → patients → patient_relations → reception_devices → doctor_schedules → schedule_overrides → session_instances → serial_pools → serial_blocks → serials → serial_events → appointments → `ALTER serials ADD FK appointment_id`, `ALTER session_instances ADD FK now_serving_serial_id` → visits → `ALTER appointments ADD FK follow_up_of_visit_id` → vitals → external_diagnostic_centres → investigation_catalog → advice_snippets → custom_brands → prescriptions → `ALTER visits ADD FK current_prescription_id` → prescription_items/investigations/advice/referrals (+ triggers) → prescription_templates/_items → doctor_favourites → doctor_drug_usage → patient_allergies/conditions/medications/documents/consents/otp_codes → offline_events → ai_suggestions → cash_shifts → doctor_revenue_shares → coupons → invoices → `ALTER appointments ADD FK invoice_id` → invoice_items → payments → refunds → discounts → coupon_redemptions → notification_templates → notifications → notification_logs → sms_gateway_settings → push_subscriptions → audit_logs → telemedicine_rooms → telemedicine_sessions → sequences. Tenant migrations live in `database/migrations/tenant/` and run via `tenants:migrate` against every schema (`Tenancy::run`, recorded in `tenant_<id>.migrations`; the migration classes must not set `$connection`); `public` migrations stay in `database/migrations/`. `public` order: `CREATE EXTENSION btree_gist` + `pg_trgm` → tenants → plans → plan_features → subscriptions → `ALTER tenants ADD FK current_subscription_id` → super_admins → domains → subscription_invoices → subscription_payments → feature_flags → usage_counters → tenant_backups → catalog_reconciliation_reports → custom_brand_promotions → impersonation_tokens → audit_logs_central → personal_access_tokens → password_reset_tokens → failed_jobs/job_batches → `public.fn_prescription_guard()`. Catalog order (`database/migrations/catalog/`, `catalog:migrate` on `catalog_admin`): `pg_trgm` → catalog_versions → routes → dosage_forms → generics → brands → strengths → icd10_codes → drug_interactions → allergy_classes → allergy_class_generics → pregnancy_categories → renal_cautions → hepatic_cautions → max_daily_doses → drug_information → catalog_import_issues.

---

## Appendix A — Enum registry

PHP string-backed enums live in **`App\Domain\<Module>\Enums`** (CONVENTIONS.md §3.2, ARCHITECTURE.md §5.3); this table is the source of enum class names and namespaces (CONVENTIONS.md §15); case values equal the CHECK lists exactly, stored as `varchar` — no Postgres `ENUM` types. Adding a value = one migration that drops and recreates the CHECK, plus the enum case, plus this document.

| Namespace | Enum class → table.column |
|---|---|
| `App\Domain\SaaS\Enums` | `TenantStatus` (tenants.status), `SubscriptionStatus`, `BillingCycle`, `SubscriptionInvoiceStatus`, `SubscriptionPaymentMethod`, `SubscriptionPaymentStatus`, `DomainType`, `DomainVerificationStatus`, `SslStatus`, `BackupType`, `BackupStatus`, `UsageMetric` (usage_counters.metric), `PlanFeatureKey` (plan_features.feature_key), `SuperTwoFactorPolicy` (the `security.super_two_factor` platform setting, §2.19 — a JSON vocabulary validated by the registry, no CHECK) |
| `App\Domain\Catalog\Enums` | `ReconciliationStatus` (catalog_reconciliation_reports.status), `CustomBrandPromotionStatus` (custom_brand_promotions.status), `CustomBrandReviewStatus` (custom_brands.review_status), `CatalogImportIssueKind` (catalog_import_issues.kind), `DosageFormCode` (dosage_forms.code), `RouteCode` (routes.code), `InteractionSeverity`, `EvidenceLevel`, `PregnancyCategory`, `LactationRisk`, `CautionLevel` (renal/hepatic_cautions.level), `DosePopulation`, `CatalogVersionStatus` |
| `App\Domain\Audit\Enums` | `CentralAuditAction` (audit_logs_central.action), `AuditAction` (audit_logs.action), `AuditActorType` (audit_logs.actor_type) |
| `App\Domain\Clinic\Enums` | `Role`, `Permission` (seeded spatie rows; ARCHITECTURE.md §6.2), `Gender` (doctors/patients.gender), `Locale` (tenants/users/patients locale columns), `PadPaperSize`, `PadOrientation`, `TokenSlipTemplate`, `LeaveType` (doctor_leaves.type) |
| `App\Domain\Patients\Enums` | `BloodGroup`, `PatientSource`, `PatientRelation`, `AllergenType`, `AllergySeverity`, `ConditionStatus`, `MedicationSource`, `DocumentType`, `OcrStatus`, `ConsentType`, `ConsentStatus`, `ConsentChannel`, `OtpPurpose`, `OtpChannel` |
| `App\Domain\Scheduling\Enums` | `ScheduleMode` (doctor_schedules.mode, session_instances.mode), `OverrideType` (schedule_overrides.type), `SessionStatus` (session_instances.status) |
| `App\Domain\Serials\Enums` | `SerialPool` (serial_pools.pool, serials.pool — SERIAL_ENGINE.md §4.1, CONVENTIONS.md §15), `SerialStatus`, `SerialPriority`, `SerialSource`, `CancelReason` (serials/appointments.cancel_reason_code), `SerialEventType`, `ActorType` (serial_events.actor_type) |
| `App\Domain\Booking\Enums` | `AppointmentType`, `BookingChannel` (appointments.channel), `AppointmentStatus`, `FeeRule`, `PaymentStatus` (appointments.payment_status) |
| `App\Domain\Reception\Enums` | `DeviceStatus`, `DeviceKind` (reception_devices.kind), `BlockStatus` (serial_blocks.status), `OfflineEventType`, `OfflineEventStatus`, `ConflictReason`, `ConflictResolution` (offline_events.resolution) |
| `App\Domain\Prescription\Enums` | `VisitType`, `VisitStatus`, `PrescriptionStatus`, `PrescriptionLanguage` (prescriptions.language, doctor_pad_settings.default_language), `DoseTiming` (prescription_items.timing), `ReferralType`, `InvestigationCategory`, `AdviceCategory` (advice_snippets.category), `AiSuggestionType`; code-only, no column: `SafetyStage`, `SafetySeverity` (PRESCRIPTION.md §5.1) |
| `App\Domain\Billing\Enums` | `InvoiceStatus`, `InvoiceItemType`, `PaymentMethod` (payments/refunds.method), `PaymentGateway`, `PaymentTxnStatus` (payments.status), `RefundStatus`, `RefundReason`, `DiscountType` (discounts/coupons.type), `DiscountReason`, `RevenueShareType`, `RevenueShareItemType`, `CashShiftStatus` |
| `App\Domain\Notifications\Enums` | `NotificationEvent` (event_key), `NotificationChannel`, `NotificationStatus`, `NotificationLogStatus`, `GatewayProvider` (sms_gateway_settings.provider) |
| `App\Domain\Telemedicine\Enums` | `TelemedicineProvider`, `RoomStatus`, `SessionEndReason`; code-only, no column: `ParticipantRole` (the `role` key of `telemedicine_sessions.participants`) |

JSON-only vocabularies (no CHECK; validated in code): `ParsedLine.unit`, `timing_code`, `schedule.type`, `duration.type`, `issues[].code` (PRESCRIPTION.md §2.11); `safety_overrides[].kind/severity` (§3.4); `drawing_json.canvas.template` (§3.4); `settings` keys and value types (Appendix B).

---

## Appendix B — Tenant settings registry (`settings.key`)

The closed list of dotted keys stored in `settings` (§3.1). `value` is jsonb of the stated type; a missing row means the default. A definition may carry `options` (an allowed set) or `pattern` (a regex the string must match), both enforced by `SettingsRegistry::validate()`, and **`secret`** (marked 🔒 below) for a credential. A secret is a storage contract, not a UI hint: `Settings::set()` encrypts it before it reaches `settings.value` (plain jsonb, and in every backup), `Settings::get()` decrypts it for module code, `Settings::all()` — the bulk read the settings screen renders — returns a mask (`••••1234`) instead, a blank submit means *keep the stored value* (clearing is the explicit `ForgetSetting` action) and the audit row carries `[redacted]`. `Settings::get('serial.elderly_skip')` is the only read path (Redis-cached per tenant under `t:{tenantId}:settings`, bigint tenant id); `Settings::set()` rejects unknown keys and wrong types. The prefix is `serial.` (singular). Branch-level presentation settings (`token_slip_width_mm`, `display_mode`) live in `branches.settings` jsonb, not here; per-doctor writer preferences live in `doctor_profiles.prefs`.

| Key | Type | Default | Meaning | Owner |
|---|---|---|---|---|
| `queue.auto_noshow_after` | int | `3` | Calls passed before a `booked` serial is auto no-showed; `0` disables; `doctor_schedules.auto_noshow_after` overrides per template and is copied onto `session_instances` | SERIAL_ENGINE.md §8 |
| `queue.auto_noshow_grace_minutes` | int | `10` | No auto no-show before `planned_start_at + delay + grace` | SERIAL_ENGINE.md §8 |
| `queue.notify_ahead` | int | `3` | "N ahead" notification distance | REALTIME.md §7 |
| `queue.delay_notify_min_change` | int (minutes) | `10` | Minimum change of `delay_minutes` before patients are notified again (dedupe bucket on `notifications`) | REALTIME.md §10 |
| `queue.display_voice` | string: `both`, `bn`, `en`, `off` | `"both"` | Waiting-room voice call-out languages | REALTIME.md §9.2 |
| `queue.public_page_enabled` | bool | `true` | Serve `/q/{doctorSlug}/today` and `GET /queue/{doctorSlug}/state` without login | REALTIME.md §5 |
| `serial.default_block_size` | int (1–30) | `5` | Offline block size when the device has no own `block_size`; `LeaseBlock` clamps to 30 | OFFLINE.md §4.1 |
| `serial.max_active_blocks_per_device` | int | `2` | Active blocks a device may hold per session (application rule, §3.3 `serial_blocks`) | OFFLINE.md §4.1 |
| `serial.block_topup_threshold` | int | `3` | Lease another block when the active one has ≤ this many numbers left (online only) | OFFLINE.md §4.2 |
| `serial.elderly_skip` | int | `2` | Waiting serials an `elderly` insert (and a reinstate) is placed after | SERIAL_ENGINE.md §7.3 |
| `serial.vip_enabled` | bool | `true` | Allow the `vip` priority | SERIAL_ENGINE.md §7.3 |
| `serial.expected_show_rate` | number 0–1 | `0.8` | Weight of `booked` (not yet arrived) serials in the ETA | SERIAL_ENGINE.md §13 |
| `serial.receptionist_extension_limit` | int | `0` | Extra serials a receptionist may add per session without Doctor/Admin approval | SERIAL_ENGINE.md §3.4 |
| `serial.cancel_cutoff_minutes` | int | `60` | Minutes before `planned_start_at` until which a patient may self-cancel online with `refund_eligible = true` (SERIAL_ENGINE.md §6 cites this key and default) | SERIAL_ENGINE.md §6, §15 |
| `kiosk.otp_required` | bool | `false` | Self-service booking (online site, kiosk / QR, telemedicine) sends a one-time code to the mobile and requires it before a serial is given. Off by default — the BRIEF §5.C flow is mobile + name → session → serial with no code step; a clinic that wants a verified number against no-shows switches it on. Read by `VerifiesBookingOtp` (the controllers) and enforced by `BookAppointment` (`OtpRequired`), so a client cannot skip it by omitting the field | BRIEF §5.C, SERIAL_ENGINE.md §11.2 |
| `kiosk.self_checkin_enabled` | bool | `false` | Patients may check themselves in from the kiosk page ("if tenant enables") | SERIAL_ENGINE.md §6 |
| `reception.pin_idle_minutes` | int | `15` | Desk PWA re-locks behind the local PIN after this idle time | OFFLINE.md §2.2 |
| `reception.sound_on_offline` | bool | `true` | Two-tone sound when the desk enters `offline` | OFFLINE.md §9 |
| `booking.online_payment_enabled` | bool | `false` | Offer online payment on the public booking site. `App\Domain\Billing\Services\BillingOnlinePaymentGateway` requires this **and** working gateway credentials (config/billing.php or the tenant's own `billing.gateway.*` rows); configuring a merchant account does not by itself open checkout to patients | BRIEF §5.I |
| `booking.self_service_daily_limit` | int (0–50) | `3` | Self-service (online / kiosk / telemedicine) bookings one mobile number — the whole household behind it — may make per clinic-local day (`tenants.timezone`, Asia/Dhaka by default). The abuse guard that stands in for the OTP when `kiosk.otp_required` is off: every booking created that day counts, cancelled or not; staff channels (counter, phone, walk-in, follow-up) are never counted or limited; `0` removes the cap. Enforced in `BookAppointment` → `SelfServiceLimitReached` (422, `booking.self_service_limit_reached`) on top of `throttle:booking` | BRIEF §5.C |
| `billing.vat_percent` | number | `0` | VAT applied to invoices | §3.5 |
| `billing.discount_approval_threshold_paisa` | int | `50000` | Discounts above this need `discounts.approved_by_user_id` | §3.5 |
| `notifications.quiet_hours_enabled` | bool | `false` | Hold non-urgent messages overnight. Off until a clinic defines a window (BRIEF §5.J "if settings define them"); `NotificationEvent::isUrgent()` overrides it either way | BRIEF §5.J |
| `notifications.quiet_hours_start` | string `HH:MM` | `"21:00"` | Start of the quiet window, clinic-local (`tenants.timezone`). Validated against `/^([01]\d\|2[0-3]):[0-5]\d$/` | BRIEF §5.J |
| `notifications.quiet_hours_end` | string `HH:MM` | `"08:00"` | End of the quiet window; a window that wraps past midnight is normal. A message produced inside the window is stored `scheduled` for this time, never dropped | BRIEF §5.J |
| `security.session_timeout_minutes` | int | `120` | Staff session lifetime; `users.session_timeout_minutes` overrides per user | §3.1, ARCHITECTURE.md §6.1 |
| `telemedicine.provider` | string: `default`, `agora`, `livekit`, `jitsi`, `null` | `"default"` | Which video driver this clinic uses; `default` follows `config('telemedicine.default')` | BRIEF §5.K |
| `telemedicine.host` | string | `""` | LiveKit URL (`wss://…`) or Jitsi domain; empty falls back to `config/telemedicine.php` | BRIEF §5.K |
| `telemedicine.api_key` | string 🔒 | `""` | Provider API key / Jitsi app id / Agora app id | BRIEF §5.K |
| `telemedicine.api_secret` | string 🔒 | `""` | Provider API secret / Agora app certificate. Encrypted at rest by the settings service, whichever screen writes it (`TelemedicineSettings::storeSecret()` encrypts for itself and is detected, never wrapped twice) | BRIEF §5.K |
| `telemedicine.recording_enabled` | bool | `false` | Allow consultation recording (a granted `telemedicine` patient consent is ALSO required per call) | BRIEF §5.K |
| `telemedicine.max_minutes` | int (5–240) | `45` | Cap on one consultation, snapshotted onto `telemedicine_rooms.settings` | BRIEF §5.K |
| `patients.ocr_driver` | string: `default`, `null`, `google` | `"default"` | OCR engine for uploaded reports; `default` follows `config('patients.ocr.driver')`, which ships as `null` (nothing leaves the clinic) | PRESCRIPTION.md §8 |
| `patients.ocr_api_key` | string 🔒 | `""` | The clinic's own Vision key; empty falls back to `config('patients.ocr.key')` | PRESCRIPTION.md §8 |

---

## Appendix C — Decisions log (2026-09-06)

Cross-document decisions taken while reconciling this document with ARCHITECTURE.md, CONVENTIONS.md, SERIAL_ENGINE.md, OFFLINE.md, REALTIME.md, PRESCRIPTION.md and CATALOG.md. Every decision is applied in every document — nothing here is pending. Naming authority: this document for tables and columns, CONVENTIONS.md §15 for classes, routes and keys.

1. `public_id` is a 26-char ULID everywhere (SQL placeholder `:ulid`); no UUIDs of any version.
2. `tenants.schema_name varchar(63)` holds the Postgres schema (`tenant_{id}`; test tenants 9001/9002 use `tenant_test_a`/`tenant_test_b`); code reads `$tenant->schema_name`, never derives it.
3. `serial_events` details go in `meta` (there is no `payload` column) and `actor_type` is NOT NULL; raw SQL writes the CHECK-list values (`capacity_extended`, `online_released`, `block_leased`, `printed`, …) — the dotted names in SERIAL_ENGINE.md/OFFLINE.md prose map onto them per SERIAL_ENGINE.md §19.7.
4. `serial_blocks.serial_pool_id` stays NOT NULL: the counter pool for device leases, the online pool for a desk-owned range released by `ReleaseOnlineToCounter`.
5. Zero-quota pools are the empty range `range_end = range_start - 1`; both exclusion constraints are half-open `int4range(range_start, range_end + 1)`.
6. Pool layout is counter → online → buffer; released block numbers are reused through the released-row free-list; a device may hold `serial.max_active_blocks_per_device` (2) active blocks — an application rule under the counter-pool lock, no partial unique index.
7. `serials`: `serial_block_id IS NULL OR source = 'offline'` (an offline-sourced serial may be pool-allocated by a conflict resolution); session-scoped idempotency index `(session_instance_id, client_event_id)`; `appointments.channel` includes `counter` and `offline`; `cancel_reason_code` includes `session_cancelled`; `serial_events.type` is `varchar(32)` and `offline_events.resolution` `varchar(24)`.
8. `branches`, `users`, `session_instances`, `reception_devices` and `serial_blocks` carry `public_id`; `doctors.room_label` exists for the display payload.
9. Tenant settings keys use the singular `serial.*` prefix; the closed registry is Appendix B (`serial.cancel_cutoff_minutes` defaults to 60).
10. The column is `appointments.follow_up_of_visit_id`; a draft follow-up appointment is `status = draft`, `type = followup`, `channel = followup` with `follow_up_of_visit_id` — there is no `prescription_id` column (reach the prescription through `visits.current_prescription_id`).
11. Delay and 3-ahead notification dedupe query `notifications (serial_id, event_key, created_at)` and `notifications.dedupe_key`; `notification_logs` has neither column.
12. Redis keys are `t:{tenantId}:…` with the bigint tenant id (`t:{tenantId}:qs:{sessionPublicId}`, `…:v`, `…:cap:…`, `…:settings`); broadcast channels are `tenant.{tenantPublicId}.…` with ULIDs; the ETag is the quoted integer `session_instances.version`; the only accessor is `App\Domain\Queue\Services\QueueStateRepository` — the earlier snapshot-store class, the `{version}-{unix_ms}` ETag and the `tenant.{id}.session.{id}` channel are withdrawn.
13. Sync replay statuses are `accepted | conflict | rejected | pending`, keyed by `client_event_id`; `offline_events.type` keeps `mark_arrived`, `cancel_serial`, `assign_patient` as reserved values; the slip-print event type is `printed`.
14. Catalog rows carry `catalog_version_id`, `is_active` and `timestampsTz()` (`CatalogModel::$timestamps = true`; the idempotency test compares `updated_at`); the DGDA number is `brands.dar_number`; the import fingerprint is `catalog_versions.checksum_sha256`; lookup keys are `varchar` with `lower()`/plain unique indexes (no `citext`); the catalog has 16 tables including `drug_information` and `catalog_import_issues`; `CatalogModel` is read-only through `CatalogWriteContext`.
15. Model class names are this document's **Model** lines (`DoctorPadSetting`, `SessionInstance`, `PatientConsent`, `AiSuggestion`, `Invoice`, `DoctorRevenueShare`, `SmsGatewaySetting`, `TelemedicineSession`, `AuditLogCentral`, `CustomBrandPromotion`, `CatalogImportIssue`, `App\Models\Tenant\CustomBrand`); prescription versions are `prescriptions` rows, handwriting pages are paths and diagnoses are `visits.diagnoses` jsonb — there is no version, handwriting-sheet or diagnosis child model.
16. Enums live in `App\Domain\<Module>\Enums` with the names of Appendix A (`App\Domain\Serials\Enums\SerialPool` for the pool column; `SessionStatus` under Scheduling); Pennant feature classes live in `App\Domain\SaaS\Features`.
17. Spatie roles are snake_case — `hospital_admin`, `doctor`, `receptionist` (compounder), `accountant`; Patient is a guard, not a role; Super Admin is central (`super` guard). Permission strings follow the `<module>.<resource>.<action>` grammar of `App\Domain\Clinic\Enums\Permission` (`serials.split.adjust`, `queue.call-next`, `reception.devices.register`, …); older flat spellings are retired.
18. `audit_logs.impersonator_super_admin_id` is the impersonation marker; `public.impersonation_tokens` is a real table (§2.17, ARCHITECTURE.md §6.5).
19. Tenant statuses are `trial, active, past_due, suspended, cancelled` (`cancelled` is the 404 case; there is no `archived`); `domains` are resolved by `domain` and `verification_status = 'verified'`.
20. Plan limits are `plan_features` rows overridden by `subscriptions.feature_overrides`; usage metrics are those of §2.10 (`appointments`, `storage_bytes`, `sms_credits`, …) — there is no `plans.limits` column.
21. Meilisearch indexes are exactly the four of §5.6 (`catalog_drugs`, `catalog_icd10`, `t{tenantId}_patients`, `t{tenantId}_custom_brands`); investigations and advice snippets are Postgres `ILIKE`; index settings live in `config/catalog.php['search']`.
22. Storage object keys are `tenants/{tenant_id}/…`, produced only by `App\Support\Storage\TenantPath::for()`.
23. Consent types are those of `patient_consents` (`data_sharing`, `sms`, `whatsapp`, …); prescription delivery defaults are derived from them.
24. Panel route parameters bind `public_id` for prescriptions, visits and patients (`{prescription}`, `{visit}`, `{patient}`); integer ids appear only inside authenticated JSON bodies for tables without `public_id`.
25. Print language and signature live on `doctor_pad_settings` (`default_language`, `signature_path`) so `pad_snapshot` is self-contained; writer preferences are `doctor_profiles.prefs`; `prescriptions.mode` is the snapshot key `snapshot.prescription.mode`, not a column.
26. Soft deletes exist on `tenants`, `super_admins`, `branches`, `users`, `doctors`, `patients`, `prescription_templates`, `custom_brands`; offline-capable tables are `serials`, `appointments`, `payments`, `offline_events` (not `vitals`); `doctor_specialties` keeps its plural name.
27. Scheduled commands are `sessions:materialise`, `sessions:close-stale`, `queue:refresh-eta`, `catalog:reconcile`, `prescriptions:recompute-favourites`, `notifications:send-reminders`, `tenants:backup`; Horizon queues are exactly `critical, default, notifications, pdf, search, reports, backups`.
