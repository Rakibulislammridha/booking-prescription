// Writing what the server sends into the cache (docs/OFFLINE.md §5.1) and re-keying local rows after `accepted`.
import type { ReceptionDB } from './db';
import { META_KEYS } from './db';
import type { EventLog } from './eventLog';
import type { CachedBlock, CachedPatient, CachedSerial, CachedSession, PrintTemplate } from './types';
import { localRef } from './types';

/** The desk serial shape the server returns (SerialPresenter): SerialResource + patient + appointment. */
export interface ServerSerial {
  public_id: string; display_code: string; number: number; position: number; status: string; priority: string; source: string;
  patient: { public_id: string; name: string; mobile_masked: string; age_text?: string | null } | null;
  appointment: { public_id: string; fee_paisa: number; payment_status: string; type?: string } | null;
  checked_in_at?: string | null;
}

export interface ServerBoardSession {
  public_id: string; code: string; date: string; status: string; mode: 'serial' | 'slot'; planned_start_at: string; planned_end_at: string; delay_minutes: number;
  doctor: { public_id: string; slug: string; name: string; name_bn: string | null; room: string | null };
  now_serving: { public_id: string; display_code: string } | null;
  counts: Record<string, number>;
  remaining: { online: number; counter: number; buffer: number; counter_in_blocks: number; released: number };
  fee_new_paisa: number; fee_followup_paisa: number; max_serials: number; version: number;
  serials: ServerSerial[];
}

export interface ServerBoard { date: string; branch: { public_id: string; name: string; code: string; slug: string }; sessions: ServerBoardSession[]; generated_at: string }

export interface ServerBlock { public_id: string; range_start: number; range_end: number; next_number: number; status: string; expires_at: string | null; revoked_at: string | null; session: string; session_code: string }

export interface BootstrapPayload {
  server_time: string;
  tenant: { public_id: string | null; name: string | null; timezone: string };
  branch: { public_id: string; name: string; code: string; slug: string; phone: string | null };
  device: Record<string, unknown>;
  actor: { public_id: string; name: string; roles: string[] };
  channel: string | null;
  days: ServerBoard[];
  blocks: ServerBlock[];
  doctors: Array<Record<string, unknown>>;
  settings: Record<string, unknown>;
  print_format: '58' | '80' | 'a5';
  print_templates: PrintTemplate[];
}

export function toCachedSession(s: ServerBoardSession, branchId: string): CachedSession {
  return {
    publicId: s.public_id, date: s.date, branchId, doctorId: s.doctor.public_id, code: s.code, status: s.status, mode: s.mode,
    plannedStartAt: s.planned_start_at, plannedEndAt: s.planned_end_at, delayMinutes: s.delay_minutes,
    doctor: { publicId: s.doctor.public_id, slug: s.doctor.slug, name: s.doctor.name, nameBn: s.doctor.name_bn, room: s.doctor.room },
    nowServing: s.now_serving?.display_code ?? null, counts: s.counts,
    remaining: { counter: s.remaining.counter, released: s.remaining.released, buffer: s.remaining.buffer, online: s.remaining.online, counterInBlocks: s.remaining.counter_in_blocks },
    feeNewPaisa: s.fee_new_paisa, feeFollowupPaisa: s.fee_followup_paisa, maxSerials: s.max_serials, version: s.version, updatedAt: Date.now(),
  };
}

export function toCachedSerial(s: ServerSerial, sessionId: string): CachedSerial {
  return {
    publicId: s.public_id, sessionId, number: s.number, displayCode: s.display_code, position: s.position, status: s.status, priority: s.priority, source: s.source,
    patientRef: s.patient?.public_id ?? '', patientName: s.patient?.name ?? '', mobileMasked: s.patient?.mobile_masked ?? '',
    appointmentId: s.appointment?.public_id ?? null, feePaisa: s.appointment?.fee_paisa ?? null, paymentStatus: s.appointment?.payment_status ?? null,
    checkedInAt: s.checked_in_at ?? null, local: false, updatedAt: Date.now(),
  };
}

export function toCachedBlock(b: ServerBlock): CachedBlock {
  const status = b.revoked_at ? 'revoked' : (b.status as CachedBlock['status']);
  return { publicId: b.public_id, sessionId: b.session, sessionCode: b.session_code, rangeStart: b.range_start, rangeEnd: b.range_end, nextNumber: b.next_number, status, expiresAt: b.expires_at };
}

/** Replace one day's sessions and their server serials; local (unsynced) serials are kept. */
export async function applyBoard(db: ReceptionDB, board: ServerBoard): Promise<void> {
  await db.transaction('rw', db.sessions, db.serials, async () => {
    const stale = await db.sessions.where('date').equals(board.date).toArray();
    const keep = new Set(board.sessions.map((s) => s.public_id));
    for (const s of stale) if (!keep.has(s.publicId)) await db.sessions.delete(s.publicId);
    for (const s of board.sessions) {
      await db.sessions.put(toCachedSession(s, board.branch.public_id));
      const existing = await db.serials.where('sessionId').equals(s.public_id).toArray();
      for (const row of existing) if (!row.local) await db.serials.delete(row.publicId);
      for (const serial of s.serials) await db.serials.put(toCachedSerial(serial, s.public_id));
    }
  });
}

export async function applyBlocks(db: ReceptionDB, blocks: ServerBlock[]): Promise<void> {
  await db.transaction('rw', db.blocks, async () => {
    const server = blocks.map(toCachedBlock);
    const ids = new Set(server.map((b) => b.publicId));
    const local = await db.blocks.toArray();
    for (const b of local) if (!ids.has(b.publicId) && b.status === 'active') await db.blocks.put({ ...b, status: 'released' });
    for (const b of server) await db.blocks.put(b);
  });
}

export async function applyBootstrap(db: ReceptionDB, payload: BootstrapPayload): Promise<void> {
  for (const day of payload.days) await applyBoard(db, day);
  await applyBlocks(db, payload.blocks);
  await db.transaction('rw', db.meta, db.printTemplates, async () => {
    await db.setMeta(META_KEYS.doctors, payload.doctors);
    await db.setMeta(META_KEYS.settings, payload.settings);
    await db.setMeta(META_KEYS.tenant, payload.tenant);
    await db.setMeta(META_KEYS.branch, payload.branch);
    await db.setMeta(META_KEYS.channel, payload.channel);
    await db.setMeta(META_KEYS.device, payload.device);
    await db.setMeta(META_KEYS.actorUser, payload.actor);
    await db.setMeta(META_KEYS.printFormat, payload.print_format);
    await db.setMeta(META_KEYS.bootstrapAt, Date.now());
    for (const template of payload.print_templates) await db.printTemplates.put(template);
  });
}

export async function cachePatients(db: ReceptionDB, rows: Array<{ public_id: string; mobile: string; name: string; gender?: string | null; dob?: string | null; age_years?: number | null; age_text?: string | null; patient_code?: string }>): Promise<void> {
  const now = Date.now();
  await db.patients.bulkPut(rows.map((p): CachedPatient => ({
    publicId: p.public_id, mobile: p.mobile, name: p.name, nameTokens: tokens(p.name), updatedAt: now,
    ...(p.gender ? { sex: p.gender === 'male' ? 'm' : p.gender === 'female' ? 'f' : 'o' } : {}),
    ...(p.dob ? { dob: p.dob } : {}), ...(p.age_years != null ? { ageYears: p.age_years } : {}),
    ageText: p.age_text ?? null, ...(p.patient_code ? { patientCode: p.patient_code } : {}),
  })));
}

export function tokens(name: string): string[] {
  return name.toLowerCase().split(/\s+/).filter(Boolean);
}

/**
 * OFFLINE §5.2: on `accepted` the local serial row is re-keyed to the server id (delete + put in one transaction) and
 * every event referencing the local id is rewritten. Returns the server-side public id.
 */
export async function rekeyLocalSerial(db: ReceptionDB, log: EventLog, clientEventId: string, server: ServerSerial, sessionId: string): Promise<string> {
  const local = localRef(clientEventId);
  await db.transaction('rw', db.serials, db.events, async () => {
    const row = await db.serials.get(local);
    const next = toCachedSerial(server, sessionId);
    if (row) {
      next.cashCollected = row.cashCollected;
      next.receiptNo = row.receiptNo;
      await db.serials.delete(local);
    }
    next.clientEventId = clientEventId;
    await db.serials.put(next);
    await log.rewriteRefs(local, server.public_id);
  });
  return server.public_id;
}

/** After register_patient is accepted (or linked): the stub becomes the server patient; dependants point at it. */
export async function rekeyLocalPatient(db: ReceptionDB, log: EventLog, localId: string, patient: { public_id: string; name: string; mobile_masked?: string; age_text?: string | null; patient_code?: string }, mobile: string): Promise<void> {
  const local = localRef(localId);
  await db.transaction('rw', db.patients, db.serials, db.events, async () => {
    const stub = await db.patients.get(local);
    if (stub) await db.patients.delete(local);
    await db.patients.put({ publicId: patient.public_id, localId, mobile: stub?.mobile ?? mobile, name: patient.name, nameTokens: tokens(patient.name), ageText: patient.age_text ?? stub?.ageText ?? null, updatedAt: Date.now(), ...(patient.patient_code ? { patientCode: patient.patient_code } : {}) });
    const serials = await db.serials.where('patientRef').equals(local).toArray();
    for (const s of serials) await db.serials.update(s.publicId, { patientRef: patient.public_id, patientName: patient.name });
    await log.rewriteRefs(local, patient.public_id);
  });
}
