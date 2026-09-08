# SaaS control plane (Module M) — decisions and contracts

Owner: engineer A-saas. Companion to BRIEF §5.M/§5.N, SCHEMA §2, ARCHITECTURE §4/§6.5/§8.4.
Nothing here re-specifies those documents; it records the decisions this module had to take.

## 1. The subscription state machine

```
trialing ──(trial ends, free plan)─────────────────────────────► active
    │                                                              │  ▲
    └──(trial ends, paid plan → first invoice issued)──► past_due ◄─┘  │ payment clears the arrears
                                                            │          │
active ──(invoice passes due_at unpaid)──────────────────► past_due    │
                                             grace expires  ▼          │
                                                        suspended ─────┘
any ──(cancel now)───────────────────────────────────────► cancelled
active ──(cancel_at_period_end, period ends)─────────────► expired
```

`tenants.status` mirrors the CURRENT subscription (`tenants.current_subscription_id`); add-ons never move it.
`SubscriptionLifecycle` is the only writer. `expired` maps to a SUSPENDED tenant, never a cancelled one: the
clinic's data is intact and one payment brings it back, so it must see the Suspended page, not a 404.

What each status does to a tenant request is `EnsureTenantIsActive`'s existing contract and is unchanged:

| tenant status | every request on the tenant host |
|---|---|
| `trial`, `active` | served normally |
| `past_due` | served, with `tenancy.past_due_banner` flashed into the session |
| `suspended` | 402 + the site `Suspended` page; JSON gets 402 `{"code":"tenancy.suspended"}` |
| `cancelled` | 404 |

**Dunning** (`App\Domain\SaaS\Support\DunningSchedule`, days after `due_at`): reminders at 0, +3, +7; auto-suspend
at +10. `subscription_invoices.dunning_step` is the ratchet, so the sweep may run hourly and each clinic still
receives each notice exactly once. Invoices are net 7 days.

**Proration: none, deliberately.** Entitlements change the moment the plan does (an upgrade is usable immediately,
for the rest of the period already paid for, at no extra charge); the PRICE changes at the next renewal. A
downgrade keeps the better plan's caps until the paid period ends. Reason: the platform bills BDT in whole
periods, collection is bKash/Nagad/bank transfer rather than a stored card, and there is no refund rail — a
proration credit would be an IOU nobody can settle, whereas "your new price starts next month" is a sentence a
clinic manager can check against a bank statement. If payment instruments are ever stored, the delta is one extra
invoice line in `ChangePlan` and nothing else changes.

## 2. Limits: where they are enforced and where the counters are written

`public.usage_counters` (SCHEMA §2.10) is written by exactly one class, `UsageMeter`, with SCHEMA §5.8's protocol:

```sql
insert into public.usage_counters (tenant_id, metric, period, value, …)
values (…) on conflict (tenant_id, metric, period)
do update set value = greatest(0, public.usage_counters.value + ?) returning value
```

One statement, so Postgres serialises concurrent writers on the row and hands each a DISTINCT post-increment
value. `PlanLimits::reserve()` compares that value with the cap and compensates + refuses if it crossed it. That
is what makes the cap hold under a race (`tests/Concurrency/SaaS/PlanLimitConcurrencyTest`); a read-then-write
gate would let every worker read the same "one left".

The gate runs on `Model::creating` (`App\Domain\SaaS\Observers\**`), not inside the module Actions ARCHITECTURE
§8.4 names, for two reasons: an after-commit listener cannot refuse anything, and a check inside one Action is
only as strong as the number of write paths that go through it — the offline replay, a seeder, an import and a
console command all bypass it. `Model::creating` is on every Eloquent write path in the process and runs inside
the caller's transaction, so a failed write un-counts itself.

| metric | shape | written by | cap |
|---|---|---|---|
| `branches` | gauge | `BranchUsageObserver` (create / activate / deactivate / delete / restore) | `branches` |
| `doctors` | gauge | `DoctorUsageObserver` | `doctors` |
| `storage_bytes` | gauge | `PatientDocumentUsageObserver` (± `size_bytes`) | `storage_bytes` |
| `appointments` | monthly | `AppointmentUsageObserver` (`draft` rows are not bookings) | `appointments_monthly` |
| `sms_credits` | monthly | `NotificationUsageObserver` at queue time; released on a permanent gateway refusal | `sms_credits_monthly` |
| `prescriptions` | monthly | `RecordPrescriptionUsage` on `PrescriptionIssued` | uncapped |

Gauges are recomputed nightly by `saas:recount-usage`, so drift from a raw write, a restore or a schema swap
self-heals instead of accumulating. Monthly meters use the CLINIC's calendar month (`tenants.timezone`).

SMS is the one cap that must not throw: SCHEMA §5.8 requires the message to be stored
`status='failed', last_error='sms_credits_exhausted'`, because a patient's booking must not fail over the clinic's
credit balance.

Route-level gate: the `plan:` middleware alias (`EnsurePlanAllows`) — `plan:telemedicine`, `plan:branches`,
`plan:appointments_monthly,5`. It is the second line, for closing a whole section cheaply and with a message; the
observers are what cannot be walked around.

## 3. Entitlements and feature flags

`plan_features` rows of every LIVE subscription (`trialing|active|past_due`), UNIONed for add-ons, with
`subscriptions.feature_overrides` applied on top (SCHEMA §2.4 — overrides win). `null` limit = unlimited, `0` =
not included; a key the plan never mentions is unlimited for numbers and OFF for modules.

Pennant (`App\Domain\SaaS\Features\*`, `public.feature_flags`) is the READ API the rest of the app already uses
(`Feature::active('ai-assist')`) and a cache of the above — never the truth. Any change to a plan, an override or
a subscription purges the resolved rows (`FeatureFlagCache`), so the next request re-resolves. Feature resolvers
take `mixed` because Pennant hands them either the scope object or its `tenant:{id}` serialisation, and typing it
`?Tenant` silently empties `Feature::for($tenant)->all()`.

## 4. Impersonation

Mint (`super`) → 64 random chars, only `sha256` stored, 60 s, bound to one tenant and one active user, audited.
Redirect to `https://{tenant-host}/panel/impersonate/{token}`. Consumption is ONE conditional UPDATE
(`… set consumed_at = now() where token_hash = ? and tenant_id = ? and consumed_at is null and expires_at > now()`)
and the caller is let in only when it affected exactly one row — that is what makes it single-use under
concurrency. The session carries `impersonated_by`, which `AuditRecorder` stamps on every tenant audit row and
`HandleInertiaRequests` exposes as `auth.impersonating` (the persistent banner). Exit logs out, invalidates the
session and writes `audit_logs_central.action = 'impersonate_end'`.

## 5. Custom domains

`domains` rows start `pending` and `TenantResolver` only ever joins on `verification_status = 'verified'`, so an
unverified claim routes nothing. Proof is a TXT record `bp-verify={token}` at `_bp-verify.{host}` (the bare host
is accepted as a fallback). Apex vs subdomain changes only the ADDRESS record we advise — an apex cannot carry a
CNAME (RFC 1034 §3.6.2) so it gets an A record. The scheduled sweep never demotes a live domain; only a deliberate
re-verification does, so a registrar outage cannot take a clinic's booking site off the air.

Note: `TenantResolver` strips a leading service label (`queue`, `book`, `display`) before looking a host up, so
`queue.hospital.com` resolves by asking for `hospital.com` — a clinic wanting the queue there registers the base
domain. Pinned by `DomainVerificationTest::test_a_service_prefixed_custom_host_resolves_through_its_base_domain`.

## 6. Paying a platform invoice

On the CENTRAL host, behind a signed URL. A suspended tenant's own host answers 402 on everything, so a pay page
in the panel would be unreachable at exactly the moment the customer wants it. The gateway contract mirrors
Billing's three steps and reuses its DTOs; `SubscriptionGatewayDriver` exists separately only because Billing's
is typed against tenant `Invoice`/`Payment` models. `LogSubscriptionGateway` is the reference implementation;
with no platform credentials the gateway is simply not offered and a super admin records the bank transfer, which
is how platform collection actually works in this market.

## 7. Central marketing copy is a server prop, not a lang bundle

The site ships ONE translation chunk per locale, shared by every site route, and the worst tenant route already
sits at 90.7 KB of the 95 KB first-load budget. The marketing/pricing/sign-up/invoice copy is ~4.5 KB gzip, so
adding a `saas.` prefix to `SITE_KEY_PREFIXES` would charge every patient booking page for text only the central
host renders — and fail the build. The strings stay in `resources/lang/*.json` (translated, `lang:check`-verified)
and `App\Domain\SaaS\Support\CentralCopy` hands each page the blocks it uses;
`resources/js/site/Components/Central/__tests__/copy.test.ts` is the guard that every `c('…')` key exists.
