// docs/OFFLINE.md §4.2–§4.3 — issuing from the device's own block. `issue()` runs inside one Dexie readwrite
// transaction over blocks + serials + events + meta: read the active block, take `nextNumber`, advance the cursor,
// insert the local serial (`local:<clientEventId>`) and append the `issue_serial` event. IndexedDB transactions are
// serialisable per store, so two tabs of one device cannot double-issue; two devices cannot because blocks are disjoint.
import type { ReceptionDB } from './db';
import type { EventLog } from './eventLog';
import { serialCode } from '../format/serial';
import type { CachedBlock, CachedSerial, OfflineEventRecord } from './types';
import { localRef } from './types';

export class BlockExhausted extends Error {
  constructor(public readonly sessionId: string) {
    super(`No active block with numbers left for session ${sessionId}`);
    this.name = 'BlockExhausted';
  }
}

export interface IssueInput {
  patientRef: string;             // pat public id or local:<localId>
  patientName: string;
  mobileMasked: string;
  priority?: 'normal' | 'elderly' | 'emergency' | 'vip';
  walkIn?: boolean;
  appointmentType?: 'new' | 'followup';
  feeAmountPaisa: number;
  dependsOn?: string;             // the register_patient event for a stub
}

export interface IssueResult { serial: CachedSerial; block: CachedBlock; event: OfflineEventRecord }

export class BlockIssuer {
  constructor(private readonly db: ReceptionDB, private readonly log: EventLog) {}

  /** The active blocks of a session, lowest range first. */
  async activeBlocks(sessionId: string): Promise<CachedBlock[]> {
    const rows = await this.db.blocks.where('[sessionId+status]').equals([sessionId, 'active']).toArray();
    return rows.filter((b) => b.nextNumber <= b.rangeEnd).sort((a, b) => a.rangeStart - b.rangeStart);
  }

  static remaining(blocks: CachedBlock[]): number {
    return blocks.reduce((sum, b) => sum + Math.max(0, b.rangeEnd - b.nextNumber + 1), 0);
  }

  /** OFFLINE §4.2: lease another when the active numbers drop to the threshold (online only — the caller checks the mode). */
  static needsTopUp(blocks: CachedBlock[], threshold: number, maxActive = 2): boolean {
    const active = blocks.filter((b) => b.status === 'active');
    return active.length < maxActive && BlockIssuer.remaining(active) <= threshold;
  }

  async issue(sessionId: string, input: IssueInput): Promise<IssueResult> {
    return this.db.transaction('rw', this.db.blocks, this.db.serials, this.db.events, this.db.meta, this.db.sessions, async () => {
      const blocks = await this.activeBlocks(sessionId);
      const block = blocks[0];
      if (!block) throw new BlockExhausted(sessionId);

      const number = block.nextNumber;
      const next = number + 1;
      const updated: CachedBlock = { ...block, nextNumber: next, status: next > block.rangeEnd ? 'exhausted' : 'active' };
      await this.db.blocks.put(updated);

      const session = await this.db.sessions.get(sessionId);
      const displayCode = serialCode(block.sessionCode || session?.code || 'A', number);
      const event = await this.log.append({
        type: 'issue_serial',
        sessionId,
        ...(input.dependsOn ? { dependsOn: input.dependsOn } : {}),
        payload: {
          sessionId, blockId: block.publicId, number, displayCode,
          patientRef: input.patientRef, priority: input.priority ?? 'normal', walkIn: input.walkIn ?? false,
          appointmentType: input.appointmentType ?? 'new', feeSnapshot: { amount: input.feeAmountPaisa, currency: 'BDT' },
        },
      });

      const serial: CachedSerial = {
        publicId: localRef(event.clientEventId), sessionId, number, displayCode, position: number * 1_000_000, status: 'booked',
        priority: input.priority ?? 'normal', source: 'offline', patientRef: input.patientRef, patientName: input.patientName, mobileMasked: input.mobileMasked,
        appointmentId: null, feePaisa: input.feeAmountPaisa, paymentStatus: 'unpaid', clientEventId: event.clientEventId, blockId: block.publicId, local: true, updatedAt: Date.now(),
      };
      await this.db.serials.put(serial);
      return { serial, block: updated, event };
    });
  }

  /**
   * OFFLINE §6.1 void_local: only for an `issue_serial` still pending. Removes the issue event and its dependants
   * (nothing reached the server), drops the local serial, restores the block cursor ONLY if it was the last number
   * issued, and appends the audit event.
   */
  async voidLocal(clientEventId: string, reason: string): Promise<OfflineEventRecord> {
    return this.db.transaction('rw', this.db.blocks, this.db.serials, this.db.events, this.db.meta, async () => {
      const issue = await this.db.events.get(clientEventId);
      if (!issue || issue.type !== 'issue_serial' || issue.status !== 'pending') throw new Error('void_local: only a pending issue_serial can be voided');
      const payload = issue.payload as { blockId: string; number: number; sessionId: string };
      const dependants = await this.log.dependants(clientEventId);
      await this.db.events.bulkDelete([clientEventId, ...dependants.map((d) => d.clientEventId)]);
      await this.db.serials.delete(localRef(clientEventId));

      const block = await this.db.blocks.get(payload.blockId);
      if (block && block.nextNumber === payload.number + 1) {
        await this.db.blocks.put({ ...block, nextNumber: payload.number, status: 'active' });
      }
      return this.log.append({ type: 'void_local', sessionId: payload.sessionId, payload: { voidedClientEventId: clientEventId, reason, number: payload.number, sessionId: payload.sessionId } });
    });
  }
}
