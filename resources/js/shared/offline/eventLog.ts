// docs/OFFLINE.md §6 — the ordered event log. `append()` assigns the ULID clientEventId and the per-device monotonic
// sequenceNo inside ONE Dexie transaction (meta + events), so a burst of clicks and two tabs of the same device can
// never share a sequence number. Components never write db.events directly (CONVENTIONS §7.6).
import type { ReceptionDB } from './db';
import { META_KEYS } from './db';
import { ulid } from '../ulid';
import type { LocalEventStatus, OfflineEventRecord, OfflineEventType, SyncEventResult } from './types';

export interface AppendInput {
  type: OfflineEventType;
  payload: Record<string, unknown>;
  sessionId?: string;
  dependsOn?: string;
  clientEventId?: string;      // tests / retries may pin the id
  occurredAt?: Date;
}

export const SENDABLE: readonly LocalEventStatus[] = ['pending', 'deferred'];

export class EventLog {
  constructor(private readonly db: ReceptionDB, private readonly actorUserId: () => string) {}

  async append(input: AppendInput): Promise<OfflineEventRecord> {
    return this.db.transaction('rw', this.db.meta, this.db.events, async () => {
      const current = (await this.db.getMeta<number>(META_KEYS.sequenceNo)) ?? 0;
      const sequenceNo = current + 1;
      const record: OfflineEventRecord = {
        clientEventId: input.clientEventId ?? ulid(),
        sequenceNo,
        type: input.type,
        payload: input.payload,
        clientOccurredAt: (input.occurredAt ?? new Date()).toISOString(),
        actorUserId: this.actorUserId(),
        status: 'pending',
        attempts: 0,
        ...(input.sessionId ? { sessionId: input.sessionId } : {}),
        ...(input.dependsOn ? { dependsOn: input.dependsOn } : {}),
      };
      await this.db.setMeta(META_KEYS.sequenceNo, sequenceNo);
      await this.db.events.add(record);
      return record;
    });
  }

  /** Sendable events in sequence order, at most `limit` (the server refuses > 200). */
  async pending(limit = 200): Promise<OfflineEventRecord[]> {
    const rows = await this.db.events.where('status').anyOf(SENDABLE as string[]).toArray();
    return rows.sort((a, b) => a.sequenceNo - b.sequenceNo).slice(0, limit);
  }

  async markSending(ids: string[]): Promise<void> {
    await this.db.transaction('rw', this.db.events, async () => {
      for (const id of ids) await this.db.events.update(id, { status: 'sending' });
    });
  }

  /** Network failure: everything in flight goes back to `pending`. */
  async revertSending(): Promise<number> {
    const rows = await this.db.events.where('status').equals('sending').toArray();
    await this.db.transaction('rw', this.db.events, async () => {
      for (const row of rows) await this.db.events.update(row.clientEventId, { status: 'pending', attempts: row.attempts + 1 });
    });
    return rows.length;
  }

  /**
   * Apply one batch of server results (OFFLINE §7.3) in one transaction. `accepted` rows keep their result for the
   * shift report (pruned after 7 days); `conflict` rows wait for a decision; `pending` (dependency unresolved) go back
   * to `deferred` and are re-sent once the dependency is accepted; `rejected` rows are kept, never re-sent.
   */
  async applyResults(results: SyncEventResult[]): Promise<void> {
    await this.db.transaction('rw', this.db.events, async () => {
      for (const r of results) {
        const status: LocalEventStatus = r.status === 'pending' ? 'deferred' : r.status;
        const row = await this.db.events.get(r.client_event_id);
        if (!row) continue;
        await this.db.events.update(r.client_event_id, {
          status,
          result: r.server_result,
          attempts: row.attempts + 1,
          ...(r.conflict_reason ? { conflictReason: r.conflict_reason } : { conflictReason: undefined }),
        });
      }
    });
  }

  async get(clientEventId: string): Promise<OfflineEventRecord | undefined> {
    return this.db.events.get(clientEventId);
  }

  /** Every event that (transitively) depends on `clientEventId`. */
  async dependants(clientEventId: string): Promise<OfflineEventRecord[]> {
    const out: OfflineEventRecord[] = [];
    const queue = [clientEventId];
    while (queue.length > 0) {
      const id = queue.shift() as string;
      const children = await this.db.events.where('dependsOn').equals(id).toArray();
      for (const child of children) {
        out.push(child);
        queue.push(child.clientEventId);
      }
    }
    return out;
  }

  /** Rewrite `local:<from>` references to `<to>` in every not-yet-accepted event (after a re-key or a link resolution). */
  async rewriteRefs(from: string, to: string): Promise<number> {
    let changed = 0;
    await this.db.transaction('rw', this.db.events, async () => {
      const rows = await this.db.events.where('status').anyOf(['pending', 'deferred', 'sending', 'conflict']).toArray();
      for (const row of rows) {
        const payload = { ...row.payload };
        let touched = false;
        for (const key of ['serialRef', 'patientRef'] as const) {
          if (payload[key] === from) { payload[key] = to; touched = true; }
        }
        if (touched) { await this.db.events.update(row.clientEventId, { payload }); changed++; }
      }
    });
    return changed;
  }

  async counts(): Promise<{ pending: number; conflicts: number; sending: number }> {
    const [pending, deferred, conflicts, sending] = await Promise.all([
      this.db.events.where('status').equals('pending').count(),
      this.db.events.where('status').equals('deferred').count(),
      this.db.events.where('status').equals('conflict').count(),
      this.db.events.where('status').equals('sending').count(),
    ]);
    return { pending: pending + deferred + sending, conflicts, sending };
  }

  /** Accepted rows older than `days` are dropped (kept meanwhile for the shift report). */
  async prune(days = 7, now: number = Date.now()): Promise<number> {
    const cutoff = now - days * 86_400_000;
    const rows = await this.db.events.where('status').equals('accepted').toArray();
    const stale = rows.filter((r) => Date.parse(r.clientOccurredAt) < cutoff).map((r) => r.clientEventId);
    if (stale.length > 0) await this.db.events.bulkDelete(stale);
    return stale.length;
  }
}
