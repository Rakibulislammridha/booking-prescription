# Offline Reception PWA — Design Specification

Module owner: F and F.1 of `docs/BRIEF.md` (LOCKED design: PWA with a
pre-allocated serial block per device; IndexedDB; ordered event log; replay on
reconnect; conflicts surfaced, never silently merged; unused block serials
returned at session close; unmistakable connection indicator).

Companions: `docs/SERIAL_ENGINE.md` (pools, `AllocateSerial`, free-list §3.5),
`docs/REALTIME.md` (the same connection store drives the polling fallback),
`docs/SCHEMA.md` (table names used verbatim).

Code locations: server `App\Domain\Reception\{Actions,Services,Handlers,Events,Exceptions,Http\Middleware}`
(routes `routes/api/reception.php`, names `api.reception.*`, controllers `App\Http\Controllers\Api\Reception\*`),
models `App\Models\Tenant\{ReceptionDevice, SerialBlock, OfflineEvent}`; client
`resources/js/shared/connection/` (the single network-state model — F-owned, built from §3/§9),
`resources/js/shared/offline/{db,eventLog,blocks,sync}.ts` (Dexie, event log, block issuer,
sync), `resources/js/shared/ulid.ts`, `resources/js/panel/sw.ts` (service worker),
`resources/js/panel/{Pages,Components}/Reception/**`. Actions with more than one parameter expose
`handle()` (CONVENTIONS.md §4); `Actor` is `App\Domain\Shared\Actor`. Sample ids such as `dev_01J...`
are abbreviations — every real public id is a bare 26-character ULID (CONVENTIONS.md §15).

---

## 1. Principles

1. **Blocks, not hope.** A device may only issue numbers it already owns
   (`serial_blocks` leased while online). Nothing offline ever "reserves" a
   number it does not hold.
2. **One event log.** Every offline action is an append-only event with a ULID
   `client_event_id` and a per-device monotonic `sequence_no`. The UI renders
   from the local cache + log; the server replays the log through the *same*
   domain actions used online.
3. **One connection model.** `resources/js/shared/connection/store.ts` is the
   only place that decides `online | degraded | offline`. Realtime, sync, the
   indicator and every "disabled while offline" button read from it.
4. **Conflicts are decisions.** A replay result of `conflict` opens a
   resolution card that a receptionist must act on. There is no "auto".
5. **Offline is a mode, not an error.** The desk is designed to be used offline
   for a whole session; every allowed action has full local feedback (slip
   printed, board updated) before the server ever hears about it.

---

## 2. Device identity and registration

`reception_devices` is the Sanctum tokenable (`HasApiTokens` on
`App\Models\Tenant\ReceptionDevice`); the guard `device` uses the `sanctum`
driver with `provider = reception_devices`. A device token authenticates the
*device*; every event carries `actor_user_id` for the *person*.

### 2.1 Registration (online, by a Hospital Admin or Receptionist with `reception.devices.register`)

```
POST /api/reception/devices/register           (auth:sanctum — the staff session; permission reception.devices.register)
{ "name": "Front desk tablet 2", "branch_id": "brn_01J...", "kind": "reception",
  "device_fingerprint": "sha256(userAgent + screen + platform)" }
→ 201
{ "device": { "public_id": "dev_01J...", "number": 2, "name": "...", "branch_id": "...", "kind": "reception" },
  "token": "12|Q9f...",                    // plain text once; stored in IndexedDB meta, never localStorage
  "abilities": ["reception:offline", "reception:sync", "reception:blocks", "reception:read"],
  "expires_at": "2026-12-05T00:00:00Z" }   // createToken(name, abilities, now()->addDays(90))
```

Server: `App\Domain\Reception\Actions\RegisterReceptionDevice` → `ReceptionDevice::create([...])`,
`$device->createToken("device:{$device->public_id}", $abilities, now()->addDays(90))`.
`number` is a small per-branch integer used as the receipt-number prefix
(`D2-000123`, §6.2). Re-registration of the same fingerprint rotates the token
and revokes the old one (`$device->tokens()->delete()`).

### 2.2 Per-request auth on `/api/reception/*`

`Authorization: Bearer <device token>` + header `X-Actor-User: usr_01J...` (the user's `public_id`).
Middleware `App\Domain\Reception\Http\Middleware\AuthenticateReceptionDevice` (applied by class name in
`routes/api/reception.php` to every route except registration; guard `device`, ARCHITECTURE.md §6.1):

1. `auth('device')->user()` must be an active device of the request tenant; ability checked per route (`reception:sync` etc.).
2. `X-Actor-User` must be an active user with role Receptionist/Hospital Admin at the device's branch; attached to the request as `actor`. When the receptionist logged into the PWA while online, the user id is cached in `meta.actorUser`; offline, a local 4-digit PIN (hashed with `argon2` in-browser is not available — use PBKDF2 via WebCrypto, 100k iterations, stored in `meta.actorPin`) re-locks the desk after 15 idle minutes. The PIN protects the tablet, not the API: the server always re-validates the actor on replay.
3. Device `last_seen_at`, `last_ip`, `app_version` updated (throttled to once per minute).

Sanctum's token `expires_at` and `last_used_at` are used as-is. Hospital Admin
can revoke a device (`POST /api/reception/devices/{device}/revoke`), which
deletes its tokens and revokes its active blocks (§4.5).

---

## 3. Connection-state machine (shared store)

### 3.1 States and inputs

| State | Meaning | What the desk does |
|---|---|---|
| `online` | HTTP reachable **and** WebSocket connected | normal; realtime pushes; sync flushes immediately |
| `degraded` | HTTP reachable, WebSocket not connected | normal actions (server-backed); realtime falls back to polling (REALTIME.md §5); banner amber |
| `offline` | HTTP not reachable | block issuing; queue events; banner red |

Inputs (all funnelled into the store, nothing else may set `mode`):

| Input | Source | Weight |
|---|---|---|
| `navigator.onLine` + `window` `online`/`offline` events | browser | `false` ⇒ immediate `offline`; `true` ⇒ only triggers a heartbeat |
| `wsState` | `echo.connector.onConnectionChange()` → `connected \| connecting \| disconnected \| failed \| reconnecting` (laravel-echo 2.4 `ConnectionStatus`) | decides online vs degraded |
| Heartbeat `GET /api/ping` (5 s timeout, `AbortController`) | store timer | reachability |
| Any API response / network error | axios interceptor | success = reachability evidence; `ERR_NETWORK`/timeout ⇒ immediate heartbeat |

`GET /api/ping` (`routes/api/tenancy.php`, `api.tenancy.ping`, foundation-owned) → `200 {"t": 1725600000, "tenant": "ten_01J..."}` with
`Cache-Control: no-store`; no auth; excluded from the service worker (§11).

### 3.2 Transition rules and debounce

Constants (`resources/js/shared/connection/constants.ts`):

```ts
export const HEARTBEAT_TIMEOUT_MS = 5_000;
export const HEARTBEAT_INTERVAL_MS = { online: 30_000, degraded: 15_000, offline: 5_000 } as const;
export const HEARTBEAT_JITTER_MS = 1_000;
export const HEARTBEAT_FAILS_TO_OFFLINE = 2;   // consecutive
export const WS_DOWN_DEBOUNCE_MS = 10_000;     // online → degraded only after WS down this long
export const WS_UP_DEBOUNCE_MS = 2_000;        // degraded → online only after WS up this long
```

| From | To | Rule |
|---|---|---|
| any | `offline` | `navigator.onLine === false`, **or** `HEARTBEAT_FAILS_TO_OFFLINE` consecutive heartbeat failures. No debounce beyond the two failures (≤ 10 s worst case). |
| `offline` | `degraded` | one successful heartbeat (or any successful API response). No debounce — recovery must be fast. |
| `degraded` | `online` | `wsState === 'connected'` continuously for `WS_UP_DEBOUNCE_MS`. |
| `online` | `degraded` | `wsState !== 'connected'` continuously for `WS_DOWN_DEBOUNCE_MS`, **or** immediately when `wsState === 'failed'`. |
| `online` | `offline` | as row 1 (a WS drop alone never means offline — it means degraded). |

Heartbeat schedule: `HEARTBEAT_INTERVAL_MS[mode] ± jitter`; an extra heartbeat
fires immediately on `window online`, on `visibilitychange → visible`, on any
axios network error, and when the WS state changes to `connected` (to confirm
HTTP too). While the tab is hidden the interval is multiplied by 4 (never
disabled: a hidden desk tab must still notice it is back).

### 3.3 The store

```ts
// resources/js/shared/connection/store.ts
import { create } from 'zustand';
import { subscribeWithSelector } from 'zustand/middleware';

export type ConnectionMode = 'online' | 'degraded' | 'offline';
export type WsState = 'connected' | 'connecting' | 'disconnected' | 'failed' | 'reconnecting' | 'unknown';
export type SyncPhase = 'idle' | 'syncing' | 'conflicts' | 'error';

export interface ConnectionState {
  mode: ConnectionMode;
  since: number;                  // epoch ms of last mode change
  wsState: WsState;
  wsSince: number;
  browserOnline: boolean;
  lastHeartbeatOkAt: number | null;
  consecutiveHeartbeatFailures: number;
  tabVisible: boolean;
  // offline-desk facts surfaced in the indicator (written by the offline module, read by the banner)
  pendingEvents: number;
  conflicts: number;
  syncPhase: SyncPhase;
  activeBlock: { displayFrom: string; displayTo: string; remaining: number } | null;
  // inputs
  setBrowserOnline(v: boolean): void;
  setWsState(s: WsState): void;
  heartbeatResult(ok: boolean): void;
  setTabVisible(v: boolean): void;
  setSyncFacts(p: Partial<Pick<ConnectionState, 'pendingEvents' | 'conflicts' | 'syncPhase' | 'activeBlock'>>): void;
}

export const useConnection = create<ConnectionState>()(subscribeWithSelector((set, get) => {
  let wsDownTimer: number | undefined, wsUpTimer: number | undefined;
  const go = (mode: ConnectionMode) => { if (get().mode !== mode) set({ mode, since: Date.now() }); };
  const reevaluate = () => {
    const s = get();
    if (!s.browserOnline || s.consecutiveHeartbeatFailures >= HEARTBEAT_FAILS_TO_OFFLINE) { go('offline'); return; }
    if (s.mode === 'offline') { if (s.lastHeartbeatOkAt) go('degraded'); return; }   // never jump straight to online
    if (s.wsState === 'failed') { go('degraded'); return; }
    // online/degraded decided by the debounced timers below
  };
  return {
    mode: 'offline', since: Date.now(), wsState: 'unknown', wsSince: Date.now(),
    browserOnline: typeof navigator === 'undefined' ? true : navigator.onLine,
    lastHeartbeatOkAt: null, consecutiveHeartbeatFailures: 0, tabVisible: true,
    pendingEvents: 0, conflicts: 0, syncPhase: 'idle', activeBlock: null,

    setBrowserOnline: (v) => { set({ browserOnline: v }); reevaluate(); },
    setWsState: (ws) => {
      set({ wsState: ws, wsSince: Date.now() });
      clearTimeout(wsDownTimer); clearTimeout(wsUpTimer);
      if (ws === 'connected') wsUpTimer = window.setTimeout(() => { if (get().mode === 'degraded') go('online'); }, WS_UP_DEBOUNCE_MS);
      else wsDownTimer = window.setTimeout(() => { if (get().mode === 'online') go('degraded'); }, WS_DOWN_DEBOUNCE_MS);
      reevaluate();
    },
    heartbeatResult: (ok) => {
      set(ok ? { lastHeartbeatOkAt: Date.now(), consecutiveHeartbeatFailures: 0 }
             : { consecutiveHeartbeatFailures: get().consecutiveHeartbeatFailures + 1 });
      reevaluate();
    },
    setTabVisible: (v) => set({ tabVisible: v }),
    setSyncFacts: (p) => set(p),
  };
}));
```

`resources/js/shared/connection/heartbeat.ts` owns the timer (`startHeartbeat(baseUrl)`),
`resources/js/shared/connection/echoBridge.ts` wires
`echo.connector.onConnectionChange(status => useConnection.getState().setWsState(status))`
and `window.addEventListener('online'|'offline')`, `document.visibilitychange`.
The site app (queue page) imports the same store; it simply never sets
`pendingEvents`/`activeBlock`.

Derived selectors: `selectCanUseServer = m => m !== 'offline'`,
`selectIsLive = m => m === 'online'`, `selectIsPolling = m => m === 'degraded'`.

---

## 4. Block lease API

### 4.1 Lease

```
POST /api/reception/blocks/lease            ability reception:blocks
{ "session": "ses_01J...", "size": 10 }
→ 201 { "block": { "public_id": "blk_01J...", "session": "ses_01J...", "session_code": "A",
                   "range_start": 11, "range_end": 20, "next_number": 11, "status": "active",
                   "leased_at": "...", "expires_at": "2026-09-06T16:00:00+06:00" },
         "pool_remaining": { "counter": 5, "released": 0 } }
→ 409 { "code": "serials.pool_exhausted" } | { "code": "reception.block_limit", "active": 2 } | { "code": "reception.session_not_open" }
```

Action `App\Domain\Reception\Actions\LeaseBlock::handle(ReceptionDevice $device, SessionInstance $s, int $requested, Actor $actor): SerialBlock`
(exceptions `PoolExhausted` from Serials, `BlockLimitReached`, `SessionNotOpen` from `App\Domain\Reception\Exceptions`, all `status()` 409).
`size = min(requested, reception_devices.block_size (per device, default 5; SCHEMA.md), settings serial.default_block_size fallback, 30, remaining)`. A device holds at most
`serial.max_active_blocks_per_device` (default 2 — an application rule counted under the counter-pool lock, SCHEMA.md §3.3) active blocks per session.
`expires_at = planned_end_at + 2h`; the block is *not* expired by time while the
session is open — `expires_at` only tells the client when to stop trusting it if
it cannot reach the server (the session will have been closed by then, §4.5).

```sql
BEGIN;
-- (a) prefer a desk-owned/released range (SERIAL_ENGINE.md §3.5) so returned numbers go out first
SELECT id, next_number, range_end FROM serial_blocks
WHERE session_instance_id = :sid AND status = 'released' AND next_number <= range_end
ORDER BY range_start FOR UPDATE SKIP LOCKED LIMIT 1;
--   carve [next_number .. LEAST(next_number + :size - 1, range_end)]:
UPDATE serial_blocks SET next_number = :carve_end + 1,
       status = CASE WHEN :carve_end + 1 > range_end THEN 'exhausted' ELSE 'released' END
WHERE id = :released_id;
-- (b) otherwise the counter pool cursor
SELECT id, next_number, range_end FROM serial_pools WHERE session_instance_id = :sid AND pool = 'counter' FOR UPDATE;
UPDATE serial_pools SET next_number = next_number + :size WHERE id = :pool_id;   -- size already clamped to remaining
-- (c) the lease itself
INSERT INTO serial_blocks (public_id, session_instance_id, serial_pool_id, reception_device_id, range_start, range_end, next_number, status, leased_at, expires_at)
VALUES (:ulid, :sid, :counter_pool_id, :device_id, :start, :end, :start, 'active', now(), :exp) RETURNING *;
--   serial_pool_id is NOT NULL and is always the counter pool — also when the range was carved from a released row in (a)
INSERT INTO serial_events (session_instance_id, serial_id, type, actor_type, actor_user_id, reception_device_id, meta, occurred_at)
VALUES (:sid, NULL, 'block_leased', 'user', :actor, :device_id, '{"block_id": :block_id, "range": [:start, :end]}', now());
--   SCHEMA.md §3.3: the JSON column is `meta` (there is no `payload`), `actor_type` is required, the type value is `block_leased`
COMMIT;
```

The lock order (released block → counter pool) is the same as
`AllocateSerial::lockOwnerRow()`, so leases and desk allocations never deadlock.

### 4.2 Client policy (`resources/js/shared/offline/blocks.ts`)

- On desk load (online) and whenever a session appears on today's board: ensure one active block per *open* session at the device's branch (`GET /api/reception/blocks?date=today` rehydrates after a reinstall — blocks belong to the device, not the browser profile).
- **Renewal** = top-up: when an active block's `remaining <= serial.block_topup_threshold` (default 3) and `mode !== 'offline'`, lease another (server enforces the limit of 2 active). Offline, the device uses its second block, then shows "block exhausted — no more serials can be issued until connection returns" (issuing stops; check-in continues).
- Blocks are never renewed by time; they live until the session closes.

### 4.3 Issuing from a block (client)

`BlockIssuer.issue(session, patientRef, opts)` inside a Dexie `readwrite`
transaction over `blocks, serials, events`: read block (`status active`,
`next_number <= range_end`), take `number = next_number`, `next_number++`,
insert local serial (`publicId = 'local:' + clientEventId`, `displayCode`,
`status = 'booked'`, `source = 'offline'`), append `issue_serial` event (§6).
IndexedDB transactions are serialisable per store, so two tabs of the same
device cannot double-issue; two devices cannot because their blocks are disjoint.

### 4.4 Release

```
POST /api/reception/blocks/{block}/release       → 200 { "released_unused": 6 }
```

`ReleaseBlock`: lock the block `FOR UPDATE`; `status = 'released'`,
`released_at = now()`; unissued numbers stay on this row as its free-list
(`next_number..range_end`). If none remain, `status = 'exhausted'`. Called by the
client when a session closes, when the receptionist logs the device out, or by
`CloseSession` for every active block (server side, SERIAL_ENGINE.md §2.5).

### 4.5 Revocation (device lost / stolen / replaced)

`POST /api/reception/blocks/{block}/revoke` (Hospital Admin) or device revocation
(§2.2). `RevokeBlock`: lock the block; `status = 'released'`, `revoked_at = now()`,
`released_at = now()`, `returned_count = range_end - next_number + 1`. The same
row keeps owning its unissued numbers as a free-list entry (§4.6) — no second
row is created, so the `serial_blocks_range_excl` exclusion constraint is never
challenged. "Revoked" is therefore `released AND revoked_at IS NOT NULL`; a
device that later replays issues from such a block hits §8.2.

### 4.6 What "returned to the pool" means — decision

Unused numbers of a released block are **reused later in the same session**.
They are drained lowest-range-first by both `LeaseBlock` (§4.1a) and desk
`AllocateSerial` for the counter pool (SERIAL_ENGINE.md §4.3a), and they count
toward "counter remaining" on the board.

Why reuse rather than leave unissued:

- Capacity is the scarce thing, not tidy numbering. A 10-number block leased to a tablet that then sat idle would otherwise silently destroy a third of a 30-serial counter quota; clinics would respond by leasing tiny blocks, which defeats offline resilience.
- Uniqueness does not depend on monotonicity. The owner-row lock discipline is identical for pools and blocks, and `UNIQUE (session_instance_id, number)` is the backstop, so reusing `A-014` after `A-023` was issued is exactly as safe as issuing `A-024`.
- Calling order is `position`, not `number` (SERIAL_ENGINE.md §7). A late `A-014` is placed at the *tail* by `PositionService::initial()` when its number is below the current maximum position — the patient with the reused number is called in arrival order, and the display shows `A-014` after `A-023` without anyone being skipped.
- Releases mid-session are rare (device logout/revoke/online closure); at session close nothing is issued any more, so "returned at session close" (the brief's wording) costs nothing and simply makes the shift report honest: `issued`, `returned`, `wasted = 0`.

The one thing we do *not* do is move pool cursors backwards; the released row is
the free-list, the pool's `next_number` only ever increases.

---

## 5. What the PWA caches, and the Dexie schema

### 5.1 Cached data (refreshed while online)

| Data | Source | Refresh | Retention |
|---|---|---|---|
| Today's (and tomorrow's) session instances at the device's branch, with pools' remaining and counts | `GET /api/reception/bootstrap?date=today` (one round trip: sessions, blocks, doctors, fee snapshots, tenant settings, actor user) | on load, on every `board.updated` push, every 5 min | 3 days |
| Serials of those sessions (compact `SerialResource` incl. patient name + masked mobile, status, position) | bootstrap + realtime events | live | with session |
| Active blocks of this device | bootstrap / lease responses | live | with session |
| Recent patients: every patient looked up/booked on this device in the last 30 days, and the branch's last 500 by `last_visit_at` | `GET /api/reception/patients/recent`, lookups | daily + on lookup | 30 days, LRU 2 000 rows |
| Patient history summary (last 10 visits: date, doctor, diagnosis line, Rx count — **no prescription body**) | fetched with a lookup | on lookup | 30 days |
| Print templates (58 mm, 80 mm, A5 HTML + CSS, clinic header, footer text) | `GET /api/reception/print-templates` | on load (StaleWhileRevalidate) | forever, versioned |
| Fee table per doctor (consultation, follow-up, free window days) | bootstrap | on load | 3 days |

### 5.2 Dexie schema (`resources/js/shared/offline/db.ts`)

```ts
import Dexie, { type EntityTable } from 'dexie';

export interface MetaRow { key: string; value: unknown }                  // deviceToken, device, actorUser, actorPin, sequenceNo, lastSyncAt, schemaVersion
export interface CachedSession { publicId: string; date: string; branchId: string; doctorId: string; code: string;
  status: string; mode: 'serial' | 'slot'; plannedStartAt: string; delayMinutes: number; counts: Record<string, number>;
  remaining: { counter: number; released: number; buffer: number; online: number }; updatedAt: number }
export interface CachedSerial { publicId: string; sessionId: string; number: number; displayCode: string; position: number;
  status: string; priority: string; source: string; patientRef: string; patientName: string; mobileMasked: string;
  clientEventId?: string; blockId?: string; local: boolean; cashCollected?: number; updatedAt: number }
export interface CachedBlock { publicId: string; sessionId: string; sessionCode: string; rangeStart: number; rangeEnd: number;
  nextNumber: number; status: 'active' | 'released' | 'exhausted' | 'revoked'; expiresAt: string }
export interface CachedPatient { publicId: string; localId?: string; mobile: string; name: string; nameTokens: string[];
  sex?: 'm' | 'f' | 'o'; dob?: string; ageYears?: number; relationToHolder?: string; updatedAt: number }
export interface CachedHistory { patientId: string; visits: Array<{ date: string; doctor: string; dx: string; rxCount: number }>; fetchedAt: number }
export interface PrintTemplate { id: '58' | '80' | 'a5'; version: number; html: string; css: string }
export interface OfflineEvent {                                             // §6
  clientEventId: string; sequenceNo: number; type: OfflineEventType; payload: unknown; clientOccurredAt: string;
  actorUserId: string; sessionId?: string; dependsOn?: string;
  status: 'pending' | 'sending' | 'accepted' | 'conflict' | 'rejected' | 'deferred';
  result?: unknown; conflictReason?: string; attempts: number;
}

export class ReceptionDB extends Dexie {
  meta!: EntityTable<MetaRow, 'key'>;
  sessions!: EntityTable<CachedSession, 'publicId'>;
  serials!: EntityTable<CachedSerial, 'publicId'>;
  blocks!: EntityTable<CachedBlock, 'publicId'>;
  patients!: EntityTable<CachedPatient, 'publicId'>;
  history!: EntityTable<CachedHistory, 'patientId'>;
  printTemplates!: EntityTable<PrintTemplate, 'id'>;
  events!: EntityTable<OfflineEvent, 'clientEventId'>;

  constructor(tenantId: string, deviceId: string) {
    super(`bp-reception-${tenantId}-${deviceId}`);
    this.version(1).stores({
      meta: 'key',
      sessions: 'publicId, date, [date+branchId], doctorId',
      serials: 'publicId, sessionId, [sessionId+number], [sessionId+status], patientRef, clientEventId',
      blocks: 'publicId, sessionId, [sessionId+status]',
      patients: 'publicId, mobile, *nameTokens, localId, updatedAt',
      history: 'patientId, fetchedAt',
      printTemplates: 'id',
      events: 'clientEventId, sequenceNo, status, [status+sequenceNo], sessionId, dependsOn',
    });
    // Future: this.version(2).stores({...}).upgrade(tx => ...) — additive only; never rename a store while
    // `events` may contain pending rows (upgrade runs before sync).
  }
}
```

Database name includes tenant and device so a shared browser profile across
clinics (rare, but demo laptops) never mixes logs. `serials.publicId` for
unsynced rows is `local:<clientEventId>`; on `accepted` the row is re-keyed to
the server id (delete + put inside one transaction) and every event referencing
the local id is rewritten.

---

## 6. The event log

### 6.1 Record format

`resources/js/shared/offline/eventLog.ts` owns the log: `append({ type, payload, sessionId?, dependsOn? })`
assigns `clientEventId` and `sequenceNo` inside one Dexie transaction and returns the row; components
never write to `db.events` directly (CONVENTIONS.md §7.6).

```ts
export type OfflineEventType =
  | 'register_patient' | 'issue_serial' | 'check_in' | 'collect_cash'
  | 'print_token' | 'void_local';

// clientEventId: ULID (monotonic factory in resources/js/shared/ulid.ts — 26 chars, time-ordered, no dependency)
// sequenceNo:     integer from meta.sequenceNo, incremented in the same Dexie transaction as the append — total order per device
// clientOccurredAt: device clock ISO-8601 with offset → offline_events.client_occurred_at (the server records received_at; device time is advisory)
// dependsOn:     clientEventId of an earlier event this one needs (issue_serial → register_patient; check_in/cash/print → issue_serial)
```

Payloads:

| type | payload |
|---|---|
| `register_patient` | `{ localId, mobile, name, sex?, ageYears? \| dob?, relationToHolder? }` |
| `issue_serial` | `{ sessionId, blockId, number, displayCode, patientRef: "pat_…" \| "local:…", priority, walkIn: boolean, appointmentType: "new"\|"followup", feeSnapshot: { amount, currency } }` |
| `check_in` | `{ serialRef: "ser_…" \| "local:…" }` |
| `collect_cash` | `{ serialRef, amount, currency: "BDT", receiptNo: "D2-000123", note? }` |
| `print_token` | `{ serialRef, format: "58"\|"80"\|"a5", copies }` (audit only; never conflicts) |
| `void_local` | `{ voidedClientEventId, reason }` — only for a `issue_serial` still `pending`; the client marks the issue event and its dependants `deferred`→removed, restores the block cursor **only if** it was the last number issued, and keeps this audit event |

`receiptNo` = `D{device.number}-{6-digit per-device counter in meta}`; unique
clinic-wide by construction, so billing can ingest it without renumbering.

### 6.2 Allowed offline vs blocked — decision

| Allowed offline | Why |
|---|---|
| Issue serial from own block (new, follow-up, walk-in flag) | the LOCKED design |
| Check in / mark arrived (any cached serial, including online-pool ones) | arrival is a physical fact the desk witnesses; replay is idempotent |
| Print token slip (and reprint) | local |
| View cached patient history summary | read-only cache |
| Create patient stub (name, mobile, sex, age) | needed to issue; reconciled on sync (§8.1) |
| Record cash collected against a serial | fee collection during an outage is unavoidable; the amount is a fact; billing reconciles (§8.5) |
| Void own unsynced serial | pure local compensation, nothing has reached the server |

| Blocked offline (button disabled with the reason) | Why |
|---|---|
| Refunds, discounts, due adjustments | money leaves the drawer — needs the server's payment state |
| Cancel any serial (except void of own unsynced) | may trigger refund; server owns status |
| Online-pool and buffer-pool serials, priority insert, reorder, call-next, transfer, postpone, session start/pause/close/delay | server-owned locks/positions; the doctor's screen is server-driven |
| Card/bKash/Nagad payments | gateway |
| Prescriptions, vitals, clinical history bodies | clinical data must not sit in a tablet's IndexedDB (brief N) |
| Editing existing patient records, merging | reconciliation risk |

### 6.3 Server-side counterpart: `AllocateFromBlock`

```php
namespace App\Domain\Reception\Actions;

final class AllocateFromBlock
{
    /** Called by IssueSerialHandler during replay. Validates block ownership/state, then delegates to AllocateSerial. */
    public function handle(ReceptionDevice $device, SerialBlock $block, int $number, AllocationRequest $r): Serial;   // AllocationRequest = App\Domain\Serials\Data\AllocationRequest
}
```

Inside `AllocateSerial`'s transaction the block is the owner row
(`FOR UPDATE`, SERIAL_ENGINE.md §4.3). Because the device issued `number`
offline, the server must issue *that* number, not the cursor: the handler
checks `block.status = 'active' AND block.next_number <= number <= block.range_end`,
then sets `next_number = number + 1` (numbers below `number` that the device
skipped — a voided slip — stay unissued on this block until release, when they
return to the free-list). Any other state is a conflict (§8.2).

---

## 7. Replay protocol

### 7.1 Request / response

```
POST /api/reception/sync                     ability reception:sync, header X-Actor-User
{ "sequence_no_from": 118, "app_version": "1.4.2",
  "events": [ { "client_event_id": "01J7…", "sequence_no": 118, "type": "register_patient", "client_occurred_at": "…", "actor_user_id": "usr_…", "depends_on": null, "payload": { … } },
              { "client_event_id": "01J7…", "sequence_no": 119, "type": "issue_serial", "depends_on": "01J7…", "payload": { … } } ] }
→ 200
{ "server_time": "2026-09-06T10:44:12+06:00",
  "results": [
    { "client_event_id": "01J7…", "status": "conflict", "conflict_reason": "duplicate_patient",
      "server_result": { "candidates": [ { "public_id": "pat_…", "name": "Rahima Begum", "sex": "f", "age": 54, "last_visit": "2026-08-30" } ] } },
    { "client_event_id": "01J7…", "status": "pending", "conflict_reason": "dependency_unresolved", "server_result": { "depends_on": "01J7…" } } ],
  "bootstrap_stale": true }        // tells the client to re-fetch /api/reception/bootstrap after this batch
```

Batch ≤ 200 events, ordered by `sequence_no` ascending (server rejects an
unordered batch with 422). The client sends only `pending`/`deferred` events;
`conflict` events are re-sent through `/sync/resolve` (§7.4).

### 7.2 Server pipeline

`App\Domain\Reception\Services\SyncReplayer::replay(ReceptionDevice $device, User $actor, array $events): SyncResult`

1. `Cache::lock('sync:'.Tenancy::id().":{$device->id}", 120)->block(10)` — one batch per device at a time (two tabs, a retry after timeout). The lock key is tenant-scoped because device ids repeat across tenant schemas (CONVENTIONS.md §15).
2. For each event, in order:
   a. **Idempotency**: `INSERT INTO offline_events (reception_device_id, client_event_id, sequence_no, type, session_instance_id, serial_block_id, payload, status, client_occurred_at, received_at) VALUES (…,'pending',:occurred,now()) ON CONFLICT (reception_device_id, client_event_id) DO NOTHING RETURNING id`. If 0 rows, load the existing row; if its status is `accepted|conflict|rejected` return the stored `server_result` verbatim (**exactly-once semantics**); if `pending` (previous attempt died mid-flight) fall through and process again — every handler is itself idempotent because it goes through `AllocateSerial`'s `client_event_id` check and `SerialTransition`'s no-op rules.
   b. Resolve `depends_on`: if the dependency is `conflict`/`rejected`/absent → result `pending` with `dependency_unresolved`, stop processing this event (its own row stays `pending`).
   c. Dispatch to a handler in `App\Domain\Reception\Handlers\*Handler::handle(OfflineEvent $e, ReplayContext $ctx): ReplayOutcome` inside `DB::transaction()`; `ReplayContext` carries the device, actor, the per-batch map `local:… → server id`, and `Actor(source: offline_replay)`.
   d. Persist outcome: `UPDATE offline_events SET status, conflict_reason, server_result, processed_at`.
3. Broadcast/notification side effects fire through the normal after-commit domain events, so an offline check-in updates the live queue the moment it replays.

Handler outcomes and mappings:

| type | Handler → engine action | accepted `server_result` |
|---|---|---|
| `register_patient` | `RegisterPatientHandler` → `App\Domain\Patients\Actions\CreatePatient` if no patient with that mobile; else conflict §8.1 | `{ patient: { public_id, name } }` and `ctx.map[localId] = id` |
| `issue_serial` | `IssueSerialHandler` → `AllocateFromBlock` (§6.3) with `AllocationRequest(source: offline, clientEventId, serialBlockId, patientId from map)` + appointment creation | `{ serial: SerialResource }`, `ctx.map['local:'+id] = serial` |
| `check_in` | `CheckInHandler` → `CheckInSerial`; if already `checked_in|in_consultation|completed` → accepted with `server_result.noop = true` (same physical fact); if `cancelled` → §8.4; if `no_show` → `ReinstateSerial(present: true)` accepted with `reinstated = true` | `{ serial }` |
| `collect_cash` | `CollectCashHandler` → `App\Domain\Billing\Actions\RecordCashPayment(serial, amount, receiptNo, collectedBy, collected_at = event.client_occurred_at)`; if the appointment is already fully paid → §8.5 | `{ payment: { public_id, receipt_no } }` |
| `print_token` | `PrintTokenHandler` → `serial_events` row of type `printed` (`meta.format`, `meta.copies`) | `{}` |
| `void_local` | `VoidLocalHandler` → `serial_events` row on the session (`serial_id NULL`) | `{}` |

Rejections (`status: rejected`, never retried by the client, shown in the
conflicts list as "could not be recorded"): `block_not_owned`, `block_unknown`,
`actor_not_permitted`, `serial_not_found`, `session_not_found`,
`payload_invalid`, `number_out_of_block_range`.

### 7.3 Client sync loop (`resources/js/shared/offline/sync.ts`)

- Trigger: `useConnection.subscribe(s => s.mode, m => m !== 'offline' && flush())`, plus every 20 s while pending > 0, plus after each new event when online (immediate, debounced 300 ms so a batch of one-click actions ships together).
- `navigator.locks.request('bp-sync-<device>', flush)` guarantees a single flusher across tabs.
- `flush()`: mark ≤ 200 `pending|deferred` events `sending` (ordered by `sequenceNo`), POST, apply results in one Dexie transaction: `accepted` → re-key local rows, drop from log after 7 days (kept for the shift report); `conflict` → store `conflictReason`/`result`, `setSyncFacts({conflicts})`; `pending` → back to `pending`; `rejected` → store. Network failure → all `sending` back to `pending`, exponential backoff 2 s → 60 s. If `bootstrap_stale` → refetch bootstrap.
- `syncPhase` becomes `conflicts` when any conflict row exists; the resolution UI (§8) is modal over the board.

### 7.4 Resolution endpoint

```
POST /api/reception/sync/resolve
{ "client_event_id": "01J7…", "resolution": "link_patient", "params": { "patient": "pat_…" } }
→ 200 { "client_event_id": "…", "status": "accepted", "server_result": { … } }      // same shape as a sync result
```

`SyncReplayer::resolve(device, actor, clientEventId, Resolution $r)` loads the
`conflict` row, re-runs its handler with the resolution attached, persists the
new outcome, and writes `offline_events.resolution` (varchar, one of the resolution names in §8) + `resolution_params jsonb` (schema addition) + `resolved_by_user_id`. The
client then re-keys/rewrites dependants and re-sends them in the next batch.

---

## 8. Conflict taxonomy and resolution flows

Every row: how the server detects it, what `server_result` carries, and the
card the receptionist sees. Cards are shown one at a time in `sequence_no`
order; each has a mandatory choice; "Decide later" keeps the card in a
persistent badge (`conflicts` in the indicator) but never dismisses it.

### 8.1 `duplicate_patient` — stub matches an existing mobile (SCHEMA.md also lists `patient_mismatch`: a serial whose `patientRef` resolves to a different patient than the server holds — treated with the same card)

Detect: `register_patient` with a mobile that has ≥ 1 patient (household
phones are common, so even an exact name match is **not** auto-linked).
`server_result: { candidates: [{public_id, name, sex, age, relation, last_visit}], stub: {…} }`.
Card: "Rahima Begum (54, F) already uses 01711-…. Is this the same person?"
→ **Link to existing** (pick one) → `link_patient {patient}`; the stub is
discarded, dependants rewritten to the server id. → **Add as new family member**
→ `family_member {holder_patient?}` → creates the patient under
`patient_relations`. Both write `audit_logs`.

### 8.2 `serial_already_used` — block revoked / re-leased while offline

Detect: `issue_serial` whose block is `revoked` (or `released` by an admin), and
`serials (session_instance_id, number)` already exists.
`server_result: { taken_by: { display_code, source, booked_at }, suggested_next: 24, block_status: "revoked" }`.
Card: "A-017 was reissued to another patient while this device was offline.
The patient is holding a slip that says A-017." → **Reissue as next counter
number & reprint** → `reissue {}` → server allocates from the counter pool
(free-list first) with the same `client_event_id`, dependants follow, the
client prints a new slip and shows "tell the patient: your number is now A-024".
→ **Discard** (patient already left / duplicate) → `discard {reason}`.
If the number is *not* taken (block revoked but number free) the server accepts
it by taking that exact number from the same (now `released`, `revoked_at` set) row: if `n = next_number` just advance; otherwise shrink the row to `[range_start..n-1]` and insert a new released row `[n+1..range_end]` (both disjoint, so `serial_blocks_range_excl` holds) and return `accepted` with `warning: "block_released"`; no card, but a toast.

### 8.3 `session_closed` (covers cancelled — `server_result.session_status` says which)

Detect: `issue_serial` or `check_in` against an instance whose status is
`closed|cancelled`. `server_result: { session_status, closed_at, alternatives: [{public_id, code, date, remaining}] }`.
Card: "Session A was closed at 13:05 while offline." → **Move to session B**
→ `move_to_session {session}` (new serial via counter pool, source `offline`,
`transferred_from_serial_id` null because the old one never existed server-side)
→ **Record as seen in the closed session** (Hospital Admin PIN) →
`record_in_closed {}` → inserts the serial with `status = completed`,
`completed_at = event.client_occurred_at`, event `serial.recorded_post_close` — for the
case where the doctor actually saw the patient during the outage. →
**Discard** `{reason}`.

### 8.4 `status_regression` — check-in of a serial cancelled meanwhile

Detect: `check_in` and the serial is `cancelled`.
`server_result: { cancelled_at, cancelled_by: "patient"|"staff"|"system", cancel_reason_code, refund: { status, amount } }`.
Card: "A-031 was cancelled online at 09:58 (refund pending ৳500). The patient
is here." → **Reinstate & check in** → `reinstate {}` → `SerialTransition`
`cancelled → checked_in` (an extra edge allowed **only** from a resolution,
actor Hospital Admin/Receptionist, event `serial.reinstated_after_cancel`),
emits `SerialReinstatedAfterCancel` so billing voids the pending refund or
re-invoices. → **Discard check-in** → `discard {}` (patient will be issued a
fresh serial normally).

### 8.5 `already_paid` — cash collected for a serial paid online

Detect: `collect_cash` and the appointment `payment_status = paid`.
`server_result: { online_payment: { method, amount, paid_at, txn_ref }, cash: { amount, receipt_no } }`.
Card: "৳500 cash was taken for A-012, but it was already paid by bKash at
08:41." → **Refund the cash now** → `refund_cash {}` → `App\Domain\Billing\Actions\RecordCashRefund`
(online action, allowed now) + prints a refund slip. → **Keep as advance/credit**
→ `credit {}` → billing credits the patient's account. → **Discard the cash
record** → `discard {reason}` (requires Hospital Admin PIN — cash that
existed is disappearing from the books; `audit_logs`).

### 8.6 Non-conflicts handled silently (noted in the sync log, no card)

Duplicate check-in (`noop`), check-in of a `no_show` (reinstated),
`print_token`/`void_local` (always accepted), stub whose mobile is new
(created). These are idempotent facts, not decisions.

---

## 9. The connection indicator — spec

Component `resources/js/shared/connection/ConnectionIndicator.tsx` (one implementation for the panel
and the site bundle — plain React + CSS custom properties, **no MUI/Emotion** so the site may import it;
F-owned, ARCHITECTURE.md §7.7), mounted above the app bar of every reception route (and in the queue
page header in its quiet form), reads only `useConnection`. The colour tokens below are CSS variables
declared in the component's own stylesheet, not in the MUI theme.

| Mode | Bar | Text (EN / বাংলা) | Extras |
|---|---|---|---|
| `online` | 4 px green strip (`#16a34a`) under the app bar + 8 px green dot with "Online" in the app bar | Online / অনলাইন | none — quiet |
| `degraded` | 40 px amber bar (`#d97706`, black text) | "Live updates paused — refreshing every 5 s" / "লাইভ আপডেট বন্ধ — প্রতি ৫ সেকেন্ডে রিফ্রেশ" | spinner; since HH:MM |
| `offline` | 48 px red bar (`#dc2626`, white text, `aria-live="assertive"`) **and** a 3 px red outline around the viewport (`body::after`, fixed, `pointer-events:none`) **and** `document.title = "⛔ OFFLINE — " + title` **and** a two-tone sound on entry (mutable, default on) | "OFFLINE since 10:42 · issuing from block A-021–A-030 (7 left) · 4 actions queued" / "অফলাইন ১০:৪২ থেকে · ব্লক A-021–A-030 (৭ বাকি) · ৪টি কাজ অপেক্ষমাণ" | every disabled button shows a tooltip "Not available offline"; the Issue button reads "Issue (offline block)" |
| back online, pending > 0 | 40 px blue bar (`#2563eb`) | "Back online — syncing 4 actions…" | progress `accepted/total` |
| conflicts > 0 | red badge on the bar "3 need your decision" | opens §8 cards | persists until zero |

Rules: the bar never auto-hides in `degraded`/`offline`; colour is never the
only signal (icon + text + title); state changes announce via `aria-live`; the
banner is rendered outside MUI's `Snackbar` system so nothing can cover it.

---

## 10. Token slip printing (offline-capable)

`resources/js/panel/Components/Reception/TokenSlip.tsx` renders the cached template
into a hidden `<iframe>` (`srcdoc`), waits for `document.fonts.ready`, then
`iframe.contentWindow.print()`. Fonts (`@fontsource/noto-sans-bengali`,
`@fontsource/inter`) are precached by the service worker, so Bangla renders
offline.

```css
/* 58 mm */ @page { size: 58mm auto; margin: 2mm } body { width: 54mm; font: 11pt/1.25 'Noto Sans Bengali','Inter',sans-serif }
/* 80 mm */ @page { size: 80mm auto; margin: 3mm } body { width: 74mm; font: 12pt/1.3 ... }
/* A5   */ @page { size: A5; margin: 10mm }       body { font: 12pt/1.4 ... }
.code { font-size: 34pt; font-weight: 700; letter-spacing: .04em; text-align: center }   /* A-042 */
.qr   { width: 28mm; margin: 2mm auto }
```

Content (all formats): clinic name + branch (template), doctor name (EN + BN),
session label (`Morning (A)` / `সকাল`), date, **display code** (large),
patient name, serial position hint ("Approx. 12 ahead" if known), estimated
call time if known else blank, fee collected / due, receipt no, QR of the
public queue URL (`/q/{doctor-slug}/today?s={serial public id or local id}` —
a local id resolves after sync via `GET /q/resolve/{localId}`), and a small
"issued offline" marker when `source = offline`. Reprint is always allowed and
logged (`print_token`, `copies`).

Printer selection is the browser's; the template's `id` is chosen from
`meta.printFormat` (set per device by the admin). The site app's queue page
is what the QR opens, so it must render the code even before the serial has
synced — it shows "Registered at the desk; live position will appear when the
desk reconnects".

---

## 11. Service worker (vite-plugin-pwa, `injectManifest`)

The `VitePWA` block of `vite.config.ts` is foundation-owned and is reproduced verbatim in
ARCHITECTURE.md §7.2 (`strategies: 'injectManifest'`, `srcDir: 'resources/js/panel'`, `filename: 'sw.ts'`,
`outDir: 'public'`, `scope: '/panel/'`, `registerType: 'prompt'` — never swap the shell mid-shift;
manifest name `Clinic Desk`). Registration is `resources/js/panel/pwa.ts`. This section owns only the
worker itself.

`resources/js/panel/sw.ts` (workbox modules, all already installed):

| Route | Strategy | Notes |
|---|---|---|
| Precache manifest (hashed `build/*`, fonts, icons) | `precacheAndRoute(self.__WB_MANIFEST)` | app shell assets |
| Navigation `GET /panel/reception*` (Inertia HTML) | `NetworkFirst`, `networkTimeoutSeconds: 3`, cache `shell-v1`, `cacheableResponse {statuses:[200]}` | cold offline boot serves the last HTML; the page then hydrates from Dexie (`bootstrap` in props is treated as stale) |
| `GET /api/reception/bootstrap*` | `NetworkFirst`, timeout 4, cache `api-bootstrap`, `expiration {maxEntries: 4, maxAgeSeconds: 259200}` | belt-and-braces; Dexie is the real store |
| `GET /api/reception/patients*`, `/history*` | `NetworkFirst`, timeout 4, `maxEntries: 500` | |
| `GET /api/reception/print-templates` | `StaleWhileRevalidate` | |
| `GET /build/*`, `/fonts/*` | `CacheFirst`, 1 year | hashed |
| **Any non-GET**, `/api/reception/sync*`, `/api/reception/blocks*`, `/api/ping`, `/broadcasting/*`, `/api/device/broadcasting/*`, `/sanctum/*`, `/queue/*` (the public state/sessions endpoints, REALTIME.md §5) | `NetworkOnly` (explicit `registerRoute` placed **first**) | mutations and liveness must never be served from cache |
| Everything else (other panel routes, Inertia JSON with `X-Inertia`) | `NetworkOnly` | the desk is the only offline surface |

`navigateFallback` is **not** used (an Inertia app must not get a generic
`index.html`); `navigateFallbackDenylist`/allowlist are unnecessary because the
navigation route is scoped. Background Sync is not used (no iOS support);
replay runs in-page (§7.3). `registerSW({ immediate: true, onNeedRefresh })`
from `virtual:pwa-register` shows "Update ready — apply when the desk is idle";
the update is applied only when `pendingEvents === 0`.

---

## 12. Test plan

### 12.1 Vitest (`resources/js/**/__tests__`, fake timers, `happy-dom`)

`shared/connection/__tests__/store.test.ts`

- `starts_offline_until_first_heartbeat_then_degraded`
- `browser_offline_event_is_immediate_offline`
- `two_consecutive_heartbeat_failures_go_offline_one_does_not`
- `ws_connected_promotes_degraded_to_online_only_after_2s`
- `ws_drop_demotes_online_to_degraded_only_after_10s_flap_within_10s_ignored`
- `ws_failed_demotes_immediately`
- `offline_never_jumps_straight_to_online`
- `heartbeat_interval_follows_mode_and_quadruples_when_hidden`
- `api_network_error_triggers_immediate_heartbeat`

`shared/offline/__tests__/eventLog.test.ts`

- `append_assigns_monotonic_sequence_no_and_ulid_in_one_transaction`
- `issue_from_block_advances_cursor_and_refuses_when_exhausted`
- `two_tabs_cannot_issue_same_number` (two `ReceptionDB` instances on the same fake IndexedDB)
- `void_local_restores_cursor_only_for_last_number`
- `flush_sends_pending_in_seq_order_max_200_and_marks_sending`
- `accepted_rekeys_local_ids_in_serials_and_dependants`
- `conflict_sets_sync_phase_and_keeps_event`
- `pending_dependency_unresolved_is_resent_after_resolution`
- `network_failure_returns_events_to_pending_with_backoff`
- `single_flusher_across_tabs_via_web_locks`

`panel/Components/Reception/__tests__/tokenSlip.test.ts` — renders each format with a Bangla
name and a `local:` id; QR URL correct.

### 12.2 PHPUnit (Postgres, `Tests\TestCase` + `Tests\Concerns\WithTenants`, `actingAsDevice()` for device auth)

`tests/Feature/Reception/DeviceRegistrationTest`: register issues token with
abilities/expiry; re-register rotates; revoked device gets 401; wrong-branch
actor gets 403.

`tests/Feature/Reception/BlockLeaseTest`: size clamp; limit of 2 active; carves released rows first;
release keeps remainder as free-list; revoke keeps the remainder on the same (now released, `revoked_at` set) row;
`CloseSession` releases all. `tests/Concurrency/Reception/BlockLeaseConcurrencyTest`
(`#[Group('concurrency')]`, `$connectionsToTransact = []`, `Tests\Support\ProcessPool` with 6 workers running
`reception:lease-hammer`): concurrent leases pairwise disjoint (also referenced by SERIAL_ENGINE.md §18.3).

`tests/Feature/Reception/SyncReplayIdempotencyTest`

- `same_batch_twice_returns_identical_results_and_creates_no_duplicates`
- `partial_failure_mid_batch_then_retry_completes_exactly_once` (kill after event 3 of 5 by throwing in a handler once)
- `unordered_batch_is_422`, `batch_over_200_is_422`
- `dependency_on_conflicted_event_returns_pending`

`tests/Concurrency/Reception/SyncReplayConcurrencyTest` (`ProcessPool`): `concurrent_batches_from_same_device_serialise_on_lock`
and `two_devices_replaying_interleaved_logs_never_share_a_number` (CONVENTIONS.md §6.5).

`SyncConflictPatientMobileExistsTest`, `SyncConflictNumberTakenTest`
(revoked block: taken → conflict + reissue resolution; free → accepted with
split released row), `SyncConflictSessionClosedTest` (move / record_in_closed
with PIN / discard), `SyncConflictSerialCancelledOnlineTest` (reinstate emits
`SerialReinstatedAfterCancel`), `SyncConflictAlreadyPaidTest` (refund_cash /
credit / discard requires HA PIN), `SyncNonConflictTest` (duplicate check-in
noop, no_show reinstated, print accepted).

`tests/Feature/Reception/OfflineIssueEndToEndTest`: lease → replay `register_patient + issue_serial + check_in +
collect_cash` → serial exists with `source = offline`, `serial_block_id`,
`client_event_id`, block cursor advanced, `SerialAllocated` and
`SerialStatusChanged` dispatched after commit (`Event::fake`), `QueueStateRepository::rebuild()` called
(the `InvalidateQueueState` listener, REALTIME.md §4.3); every table written is covered by
`assertTenantIsolated()` and every clinical write by `assertAudited()` (CONVENTIONS.md §6.4).

---

## 13. Schema additions requested (reconciled against SCHEMA.md)

> Reconciled 2026-09-06: every item below is now in SCHEMA.md. The section is kept as the rationale record — where it says "add", "missing" or "supersedes", SCHEMA.md already has it.

1. `reception_devices`: add `public_id char(26)` ULID (`HasPublicId`), `number smallint` (per-branch receipt prefix), `kind varchar(10) check in (reception, display)` (REALTIME.md display tokens), `app_version varchar(20)`. Existing `device_fingerprint`, `status`, `block_size`, `last_seen_at`, `last_sync_at`, `last_ip`, `user_agent`, `revoked_at` are used as-is. `device_secret_hash` is **not used** by this design (Sanctum device tokens per the brief's "Sanctum-token-based device identity"); make it nullable or drop it. The model must use `HasApiTokens`. Also needed for the wire shapes above: `branches.public_id` (channel names, `branch_id` in the registration payload) and `users.public_id` (`X-Actor-User`, `actor_user_id` in events) — both in SCHEMA.md §3.1.
2. `serial_blocks`: `reception_device_id` **nullable** (NULL = desk-owned released range); `serial_pool_id` may point at the online pool for §3.6 releases; add `public_id`, `revoked_at`, `expires_at`; relax the "one active block per device per session" partial unique to allow 2 (code-enforced); `returned_count` semantics = returned **and reusable** (supersedes SCHEMA.md §5.1.3 "not re-issued"). Keep `serial_blocks_range_excl`.
3. `offline_events`: add `depends_on char(26) null`, `resolution_params jsonb null`, `processed_at timestamptz null`, `attempts smallint default 0`; `type` list gains `void_local` (and `mark_arrived`/`cancel_serial`/`assign_patient` may stay unused — check-in and mark-arrived are one transition, cancellation is blocked offline, patient assignment is expressed with `depends_on`); `conflict_reason` list gains `already_paid`, `dependency_unresolved` (`status_regression` is used for the cancelled-online case, `serial_already_used` for revoked-block collisions, `duplicate_patient` for mobile matches); `resolution` list becomes `link_patient, family_member, reissue, move_to_session, record_in_closed, reinstate, refund_cash, credit, discard`; unique `(reception_device_id, client_event_id)` and index `(reception_device_id, status)`.
4. `serials`: uses existing `serial_block_id`, `reception_device_id`, `client_event_id`, `issued_by_user_id`; see SERIAL_ENGINE.md §19.5 for the session-scoped idempotency index.
5. `serial_events`: nullable `serial_id` and extra `type` values (`block_leased`, `block_released`, `block_revoked`, `printed`, `void_local`, `recorded_post_close`, `reinstated_after_cancel`) — SERIAL_ENGINE.md §19.7.
6. `patients.mobile` non-unique index (households) and `patient_relations` for `family_member`.
7. `settings` keys: `serial.default_block_size` (exists), `serial.max_active_blocks_per_device` (2), `serial.block_topup_threshold` (3), `reception.pin_idle_minutes` (15), `reception.sound_on_offline` (true).
8. **Superseded provisional text elsewhere**: SCHEMA.md §3.3 `offline_events.status`/`resolution` lists and the `payload`/`server_result` JSON shapes are replaced by §6.1/§7.1 of this document (`accepted|conflict|rejected|pending`; resolutions of item 3). ARCHITECTURE.md §7.7 and §9.2 already describe the same store (`online|degraded|offline`) and the same replay results as this document — there is no second state machine.
