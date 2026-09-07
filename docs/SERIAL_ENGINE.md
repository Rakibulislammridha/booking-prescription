# Serial (Token) Engine — Design Specification

Module owner: modules B (schedule & slot engine), C (booking channels), D (serial
engine) of `docs/BRIEF.md`. Companion documents: `docs/OFFLINE.md` (block leases
and replay), `docs/REALTIME.md` (queue state and broadcasting), `docs/SCHEMA.md`
(tables — this document uses those table names verbatim).

Code locations (ARCHITECTURE.md §5.3, CONVENTIONS.md §2 — one engineer owns both modules):
materialisation and templates in `App\Domain\Scheduling\{Services,Jobs,Actions}`; everything that
touches `session_instances`, `serial_pools`, `serials`, `serial_events` — allocation, transitions,
positions, capacity, split changes and the session lifecycle actions (`StartSession`, `PauseSession`,
`ResumeSession`, `CloseSession`, `CancelSession`, `DelaySession`, `ExtendSessionCapacity`,
`ChangePoolSplit`, `ReleaseOnlineToCounter`) — in `App\Domain\Serials\{Actions,Services,Data,Events,Enums,Exceptions}`.
Enums are SCHEMA.md Appendix A's names under `App\Domain\Serials\Enums` (`SerialStatus`, `SerialPool`,
`SerialSource`, `SerialPriority`, `CancelReason`, `SerialEventType`, `ActorType`); `SessionStatus`, `ScheduleMode` and
`OverrideType` sit in `App\Domain\Scheduling\Enums`. Domain exceptions extend
`App\Domain\Shared\Exceptions\DomainException`; their `code()` is dotted (`serials.pool_exhausted`) and
conflict-type ones return `status()` 409 (ARCHITECTURE.md §2). `Actor` below is `App\Domain\Shared\Actor`.

LOCKED by the brief and honoured here: a serial is scoped to
(branch, doctor, session, date) = one `session_instances` row; display format is
`A-042`; atomic allocation under a row lock; daily reset; online pool never
consumes counter serials; only Super Admin and Doctor change the online/counter
split; every reorder writes an audit entry; statuses
Booked → Checked-in → In Consultation → Completed plus No-show, Cancelled,
Postponed; auto no-show after N passed with reinstate; transfer to another
doctor.

---

## 1. Definitions and invariants

| Term | Meaning |
|---|---|
| Session template | `doctor_schedules` row (weekday, `session_code` `A`/`B`/…, start/end time, `mode`, `max_serials`, `online_quota`, `counter_quota`, `buffer_quota`, `avg_consult_minutes`, `slot_minutes`, `auto_noshow_after`). There is no separate `doctor_sessions` table (SCHEMA.md). Owned by Scheduling. |
| Session instance | `session_instances` row for one (branch, doctor, session_date, session_code). The only allocation scope. |
| Pool | `serial_pools` row: `online`, `counter`, `buffer`, each a contiguous number range with a cursor `next_number`. |
| Block | `serial_blocks` row: sub-range carved from the counter pool, leased to a `reception_devices` row for offline issuing (OFFLINE.md). A block with `reception_device_id IS NULL` is a *desk-owned* released range (free-list, §3.5). |
| Number | `serials.number`, integer, UNIQUE with `session_instance_id` (`serials_session_number_uniq`). Identity. Never changes. `serials.pool` records the range it came from. |
| Position | `serials.position`, integer. Calling order. Changes on reorder / priority insert / reinstate. |
| Display code | `serials.display_code` = `{session_code}-{number zero-padded to 3}` e.g. `A-042`. |

Invariants the engine must never violate (each has a test in §18):

1. **I-UNIQUE** — no two `serials` rows share `(session_instance_id, number)`. Enforced by the unique index *and* by the lock discipline; the index is the backstop, not the mechanism.
2. **I-OWNER** — at any instant every unissued number of a session belongs to exactly one *owner row*: a `serial_pools` row (numbers `next_number..range_end`) or a `serial_blocks` row (numbers `next_number..range_end`). Owner rows never overlap. A number is issued only by the transaction that holds `FOR UPDATE` on its owner row.
3. **I-RANGE** — `serials.number` is inside the range of the pool the serial was allocated from (or of the block, for `source = offline`).
4. **I-ONLINE-NEVER-COUNTER** — `source in ('online','kiosk')` never produces a number from the counter or buffer pools' ranges; the online pool never receives numbers from the counter pool (§3.6).
5. **I-POSITION** — `position` is unique per session instance among non-terminal serials (enforced in application code under the session lock; a non-unique partial index is not used because renormalisation temporarily reassigns).
6. **I-EVENTS** — every status change, reorder, priority insert, transfer, postpone, capacity change and pool split change writes one `serial_events` row in the same transaction.

---

## 2. Session instance materialisation

### 2.1 Inputs and precedence

Instances are derived from four tables. Precedence, highest first:

| # | Source | Effect |
|---|---|---|
| 1 | `schedule_overrides` for (doctor_id, branch_id, override_date, session_code or NULL = all) | `type = cancelled` → no instance (or existing one cancelled); `late_start` → `delay_minutes`; `cut_short`/`time_change` → planned times; `capacity_change` → quotas (§3.6 rules: untouched instance → rewrite; live instance → buffer extension only); `extra_session` → creates an instance on an off day; `applied_at` stamped when absorbed |
| 2 | `doctor_leaves` with `starts_on <= date <= ends_on`, `is_cancelled = false` (doctor_id, optional branch_id) | no instance; existing instances → `CancelSession` (type `emergency` notifies immediately) |
| 3 | `holidays` with `holiday_date = date` for the branch or all branches, unless `doctor_schedules.works_on_holidays = true` (schema addition) | no instance |
| 4 | `doctor_schedules` (doctor_id, branch_id, weekday, session_code, start/end time, mode, quotas, `avg_consult_minutes`, `effective_from/to`, `is_active`) | the weekly template — one row per (doctor, branch, weekday, session_code) |

An override of `type = extra_session` creates an instance on a day the weekly template has none (e.g. a one-off Friday session). Overrides win over leaves and holidays so an admin can explicitly open a session on a holiday.

### 2.2 When rows are created

Two paths, both idempotent:

1. **Scheduled**: `php artisan sessions:materialise {--days=14} {--tenant=} {--date=}` (`App\Console\Commands\Scheduling\MaterialiseSessionsCommand`) is registered by `App\Domain\Scheduling\Schedule` at `00:10` Asia/Dhaka (ARCHITECTURE.md §4.7 — modules never edit `routes/console.php`). It runs in central context and dispatches one `App\Domain\Scheduling\Jobs\MaterialiseTenantSessions` (uses the `TenantAware` trait, queue `default`) per active tenant, which calls `SessionMaterialiser::materialiseRange(today, today + days)` in the tenant's timezone.
2. **On demand**: any read of the public calendar, any booking, any block lease and any board load calls `SessionMaterialiser::ensure(branchId, doctorId, date, sessionCode)` for the dates it touches. This covers new tenants, newly created schedules and the horizon beyond `--days`.

```php
namespace App\Domain\Scheduling\Services;

final class SessionMaterialiser
{
    /** Ensure all instances for one doctor+branch on one date exist. Returns them. */
    public function ensureDay(int $branchId, int $doctorId, CarbonImmutable $date): Collection;

    /** Ensure one specific instance exists (or return null if the schedule yields none). */
    public function ensure(int $branchId, int $doctorId, CarbonImmutable $date, string $sessionCode): ?SessionInstance;

    /** Scheduled sweep for every doctor/branch in the tenant. */
    public function materialiseRange(CarbonImmutable $from, CarbonImmutable $to): int;

    /** Re-apply template/override changes to an existing instance if it is still untouched. */
    public function resync(SessionInstance $instance): ResyncResult; // applied | refused_has_serials | refused_has_blocks
}
```

### 2.3 Idempotent creation (the critical statement)

Uniqueness is guaranteed by the unique index on
`session_instances (branch_id, doctor_id, session_date, session_code)`. Creation is a
single transaction:

```sql
BEGIN;
INSERT INTO session_instances
  (public_id, branch_id, doctor_id, doctor_schedule_id, session_date, session_code, status, mode,
   planned_start_at, planned_end_at, delay_minutes, max_serials, online_quota, counter_quota, buffer_quota,
   slot_minutes, avg_consult_seconds, consult_samples, auto_noshow_after, fee_new_paisa, fee_followup_paisa, version)
VALUES (:ulid, :branch, :doctor, :template, :date, :code, 'scheduled', :mode,
        :start, :end, 0, :max, :o, :c, :b, :slot_minutes, :seed_avg, 0, :auto_noshow_after, :fee_new, :fee_fu, 1)
ON CONFLICT (branch_id, doctor_id, session_date, session_code) DO NOTHING
RETURNING id;
-- rowcount 1  => we own creation: insert the three pools (2.4) in this same transaction
-- rowcount 0  => another transaction created it; Postgres made us wait for its commit, so
--                the instance AND its pools are now visible. SELECT and return.
COMMIT;
```

Laravel: `DB::table('session_instances')->insertOrIgnore([...])` emits
`ON CONFLICT DO NOTHING`; check the returned affected-row count. Do **not** use
`firstOrCreate` (SELECT-then-INSERT races under Octane workers).

`seed_avg` = `doctor_schedules.avg_consult_minutes * 60` (template default 6 min → 360 s); `auto_noshow_after` = template value, else `settings` key `queue.auto_noshow_after` (default 3); fee snapshots per SCHEMA.md §5.10.

### 2.4 Resync rules

`resync()` re-reads the template + override and updates
`planned_start_at`, `planned_end_at`, `max_serials`, `mode` and the pool ranges
**only if** every `counts` value is 0 and no `serial_blocks` row exists. Otherwise
it returns `refused_*` and the UI tells the admin to use an override action
instead (`DelaySession`, `ExtendSessionCapacity`, `CancelSession`).

### 2.5 Session status lifecycle

`scheduled → running → (paused ⇄ running) → closed`; `scheduled|running|paused → cancelled`.

| Transition | Trigger | Side effects |
|---|---|---|
| scheduled → running | first `CallNext`, or Doctor/Reception `StartSession`, or `DoctorArrived` | `actual_start_at = now()`, `DoctorArrived` broadcast |
| running → paused | Doctor `PauseSession` | ETA freezes (§13), `SessionPaused` event |
| paused → running | Doctor `ResumeSession` | `pause_seconds` accumulated (schema addition §19) |
| any → closed | Reception/Doctor `CloseSession`; `sessions:close-stale` at 23:55 | locks all 3 pools, releases every active block (OFFLINE.md §4.5), auto no-shows remaining `booked`/`checked_in` with reason `session_closed`, `actual_end_at = now()`, `SessionClosed` event |
| any → cancelled | Hospital Admin/Doctor `CancelSession(reason)` or override `kind = cancelled` | every non-terminal serial → `cancelled` reason `session_cancelled` (emits `SerialCancelled` for billing refund hooks), `SessionCancelled` broadcast + notification fan-out |

---

## 3. Pools

### 3.1 Layout and sizing — decision

For an instance with `counter_quota = C`, `online_quota = O`, `buffer_quota = B`,
`max_serials = C + O + B`:

```
counter : 1            .. C
online  : C + 1        .. C + O
buffer  : C + O + 1    .. C + O + B
```

Each pool allocates ascending from its `range_start`; `next_number` starts at
`range_start`. A pool with quota 0 gets a row with `range_start = range_end + 1`
(empty, `next_number = range_start`) so lookups never miss.

Justification:

- **Counter first.** Counter numbers are the ones written on paper slips, shouted in a corridor and typed by receptionists; small numbers are cheapest to say and mis-type least. Offline blocks (OFFLINE.md) are carved from the counter pool's low end, so a device that goes offline at 08:00 is printing `A-001 … A-010`, exactly what staff expect.
- **Online in the middle.** The online pool is the only one that can be *closed* mid-session (§3.6); its unissued remainder is then adjacent-from-below to the buffer and adjacent-from-above to the counter's issued numbers, which lets us hand it to the counter as a released range without any renumbering.
- **Buffer last.** The one capacity change that happens every day — "the doctor agrees to see 5 more" — becomes `buffer.range_end += 5`, an append above every existing number. It can never collide with anything, so it needs no coordination with online booking, blocks or the desk.
- `position = number × 1 000 000` at allocation (§7), so this layout also gives a sane default calling order: counter (booked in person/phone) first, online next, walk-ins last, with priority insert to override.

### 3.2 Which channel draws from which pool

| `serials.source` | Pool | Who | Notes |
|---|---|---|---|
| `online` | online | patient on the public site | never spills |
| `kiosk` | online | patient scanning the desk QR | kiosk is a self-service *online* channel physically at the desk; it must not eat the counter's numbers, which are the receptionist's working capital |
| `counter` | counter | Reception (phone or in person) | never spills; when exhausted the patient present in person is issued as `walkin` |
| `walkin` | buffer | Reception | when exhausted → `ExtendSessionCapacity` (§3.4) |
| `followup` | counter if created by staff, online if created by the patient | see §11.3 | |
| `offline` | the device's block (counter range) | Reception PWA replay | OFFLINE.md |

A `priority` of `elderly|emergency|vip` does not change the pool; it changes
`position` (§7). Emergencies at the door are `walkin` + `priority = emergency`.

### 3.3 Exhaustion

`AllocateSerial` throws `App\Domain\Serials\Exceptions\PoolExhausted(pool, sessionInstanceId)`
(`status()` 409, `code: "serials.pool_exhausted"`, body includes the other pools' remaining
counts so the UI can offer the right next step):

| Pool exhausted | Online site | Reception desk |
|---|---|---|
| online | "No online serials left. Call {branch phone}." | n/a |
| counter | n/a | button "Issue as walk-in (buffer: 3 left)" |
| buffer | n/a | button "Request extension" → §3.4 |

Spill-over is **never automatic**. Every cross-pool movement is an explicit,
role-checked, audited action (§3.4, §3.6).

### 3.4 Capacity extension

```php
namespace App\Domain\Serials\Actions;

final class ExtendSessionCapacity
{
    /** @throws IllegalSessionState */
    public function handle(SessionInstance $instance, int $extraSerials, Actor $actor, ?string $reason): SessionInstance;   // several params → handle(), CONVENTIONS.md §4
}
```

Authorised: Doctor (own sessions), Hospital Admin, Super Admin. A tenant setting
`serial.receptionist_extension_limit` (default 0) lets a receptionist extend by
at most that many per session without approval.

```sql
BEGIN;
SELECT id, range_end FROM serial_pools WHERE session_instance_id = :sid AND pool = 'buffer' FOR UPDATE;
UPDATE serial_pools SET range_end = range_end + :k WHERE id = :buffer_pool_id;
UPDATE session_instances SET max_serials = max_serials + :k WHERE id = :sid;
INSERT INTO serial_events (session_instance_id, serial_id, type, actor_type, actor_user_id, meta, occurred_at)
VALUES (:sid, NULL, 'capacity_extended', 'user', :actor, '{"by": :k, "reason": :reason}', now());   -- SCHEMA.md §3.3: JSON goes in `meta`, `actor_type` is NOT NULL
COMMIT;
```

Emits `SessionCapacityExtended` → queue-state rebuild (REALTIME.md).

### 3.5 Released ranges (free-list) — how numbers come back to the counter

A `serial_blocks` row with `status = 'released'` and `next_number <= range_end`
is a set of counter-pool numbers that were handed out (to a device, or set aside
from the online pool by §3.6) and never issued. **Such numbers are reused**: the
counter pool drains released rows *before* advancing its own cursor.
Justification and the exact rules are in OFFLINE.md §4.6; the allocation-side
consequence is in §4.3 below (one extra `SELECT … FOR UPDATE SKIP LOCKED`).

### 3.6 Changing the online/counter split — decision

Super Admin and Doctor only (Hospital Admin cannot; brief D; permission `serials.split.adjust`, ARCHITECTURE.md §6.2).

1. **Template level** (`doctor_schedules.online_quota / counter_quota / buffer_quota`): affects future materialisations only.
2. **Untouched instance**: `ChangePoolSplit(instance, C', O')` rewrites the three ranges. Legal only while every pool has `next_number = range_start` and no `serial_blocks` exist; otherwise refused (`SplitLocked`, `code: "serials.split_locked"`, 409).
3. **Live instance — one-way release online → counter**: `ReleaseOnlineToCounter(instance, k | null)`. With `k = null` the whole unissued online remainder is released and online booking closes for that instance; with `k` the *top* `k` numbers of the online range are released (legal iff `online.next_number <= online.range_end - k + 1`).

```sql
BEGIN;
SELECT * FROM serial_pools WHERE session_instance_id = :sid AND pool IN ('online','counter') FOR UPDATE;
-- guard: :new_end >= online.next_number - 1
UPDATE serial_pools SET range_end = :new_end WHERE id = :online_pool_id;
INSERT INTO serial_blocks (public_id, session_instance_id, serial_pool_id, reception_device_id, range_start, range_end, next_number, status, released_at)
VALUES (:ulid, :sid, :online_pool_id, NULL, :new_end + 1, :old_end, :new_end + 1, 'released', now())
RETURNING id;   -- serial_pool_id stays NOT NULL: a desk-owned released range still belongs to the pool it was carved from (here the online pool)
INSERT INTO serial_events (session_instance_id, serial_id, type, actor_type, actor_user_id, meta, occurred_at)
VALUES (:sid, NULL, 'online_released', 'user', :actor, '{"range": [:new_end + 1, :old_end], "released_block_id": :block_id}', now());
COMMIT;
```

The reverse (counter → online) is **not provided**. The locked rule exists to
protect the receptionist's numbers from the internet; giving counter numbers to
the internet protects nothing and would require a second free-list mechanism
for the online pool. A doctor who wants more online capacity extends the buffer
and asks reception to issue from it, or changes the template for tomorrow.

---

## 4. `AllocateSerial` — the atomic allocation

### 4.1 Signature and DTO

```php
namespace App\Domain\Serials\Actions;

final class AllocateSerial
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PositionService $positions,
        private readonly DisplayCode $codes,
        private readonly SerialEventWriter $events,
    ) {}

    /**
     * @throws PoolExhausted
     * @throws SessionNotAcceptingSerials   (status closed|cancelled, or date in the past)
     * @throws SlotUnavailable              (slot mode only)
     * @throws AllocationRetryExhausted     (5 consecutive unique violations — alarm)
     */
    public function __invoke(AllocationRequest $request): Serial;
}

namespace App\Domain\Serials\Data;

final readonly class AllocationRequest
{
    public function __construct(
        public int $sessionInstanceId,
        public SerialPool $pool,             // online|counter|buffer (App\Domain\Serials\Enums\SerialPool)
        public SerialSource $source,         // online|counter|walkin|kiosk|followup|offline
        public SerialPriority $priority,     // normal|elderly|emergency|vip
        public ?int $patientId,              // null only for kiosk-before-OTP flows; must be set before check-in
        public ?int $appointmentId,          // set by the booking flow after the serial exists, or pre-created
        public ?string $clientEventId,       // ULID; idempotency key (offline replay, and online double-submit)
        public ?int $actorUserId,            // null for public site / kiosk
        public ?CarbonImmutable $slotStartAt,// slot mode only
        public ?int $transferredFromSerialId = null,
        public ?int $serialBlockId = null,   // only for source = offline (AllocateFromBlock delegates here)
    ) {}
}
```

### 4.2 Transaction, lock, insert, retry

```php
public function __invoke(AllocationRequest $r): Serial
{
    // 0. Idempotency (outside the lock; cheap): same client_event_id in the same session returns the existing row.
    if ($r->clientEventId !== null) {
        $existing = Serial::query()
            ->where('session_instance_id', $r->sessionInstanceId)
            ->where('client_event_id', $r->clientEventId)
            ->first();
        if ($existing) return $existing;
    }

    // 1. Whole allocation is one transaction; `attempts: 3` retries only on DeadlockException.
    return $this->db->connection('pgsql')->transaction(function () use ($r) {

        // 2. Lock the owner row FIRST. This serialises every allocation in this pool.
        $owner = $this->lockOwnerRow($r);   // §4.3 — returns ['kind' => 'pool'|'block', 'id', 'next_number', 'range_end']

        // 3. Session guard (plain SELECT is sufficient: CloseSession/CancelSession lock all pools before they change status).
        $session = SessionInstance::query()->findOrFail($r->sessionInstanceId);
        if (! in_array($session->status, ['scheduled', 'running', 'paused'], true)) {
            throw new SessionNotAcceptingSerials($session);
        }

        // 4. Take numbers until an insert succeeds (savepoint per attempt).
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            if ($owner['next_number'] > $owner['range_end']) {
                throw new PoolExhausted($r->pool, $r->sessionInstanceId);
            }
            $number = $owner['next_number'];
            $this->advanceCursor($owner);                 // UPDATE owner SET next_number = next_number + 1
            $owner['next_number']++;

            try {
                // Nested transaction() == SAVEPOINT on Postgres; a unique violation aborts only the savepoint.
                $serial = $this->db->connection('pgsql')->transaction(fn () => $this->insertSerial($r, $session, $number));
            } catch (UniqueConstraintViolationException $e) {
                $this->events->write($session, null, 'serial.number_skipped', ['number' => $number, 'reason' => 'unique_violation']);
                report(new AllocationDriftDetected($session, $number, $e));  // must never happen: invariant I-OWNER breached
                continue;
            }

            $this->events->write($session, $serial, 'serial.allocated', [...]);
            CountsRecalculator::run($session->id);      // §6.4 — also bumps session_instances.version
            SerialAllocated::dispatch($serial);          // ShouldDispatchAfterCommit — see §15
            return $serial;
        }
        throw new AllocationRetryExhausted($r->sessionInstanceId);
    }, attempts: 3);
}
```

Why it is correct:

- Every number is issued only while holding `FOR UPDATE` on its owner row (I-OWNER), so two concurrent transactions for the same pool are strictly serialised at step 2; the second one reads the incremented `next_number` after the first commits.
- `range_end` is checked *inside* the lock, so exhaustion is exact — the 26th request against a 25-number pool fails, never the 24th or 27th.
- The cursor advances even if the insert fails, so a skipped number is never handed out twice.
- The unique violation path is defence in depth: with I-OWNER intact it is unreachable; if it fires, the alert in `report()` tells us a block and a pool overlapped somewhere and the test in §18.3 must be re-run.
- Lock order is always: owner row (pool or block) → session row (read) → serials insert. `CloseSession`, `CancelSession`, `ExtendSessionCapacity`, `LeaseBlock`, `ReleaseBlock` take the same rows in the same order (pools by `pool` ascending alphabetical: `buffer`, `counter`, `online`), so deadlocks are limited to the rare `attempts: 3` retry.

### 4.3 `lockOwnerRow()` — the SQL

Counter pool (drains released ranges first, §3.5):

```sql
-- (a) try a released range; SKIP LOCKED lets two desks drain different released rows in parallel
SELECT id, next_number, range_end
FROM serial_blocks
WHERE session_instance_id = :sid AND status = 'released' AND next_number <= range_end
ORDER BY range_start
FOR UPDATE SKIP LOCKED
LIMIT 1;

-- (b) if none, the pool itself
SELECT id, next_number, range_end
FROM serial_pools
WHERE session_instance_id = :sid AND pool = :pool
FOR UPDATE;
```

Online and buffer pools: statement (b) only. `source = offline` (replay):
`SELECT … FROM serial_blocks WHERE id = :block_id AND reception_device_id = :device AND status = 'active' FOR UPDATE`
(OFFLINE.md §6.3 handles the "not active" cases before calling here).

`advanceCursor()`:

```sql
UPDATE serial_pools  SET next_number = next_number + 1, updated_at = now() WHERE id = :id;            -- pool owner
UPDATE serial_blocks SET next_number = next_number + 1,
                         status = CASE WHEN next_number + 1 > range_end THEN 'exhausted' ELSE status END,
                         updated_at = now()
WHERE id = :id;                                                                                     -- block owner
```

### 4.4 `insertSerial()`

```sql
INSERT INTO serials
  (public_id, session_instance_id, number, display_code, position, pool, status, priority, source,
   appointment_id, patient_id, serial_block_id, reception_device_id, client_event_id, transferred_from_serial_id,
   slot_start_at, booked_at, issued_by_user_id, created_at, updated_at)
VALUES
  (:ulid, :sid, :number, :display_code, :position, :pool, 'booked', :priority, :source,
   :appointment_id, :patient_id, :block_id, :device_id, :client_event_id, :transferred_from,
   :slot_start_at, now(), :actor, now(), now())
RETURNING *;
```

- `display_code` from `DisplayCode::format($session->session_code, $number)` (§5.2).
- `position` from `PositionService::initial($session, $number, $priority, $slotStartAt)` (§7.1). For `priority != normal` the action calls `PriorityInsert` *after* the insert, in the same transaction, so the audit trail shows both the allocation and the insert.
- Slot mode: `slot_start_at` set; the unique partial index `(session_instance_id, slot_start_at) WHERE slot_start_at IS NOT NULL` makes a double-booked slot a `UniqueConstraintViolationException` that is translated to `SlotUnavailable` (not retried — the *slot* is taken, not the number). See §10.

### 4.5 Correctness under Octane

- The action is stateless and resolved from the container per call; no static caches, no memoised session or pool objects.
- The PDO connection is reused across requests by Octane. `Tenancy::initialize()` (run by the global `ResolveTenant` middleware) therefore sets the search path to **exactly** `"tenant_<id>"` — never with a `public` fallback — on **every** request before any query (ARCHITECTURE.md §4.1), and the Octane `ResetTenancy` listener (`RequestTerminated`/`TaskTerminated`) resets it to `public` and asserts `DB::connection('pgsql')->transactionLevel() === 0`, force-rolling-back otherwise (a leaked transaction would keep pool locks and freeze booking clinic-wide; ARCHITECTURE.md §4.5).
- No lock is held across any I/O other than the database (no HTTP, no Redis, no broadcasting inside the transaction). Broadcast and notification side effects are `ShouldDispatchAfterCommit` events.
- `attempts: 3` in `transaction()` retries only `DeadlockException`; a retried closure re-runs the idempotency check, so a retry after a partial commit is impossible (Postgres rolled it back entirely).
- The concurrency test (§18.1) runs against a real Postgres with 24 OS processes, which is the same interleaving Octane workers produce.

---

## 5. Daily reset and display code

### 5.1 Daily reset

There is no reset job. A new `session_instances` row per (branch, doctor, date,
session_code) has fresh pools starting at `range_start`; yesterday's rows are
untouched history. The only day-boundary work is `sessions:close-stale`
(23:55): every instance with `date < today` and `status in (scheduled, running, paused)`
goes through `CloseSession` with `reason = stale`.

### 5.2 Display code

```php
namespace App\Domain\Serials\Services;

final class DisplayCode
{
    public static function format(string $sessionCode, int $number): string
    {   // 'A', 42 -> 'A-042';  'B', 1204 -> 'B-1204' (never truncates)
        return sprintf('%s-%s', strtoupper($sessionCode), str_pad((string) $number, 3, '0', STR_PAD_LEFT));
    }
    public static function parse(string $code): array; // ['session_code' => 'A', 'number' => 42]; throws InvalidDisplayCode
}
```

The code is stored on the row (not derived) so exports and old events print
identically even if the session code is renamed on the template later.
Bangla-digit rendering is a *presentation* concern (`Intl` on the client); the
stored code is ASCII.

---

## 6. Status state machine

Enum `App\Domain\Serials\Enums\SerialStatus`: `booked, checked_in, in_consultation, completed, no_show, cancelled, postponed`.
Terminal: `completed, cancelled, postponed`. "Mark arrived" and "check in" on the
desk are the **same** transition (`→ checked_in`); the desk shows one button.

| From | To | Action class | Who | Guards | Side effects (all in one transaction) |
|---|---|---|---|---|---|
| — | booked | `AllocateSerial` | any channel | pool not exhausted, session not closed/cancelled | `booked_at`; event `serial.allocated`; counts; `SerialAllocated` |
| booked | checked_in | `CheckInSerial` | Reception, kiosk self check-in (if tenant enables), offline replay | session not closed | `checked_in_at`; `serial.checked_in`; counts; `SerialStatusChanged` |
| booked | in_consultation | `CallSerial` (specific) | Doctor, Reception | patient physically present | implicit check-in: sets `checked_in_at` **and** `called_at`; two events |
| booked, checked_in | no_show | `MarkNoShow` (manual) / `ApplyAutoNoShow` (§8) | Reception, Doctor, system | — | `no_show_at`, `no_show_reason` in event; counts; `SerialNoShow` |
| booked, checked_in | cancelled | `CancelSerial(reasonCode)` | Patient (online, before the `serial.cancel_cutoff_minutes` tenant setting, default 60), Reception, Hospital Admin, system (session cancel) | — | `cancelled_at`; `serial.cancelled` with reason code; counts; `SerialCancelled` (billing refund hook §15) |
| booked, checked_in | postponed | `PostponeSerial` (§9.1) | Reception, Doctor | a target instance exists | new serial allocated in target; old gets `postponed_at`; `SerialPostponed` |
| booked, checked_in | cancelled (reason `transferred`) | `TransferSerial` (§9.2) | Reception, Doctor, Hospital Admin | target doctor session accepting | new serial in target session; `SerialTransferred`; patient notified |
| checked_in | in_consultation | `CallNext` / `CallSerial` | Doctor, Reception (call-next button, permission `queue.call-next`) | session running/scheduled | `called_at`; `now_serving_serial_id`; session `scheduled→running` if needed; `SerialCalled` (domain event; the Queue module's listener broadcasts it immediately — REALTIME.md §3) |
| in_consultation | completed | `CompleteConsultation` | Doctor (prescription issued or "done") | — | `completed_at`; EMA update (§13); counts; `SerialCompleted` |
| in_consultation | checked_in | `ReturnToQueue(reason)` / `SkipCalled` | Doctor, Reception | — | `position` moved after the next 2 waiting (§7.3); `skip_count + 1`; `serial.skipped` |
| no_show | checked_in | `ReinstateSerial` | Reception, Doctor | session not closed | `position` = priority-insert `elderly` rule (§7.3); `reinstated_at` in event; counts; `SerialReinstated` |
| no_show | booked | `ReinstateSerial(present: false)` | Reception | session not closed | same, patient not yet arrived |
| cancelled | checked_in | `ReinstateAfterCancel` — **only** from an offline-sync resolution (OFFLINE.md §8.4) | Reception, Hospital Admin | session not closed | position by `elderly` rule; event `serial.reinstated_after_cancel`; `SerialReinstatedAfterCancel` (billing voids/reissues refund) |

Anything else throws `IllegalTransition(from, to)` (409, `code: "serials.illegal_transition"`).
`SerialTransition` is the single choke point every action calls:

```php
namespace App\Domain\Serials\Services;

final class SerialTransition
{
    /** Locks the serial row FOR UPDATE, validates the edge, stamps timestamps, writes serial_events + audit_logs, recalculates counts. */
    public function apply(Serial $serial, SerialStatus $to, Actor $actor, array $context = []): Serial;
}
```

`Actor` = `App\Domain\Shared\Actor` (`{userId|null, role, deviceId|null, patientId|null, ip, source: web|api|offline_replay|system}`,
ARCHITECTURE.md §5.3); it is written to `serial_events.actor_*` and to `audit_logs`.

### 6.4 Counts and the queue version

The six `session_instances.*_count` columns (+ `postponed_count`, schema addition) are recalculated, never incremented, and every recalculation bumps `session_instances.version` — the integer REALTIME.md uses as the queue ETag:

```sql
UPDATE session_instances s SET
  booked_count = c.booked, checked_in_count = c.checked_in, in_consultation_count = c.in_consultation,
  completed_count = c.completed, no_show_count = c.no_show, cancelled_count = c.cancelled, postponed_count = c.postponed,
  version = s.version + 1, updated_at = now()
FROM (SELECT count(*) FILTER (WHERE status = 'booked')          AS booked,
             count(*) FILTER (WHERE status = 'checked_in')      AS checked_in,
             count(*) FILTER (WHERE status = 'in_consultation') AS in_consultation,
             count(*) FILTER (WHERE status = 'completed')       AS completed,
             count(*) FILTER (WHERE status = 'no_show')         AS no_show,
             count(*) FILTER (WHERE status = 'cancelled')       AS cancelled,
             count(*) FILTER (WHERE status = 'postponed')       AS postponed
      FROM serials WHERE session_instance_id = :sid) c
WHERE s.id = :sid
RETURNING s.version;
```

One indexed aggregate per transition (`serials (session_instance_id, status)`), no drift, safe under concurrency because it runs after the serial row lock. Reorders, priority inserts, delay/extend/pause and pool changes run only the `version = version + 1` part (`CountsRecalculator::bumpVersion($sid)`).

---

## 7. Position, priority insert, drag reorder

### 7.1 Numbering scheme

- `position` is a bigint with a **1,000,000 gap** (SCHEMA.md §5.2). Initial value: `max(number * 1_000_000, current_max_position + 1_000_000)` (serial mode) or
  `slot_index * 1_000_000` (slot mode, `slot_index = (slot_start_at - planned_start_at) / slot_minutes + 1`).
  The `max()` matters only for a *reused* number from the counter free-list (§3.5, OFFLINE.md §4.6): a
  late `A-014` issued after `A-023` joins the **tail**, in arrival order, instead of jumping ahead.
- Insert between neighbours `a < b`: `position = a + floor((b - a) / 2)`. Insert at head: `min - 1_000_000`; at tail: `max + 1_000_000`.
- If `b - a < 2` (no integer room) the action **renormalises** first: every non-terminal serial of the instance, ordered by current `position, number`, gets `rank * 1_000_000`. This happens inside the same transaction, under the session lock, and writes one `renormalised` event with the full before/after map in `meta`. With gaps of 10⁶ and midpoint insertion this is needed only after ~20 consecutive inserts at the same spot; in practice it is rare.
- **Reorder never changes `number` or `display_code`.** Tests §18.6 assert it.

```php
final class PositionService
{
    public function initial(SessionInstance $s, int $number, SerialPriority $p, ?CarbonImmutable $slot): int;
    public function between(?int $before, ?int $after): int;        // may throw NeedsRenormalisation
    public function renormalise(SessionInstance $s): array;          // [serial_id => [old, new]]
    public function afterNowServing(SessionInstance $s, int $skip): int; // position after `skip` waiting serials past now_serving
}
```

Session lock for all position work: `SELECT id FROM session_instances WHERE id = :sid FOR UPDATE`
(taken *after* the serial row lock in `SerialTransition`, and only by position-changing actions).

### 7.2 Drag reorder

`ReorderSerial(serial, afterSerialId|null, beforeSerialId|null, actor, reason|null)` —
Reception, Doctor, Hospital Admin. Both neighbours must belong to the same
instance and be non-terminal; the moved serial must be `booked|checked_in`
(`in_consultation` cannot be moved; terminal serials are not in the list).
Event `serial.reordered` `{from_position, to_position, after: 'A-012', before: 'A-019', reason}`
+ `audit_logs` row. Emits `SerialReordered` → queue-state rebuild. The desk UI
uses `@dnd-kit/sortable`; the request carries the neighbours' `public_id`s, not
indexes, so a stale board cannot drop a serial into the wrong place — if a
neighbour is no longer adjacent/non-terminal the server answers 409
`code: "serials.reorder_stale"` (`ReorderStale`) and the board reloads.

### 7.3 Priority insert rules

`PriorityInsert(serial, priority, actor, reason)` sets `serials.priority` and the position:

| Priority | Position | Rationale |
|---|---|---|
| `emergency` | immediately after `now_serving` (before every waiting serial) | clinical |
| `vip` | after `now_serving` and after any waiting `emergency` | commercial; tenant may disable VIP (`serial.vip_enabled`) |
| `elderly` | after the next **2** waiting serials (tenant setting `serial.elderly_skip`, default 2) | fairness: the two patients already standing up are not pushed back |
| `normal` (removing a priority) | back to `number * 1_000_000` (or tail if that would jump ahead) | |

The same rule table drives `ReinstateSerial` (uses the `elderly` rule) and
`SkipCalled` (moves after the next 2 waiting). Every call writes
`serial.priority_inserted` with `{priority, from_position, to_position, reason}`;
`reason` is mandatory for `vip`.

---

## 8. Auto no-show and reinstate

Settings: `session_instances.auto_noshow_after` (copied at materialisation from the template or `settings` key `queue.auto_noshow_after`; N, default 3, 0 disables),
`queue.auto_noshow_grace_minutes` (default 10 — no auto no-show before
`planned_start_at + delay + grace`).

On every `SerialCalled` for serial S (inside the call transaction, after the
transition):

```sql
UPDATE serials SET passed_count = passed_count + 1
WHERE session_instance_id = :sid AND status = 'booked' AND position < :called_position
RETURNING id, passed_count;
```

Rows whose `passed_count >= N` are transitioned by `ApplyAutoNoShow` via
`SerialTransition::apply(no_show, actor: system, context: {reason: 'auto', passed: N})`.
Only `booked` (not arrived) serials are eligible; `checked_in` serials are never
auto no-showed — they are present. `passed_count` is a schema addition (§19).

Reinstate: `ReinstateSerial(serial, present: bool)` — `no_show → checked_in`
(present) or `→ booked`; `passed_count = 0`; position by the `elderly` rule so a
reinstated patient is called soon but not ahead of the two already standing.
A serial can be reinstated any number of times while the session is open; the
`serial_events` trail shows each.

---

## 9. Postpone and transfer

### 9.1 Postpone to next session

`PostponeSerial(serial, targetInstance|null, actor, reason)`. With `null` the
target is the doctor's next instance at the same branch (today's `B` after `A`,
else tomorrow's first). Transaction:

1. `SerialTransition::apply(old, postponed, …)` (row lock on old).
2. `AllocateSerial(new AllocationRequest(target, pool: sourcePoolOf(old) — online→online, everything else→counter, source: old.source, priority: old.priority, patientId, appointmentId: old.appointment_id, transferredFromSerialId: old.id, clientEventId: "postpone:{old.public_id}"))` — the `clientEventId` makes a retried request idempotent.
3. `appointments.session_instance_id` and `serial_id` updated to the new serial; old serial gets `postponed_to_serial_id = new.id`, new serial has `transferred_from_serial_id = old.id` (both directions are queryable).
4. Event on old `serial.postponed {to_serial: 'B-007', to_session: …}`; on new `serial.allocated {from_serial: 'A-042'}`.
5. `SerialPostponed(old, new)` → notification "Your serial moved to B-007 (evening)".

If the target pool is exhausted the whole transaction rolls back and the desk
is told (`pool_exhausted` with target details); the receptionist may extend the
target session first.

### 9.2 Transfer to another doctor

`TransferSerial(serial, targetInstance, actor, reason)` — target belongs to a
**different doctor** (same-doctor moves are postpones). Same transaction shape;
the old serial becomes `cancelled` with `cancel_reason_code = 'transferred'` and
`transferred_to_serial_id = new.id` so the old doctor's counts and revenue are
correct, and `SerialTransferred(old, new, feeDeltaExpected)` is emitted for
billing (fee may differ between doctors; the engine only reports
`old_fee_snapshot` and `target doctor's current fee`). Bulk variant
`TransferSession(sourceInstance, targetInstance, actor, reason)` iterates every
non-terminal serial by `position`, allocating in order, stopping at the first
`PoolExhausted` with a partial report (transfers already committed stay; the
caller may extend and re-run — idempotent by `clientEventId = "transfer:{old.public_id}"`).

---

## 10. Slot mode differences

`session_instances.mode = 'slot'`, `doctor_schedules.slot_minutes` (e.g. 15).

| Aspect | Serial mode | Slot mode |
|---|---|---|
| What the patient picks | nothing (next number) | a `slot_start_at` from `CapacityService::freeSlots()` |
| Number | pool cursor | pool cursor (identity only) |
| Uniqueness of time | n/a | partial unique index `serials (session_instance_id, slot_start_at) WHERE slot_start_at IS NOT NULL` → `SlotUnavailable` (409, `code: "serials.slot_taken"`, no retry) |
| Pools | by channel (§3.2) | same; quotas cap *how many* slots each channel may take, not *which* times — every channel sees all free times |
| Initial `position` | `number * 1_000_000` | `slot_index * 1_000_000` |
| ETA | queue arithmetic (§13) | `max(slot_start_at, queue ETA)` — a doctor running late pushes slots by the queue estimate |
| Auto no-show | N passed | additionally at `slot_start_at + slot_minutes` if not checked in (`ApplyAutoNoShow` runs from a per-minute scheduler for slot sessions) |
| Walk-ins | buffer | buffer, `slot_start_at = null`, position at tail — they fill gaps |

Free slots (no lock): generate `planned_start_at .. planned_end_at` step
`slot_minutes`, minus `SELECT slot_start_at FROM serials WHERE session_instance_id = :sid AND status NOT IN ('cancelled','postponed','no_show') AND slot_start_at IS NOT NULL`.

---

## 11. Booking channels

### 11.1 Walk-in from the buffer

`POST /api/sessions/{session}/serials` with `source: "walkin"` → pool `buffer`.
Priority may be set in the same request. Exhaustion → `ExtendSessionCapacity`.

### 11.2 Kiosk / QR self-booking

The desk prints/shows a QR for `URL::signedRoute('site.booking.kiosk', ['branch' => …, 'session' => …], now()->addHours(12))` (`routes/site/booking.php`, Booking module).
The page (site app, `site/Pages/Booking/Kiosk.tsx`) asks for mobile → OTP (tenant setting `kiosk.otp_required`, default true; `App\Domain\Patients\Services\OtpService`) → patient auto-match/create (Patients module, `App\Domain\Patients\Actions\FindOrCreatePatientByMobile`) → `AllocateSerial(pool: online, source: kiosk, clientEventId: <browser-generated ULID from shared/ulid.ts>)`. Rate limit `RateLimiter::for('kiosk', 5 per minute per mobile, 60 per minute per branch)` registered by `BookingServiceProvider`. The serial is `booked`; the desk checks it in when the patient walks up.

### 11.3 Follow-up rebooking

`App\Domain\Booking\Actions\RebookFollowUp(previousVisitId, targetInstance, actor)` — one tap from the
visit timeline (staff) or the patient portal. Allocates with
`source: followup`, `appointments.type = 'followup'`, `appointments.follow_up_of_visit_id`,
and emits `App\Domain\Booking\Events\AppointmentBooked` with `previous_visit_id` so billing applies the
doctor's free follow-up window (ARCHITECTURE.md §5.4). Pool: counter when a staff user acts, online
when the patient acts. Prescription module's "follow-up date" (`FollowUpScheduled` →
`App\Domain\Booking\Listeners\CreateDraftFollowUpAppointment`, PRESCRIPTION.md §4.8) creates a
*draft* appointment (no serial) — the draft becomes a serial only when
confirmed through this action.

---

## 12. Capacity queries (no locks)

`App\Domain\Serials\Services\CapacityService`:

```php
public function remaining(int $sessionInstanceId): array;            // ['online'=>int,'counter'=>int,'buffer'=>int,'counter_in_blocks'=>int,'released'=>int]
public function remainingForRange(int $doctorId, int $branchId, CarbonImmutable $from, CarbonImmutable $to): array; // keyed by date+code
public function freeSlots(SessionInstance $s): array;                 // slot mode, §10
```

```sql
SELECT p.session_instance_id, p.pool, GREATEST(0, p.range_end - p.next_number + 1) AS remaining
FROM serial_pools p WHERE p.session_instance_id = ANY(:ids);

SELECT session_instance_id,
       SUM(range_end - next_number + 1) FILTER (WHERE status = 'active')   AS counter_in_blocks,
       SUM(range_end - next_number + 1) FILTER (WHERE status = 'released') AS released
FROM serial_blocks WHERE session_instance_id = ANY(:ids) AND next_number <= range_end GROUP BY 1;
```

Public calendar "serials remaining" = `online.remaining` only. Desk board counter
remaining = `counter.remaining + released` (numbers the desk can still issue
itself) with `counter_in_blocks` shown separately as "on devices". Results are
cached in Redis `t:{tenantId}:cap:{sessionInstancePublicId}` (`tenantId` = bigint `Tenancy::id()`, CONVENTIONS.md §15) for 10 s and deleted by the
`SerialAllocated`/`SerialCancelled`/`SessionCapacityExtended` listeners;
the public calendar therefore never touches a locked row and may over-report by
one for at most 10 s, which the atomic allocation then corrects with a clean
`pool_exhausted`.

---

## 13. ETA — running average of actual consultation times

`session_instances.avg_consult_seconds` (with `consult_samples` counting accepted samples) is an exponential moving average of the
**call-to-complete** duration `completed_at − called_at` — what the next patient
actually waits, including the walk from the corridor.

```php
// App\Domain\Queue\Services\EtaCalculator (Queue module owns ETA per CONVENTIONS.md; Serials calls it through the container)
final class EtaCalculator
{
    public const ALPHA = 0.25;        // ≈ 7-sample window
    public const MIN_SAMPLES = 3;
    public const CLAMP = [30, 1800];  // seconds; outside → sample discarded (doctor forgot to press complete)

    public function updateAverage(SessionInstance $s, Serial $completed): int
    {   // called inside CompleteConsultation's transaction, after the row lock on session_instances
        $sample = $completed->completed_at->diffInSeconds($completed->called_at);
        if ($sample < self::CLAMP[0] || $sample > self::CLAMP[1]) return $s->avg_consult_seconds;
        return (int) round(self::ALPHA * $sample + (1 - self::ALPHA) * $s->avg_consult_seconds);
    }

    /** ETA per active serial; input is the ordered active list, output keyed by serial id. */
    public function estimate(SessionInstance $s, Collection $active, CarbonImmutable $now): array;
}
```

`estimate()`:

```
avg   = s.avg_consult_seconds                       (seeded from the template, §2.3)
conf  = s.consult_samples >= MIN_SAMPLES ? 'normal' : 'low'
base  = s.status == running ? now
      : s.status == paused  ? null                  (ETA suppressed, UI shows "paused")
      : max(now, planned_start_at + delay_minutes)
rem   = now_serving ? max(0, avg - (now - now_serving.called_at)) : 0
show  = tenant setting serial.expected_show_rate (default 0.8)
for each active serial X ordered by position:
    ahead_ci = checked_in serials with position < X.position
    ahead_bk = booked serials with position < X.position
    eta[X]   = base + rem + (ahead_ci + round(ahead_bk * show)) * avg
    slot mode: eta[X] = max(eta[X], X.slot_start_at)
```

Output field per serial in the QueueState (REALTIME.md): `eta` ISO-8601 or
`null`, plus `eta_confidence`. The number shown to the patient is rounded to 5
minutes and never earlier than `now + 1 min`.

---

## 14. Consultation timing

| Action | Stamps | Emits |
|---|---|---|
| `CallNext(instance, actor)` | picks `checked_in` with lowest `position` (`FOR UPDATE SKIP LOCKED` on that row); `called_at`; `now_serving_serial_id`; `actual_start_at` if null; status `running` | `SerialCalled` (domain event, after commit) → the Queue module broadcasts `serial.called` **now** and `call.next` to the doctor + display channels (REALTIME.md §3), runs the auto no-show sweep (§8) and the 3-ahead notifications (REALTIME.md §7) |
| `CallSerial(serial, actor)` | as above for a specific serial (implicit check-in if `booked`) | same |
| `StartConsultation(serial)` | `consultation_started_at` (optional; set when the doctor opens the prescription) | `SerialStatusChanged` is **not** re-emitted (status unchanged) |
| `CompleteConsultation(serial)` | `completed_at`; EMA (§13); `now_serving_serial_id = null` if it was this serial | `SerialCompleted`, queue-state rebuild |
| `SkipCalled(serial)` / `ReturnToQueue(serial, reason)` | back to `checked_in`; `skip_count + 1` (schema addition) | `SerialStatusChanged` |

`CallNext` when nothing is `checked_in` returns 200 `{ "called": null, "waiting_booked": n }` — no exception, the doctor screen shows "no one has arrived".

---

## 15. Domain events emitted (hooks for billing, notifications, realtime)

All in `App\Domain\Serials\Events`, dispatched with `ShouldDispatchAfterCommit`
(`Illuminate\Contracts\Events\ShouldDispatchAfterCommit`) so listeners never see
uncommitted rows. Payload = model ids + a frozen array snapshot (listeners must
not rely on the model being unchanged when a queued listener runs). None of these
classes broadcast: the Queue module listens to them and emits its own
`App\Domain\Queue\Events\*` wire events (same short names for `SerialCalled`,
`SerialStatusChanged`, `SessionDelayed`, `SessionCancelled`, `DoctorArrived` — different
classes; REALTIME.md §3, ARCHITECTURE.md §5.4). Consumers are listed by module; the
consuming module owns the listener.

| Event | Payload | Consumers |
|---|---|---|
| `SerialAllocated` | serial, session, source, pool, appointment_id | billing (creates invoice line if fee due), notifications (booking confirmed), realtime |
| `SerialStatusChanged` | serial, from, to, actor | realtime, audit |
| `SerialCalled` | serial, session, previous now_serving | realtime (broadcast now), notifications (3-ahead), auto no-show |
| `SerialCompleted` | serial, duration_seconds | realtime, reports |
| `SerialCancelled` | serial, reason_code, cancelled_by_role, minutes_before_planned_start, `refund_eligible` (true when cancelled by clinic/system, or by patient before `serial.cancel_cutoff_minutes`) | **billing**: decides refund/credit; the engine never touches `payments`/`refunds` |
| `SerialNoShow` | serial, reason auto|manual|session_closed | billing (no-show fee policy), notifications |
| `SerialReinstated` | serial | realtime |
| `SerialReinstatedAfterCancel` | serial, prior refund status | **billing** (void pending refund or re-invoice), realtime — only raised by the OFFLINE.md §8.4 resolution |
| `SerialPostponed` | old, new | notifications (patient), billing (no fee change) |
| `SerialTransferred` | old, new, old_fee_snapshot, target_fee | notifications (patient: new doctor/code), billing (fee delta) |
| `SerialReordered` / `SerialPriorityInserted` | serial, from_position, to_position | realtime |
| *(not a Serials event)* `App\Domain\Queue\Events\SerialApproaching` | serial, ahead | raised by the Queue module's `NotifyApproachingSerials` listener on `SerialCalled` (REALTIME.md §7); consumed by notifications ("3 ahead") |
| `SessionCapacityExtended`, `SessionDelayed`, `SessionCancelled`, `SessionClosed`, `DoctorArrived`, `SessionPaused`, `SessionResumed` | session, actor, values | realtime, notifications |

Revenue neutrality: the engine records fee snapshots on `appointments` only at
allocation (copied from the doctor profile by the booking flow) and never
mutates payment state. Cancellation, transfer and postpone are *facts* the
engine reports; money movements are billing-module reactions.

---

## 16. HTTP endpoints (`api` surface, Sanctum/session auth; public ids in URLs)

All `/api/sessions/*` and `/api/serials/*` routes live in `routes/api/serials.php` (names `api.sessions.*` /
`api.serials.*`, middleware `auth:sanctum` — the desk PWA and doctor screen call them with the staff session;
offline replay never calls them directly, it goes through `POST /api/reception/sync`). The two public ones live
in `routes/api/booking.php` (`api.booking.public.store`, `throttle:booking`) and `routes/api/scheduling.php`
(`api.scheduling.availability`), no auth. Route parameters bind by `public_id` (`{session:public_id}`,
`{serial:public_id}`). Controllers: `App\Http\Controllers\Api\{Serials,Booking,Scheduling}\*`. Domain
failures surface as `{message, code}` with the status from `DomainException::status()` (ARCHITECTURE.md §2) —
the `409 {code:…}` cells below are those.

| Method & path | Body → Response | Action |
|---|---|---|
| `POST /api/sessions/{session}/serials` | `{source:"counter"\|"walkin", patient_id, priority?, appointment_type?, slot_start_at?, client_event_id?}` → `201 {serial: SerialResource}` / `409 {code:"serials.pool_exhausted", remaining:{…}}` | `AllocateSerial` |
| `POST /api/public/bookings` (`routes/api/booking.php`, no auth, `throttle:booking`) | `{doctor_slug, date, session_code, mobile, otp, patient:{…}, slot_start_at?, client_event_id}` → `201 {serial:{display_code, public_id, queue_url}, appointment:{…}}` | booking flow → `AllocateSerial(pool: online)` |
| `POST /api/serials/{serial}/check-in` | `{}` → `200 {serial}` | `CheckInSerial` |
| `POST /api/serials/{serial}/call` `/start` `/complete` `/skip` `/return` | `{reason?}` | §14 |
| `POST /api/serials/{serial}/cancel` | `{reason_code, note?}` → `200 {serial, refund_eligible}` | `CancelSerial` |
| `POST /api/serials/{serial}/no-show` · `/reinstate` | `{}` · `{present: bool}` | §8 |
| `POST /api/serials/{serial}/postpone` | `{target_session?: public_id, reason}` → `200 {old, new}` | §9.1 |
| `POST /api/serials/{serial}/transfer` | `{target_session: public_id, reason}` → `200 {old, new, fee_delta_expected}` | §9.2 |
| `POST /api/serials/{serial}/reorder` | `{after?: public_id, before?: public_id, reason?}` → `200 {serial}` / `409 {code:"serials.reorder_stale"}` | §7.2 |
| `POST /api/serials/{serial}/priority` | `{priority, reason?}` | §7.3 |
| `POST /api/sessions/{session}/call-next` | `{}` → `200 {called: serial\|null, waiting_booked}` | §14 |
| `POST /api/sessions/{session}/extend` | `{extra: int, reason?}` → `200 {session, pools}` | §3.4 |
| `POST /api/sessions/{session}/pools/release-online` | `{count?: int}` → `200 {pools, released_block}` | §3.6 |
| `PUT /api/sessions/{session}/pools/split` | `{counter_quota, online_quota}` → `200` / `409 {code:"serials.split_locked"}` | §3.6 |
| `POST /api/sessions/{session}/start` `/pause` `/resume` `/close` `/cancel` `/delay` | `{reason?}` · `{delay_minutes, message?}` | §2.5 |
| `GET /api/sessions/{session}/capacity` | → `{online, counter, buffer, counter_in_blocks, released}` | §12 |
| `GET /api/public/doctors/{slug}/availability?from&to` | → `{days:[{date, sessions:[{code, public_id, mode, planned_start_at, online_remaining, free_slots?}]}]}` | §12 (materialises on demand) |

`App\Http\Resources\Serials\SerialResource`: `{public_id, display_code, number, position, status, priority, source, patient:{public_id, name, mobile_masked}, booked_at, checked_in_at, called_at, completed_at, eta, session:{public_id, code, date}}` — the same resource the reception bootstrap (OFFLINE.md §5.1) and the Inertia board props use.

---

## 18. Test plan

All PHPUnit tests in this section run against Postgres (`DB_DATABASE=booking_test_N`, per-engineer
env from `scripts/test-agent.sh N`, CONVENTIONS.md §6.1), not SQLite — locks and `ON CONFLICT` semantics
are the subject under test. Every class extends `Tests\TestCase` (`Tests\Concerns\WithTenants` provisions
`tenant_test_a`/`tenant_test_b`, ids 9001/9002); a shared `Tests\Support\SerialFixtures` trait creates a
doctor with one weekly template (`C=10, O=10, B=5` unless stated) inside `asTenant('a')`. Tests that spawn
processes live in `tests/Concurrency/Serials/`, are `#[Group('concurrency')]`, set
`protected array $connectionsToTransact = []` (committed state) and truncate in `tearDown()`; everything
else is `tests/Feature/Serials/` (transaction-wrapped) or `tests/Unit/Serials/` (no container).

### 18.1 `tests/Concurrency/Serials/AllocateSerialConcurrencyTest`

- `test_600_parallel_allocations_produce_600_unique_in_range_numbers`: instance with `counter_quota = 600`. `Tests\Support\ProcessPool::run(workers: 24, command: ['php', 'artisan', 'serials:hammer', '--tenant=9001', "--session={$id}", '--pool=counter', '--count=25'], timeoutSeconds: 120)` spawns **24** `Symfony\Component\Process\Process` workers (the `serials:hammer` command is `App\Console\Commands\Serials\HammerCommand`, refuses to run in production; each process boots its own container and PDO connection — never `pcntl_fork` inside PHPUnit, PDO handles are not fork-safe). Each worker allocates 25 serials in a tight loop with `source = counter`, `client_event_id = null`, random 0–5 ms jitter, and prints one JSON line per allocation. Assertions after `$results->failed() === 0`: `count(*) = 600`; `count(DISTINCT number) = 600`; `min = 1`, `max = 600` (no gaps because no failures); every number within `[range_start, range_end]` of the counter pool; `serial_pools.next_number = 601`; no `number_skipped` event exists; wall time < 60 s.
- `test_parallel_allocations_across_three_pools_stay_in_their_ranges`: 8 workers per pool (`online`, `counter`, `buffer`), 20 each; assert per-pool ranges and totals.
- `test_parallel_idempotent_replays_return_the_same_serial`: 10 workers send the same `client_event_id`; exactly one row.
- `test_unique_index_holds_without_the_owner_lock` (CONVENTIONS.md §6.5): `config(['serials.testing_skip_owner_lock' => true])` is passed to the workers (`--skip-owner-lock`, honoured only in `testing`) so `lockOwnerRow()` reads without `FOR UPDATE`; 8 workers × 25; `count(DISTINCT number) === count(*)`, and the collisions surface as `number_skipped` events / `AllocationDriftDetected` reports instead of duplicate rows.

### 18.2 `PoolExhaustionTest`

- `test_exact_boundary` (Feature): quota 25, 25 allocations succeed, the 26th throws `PoolExhausted`; `next_number = range_end + 1`.
- `test_exhaustion_under_concurrency` (Concurrency): 30 workers × 1 allocation against quota 25; exactly 25 rows, 5 workers exit with `serials.pool_exhausted`.
- `test_online_never_spills_into_counter`: online exhausted, counter has 10 left → `PoolExhausted`; counter still 10.
- `test_extend_capacity_appends_above_max`: buffer exhausted → `ExtendSessionCapacity(5)` → next walk-in gets `max_serials_old + 1`.

### 18.3 `BlockPoolNonOverlapTest`

- `test_leased_block_and_pool_allocations_are_disjoint`: lease block of 10 (OFFLINE.md `LeaseBlock`), interleave 15 pool allocations, 10 `AllocateFromBlock`; assert the union is 25 distinct numbers, block numbers ⊂ block range, pool numbers > block range.
- `test_released_block_numbers_are_reissued_lowest_first_and_only_once`: lease 10, issue 3 from the block, release; then 12 counter allocations → numbers `4..10` then pool cursor; distinct.
- `test_two_devices_get_disjoint_blocks_under_concurrency` (`tests/Concurrency/Reception/BlockLeaseConcurrencyTest`, shared with OFFLINE.md §12.2): 6 `ProcessPool` workers each lease 10 in parallel; ranges pairwise disjoint and contiguous.
- `test_release_online_to_counter_creates_a_desk_owned_released_block`.

### 18.4 `AllocateSerialDriftTest`

- `test_unique_violation_is_skipped_and_reported`: insert a `serials` row directly with `number = next_number`; the action returns `number + 1`, writes `serial.number_skipped`, `report()` received `AllocationDriftDetected`.
- `test_five_consecutive_violations_throw_retry_exhausted`.

### 18.5 `SessionMaterialiserTest`

- `test_weekly_template_creates_instances_and_three_pools_with_correct_ranges`
- `test_holiday_suppresses_unless_works_on_holidays`, `test_leave_suppresses`, `test_override_cancelled_suppresses`, `test_override_extra_creates_on_off_day`, `test_override_modified_changes_times_and_quotas`
- `test_concurrent_ensure_creates_exactly_one_instance` (`tests/Concurrency/Scheduling/`, 12 `ProcessPool` workers running `sessions:materialise --tenant=9001 --date=…`)
- `test_resync_refused_when_serials_exist`, `test_command_dispatches_per_tenant`, `test_close_stale_closes_yesterday`

### 18.6 `SerialStateMachineTest` (data provider over the table in §6)

- `test_every_allowed_transition_stamps_timestamp_writes_event_and_recounts`
- `test_every_disallowed_transition_throws_illegal_transition`
- `test_reorder_and_priority_insert_never_change_number_or_display_code`
- `test_midpoint_insert_renormalises_when_no_room` (11 inserts at the same spot)
- `test_auto_no_show_after_n_passed_and_reinstate_positions_after_two`
- `test_postpone_links_transferred_from_and_moves_appointment`
- `test_transfer_cancels_old_with_reason_transferred_and_emits_fee_delta`
- `test_cancel_emits_refund_eligibility_by_role_and_cutoff`
- `test_close_session_no_shows_remaining_and_releases_blocks`

### 18.7 `EtaCalculatorTest`, `CapacityServiceTest`, `SlotModeTest`

- `test_seed_used_until_three_samples_then_ema`, `test_outlier_samples_discarded`, `test_eta_accounts_for_in_consultation_elapsed`, `test_eta_null_when_paused`, `test_booked_ahead_weighted_by_show_rate`
- `test_remaining_never_locks_rows` (asserts via `pg_locks` during a held `FOR UPDATE` in another connection that the query still returns)
- `test_slot_double_booking_raises_slot_unavailable_not_retry`, `test_slot_position_by_slot_index`

---

## 19. Schema additions requested

> Reconciled 2026-09-06: every item below is now in SCHEMA.md (the authoritative column list). The section is kept as the rationale record — where it says "missing", "add" or "supersedes", SCHEMA.md already has it.

Columns/indexes this design needs beyond the fixed table list (for `docs/SCHEMA.md`):

1. `session_instances.public_id` `char(26)` ULID unique via `HasPublicId` (used in URLs/channels; SCHEMA.md lists the table without `public_id: yes`); `session_instances.pause_seconds int default 0`; `session_instances.postponed_count smallint default 0` (the other six `*_count` columns exist). The unique `(branch_id, doctor_id, session_date, session_code)` required by §2.3 already exists.
2. `serials.slot_start_at timestamptz null` + partial unique index `(session_instance_id, slot_start_at) WHERE slot_start_at IS NOT NULL` (§10).
3. `serials.passed_count smallint not null default 0` (§8) and `serials.skip_count smallint not null default 0` (§14).
4. `serials.cancel_reason_code` and `serials.t3_notified_at` exist in SCHEMA.md and are used as-is (§9.2, REALTIME.md §7).
5. `serials` partial unique index `(session_instance_id, client_event_id) WHERE client_event_id IS NOT NULL` — SCHEMA.md has the per-device variant `(reception_device_id, client_event_id)`, which does not cover online/kiosk double-submits where `reception_device_id IS NULL`; both may coexist. `(session_instance_id, status)` and `(session_instance_id, position, number)` already exist.
6. `serial_blocks`: `reception_device_id` must be **nullable** and `serial_pool_id` may reference the **online** pool row (NULL device = desk-owned released range, §3.5/§3.6); add `public_id`, `revoked_at timestamptz null`, `expires_at`; `returned_count` keeps its value but the SCHEMA.md note "never re-issued" is superseded by OFFLINE.md §4.6 (returned numbers **are** reused via the released row); the partial unique "one active block per device per session" becomes "at most `serial.max_active_blocks_per_device` (2)" enforced in `LeaseBlock`. The `serial_blocks_range_excl` exclusion constraint is kept and relied upon.
7. `serial_events`: `serial_id` must be **nullable** for session-level rows; `type` check list gains `number_skipped`, `renormalised`, `skipped`, `printed`, `reinstated_after_cancel`, `recorded_post_close`, `capacity_extended`, `online_released`, `split_changed`, `block_leased`, `block_released`, `block_revoked`, `delayed`, `void_local`. Mapping of names used in this document → SCHEMA.md `type`: `serial.allocated`→`booked`, `serial.checked_in`→`checked_in`, `serial.cancelled`→`cancelled`, `serial.postponed`→`postponed`, `serial.reordered`→`reordered`, `serial.priority_inserted`→`priority_changed`, `serial.number_skipped`→`number_skipped`, `serial.positions_renormalised`→`renormalised`, `serial.skipped`→`skipped`, `pool.online_released`→`online_released`, `session.capacity_extended`→`capacity_extended`, `block.leased`→`block_leased`, `block.released`→`block_released`, `block.revoked`→`block_revoked`, `session.delayed`→`delayed`, `serial.reinstated_after_cancel`→`reinstated_after_cancel`, `serial.recorded_post_close`→`recorded_post_close`; raw SQL in this document and in OFFLINE.md writes the SCHEMA.md values; the JSON details go in the `meta` column (there is no `payload` column), the actor in `actor_type/actor_user_id/reception_device_id` (`actor_type` is NOT NULL).
8. `doctor_schedules.works_on_holidays boolean default false` (the only template column missing; quotas, `avg_consult_minutes`, `slot_minutes`, `mode`, `auto_noshow_after` exist). `schedule_overrides.type` values are used as defined in SCHEMA.md.
9. `settings` keys (SCHEMA.md `settings` table, dotted keys): `queue.auto_noshow_after` (exists), `queue.auto_noshow_grace_minutes`, `serial.elderly_skip`, `serial.vip_enabled`, `serial.expected_show_rate`, `serial.receptionist_extension_limit`, `serial.cancel_cutoff_minutes`, `kiosk.otp_required`.
10. **Design decisions in this document that supersede provisional text in SCHEMA.md §5.1**: pool order is counter → online → buffer (§3.1), not online-first (SCHEMA.md §3.3's "online 1–60, counter 61–100" example is illustrative only); released block numbers are reused (OFFLINE.md §4.6); mid-session split changes are limited to §3.6; `CallNext` lives in `App\Domain\Serials` (it is a transition) while `EtaCalculator` lives in `App\Domain\Queue` per CONVENTIONS.md ownership.
11. `settings` keys use the `serial.*` prefix (`serial.elderly_skip`, `serial.vip_enabled`, `serial.expected_show_rate`, `serial.receptionist_extension_limit`, `serial.cancel_cutoff_minutes`) to match SCHEMA.md's existing `serial.default_block_size`; `config/serials.php` (CONVENTIONS.md §11) holds only deployment defaults, never tenant-editable values.
