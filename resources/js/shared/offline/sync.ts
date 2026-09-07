// docs/OFFLINE.md §7.3 — the client sync loop. Triggers: the connection store leaving `offline`, every 20 s while
// events are pending, and 300 ms after each new event when the server is reachable. `navigator.locks` guarantees a
// single flusher across tabs. Results are applied in one Dexie transaction: accepted → re-key local rows; conflict →
// card; pending → deferred (re-sent after the dependency resolves); rejected → kept. Network failure → back to
// pending with exponential backoff 2 s → 60 s. The store's `pendingEvents / conflicts / syncPhase` are the only UI facts.
import { useConnection, type ConnectionMode } from '../connection/store';
import type { ReceptionDB } from './db';
import { META_KEYS } from './db';
import type { EventLog } from './eventLog';
import { rekeyLocalPatient, rekeyLocalSerial, type ServerSerial } from './bootstrap';
import type { ConflictResolution, OfflineEventRecord, ResolveRequest, SyncEventResult, SyncRequest, SyncResponse, WireEvent } from './types';

export interface SyncTransport {
  sync(body: SyncRequest): Promise<SyncResponse>;
  resolve(body: ResolveRequest): Promise<SyncEventResult>;
}

export interface SyncEngineOptions {
  db: ReceptionDB;
  log: EventLog;
  transport: SyncTransport;
  appVersion: string;
  lockName: string;                       // bp-sync-<device>
  onBootstrapStale?: () => void;
  onAccepted?: (event: OfflineEventRecord, result: SyncEventResult) => Promise<void> | void;
  now?: () => number;
}

export const SYNC_BATCH = 200;
export const SYNC_DEBOUNCE_MS = 300;
export const SYNC_INTERVAL_MS = 20_000;
export const BACKOFF_MIN_MS = 2_000;
export const BACKOFF_MAX_MS = 60_000;

type LockRequest = (name: string, callback: () => Promise<unknown>) => Promise<unknown>;
type Timer = ReturnType<typeof setTimeout> | undefined;

/** navigator.locks when the browser has it; otherwise an in-process mutex (tests, old WebViews). */
function lockRequest(): LockRequest {
  const locks = (globalThis.navigator as { locks?: { request: LockRequest } } | undefined)?.locks;
  if (locks && typeof locks.request === 'function') return (name, cb) => locks.request(name, cb);
  const chains = new Map<string, Promise<unknown>>();
  return (name, cb) => {
    const previous = chains.get(name) ?? Promise.resolve();
    const next = previous.catch(() => undefined).then(cb);
    chains.set(name, next);
    return next;
  };
}

export class SyncEngine {
  private readonly lock = lockRequest();
  private debounce: Timer;
  private interval: Timer;
  private retry: Timer;
  private backoffMs = BACKOFF_MIN_MS;
  private unsubscribe: (() => void) | null = null;
  private running = false;
  private inflight: Promise<unknown> | null = null;

  constructor(private readonly opts: SyncEngineOptions) {}

  start(): void {
    if (this.running) return;
    this.running = true;
    this.unsubscribe = useConnection.subscribe((s) => s.mode, (mode: ConnectionMode) => { if (mode !== 'offline') void this.flush(); });
    this.interval = setInterval(() => { void this.tick(); }, SYNC_INTERVAL_MS);
    void this.publishFacts();
    if (useConnection.getState().mode !== 'offline') void this.flush();
  }

  stop(): void {
    this.running = false;
    this.unsubscribe?.();
    this.unsubscribe = null;
    clearTimeout(this.debounce);
    clearInterval(this.interval);
    clearTimeout(this.retry);
  }

  /** After a new event: ship a burst of clicks together (300 ms), only when the server is reachable. */
  requestFlush(): void {
    void this.publishFacts();
    if (useConnection.getState().mode === 'offline') return;
    clearTimeout(this.debounce);
    this.debounce = setTimeout(() => { void this.flush(); }, SYNC_DEBOUNCE_MS);
  }

  private async tick(): Promise<void> {
    const { pending } = await this.opts.log.counts();
    if (pending > 0 && useConnection.getState().mode !== 'offline') await this.flush();
  }

  /** One batch: ≤ 200 sendable events by sequenceNo → POST → apply. Returns what happened for tests/UI. */
  async flush(): Promise<'offline' | 'idle' | 'done' | 'error' | 'skipped'> {
    if (useConnection.getState().mode === 'offline') return 'offline';
    let outcome: 'offline' | 'idle' | 'done' | 'error' | 'skipped' = 'skipped';
    const run = this.lock(this.opts.lockName, async () => {
      outcome = await this.flushLocked();
    });
    this.inflight = run;
    try {
      await run;
    } finally {
      if (this.inflight === run) this.inflight = null;
    }
    return outcome;
  }

  /** Await whatever flush a timer or the debounce started (tests, "apply update when idle"). */
  async settle(): Promise<void> {
    while (this.inflight) {
      const current = this.inflight;
      await current.catch(() => undefined);
      if (this.inflight === current) this.inflight = null;
    }
  }

  private async flushLocked(): Promise<'idle' | 'done' | 'error'> {
    const batch = await this.opts.log.pending(SYNC_BATCH);
    if (batch.length === 0) {
      await this.publishFacts('idle');
      return 'idle';
    }
    await this.opts.log.markSending(batch.map((e) => e.clientEventId));
    await this.publishFacts('syncing');
    const first = batch[0] as OfflineEventRecord;
    const body: SyncRequest = { sequence_no_from: first.sequenceNo, app_version: this.opts.appVersion, events: batch.map(toWire) };

    let response: SyncResponse;
    try {
      response = await this.opts.transport.sync(body);
    } catch {
      await this.opts.log.revertSending();
      await this.publishFacts('error');
      this.scheduleRetry();
      return 'error';
    }

    this.backoffMs = BACKOFF_MIN_MS;
    await this.opts.log.applyResults(response.results);
    await this.opts.log.revertSending();   // anything the server did not answer for goes back to pending
    for (const result of response.results) {
      if (result.status !== 'accepted') continue;
      const event = batch.find((e) => e.clientEventId === result.client_event_id);
      if (event) await this.afterAccepted(event, result);
    }
    await this.opts.db.setMeta(META_KEYS.lastSyncAt, (this.opts.now ?? Date.now)());
    if (response.bootstrap_stale) this.opts.onBootstrapStale?.();
    await this.publishFacts();
    const remaining = await this.opts.log.pending(1);
    if (remaining.length > 0 && remaining.some((e) => e.status === 'pending')) this.requestFlush();
    return 'done';
  }

  /** OFFLINE §7.4: send the decision; the result replaces the conflict row and dependants are re-sent next flush. */
  async resolve(clientEventId: string, resolution: ConflictResolution, params: Record<string, unknown> = {}): Promise<SyncEventResult> {
    const result = await this.opts.transport.resolve({ client_event_id: clientEventId, resolution, params });
    await this.opts.log.applyResults([result]);
    const event = await this.opts.log.get(clientEventId);
    if (event && result.status === 'accepted') await this.afterAccepted(event, result);
    if (result.status !== 'conflict') {
      // dependants were parked as deferred/pending: ship them (or, after a discard, they will be rejected by the server)
      const dependants = await this.opts.log.dependants(clientEventId);
      await this.opts.db.transaction('rw', this.opts.db.events, async () => {
        for (const d of dependants) if (d.status === 'deferred' || d.status === 'conflict') await this.opts.db.events.update(d.clientEventId, { status: 'pending' });
      });
    }
    await this.publishFacts();
    this.requestFlush();
    return result;
  }

  private async afterAccepted(event: OfflineEventRecord, result: SyncEventResult): Promise<void> {
    const server = result.server_result;
    if (event.type === 'issue_serial' && server.serial) {
      const serial = server.serial as ServerSerial;
      const sessionId = (event.payload.sessionId as string | undefined) ?? (event.sessionId ?? '');
      await rekeyLocalSerial(this.opts.db, this.opts.log, event.clientEventId, serial, sessionId);
    }
    if (event.type === 'register_patient' && server.patient) {
      const patient = server.patient as { public_id: string; name: string; mobile_masked?: string; age_text?: string | null; patient_code?: string };
      await rekeyLocalPatient(this.opts.db, this.opts.log, String(event.payload.localId ?? ''), patient, String(event.payload.mobile ?? ''));
    }
    if (event.type === 'check_in' && server.serial) {
      const serial = server.serial as ServerSerial;
      await this.opts.db.serials.update(serial.public_id, { status: serial.status, checkedInAt: serial.checked_in_at ?? null, updatedAt: Date.now() });
    }
    await this.opts.onAccepted?.(event, result);
  }

  private scheduleRetry(): void {
    clearTimeout(this.retry);
    const delay = this.backoffMs;
    this.backoffMs = Math.min(BACKOFF_MAX_MS, this.backoffMs * 2);
    this.retry = setTimeout(() => { void this.flush(); }, delay);
  }

  currentBackoffMs(): number {
    return this.backoffMs;
  }

  async publishFacts(phase?: 'idle' | 'syncing' | 'conflicts' | 'error'): Promise<void> {
    const { pending, conflicts } = await this.opts.log.counts();
    const syncPhase = conflicts > 0 ? 'conflicts' : (phase ?? (pending > 0 ? useConnection.getState().syncPhase : 'idle'));
    useConnection.getState().setSyncFacts({ pendingEvents: pending, conflicts, syncPhase: syncPhase === 'syncing' && pending === 0 ? 'idle' : syncPhase });
  }
}

export function toWire(e: OfflineEventRecord): WireEvent {
  return {
    client_event_id: e.clientEventId, sequence_no: e.sequenceNo, type: e.type, client_occurred_at: e.clientOccurredAt, actor_user_id: e.actorUserId,
    depends_on: e.dependsOn ?? null, ...(e.sessionId ? { session_id: e.sessionId } : {}), payload: e.payload,
  };
}
