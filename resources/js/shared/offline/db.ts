// docs/OFFLINE.md §5.2 — the Dexie schema. One database per (tenant, device) so a shared browser profile never mixes
// logs. `serials.publicId` for unsynced rows is `local:<clientEventId>` and is re-keyed on `accepted`.
import Dexie, { type EntityTable } from 'dexie';
import type { CachedBlock, CachedHistory, CachedPatient, CachedSerial, CachedSession, MetaRow, OfflineEventRecord, PrintTemplate } from './types';

export class ReceptionDB extends Dexie {
  meta!: EntityTable<MetaRow, 'key'>;
  sessions!: EntityTable<CachedSession, 'publicId'>;
  serials!: EntityTable<CachedSerial, 'publicId'>;
  blocks!: EntityTable<CachedBlock, 'publicId'>;
  patients!: EntityTable<CachedPatient, 'publicId'>;
  history!: EntityTable<CachedHistory, 'patientId'>;
  printTemplates!: EntityTable<PrintTemplate, 'id'>;
  events!: EntityTable<OfflineEventRecord, 'clientEventId'>;

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
    // Future: this.version(2).stores({...}).upgrade(tx => ...) — additive only; never rename a store while `events`
    // may contain pending rows (upgrade runs before sync).
  }

  async getMeta<T>(key: string): Promise<T | undefined> {
    const row = await this.meta.get(key);
    return row?.value as T | undefined;
  }

  async setMeta(key: string, value: unknown): Promise<void> {
    await this.meta.put({ key, value });
  }
}

export const META_KEYS = {
  deviceToken: 'deviceToken', device: 'device', actorUser: 'actorUser', actorPin: 'actorPin', sequenceNo: 'sequenceNo', lastSyncAt: 'lastSyncAt',
  schemaVersion: 'schemaVersion', receiptCounter: 'receiptCounter', printFormat: 'printFormat', doctors: 'doctors', settings: 'settings', tenant: 'tenant',
  branch: 'branch', channel: 'channel', bootstrapAt: 'bootstrapAt',
} as const;
