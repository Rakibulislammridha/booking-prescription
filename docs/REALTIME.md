# Realtime Live Queue — Design Specification

Module owner: E (live queue) plus the realtime halves of D and F of
`docs/BRIEF.md`. LOCKED: Laravel Reverb with a designed polling fallback
(`GET /queue/{doctor}/state`, ETag, 5 s); public link without login
(`queue.hospital.com/dr-rahman/today`); ETA from a running average of actual
consultation times; T-3 notification; waiting-room display with Bangla/English
voice; one-tap delay broadcast; one connection-state model shared with OFFLINE.md.

Companions: `docs/SERIAL_ENGINE.md` (events emitted, ETA maths),
`docs/OFFLINE.md` §3 (the connection store this document plugs into).

Code locations (ARCHITECTURE.md §5.3, CONVENTIONS.md §2): everything in this document is the **Queue**
module — `App\Domain\Queue\{Events,Listeners,Services,Actions,Jobs}`, `App\Domain\Queue\{TenantChannel,ChannelGuards}`
(there is no `Realtime` module or namespace); routes `routes/site/queue.php` (`site.queue.*`, the public pages
and the LOCKED polling endpoint) and `routes/panel/queue.php` (`panel.queue.*`, the doctor screen) — the
call-next/delay JSON endpoints are the Serials module's `routes/api/serials.php` (SERIAL_ENGINE.md §16);
controllers `App\Http\Controllers\{Site,Panel}\Queue\*`; pages `resources/js/site/Pages/{Queue,Display}/**`,
`resources/js/panel/Pages/Queue/**`; client library `resources/js/shared/realtime/**`. Sample ids such as
`ses_01J…` are abbreviations — real public ids are bare 26-character ULIDs (CONVENTIONS.md §15).

Verified library surface (do not use anything else without checking `vendor/`
and `node_modules/`): `laravel/reverb ^1.11` (`config/reverb.php`, `apps.apps[]`,
`allowed_origins`, `ping_interval`, `activity_timeout`, `scaling.enabled` via
Redis); `Illuminate\Broadcasting` — `ShouldBroadcast`, `ShouldBroadcastNow`,
`ShouldRescue`, `InteractsWithSockets` (`dontBroadcastToCurrentUser()`),
`Channel`, `PrivateChannel`, `broadcastOn()`, `broadcastAs()`,
`broadcastWith()`, public property `$broadcastQueue`, `afterCommit`,
`Broadcast::channel($name, $callback, ['guards' => [...]])`,
`Broadcast::on($channels)->as()->with()->send()` (`AnonymousEvent`);
`laravel-echo ^2.4` — `new Echo({broadcaster:'reverb', key, wsHost, wsPort,
wssPort, forceTLS, enabledTransports, authEndpoint, bearerToken, Pusher})`,
`echo.channel()`, `echo.private()`, `.listen('.name', cb)`, `.subscribed(cb)`,
`.error(cb)`, `echo.leave()`, `echo.connectionStatus()` →
`connected|connecting|disconnected|failed|reconnecting`,
`echo.connector.onConnectionChange(cb)` (returns an unbind function);
`pusher-js ^8.6` options `activityTimeout`, `pongTimeout`, `unavailableTimeout`.

---

## 1. Topology

```
Octane workers ──(HTTP POST /apps/{id}/events, Reverb Pusher-protocol API)──▶ Reverb server ──WS──▶ browsers
      │                                                                                 ▲
      └── Redis (Dragonfly): QueueState snapshots + version; Reverb scaling pub/sub ─────┘ (multi-node only)
```

- One Reverb application (key/secret) for the whole SaaS. Tenant isolation is by channel name prefix + private-channel authorisation (§2). `allowed_origins` stays `['*']` because tenants bring custom domains; private channels are protected by `/broadcasting/auth`, public channels carry no PII (§3).
- Broadcast connection `reverb` in `config/broadcasting.php`. Queued broadcasts go to Horizon queue `critical` (`supervisor-critical`, `maxProcesses` 4, `timeout` 30 — ARCHITECTURE.md §4.7). Latency-critical events implement `ShouldBroadcastNow` (§3.2).
- `REVERB_APP_PING_INTERVAL=30`, `REVERB_APP_ACTIVITY_TIMEOUT=30` so a dead phone connection is detected within ~60 s server-side; client-side `activityTimeout: 30000, pongTimeout: 10000, unavailableTimeout: 5000`.
- `REVERB_SCALING_ENABLED=true` in production (multi-node); Dragonfly speaks the Redis protocol Reverb needs.

---

## 2. Channel design

`tenantId` below is the tenant's **public id** (`tenants.public_id`, a 26-char ULID — never the integer PK). `sessionInstancePublicId` = `session_instances.public_id`; `branchPublicId` = `branches.public_id`; `doctorPublicId` = `doctors.public_id` (all ULIDs via `HasPublicId`).

| Channel | Kind | Name | Subscribers | Auth |
|---|---|---|---|---|
| Queue | public | `tenant.{tenantId}.queue.{sessionInstancePublicId}` | public queue page, token-slip QR visitors, display tiles | none |
| Reception board | private | `tenant.{tenantId}.reception.{branchPublicId}` | desk PWA | staff user of that branch (`web` or `sanctum`) or a reception device of that branch (`device`) |
| Doctor screen | private | `tenant.{tenantId}.doctor.{doctorPublicId}` | the doctor's panel | the doctor themself, or staff with permission `queue.call-next` who hold no `doctors` row — and never a user `DoctorScope` restricts, assigned to that doctor or not |
| Waiting-room display | private | `tenant.{tenantId}.display.{branchPublicId}` | TV pages | a `reception_devices` row with `kind = display` at that branch (Sanctum device token in `bearerToken`), or a staff user of that branch (`BranchAccess`) whom `DoctorScope` does not restrict — the board preview of §9 |
| Prescription writer | private | `tenant.{tenantId}.prescription.{prescriptionPublicId}` | the writer tab (PRESCRIPTION.md §7.5 `PdfReady`) | staff user allowed to view that prescription (`PrescriptionPolicy::view`); guard callback `App\Domain\Prescription\Services\PrescriptionChannelGuard` — owned by P, listed here so `routes/channels.php` has one inventory |

Presence channels are not used (no member lists needed; presence would leak
who is watching). Encrypted private channels are not used (payloads carry
first names only on private channels; TLS is mandatory anyway).

`routes/channels.php` (foundation-owned — Q and P request these lines, CONVENTIONS.md §2.1):

```php
use Illuminate\Support\Facades\Broadcast;
use App\Domain\Queue\ChannelGuards;
use App\Domain\Prescription\Services\PrescriptionChannelGuard;

Broadcast::channel('tenant.{tenant}.reception.{branch}', [ChannelGuards::class, 'reception'], ['guards' => ['web', 'sanctum', 'device']]);
Broadcast::channel('tenant.{tenant}.doctor.{doctor}',    [ChannelGuards::class, 'doctor'],    ['guards' => ['web', 'sanctum']]);
Broadcast::channel('tenant.{tenant}.display.{branch}',   [ChannelGuards::class, 'display'],   ['guards' => ['device', 'web']]);
Broadcast::channel('tenant.{tenant}.prescription.{prescription}', [PrescriptionChannelGuard::class, 'view'], ['guards' => ['web']]);
```

```php
namespace App\Domain\Queue;

final class ChannelGuards
{
    public function reception(User|ReceptionDevice $auth, string $tenant, string $branch): bool;
    public function doctor(User $auth, string $tenant, string $doctor): bool;
    public function display(User|ReceptionDevice $auth, string $tenant, string $branch): bool;
    // each: assert $tenant === current tenant public id (the tenancy middleware already ran on /broadcasting/auth),
    // then the role/branch/device-kind rule above. Returning false => 403 => pusher:subscription_error on the client.
}
```

`Broadcast::routes(['middleware' => ['web', 'tenant']])` and
`Broadcast::routes(['middleware' => ['auth:device', 'tenant'], 'prefix' => 'api/device'])`
(second registration gives devices an auth endpoint without a session; the
display/desk Echo instance uses `authEndpoint: '/api/device/broadcasting/auth'`
with `bearerToken`).

Channel helper (server): `App\Domain\Queue\TenantChannel::queue(SessionInstance $s)`,
`::reception(Branch $b)`, `::doctor(Doctor $d)`, `::display(Branch $b)`, `::prescription(Prescription $p)` return
`Channel`/`PrivateChannel` instances with the tenant prefix — events never
build names by hand. ARCHITECTURE.md §4.8 lists the same five channels; the
queue channel is keyed by **session instance** (a doctor has several sessions a day)
and every segment is a public id, never an integer id.

---

## 3. Event catalogue

All events live in `App\Domain\Queue\Events`, are constructed by listeners
on the domain events of SERIAL_ENGINE.md §15 (never dispatched from
controllers), carry `broadcastAs()` short names (clients listen with a leading
dot: `.serial.called`), and put `version` (§4) in every payload so clients can
discard stale messages. Payloads are arrays built in `broadcastWith()` — no
Eloquent models are serialised. Five of these classes (`SerialCalled`,
`SerialStatusChanged`, `SessionDelayed`, `SessionCancelled`, `DoctorArrived`) share their
short name with the `App\Domain\Serials\Events\*` domain event they mirror — they are
**different classes** (the Serials one is `ShouldDispatchAfterCommit` and never broadcasts;
this one is the wire event). Always import with the FQCN; the listener that bridges them is
`App\Domain\Queue\Listeners\BroadcastSerialCalled` etc. (ARCHITECTURE.md §5.4).

### 3.1 Payloads

`serial.called` — **`SerialCalled`** (`ShouldBroadcastNow`, `ShouldRescue`) on queue + reception + doctor + display:

```json
{ "v": 1, "session": "ses_01J…", "version": 418,
  "serial": { "id": "ser_01J…", "code": "A-042", "n": 42, "pos": 42000000 },
  "now_serving": "A-042", "previous": "A-041", "called_at": "2026-09-06T10:44:12+06:00",
  "room": "Room 3" }
```

The **doctor and display channels only** additionally receive
`"patient": { "first_name": "Rahima", "age": 54, "sex": "f" }` — implemented as
**two** event classes sharing a base: `SerialCalled` (public payload, public
channel) and `SerialCalledPrivate`, which `QueueBroadcaster::serialCalled()`
dispatches twice — the card to `doctor` + `display`
(`QueueBroadcaster::chamberChannels()`), and the bare public payload to
`reception`. The public queue channel never carries names.

**The reception channel gets no patient card**, and this used to say it did.
The desk channel is one branch-wide channel every active staff user of the
branch holds: with the card on it, a compounder assigned to Dr A — scoped to
Dr A by `DoctorScope` in every board, list and policy — received a live
patient-by-patient feed of every other chamber at the branch over the socket,
with no HTTP request and no policy in the path. A channel cannot be narrowed
per subscriber (one payload, many listeners), so the card is not sent: the
desk re-fetches its board on `serial.called`, and those rows are doctor-scoped.
Nothing on the desk ever read the block (`useDesk.ts`, `Queue/Today.tsx`).

`serial.status_changed` — **`SerialStatusChanged`** (queued) on queue + reception:

```json
{ "v": 1, "session": "ses_…", "version": 419, "serial": { "id": "ser_…", "code": "A-031" },
  "from": "booked", "to": "checked_in", "at": "…", "counts": { "booked": 12, "checked_in": 6, "in_consultation": 1, "completed": 20, "no_show": 2, "cancelled": 1, "postponed": 0 } }
```

`queue.state` — **`QueueStateUpdated`** (queued, coalesced §4.4) on queue + display: `{ "state": <QueueState> }` (§4.1).

`session.delayed` — **`SessionDelayed`** (`ShouldBroadcastNow`) on queue + reception + display:

```json
{ "v": 1, "session": "ses_…", "version": 420, "delay_minutes": 40,
  "expected_start_at": "2026-09-06T17:40:00+06:00", "message": "Doctor is in surgery", "message_bn": "ডাক্তার অপারেশনে আছেন" }
```

`session.cancelled` — **`SessionCancelled`** (`ShouldBroadcastNow`) on queue + reception + display: `{ "session", "version", "reason", "message", "alternatives": [{ "code": "B", "date": "…", "public_id": "…" }] }`.

`doctor.arrived` — **`DoctorArrived`** (queued) on queue + reception + display: `{ "session", "version", "actual_start_at" }`.

`board.updated` — **`BoardUpdated`** (queued, coalesced per branch, ≤ 1/s) on reception:

```json
{ "v": 1, "branch": "brn_…", "at": "…",
  "sessions": [ { "id": "ses_…", "doctor": "doc_…", "code": "A", "status": "running", "now_serving": "A-042",
                  "counts": { "booked": 12, "checked_in": 6, "in_consultation": 1, "completed": 20, "no_show": 2, "cancelled": 1, "postponed": 0 },
                  "remaining": { "online": 3, "counter": 4, "released": 0, "buffer": 2 }, "delay_minutes": 0, "version": 418 } ] }
```

`call.next` — **`CallNext`** (`ShouldBroadcastNow`) on doctor + display only (the "push to the doctor's screen and the waiting room" of brief F):

```json
{ "v": 1, "session": "ses_…", "doctor": "doc_…", "serial": { "id": "ser_…", "code": "A-042" },
  "patient": { "first_name": "Rahima", "age": 54, "sex": "f", "vitals_taken": true }, "room": "Room 3",
  "speak": { "bn": "সিরিয়াল এ বিয়াল্লিশ, রুম তিন", "en": "Serial A forty-two, room three" }, "version": 418 }
```

### 3.2 Broadcasting classes — shape

```php
namespace App\Domain\Queue\Events;

final class SerialCalled implements ShouldBroadcastNow, ShouldRescue
{
    use InteractsWithSockets;
    public function __construct(public readonly array $payload, public readonly array $channels) {}
    public function broadcastOn(): array   { return $this->channels; }              // Channel|PrivateChannel[]
    public function broadcastAs(): string  { return 'serial.called'; }
    public function broadcastWith(): array { return $this->payload; }
}

final class QueueStateUpdated implements ShouldBroadcast
{
    public string $broadcastQueue = 'critical';
    public bool $afterCommit = true;
    // ... broadcastAs 'queue.state', broadcastWith ['state' => $this->state]
}
```

`ShouldRescue` on the *Now* events: if Reverb is unreachable the HTTP request
that called next must still succeed (the poll fallback carries the state);
the failure is logged and Pulse/alerting notices.

---

## 4. The QueueState document

### 4.1 Type (client) / shape (server)

`resources/js/shared/realtime/types.ts` — the same JSON is the WebSocket payload
(`queue.state`) and the polling response body.

```ts
export type SerialShort = 'b' | 'c' | 'i';      // booked | checked_in | in_consultation (terminal serials are not listed)
export interface QueueSerial { id: string; c: string; n: number; p: number; s: SerialShort; pr?: 'e' | 'v' | 'el'; eta: string | null; ahead: number }
export interface QueueState {
  v: 1;
  session: { id: string; code: string; date: string; status: 'scheduled' | 'running' | 'paused' | 'closed' | 'cancelled'; mode: 'serial' | 'slot';
             planned_start_at: string; expected_start_at: string; delay_minutes: number;
             doctor: { id: string; slug: string; name: string; name_bn: string | null; room: string | null };
             branch: { id: string; name: string } };
  now_serving: { id: string; c: string; n: number; called_at: string } | null;
  last_called: Array<{ c: string; called_at: string }>;                  // up to 3, most recent first
  counts: { booked: number; checked_in: number; in_consultation: number; completed: number; no_show: number; cancelled: number; postponed: number; waiting: number };
  avg_consult_seconds: number; eta_confidence: 'low' | 'normal';
  serials: QueueSerial[];                                                 // ordered by p asc; ≤ max_serials entries, short keys — ≈ 60 B each
  updated_at: string; version: number;
}
```

Budget: 100 active serials ≈ 7 KB raw, ≈ 1.5 KB gzip. Reverb
`max_message_size` default 10 000 bytes — the builder trims `serials` to the
`now_serving` window ±80 when a session exceeds 120 active serials and sets
`"truncated": true` (rare; large camps).

### 4.2 Builder

```php
namespace App\Domain\Queue\Services;

final class QueueStateBuilder
{
    public function __construct(private EtaCalculator $eta) {}
    /** One query for the session, one for active serials ordered by position, one for last_called. */
    public function build(SessionInstance $session, int $version, CarbonImmutable $now): array;
}

final class QueueStateRepository
{
    public const TTL_MIN = 7200;                                  // 2 h after planned end, at least 2 h
    /** Redis keys are internal: {tenantId} is the bigint Tenancy::id(), the session part is the public id (CONVENTIONS.md §15). */
    public function key(int $tenantId, string $sessionPublicId): string        { return "t:{$tenantId}:qs:{$sessionPublicId}"; }
    public function versionKey(int $tenantId, string $sessionPublicId): string { return $this->key($tenantId, $sessionPublicId).':v'; }

    /** Cheap read path used by the poll endpoint: version only (1 GET). */
    public function version(SessionInstance $s): ?int;
    /** Snapshot JSON string or null (1 GET). */
    public function snapshot(SessionInstance $s): ?string;
    /** Rebuild under a short lock; returns the new state. Called by the InvalidateQueueState listener. */
    public function rebuild(SessionInstance $s): array;
}
```

`rebuild()` — the version is `session_instances.version`, bumped inside the
mutating transaction by every counted transition / reorder / session change
(SERIAL_ENGINE.md §6.4), so it is monotonic, transactional and survives a Redis
flush; Redis only mirrors it for the cheap 304 path:

```php
return Cache::lock("qs-build:{$s->public_id}", 5)->block(3, function () use ($s) {
    $s     = $s->fresh();                                                       // read the committed version
    $state = $this->builder->build($s, $s->version, now());
    $ttl   = max(self::TTL_MIN, $s->planned_end_at->addHours(2)->diffInSeconds(now()));
    $t = Tenancy::id();
    Redis::set($this->key($t, $s->public_id), json_encode($state, JSON_UNESCAPED_UNICODE), 'EX', $ttl);
    Redis::set($this->versionKey($t, $s->public_id), $s->version, 'EX', $ttl);
    return $state;
});
```

The lock serialises concurrent rebuilds so a snapshot for version 419 can never
be overwritten by a slower writer that read 418 (`rebuild()` re-reads the row
under the lock and skips the write if Redis already holds a higher version).
Cold cache (TTL expired, Redis flushed): `snapshot()` returns null → the
endpoint calls `rebuild()` inline (≈ 5 ms) — no thundering herd because of the
lock. Redis key names here (`t:{tenantId}:qs:{sessionInstancePublicId}`, `…:v`) are the canonical
ones (ARCHITECTURE.md §9.3, CONVENTIONS.md §15, SCHEMA.md §5.7); `QueueStateRepository` is the only accessor
(SCHEMA.md §3.3: `queue_snapshots` is not a table).

### 4.3 Invalidation — every serial event rebuilds

Listener `App\Domain\Queue\Listeners\InvalidateQueueState` is registered
(synchronously, after commit) for: `SerialAllocated`, `SerialStatusChanged`,
`SerialCalled`, `SerialCompleted`, `SerialCancelled`, `SerialNoShow`,
`SerialReinstated`, `SerialPostponed`, `SerialTransferred` (both sessions),
`SerialReordered`, `SerialPriorityInserted`, `SessionCapacityExtended`,
`SessionDelayed`, `SessionCancelled`, `SessionClosed`, `DoctorArrived`,
`SessionPaused`, `SessionResumed`. It calls `rebuild()` and then dispatches
`QueueStateUpdated` and `BoardUpdated` (queued). Rebuilding synchronously (not
in a job) is deliberate: the poll endpoint must be correct the instant the
mutating request returns, and the cost is one indexed query.

`queue:refresh-eta` (`App\Console\Commands\Queue\RefreshEtaCommand`, run every minute through
`tenants:run` from `App\Domain\Queue\Schedule` — ARCHITECTURE.md §4.7) rebuilds every `running` session
with `now_serving` older than 60 s so `eta` values keep moving even when no
event fires; it bumps `version` via `CountsRecalculator::bumpVersion()` first so
ETag-based clients fetch the new ETAs.

### 4.4 Coalescing pushes

`QueueStateUpdated` and `BoardUpdated` jobs implement
`Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing` with
`uniqueId = "qs:{$sessionPublicId}"` / `"board:{$branchPublicId}"` and
`uniqueFor = 1`. A burst of ten check-ins queues at most one pending push
(plus one in flight); the pushed payload is always read from Redis at send
time, so the last push carries the newest version. `serial.called` is never
coalesced (each call is significant).

---

## 5. Polling endpoint and URL scheme

### 5.1 Routes (site surface, no auth, tenant resolved by the global `ResolveTenant` middleware)

All in `routes/site/queue.php` (globbed by `bootstrap/app.php` into the `site.` group — there is no
`routes/web.php` and no per-route `Route::domain()` for the tenant host; the host → tenant mapping is
ARCHITECTURE.md §4.2, and the `queue.` vanity label is stripped by `TenantResolver` before lookup):

```php
// routes/site/queue.php — names get the `site.` prefix from the group
use App\Http\Controllers\Site\Queue\{QueuePageController, ResolveLocalSerialController, DisplayPageController, QueueStateController, QueueSessionsController};

Route::domain('queue.{tenantHost}')->where(['tenantHost' => '.+'])->group(function () {   // queue.hospital.com/dr-rahman/today (BRIEF E) — same controller, shorter link for SMS/QR
    Route::get('/{doctorSlug}/today', QueuePageController::class)->name('queue.vanity');
});
Route::get('/q/{doctorSlug}/today', QueuePageController::class)->name('queue.page');
Route::get('/q/resolve/{localId}', ResolveLocalSerialController::class)->name('queue.resolve');      // OFFLINE.md §10
Route::get('/display/{branchSlug}', DisplayPageController::class)->name('queue.display');
Route::get('/queue/{doctorSlug}/state', QueueStateController::class)->middleware('throttle:queue-state')->name('queue.state');   // LOCKED path
Route::get('/queue/{doctorSlug}/sessions', QueueSessionsController::class)->name('queue.sessions');                              // list today's instances
```

The vanity group is declared first in the file so it wins over any later unconstrained site route.
`throttle:queue-state` = 30 requests/min per IP+session (a 5 s poll uses 12), registered by
`QueueServiceProvider`. Route names: `site.queue.page`, `site.queue.vanity`, `site.queue.resolve`,
`site.queue.display`, `site.queue.state`, `site.queue.sessions`.

### 5.2 `GET /queue/{doctorSlug}/state?session={sessionPublicId}`

- Without `session`: the doctor's *current* instance today — `running` if any, else the next `scheduled`, else the last `closed` (so a late visitor sees "session ended"). Response header `X-Queue-Session: ses_…` tells the client which one it got; the client then pins `?session=` so ETag versions compare within one sequence.
- `If-None-Match` handling (Symfony `Response::setEtag()` / `isNotModified()`):

```php
final class QueueStateController
{
    public function __invoke(Request $r, string $doctorSlug, QueueStateRepository $repo, SessionResolver $resolver): Response
    {
        $session = $resolver->resolve($doctorSlug, $r->query('session'));          // 404 if unknown/other tenant
        $version = $repo->version($session);                                        // 1 Redis GET
        if ($version !== null && $r->headers->get('If-None-Match') === '"'.$version.'"') {
            return response('', 304, ['ETag' => '"'.$version.'"', 'Cache-Control' => 'no-cache, private', 'X-Queue-Session' => $session->public_id]);
        }
        $json = $repo->snapshot($session) ?? json_encode($repo->rebuild($session), JSON_UNESCAPED_UNICODE);
        $version = json_decode($json, false)->version;                              // trust the snapshot's own version
        return response($json, 200, ['Content-Type' => 'application/json; charset=utf-8', 'ETag' => '"'.$version.'"',
                                     'Cache-Control' => 'no-cache, private', 'Vary' => 'Accept-Encoding', 'X-Queue-Session' => $session->public_id]);
    }
}
```

The ETag **is** the version (strong, quoted). `no-cache` forces revalidation on
every fetch while allowing the browser to keep the body for the 304 case; no
CDN caching (per-tenant custom domains, sub-second freshness). Cost: 304 path =
1 Redis GET + no JSON work; 200 path = 1 Redis GET (string passthrough, no
decode except the version read — replace with a second `GET :v` if profiling
shows decode cost).

### 5.3 Page URL and identity of "your serial"

`https://{tenant-domain}/q/dr-rahman/today?s=ser_01J…` — `s` is the serial's
public id (from the booking confirmation, SMS, or the slip's QR). The page
highlights that serial, shows "patients ahead" (`ahead`) and its `eta`. A
`local:` id (slip printed offline) is resolved by `/q/resolve/{localId}` every
poll until it maps. Without `s` the page shows the board only, with a "Enter
your serial (A-042)" field that pins by code (no PII is exposed by knowing a
code — the page shows only codes and statuses).

---

## 6. Client algorithm — WebSocket first, polling fallback, version dedupe

Lives in the **shared** store so the desk PWA and the site page use one
implementation: `resources/js/shared/realtime/liveQueue.ts` reads
`useConnection` (OFFLINE.md §3) and never decides connectivity itself.
`subscribeQueue()` below is the primitive; React code uses
`resources/js/shared/realtime/useQueueState.ts` (a hook that owns one handle per mounted
component) and `resources/js/shared/realtime/useChannel.ts` for the private channels
(reception/doctor/display/prescription) — CONVENTIONS.md §7.2.

```ts
// resources/js/shared/realtime/liveQueue.ts
import { useConnection } from '../connection/store';
import type { QueueState } from './types';

export const POLL_INTERVAL_MS = 5_000;
export const POLL_HIDDEN_INTERVAL_MS = 30_000;
export const WS_SILENCE_GUARD_MS = 90_000;     // connected but silent while running → one sanity poll

export interface LiveQueueHandle { getState(): QueueState | null; stop(): void }

export function subscribeQueue(opts: {
  tenantId: string; doctorSlug: string; sessionId: string | null;             // null → let the server pick, then pin
  echo: () => Promise<Echo<'reverb'> | null>;                                 // lazy: the site page loads Echo after first paint
  onState(s: QueueState): void; onEvent?(name: string, payload: unknown): void;
}): LiveQueueHandle {
  let current: QueueState | null = null, etag: string | null = null, sessionId = opts.sessionId;
  let pollTimer: number | undefined, guardTimer: number | undefined, stopped = false, channel: any = null;

  const apply = (next: QueueState, source: 'ws' | 'poll') => {
    if (current && next.version <= current.version) return;                  // DEDUPE: an older WS frame never overwrites a newer poll (and vice versa)
    current = next; etag = `"${next.version}"`; opts.onState(next); armGuard();
  };

  const poll = async () => {
    if (stopped || useConnection.getState().mode === 'offline') return;       // offline: nothing to poll; the banner explains
    try {
      const url = `/queue/${opts.doctorSlug}/state` + (sessionId ? `?session=${sessionId}` : '');
      const res = await fetch(url, { headers: etag ? { 'If-None-Match': etag } : {}, cache: 'no-store' });
      const sid = res.headers.get('X-Queue-Session'); if (sid && !sessionId) { sessionId = sid; await ensureChannel(); }
      if (res.status === 200) apply(await res.json(), 'poll');
      // 304 → nothing to do; a failure is reported to the connection store by the axios/fetch interceptor
    } catch { /* interceptor already called heartbeatResult(false)-path via an immediate heartbeat */ }
  };

  const schedulePoll = () => {
    clearTimeout(pollTimer);
    const { mode, tabVisible } = useConnection.getState();
    if (mode === 'online' || mode === 'offline') return;                       // online: WS is authoritative; offline: idle
    pollTimer = window.setTimeout(async () => { await poll(); schedulePoll(); }, tabVisible ? POLL_INTERVAL_MS : POLL_HIDDEN_INTERVAL_MS);
  };

  const armGuard = () => {                                                      // silent-WS guard: connected but no frame for 90 s while running
    clearTimeout(guardTimer);
    if (current?.session.status !== 'running') return;
    guardTimer = window.setTimeout(() => { if (useConnection.getState().mode === 'online') void poll(); armGuard(); }, WS_SILENCE_GUARD_MS);
  };

  const ensureChannel = async () => {
    if (channel || !sessionId) return;
    const echo = await opts.echo(); if (!echo || stopped) return;
    channel = echo.channel(`tenant.${opts.tenantId}.queue.${sessionId}`)
      .listen('.queue.state', (e: { state: QueueState }) => apply(e.state, 'ws'))
      .listen('.serial.called', (e: any) => { opts.onEvent?.('serial.called', e); if (current && e.version > current.version) void poll(); }) // small event → fetch the full state once
      .listen('.session.delayed', (e: any) => { opts.onEvent?.('session.delayed', e); void poll(); })
      .listen('.session.cancelled', (e: any) => { opts.onEvent?.('session.cancelled', e); void poll(); })
      .listen('.doctor.arrived', () => void poll())
      .error(() => useConnection.getState().setWsState('failed'));
  };

  // mode transitions drive everything
  const unsub = useConnection.subscribe(s => s.mode, (mode, prev) => {
    if (mode === 'online')   { void poll(); clearTimeout(pollTimer); }         // CATCH-UP: one immediate poll on (re)connect, then WS only
    if (mode === 'degraded') { void poll(); schedulePoll(); }                  // start 5 s polling
    if (mode === 'offline')  { clearTimeout(pollTimer); }
  });
  const unsubVis = useConnection.subscribe(s => s.tabVisible, v => { if (v) void poll(); schedulePoll(); });

  void poll().then(ensureChannel); schedulePoll();
  return { getState: () => current, stop: () => { stopped = true; unsub(); unsubVis(); clearTimeout(pollTimer); clearTimeout(guardTimer); channel && opts.echo().then(e => e?.leave(`tenant.${opts.tenantId}.queue.${sessionId}`)); } };
}
```

Rules restated:

1. **5 s interval** in `degraded`; **30 s** when the tab is hidden (`visibilitychange`), immediate poll on becoming visible.
2. **ETag/If-None-Match** on every poll; 304 costs one Redis GET server-side and no parsing client-side.
3. **Resume WebSocket**: nothing to do — Echo/pusher-js reconnects on its own; the store's `degraded → online` transition (WS up 2 s) stops the timer after **one catch-up poll**, which closes the gap of frames missed while disconnected.
4. **Dedupe by version**: `apply()` ignores anything with `version <= current.version`, whichever transport delivered it.
5. **Offline**: no polling, no WS; the page shows the last state with "Not connected — showing the queue as of 10:42".
6. Small events (`serial.called`) trigger a full-state poll rather than being patched locally, so the client has one code path for state and the payloads stay minimal.

The Echo instance itself is created once per app in
`resources/js/shared/realtime/echo.ts` (`createEcho({key, wsHost, wsPort, wssPort, forceTLS, enabledTransports: ['ws','wss'], authEndpoint, bearerToken?, Pusher, activityTimeout: 30000, pongTimeout: 10000, unavailableTimeout: 5000})`)
and immediately bridged into the store with `echo.connector.onConnectionChange(...)`.
The site page imports it with `import()` after first paint (§8).

---

## 7. "3 ahead" notification trigger

Server-side, on every `SerialCalled` (queued listener
`App\Domain\Queue\Listeners\NotifyApproachingSerials`, queue `notifications`):

```sql
-- distance = number of *waiting* serials strictly between now_serving and X, counting X itself as ahead=0..; notify when X is the 4th in line (3 ahead)
WITH waiting AS (
  SELECT id, position, status, t3_notified_at,
         ROW_NUMBER() OVER (ORDER BY position) - 1 AS ahead
  FROM serials
  WHERE session_instance_id = :sid AND status IN ('booked','checked_in') AND position > :now_serving_position
)
UPDATE serials s SET t3_notified_at = now()
FROM waiting w
WHERE s.id = w.id AND w.ahead <= 3 AND s.t3_notified_at IS NULL
RETURNING s.id, w.ahead;
```

For each returned row the listener dispatches `App\Domain\Queue\Events\SerialApproaching(serial, ahead)`
(a Queue event — listed in SERIAL_ENGINE.md §15 for completeness and in ARCHITECTURE.md §5.4); the notifications module renders "3 patients ahead of
you, please reach the chamber" (`ahead` may be < 3 when several no-shows
collapse the line — the text says the actual count). Dedupe is the
`t3_notified_at IS NULL` predicate inside one `UPDATE … RETURNING`, so
concurrent calls, reorders and reinstates can never notify a patient twice.
Reinstate/priority-insert does not reset the stamp; if a patient is moved back
they simply are not notified again (stated in the SMS: "your position may
change").

Tenant setting `queue.notify_ahead` (default 3) replaces the literal.

---

## 8. Public queue page — rendering budget

Route `site.queue.page` (and `site.queue.vanity`), Inertia page `resources/js/site/Pages/Queue/Today.tsx` (`Inertia::render('Queue/Today')`),
**no MUI, no emotion, no icon fonts, no date library** (`Intl.DateTimeFormat`
with `bn-BD`/`en-BD`).

| Budget | Target | How |
|---|---|---|
| First paint | ≤ 1.5 s on a 2018 Android WebView over 3G (400 kbps, 400 ms RTT) | the initial `QueueState` is embedded in the Inertia props (server reads Redis once), so the first render needs no XHR; SSR optional |
| JS for this route | ≤ 95 KB gzip **total** including React 19 + react-dom (≈ 45 KB) and the Inertia client; page code ≤ 12 KB | separate Vite input `resources/js/site/app.tsx`; `laravel-echo` + `pusher-js` (≈ 25 KB gzip) in a lazy chunk loaded after first paint and only when `navigator.onLine` |
| CSS | ≤ 8 KB gzip | Tailwind v4 utilities scoped to the site app; no component library |
| Fonts | system UI + `Noto Sans Bengali` subset ≤ 70 KB woff2, `font-display: swap` | numbers render in the system font before the Bangla font arrives |
| Payload per update | ≤ 2 KB gzip (`QueueState`) | short keys (§4.1) |
| Memory | ≤ 40 MB | no virtualised lists needed under 200 rows |
| Compatibility | Chrome/WebView ≥ 80, Android 8+ | Vite `build.target: 'es2019'` for the site app; no top-level `await`, no `?.` on old targets (transpiled) |

Layout (portrait first): huge "Now serving `A-042`", then "Your serial `A-057`
· 12 ahead · ~11:25", then the compact list. No polling or WS is started while
`document.visibilityState === 'hidden'` beyond the 30 s rule; the page never
autoplays audio; a `Wake Lock` is requested only when the visitor taps "Keep
screen on".

---

## 9. Waiting-room display mode

Route `site.queue.display` (`/display/{branchSlug}?token=…&kiosk=1`), site app,
`resources/js/site/Pages/Display/Board.tsx`. The TV/Android box opens the URL
once; `token` is a display-device Sanctum token (OFFLINE.md §2, `kind = display`)
exchanged on load for the Echo `bearerToken`; the URL itself is not the secret
(tokens rotate, the page stores it in `sessionStorage`).

### 9.1 Layout

- Grid of tiles, one per running/scheduled session at the branch (2 × 2 up to 3 × 3; > 9 doctors paginate every 12 s). Each tile: doctor name (BN large, EN small), room, **now serving** in ≥ 160 px digits (`clamp(96px, 12vw, 220px)`), "next: A-043 A-044 A-045", counts "waiting 6 · done 20", delay badge in amber ("40 min late"), paused/closed states in grey.
- Contrast ≥ 7:1, dark background (`#0b1220`) with white digits; no animation except a 2 s highlight flash on `call.next`.
- Data: subscribes to `tenant.{t}.display.{branch}` for `call.next`, `session.delayed`, `session.cancelled`, `doctor.arrived`, and to each session's public `queue.state`; polling fallback per session via §6 (`degraded` mode shows a small amber dot in the corner, never a banner over the numbers).

### 9.2 Voice call-out

`resources/js/site/Pages/Display/voice.ts` (Q-owned, co-located with the page):

1. **Web Speech API** first: `speechSynthesis.getVoices()`; pick a voice with `lang` starting `bn` for the Bangla line and `en` for the English line; `SpeechSynthesisUtterance(text)`, `rate 0.9`, queue the two utterances back-to-back; the server sends ready-made strings in `call.next.speak` (numbers spelled in Bangla words: "বিয়াল্লিশ") so the client never needs a number-to-words library.
2. **Fallback** (no `bn` voice — most Android TV boxes): pre-recorded MP3 clips under `/audio/{bn,en}/`: `chime`, `serial`, letters `A–F`, digits `0–9`, `hundred`, `room`, words `1–20`. Play sequence for `A-042`: bn: `chime, serial, A, 4, 2, room, 3` (digit-by-digit reading is how numbers are called in BD halls and avoids recording every number). Clips are precached by the display's service worker (site app gets a minimal SW for `/display/*` only: precache audio + shell, everything else `NetworkOnly`).
3. Autoplay policy: first load shows "Tap anywhere to enable sound"; the tap creates the `AudioContext`, plays a silent buffer, stores `sound=on` in `sessionStorage`; kiosk relaunches re-prompt (unavoidable).
4. Each `call.next` is spoken once (dedupe by `serial.id + called_at`); a queue of announcements plays sequentially with a 1 s gap; if more than 3 are pending, older ones are dropped and only the latest per doctor is spoken.

### 9.3 Kiosk-mode auto-refresh and self-healing

- `?kiosk=1`: request fullscreen on the enabling tap, hide the cursor, disable text selection, `Wake Lock`.
- Full reload every 6 h at the quietest moment (no `call.next` in the last 60 s), and immediately if no state update or successful poll has been observed for 3 minutes while any session is `running`, or on an uncaught error (window `error`/`unhandledrejection` → reload after 5 s, max once per minute).
- The page re-fetches `/queue/{doctor}/sessions` for every doctor every 10 min so newly materialised sessions appear without a reload.

---

## 10. Delay broadcast — one tap

`POST /api/sessions/{session}/delay { "delay_minutes": 40, "message": "Doctor in surgery" }`
(Doctor, Reception, Hospital Admin; permission `queue.delay.broadcast`; SERIAL_ENGINE.md §16) →
`App\Domain\Serials\Actions\DelaySession`: `session_instances.delay_minutes = 40` (absolute, not additive; the
desk UI offers +15/+30/+45/custom), `serial_events` type `delayed`, emits
`App\Domain\Serials\Events\SessionDelayed` (domain, after commit) →

1. `App\Domain\Queue\Listeners\InvalidateQueueState` rebuilds (ETAs shift by the delay, `expected_start_at` updated),
2. `App\Domain\Queue\Events\SessionDelayed` (`ShouldBroadcastNow`, wire name `session.delayed`) on queue + reception + display,
3. `App\Domain\Notifications\Listeners\FanOutSessionDelay` (queued, queue `notifications`) → one notification per serial in `booked|checked_in` through the notifications module (SMS/push template `doctor_delayed` with `{delay_minutes, expected_start_time, doctor}`), deduped per (serial, delay bucket) on `notifications` — `dedupe_key = doctor_delayed:{serial_id}:{bucket}` plus the index `notifications (serial_id, event_key, created_at)` (SCHEMA.md §3.6), bucket width = the `queue.delay_notify_min_change` tenant setting (default 10 min) — so tapping +15 twice within a minute sends once.

Clearing the delay (`delay_minutes: 0`) broadcasts `session.delayed` with 0 and sends no notifications.

---

## 11. Doctor screen — "Today's session"

The doctor's whole day on one page. The sidebar entry that opens it is **Today's session / আজকের সেশন**
(`PanelLayout` NAV, `doctor: true` — a user with a `doctors` row gets it *instead of* the generic "Live queue",
which is the branch overview everyone else lands on; `panel.queue.index` would have redirected them here anyway,
so the drawer never shows two entries for one route family). Its badge is the checked-in count, seeded on every
navigation by the `today_session` shared prop (`HandleInertiaRequests::todaySession` — one query over
`session_instances` for the doctor's current instance today, `SessionResolver::pick`) and kept live, while the
page itself is open, from the queue state it already subscribes to (`useTodaySessionBadge`). No second poller.

The page carries three things: the **session header** (code, planned hours, room, status and the counts
booked / checked-in / in-consultation / completed / no-show / remaining, all read off `QueueState.counts`), the
**now-serving** panel, and the **roster** — every serial of the session in serial-number order (the desk board's
order), built by `App\Domain\Queue\Services\SessionRosterBuilder` in five queries whatever the session size:
patient name, sex, age, patient code, the compounder's vitals (°C on the wire, °F on screen) and the visit's
current prescription. Each row offers only what its state allows — **Call this patient** (`CallSerial`) for a
checked-in row, **Start**, **Prescribe** (→ `panel.prescription.visits.start` → the writer), **View / Print** once
issued. The roster re-reads itself through `panel.queue.doctor.roster` when the queue version moves (a check-in at
the desk) and after a row action — `start` stamps `consultation_started_at` without a status change, so it bumps
no version and no frame would ever arrive (SERIAL_ENGINE.md §6.4).

Who may drive it: `SerialPolicy::call` / `SessionInstancePolicy::callNext` = permission `queue.call-next`, and a
user who IS a doctor only on their own session — the same rule `ChannelGuards::doctor` applies to the channel.

**Issue → next patient.** `POST /panel/queue/sessions/{session}/call-next-visit`
(`panel.queue.call-next-visit`, `CallNextVisitController`) is the post-issue bar's one click: `CallNext` plus the
called serial's visit through the same idempotent `StartVisit`, answering
`{called, visit, writer_url, waiting_booked}`. `called: null` means nobody has arrived (the bar returns to the
session page, which says so); `writer_url: null` means the caller may not write that visit (an operator).
A serial still `in_consultation` anywhere on the session is refused with **409 `queue.chamber_occupied`** and the
refusal is shown, never swallowed: issuing is what completes the consultation
(`CompleteConsultationOnPrescriptionIssued`), so a chamber that is still occupied means something else is, and
calling on top of it would put two patients in one room.

Panel page `resources/js/panel/Pages/Queue/Doctor.tsx` (MUI, `Inertia::render('Queue/Doctor')`,
`routes/panel/queue.php` → `panel.queue.doctor`): subscribes with `useChannel()` to
`tenant.{t}.doctor.{doctorPublicId}` (`call.next`, `serial.called` private variant)
and with `useQueueState()` to the session's public `queue.state`. The big button posts
`POST /api/sessions/{session}/call-next` (`CallNext`, SERIAL_ENGINE.md §14);
the response already contains the called serial + patient card, so the screen
updates optimistically from the response and reconciles on the `serial.called`
frame (version check). Also: "Call specific" (`/serials/{serial}/call`), "Skip"
(`/skip`), "Complete" (`/complete` — also triggered automatically by the Serials listener
`CompleteConsultationOnPrescriptionIssued` when a prescription is issued, ARCHITECTURE.md §5.4),
"Pause/Resume session".

The reception board's **Call-next** button (brief F) hits the same endpoint
with the same guard (permission `queue.call-next` at that branch); `CallNext` fires
`call.next` on the doctor **and** display channels in one broadcast, which is
the "push simultaneously to the doctor's screen and the waiting-room display".
`dontBroadcastToCurrentUser()` is deliberately not used: the tab that clicked
must also receive the frame (it may have been optimistic on stale data).

Keyboard: Space = call next, N = no-show current, Enter = complete. Latency
target from click to display update: ≤ 300 ms on LAN (`ShouldBroadcastNow` +
Reverb local).

---

## 12. Operational notes

- Reverb runs as its own process (`php artisan reverb:start --host=0.0.0.0 --port=8080`) behind the same reverse proxy as Octane, path `/app` (`REVERB_SERVER_PATH`), TLS terminated at the proxy; the browser connects to `wss://{tenant-domain}/app` so custom domains need no extra DNS for WebSockets; the `queue.` subdomain is CNAMEd to the same edge.
- Horizon queues used by this module (the canonical list is ARCHITECTURE.md §4.6 — nothing else exists): `critical` for `QueueStateUpdated`/`BoardUpdated` (both `ShouldBeUniqueUntilProcessing`, §4.4), `notifications` for `NotifyApproachingSerials`/`FanOutSessionDelay`, `default` for the ETA refresh.
- Redis key inventory (`{t}` = bigint tenant id, `{s}` = session instance public id): `t:{t}:qs:{s}` (QueueState), `t:{t}:qs:{s}:v` (version), `t:{t}:cap:{s}` (capacity, SERIAL_ENGINE.md §12), lock `qs-build:{s}`, Reverb scaling channel `reverb`. Memory: ≈ 10 KB per active session; TTL bounds growth.
- Pulse: Reverb's `pulse_ingest_interval` on; alert when `ShouldRescue` swallows a broadcast (log channel `realtime`).
- The public state endpoint is the only unauthenticated read of tenant data; it returns codes and statuses only, never patient identifiers.

---

## 13. Test plan

### 13.1 PHPUnit (`tests/Feature/Queue` and `tests/Concurrency/Queue`; `Tests\TestCase` + `WithTenants`; Postgres `booking_test_N` and the Dragonfly database `REDIS_DB=N` from `scripts/test-agent.sh N` — CONVENTIONS.md §6.1; `#[Group('realtime')]` for tests that touch Redis)

`QueueStateEndpointTest`

- `returns_200_with_etag_equal_to_version_and_no_cache_headers`
- `returns_304_when_if_none_match_equals_version_and_performs_no_snapshot_read` (spy on `QueueStateRepository::snapshot`)
- `returns_200_with_new_version_after_a_serial_event`
- `picks_running_session_by_default_then_next_scheduled_then_last_closed_and_sets_x_queue_session`
- `pins_session_via_query_and_404s_for_other_tenant_or_unknown_slug`
- `payload_contains_no_patient_identifiers` (schema assertion over `serials[*]` keys)
- `throttle_applies_after_30_requests_per_minute`

`tests/Concurrency/Queue/QueueStateConcurrencyTest` (`#[Group('concurrency')]`, `$connectionsToTransact = []`, `Tests\Support\ProcessPool`)

- `cold_cache_rebuilds_inline_once_under_lock` (delete keys, 10 workers hitting `site.queue.state`, assert one `rebuild` per version)
- `version_is_monotonic_under_parallel_rebuilds` (8 workers rebuilding; snapshot version == version key)
- `concurrent_calls_never_double_notify` (parallel `CallNext` workers; see `ThreeAheadNotificationTest`)

`QueueStateInvalidationTest`

- `every_listed_domain_event_bumps_version_and_rewrites_snapshot` (data provider over §4.3)
- `ttl_is_at_least_two_hours_and_extends_to_planned_end_plus_two_hours`
- `transfer_invalidates_both_sessions`
- `eta_refresh_command_rebuilds_only_running_sessions_with_stale_now_serving`

`BroadcastingTest` (`Event::fake([...])` on the realtime events, then assert with `broadcastOn()`/`broadcastAs()`/`broadcastWith()`)

- `serial_called_is_broadcast_now_on_queue_reception_doctor_display_with_public_payload_without_names`
- `serial_called_private_carries_first_name_only_on_private_channels` — and `…_the_reception_copy_carries_no_patient_card` (§3.1)
- `queue_state_updated_is_queued_unique_until_processing_and_reads_snapshot_at_send_time`
- `board_updated_is_coalesced_per_branch`
- `call_next_goes_to_doctor_and_display_only`
- `session_delayed_and_cancelled_are_broadcast_now`
- `channel_names_are_tenant_prefixed_with_public_ids`
- `broadcast_failure_is_rescued_and_request_still_succeeds` (bind a throwing broadcaster; `ShouldRescue`)

`ChannelAuthTest` (`POST /broadcasting/auth` and `/api/device/broadcasting/auth`)

- `reception_channel_allows_branch_staff_and_branch_devices_denies_other_branch_and_other_tenant`
- `doctor_channel_allows_the_doctor_and_operators_denies_other_doctors`
- `display_channel_allows_display_devices_denies_reception_devices`
- `display_channel_denies_staff_of_another_branch_and_any_doctor_scoped_user` (§2 — the leg that was `is_active` alone)
- `public_queue_channel_needs_no_auth`

`ThreeAheadNotificationTest`

- `notifies_serials_at_distance_three_exactly_once`
- `no_shows_collapsing_the_line_notify_with_actual_ahead_count`
- (the parallel variant `concurrent_calls_never_double_notify` lives in `tests/Concurrency/Queue`)
- `reinstated_serial_is_not_renotified`
- `respects_tenant_notify_ahead_setting`

`SessionDelayTest`: absolute delay, ETA shift, notification dedupe bucket, clear-to-zero sends nothing.

### 13.2 Vitest (`resources/js/shared/realtime/__tests__` for `liveQueue`, `resources/js/site/Pages/Display/__tests__` for `voice`/`display`; fake timers, mocked `fetch` and a fake Echo channel)

`liveQueue.test.ts`

- `initial_poll_then_channel_subscription_pins_session_from_header`
- `online_mode_stops_polling_after_one_catch_up_poll`
- `degraded_mode_polls_every_5s_with_if_none_match_and_ignores_304`
- `hidden_tab_polls_every_30s_and_polls_immediately_when_visible`
- `offline_mode_stops_polling_and_keeps_last_state`
- `older_ws_frame_does_not_overwrite_newer_poll` (poll v420 then ws v419 → state stays 420)
- `older_poll_does_not_overwrite_newer_ws`
- `serial_called_event_triggers_one_full_state_poll`
- `silent_ws_guard_polls_after_90s_while_running_only`
- `reconnect_transition_degraded_to_online_performs_catch_up_poll`
- `stop_leaves_channel_and_clears_timers`

`voice.test.ts`: picks a `bn` voice when present; falls back to clip sequence
`chime, serial, A, 4, 2, room, 3` for `A-042`/Room 3; dedupes by serial+called_at;
drops stale announcements beyond 3.

`display.test.ts`: reload after 3 min without updates while running; no reload
when all sessions are closed; pagination beyond 9 tiles.

---

## 14. Schema additions requested

> Reconciled 2026-09-06: every item below is now in SCHEMA.md. The section is kept as the rationale record — where it says "missing" or "requested", SCHEMA.md already has it.

1. `serials.t3_notified_at` exists in SCHEMA.md and is used for the §7 dedupe; `serials.estimated_call_at` is refreshed by `QueueStateBuilder` for slips/SMS.
2. `session_instances.public_id` `char(26)` ULID unique via `HasPublicId` (missing in SCHEMA.md; also requested by SERIAL_ENGINE.md §19.1), `branches.public_id` (channel names above; `branches` currently has only `slug`), `doctors.room_label varchar(40) null` (payload `room`); `tenants.public_id`, `doctors.public_id`, `doctors.slug`, `branches.slug`, `doctors.name_bn` exist.
3. `reception_devices.kind enum(reception, display)` (OFFLINE.md §13.1) so display tokens can be distinguished in `ChannelGuards::display`.
4. `notifications.serial_id` and the index `notifications (serial_id, event_key, created_at)` serve the delay-notification dedupe bucket (§10); `notification_logs` has neither column and is never queried for dedupe.
5. `settings` keys: `queue.notify_ahead` (exists), `queue.delay_notify_min_change` (10), `queue.display_voice` (`both|bn|en|off`), `queue.public_page_enabled` (true).
6. SCHEMA.md §5.7, ARCHITECTURE.md §4.8/§9.3 and this document agree on the `QueueState` document (§4.1), the ETag `"<version>"` (§5.2), the key `t:{tenantId}:qs:{sessionInstancePublicId}` (§4.2), the channel `tenant.{tenantId}.queue.{sessionInstancePublicId}` (§2) and the accessor `App\Domain\Queue\Services\QueueStateRepository`; the earlier snapshot-store class, the `{version}-{unix_ms}` ETag and the `tenant.{id}.session.{id}` channel are withdrawn.
