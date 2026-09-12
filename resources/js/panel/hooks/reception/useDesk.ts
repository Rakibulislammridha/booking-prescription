// The desk's offline-first glue (CONVENTIONS §7.6, OFFLINE §5–§7): one hook that owns the Dexie database, the event
// log, the block issuer and the sync engine for THIS device, keeps the board fresh (Inertia props → device bootstrap →
// realtime `board.updated` / 5 s polling in degraded mode → Dexie when offline) and exposes the desk actions. Every
// mutation offline goes through eventLog.append(); online, server-backed actions call the panel/api endpoints.
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useConnection, type ConnectionMode } from '@shared/connection/store';
import { useChannel } from '@shared/realtime/useChannel';
import {
  applyBlocks, applyBoard, applyBootstrap, BlockIssuer, byNumber, cachePatients, canHaveVitals, EventLog, META_KEYS, nextToCall, ReceptionDB, SyncEngine, useConflicts,
  type BootstrapPayload, type CachedBlock, type CachedSerial, type CachedSession, type ConflictResolution, type PrintTemplate, type ServerBoard,
} from '@shared/offline';
import { ulid } from '@shared/ulid';
import { tokens } from '@shared/offline/bootstrap';
import { fetchBoard, fetchBootstrap, fetchDeviceBoard, fetchRecentPatients, leaseBlock, resolveConflict, syncEvents, type DeviceAuth } from '@panel/api/reception';
import type { Board, BoardSession, DeskSerial, ReceptionDevice, RegisterDeviceResponse } from '@shared/types/models';

export const DEVICE_POINTER_KEY = 'bp.reception.device';
export const BOARD_POLL_MS = 5_000;
export const BOOTSTRAP_REFRESH_MS = 300_000;
export const APP_VERSION = (import.meta.env.VITE_APP_VERSION as string | undefined) ?? '1.0.0';

export interface DevicePointer { tenantId: string; devicePublicId: string }

export interface DeskSettings { defaultBlockSize: number; topupThreshold: number; maxActiveBlocks: number; pinIdleMinutes: number }

export interface DeskIssueInput { session: BoardSession; patientRef: string; patientName: string; mobileMasked: string; priority?: 'normal' | 'elderly' | 'emergency' | 'vip'; appointmentType?: 'new' | 'followup'; feeAmountPaisa: number; stub?: { localId: string; mobile: string; name: string; sex?: 'm' | 'f' | 'o'; ageYears?: number } }

export interface Desk {
  mode: ConnectionMode;
  board: Board;
  registered: boolean;
  device: ReceptionDevice | null;
  actorPublicId: string | null;
  blocks: CachedBlock[];
  templates: PrintTemplate[];
  settings: DeskSettings;
  refresh(): Promise<void>;
  registerFromResponse(r: RegisterDeviceResponse, tenantId: string, actorPublicId: string): Promise<void>;
  forgetDevice(): Promise<void>;
  ensureBlocks(session: BoardSession): Promise<void>;
  issueOffline(input: DeskIssueInput): Promise<CachedSerial>;
  checkInOffline(serialRef: string): Promise<void>;
  collectCashOffline(serialRef: string, amountPaisa: number, note?: string): Promise<string>;
  printed(serialRef: string, format: PrintFormatId, copies?: number): Promise<void>;
  voidLocal(clientEventId: string, reason: string): Promise<void>;
  resolve(clientEventId: string, resolution: ConflictResolution, params?: Record<string, unknown>): Promise<void>;
  flush(): Promise<void>;
  cachedPatients(q: string): Promise<Array<{ publicId: string; name: string; mobile: string; ageText?: string | null }>>;
}

type PrintFormatId = PrintTemplate['id'];

function readPointer(): DevicePointer | null {
  try {
    const raw = localStorage.getItem(DEVICE_POINTER_KEY);
    return raw ? (JSON.parse(raw) as DevicePointer) : null;
  } catch {
    return null;
  }
}

function writePointer(pointer: DevicePointer | null): void {
  try {
    if (pointer) localStorage.setItem(DEVICE_POINTER_KEY, JSON.stringify(pointer));
    else localStorage.removeItem(DEVICE_POINTER_KEY);
  } catch {
    /* private mode */
  }
}

/** Merge the cached sessions + serials (server rows and local unsynced ones) into the board shape the page renders. */
export function boardFromCache(sessions: CachedSession[], serials: CachedSerial[], base: Board): Board {
  const bySession = new Map<string, CachedSerial[]>();
  for (const s of serials) (bySession.get(s.sessionId) ?? bySession.set(s.sessionId, []).get(s.sessionId))?.push(s);
  const toDesk = (s: CachedSerial): DeskSerial => ({
    public_id: s.publicId, display_code: s.displayCode, number: s.number, position: s.position, status: s.status as DeskSerial['status'], priority: s.priority as DeskSerial['priority'],
    source: s.source as DeskSerial['source'], pool: 'counter', patient_id: null, appointment_id: null, slot_start_at: null, booked_at: '', checked_in_at: s.checkedInAt ?? null,
    called_at: null, completed_at: null, no_show_at: null, cancelled_at: null, cancel_reason_code: null, passed_count: 0, skip_count: 0, eta: null,
    patient: s.patientRef ? { public_id: s.patientRef, name: s.patientName, mobile_masked: s.mobileMasked, age_text: null, sex: null, patient_code: '' } : null,
    appointment: s.appointmentId || s.feePaisa !== null ? { public_id: s.appointmentId ?? '', type: 'new', channel: s.local ? 'offline' : 'counter', status: (s.appointmentStatus ?? 'confirmed') as NonNullable<DeskSerial['appointment']>['status'], fee_paisa: s.feePaisa ?? 0, list_fee_paisa: s.feePaisa ?? 0, fee_rule: 'new', payment_status: (s.paymentStatus ?? 'unpaid') as DeskSerial['appointment'] extends infer A ? A extends { payment_status: infer P } ? P : never : never, hold_expires_at: s.holdExpiresAt ?? null } : null,
    // Rebuilt from the cache, staleness and all — the row shows it as "as of the last sync" while offline
    // (shared/offline/types.ts CachedSerial documents why the flag is cached at all).
    vitals: canHaveVitals(s.status) ? { recorded: s.hasVitals ?? false, readings: s.vitalsReadings ?? 0, recorded_at: s.vitalsAt ?? null, reviewed: s.vitalsReviewed ?? false } : null,
    prescription: s.prescriptionId ? { public_id: s.prescriptionId, verification_code: s.prescriptionCode ?? null, version: s.prescriptionVersion ?? 1 } : null,
  });
  return {
    ...base,
    sessions: sessions.filter((s) => s.date === base.date).map((s): BoardSession => {
      // Number order and CallNext's pick, from the cached rows — the same shared rule the tile applies, so a
      // patient checked in offline is "Next" here exactly as the server would say once it hears about it.
      const rows = bySession.get(s.publicId) ?? [];
      const next = nextToCall(rows);
      return {
        public_id: s.publicId, code: s.code, date: s.date, status: s.status as BoardSession['status'], mode: s.mode,
        doctor: { public_id: s.doctor.publicId, slug: s.doctor.slug, name: s.doctor.name, name_bn: s.doctor.nameBn, room: s.doctor.room },
        planned_start_at: s.plannedStartAt, planned_end_at: s.plannedEndAt, expected_start_at: s.plannedStartAt, delay_minutes: s.delayMinutes,
        now_serving: s.nowServing ? { public_id: '', display_code: s.nowServing } : null,
        next_serial: next ? { public_id: next.publicId, display_code: next.displayCode } : null,
        counts: { booked: s.counts.booked ?? 0, checked_in: s.counts.checked_in ?? 0, in_consultation: s.counts.in_consultation ?? 0, completed: s.counts.completed ?? 0, no_show: s.counts.no_show ?? 0, cancelled: s.counts.cancelled ?? 0, postponed: s.counts.postponed ?? 0 },
        remaining: { online: s.remaining.online, counter: s.remaining.counter, buffer: s.remaining.buffer, counter_in_blocks: s.remaining.counterInBlocks, released: s.remaining.released },
        fee_new_paisa: s.feeNewPaisa, fee_followup_paisa: s.feeFollowupPaisa, max_serials: s.maxSerials, version: s.version,
        serials: byNumber(rows).map(toDesk),
      };
    }),
  };
}

export function useDesk(initial: Board, options: { tenantPublicId: string | null; channel: string | null; actorPublicId: string | null; settings: Record<string, unknown> }): Desk {
  const mode = useConnection((s) => s.mode);
  const [board, setBoard] = useState<Board>(initial);
  const [pointer, setPointer] = useState<DevicePointer | null>(() => readPointer());
  const [device, setDevice] = useState<ReceptionDevice | null>(null);
  const [blocks, setBlocks] = useState<CachedBlock[]>([]);
  const [templates, setTemplates] = useState<PrintTemplate[]>([]);
  const dbRef = useRef<ReceptionDB | null>(null);
  const logRef = useRef<EventLog | null>(null);
  const issuerRef = useRef<BlockIssuer | null>(null);
  const syncRef = useRef<SyncEngine | null>(null);
  const authRef = useRef<DeviceAuth | null>(null);
  const actorRef = useRef<string | null>(options.actorPublicId);
  const settings = useMemo<DeskSettings>(() => ({
    defaultBlockSize: Number(options.settings['serial.default_block_size'] ?? 5),
    topupThreshold: Number(options.settings['serial.block_topup_threshold'] ?? 3),
    maxActiveBlocks: Number(options.settings['serial.max_active_blocks_per_device'] ?? 2),
    pinIdleMinutes: Number(options.settings['reception.pin_idle_minutes'] ?? 15),
  }), [options.settings]);

  useEffect(() => { setBoard(initial); }, [initial]);

  const renderFromCache = useCallback(async (base: Board): Promise<void> => {
    const db = dbRef.current;
    if (!db) return;
    const [sessions, serials, activeBlocks] = await Promise.all([db.sessions.where('date').equals(base.date).toArray(), db.serials.toArray(), db.blocks.toArray()]);
    setBlocks(activeBlocks);
    if (sessions.length > 0) setBoard(boardFromCache(sessions, serials, base));
  }, []);

  const loadBootstrap = useCallback(async (): Promise<void> => {
    const db = dbRef.current;
    const auth = authRef.current;
    if (!db || !auth) return;
    const payload: BootstrapPayload = await fetchBootstrap(auth);
    await applyBootstrap(db, payload);
    setTemplates(payload.print_templates);
    setDevice(payload.device as unknown as ReceptionDevice);
    const patients = await fetchRecentPatients(auth, 200).catch(() => []);
    if (patients.length > 0) await cachePatients(db, patients);
    const today = payload.days[0];
    if (today) await renderFromCache(today as unknown as Board);
    useConnection.getState().setSyncFacts({ activeBlock: activeBlockFacts(await db.blocks.toArray()) });
  }, [renderFromCache]);

  // open the device database when a pointer exists; boot the sync engine
  useEffect(() => {
    let cancelled = false;
    if (!pointer) { dbRef.current = null; logRef.current = null; issuerRef.current = null; syncRef.current?.stop(); syncRef.current = null; return undefined; }
    const db = new ReceptionDB(pointer.tenantId, pointer.devicePublicId);
    const log = new EventLog(db, () => actorRef.current ?? '');
    dbRef.current = db;
    logRef.current = log;
    issuerRef.current = new BlockIssuer(db, log);
    void (async () => {
      const token = await db.getMeta<string>(META_KEYS.deviceToken);
      const cachedActor = await db.getMeta<{ public_id: string }>(META_KEYS.actorUser);
      const cachedDevice = await db.getMeta<ReceptionDevice>(META_KEYS.device);
      const cachedTemplates = await db.printTemplates.toArray();
      if (cancelled) return;
      if (cachedDevice) setDevice(cachedDevice);
      if (cachedTemplates.length > 0) setTemplates(cachedTemplates);
      if (!actorRef.current && cachedActor) actorRef.current = cachedActor.public_id;
      if (token && actorRef.current) {
        authRef.current = { token, actorPublicId: actorRef.current, appVersion: APP_VERSION };
        const engine = new SyncEngine({
          db, log, appVersion: APP_VERSION, lockName: `bp-sync-${pointer.devicePublicId}`,
          transport: { sync: (body) => syncEvents(authRef.current as DeviceAuth, body), resolve: (body) => resolveConflict(authRef.current as DeviceAuth, body) },
          onBootstrapStale: () => { void loadBootstrap(); },
          onAccepted: async () => { await useConflicts.getState().refresh(db); await renderFromCache(board); },
        });
        syncRef.current = engine;
        engine.start();
        await useConflicts.getState().refresh(db);
        await renderFromCache(board);
        if (useConnection.getState().mode !== 'offline') await loadBootstrap().catch(() => undefined);
        else await renderFromCache(board);
      }
    })();
    return () => { cancelled = true; syncRef.current?.stop(); syncRef.current = null; db.close(); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pointer]);

  // refresh the board: device board when registered, else the panel JSON; offline → cache
  const refresh = useCallback(async (): Promise<void> => {
    const state = useConnection.getState().mode;
    if (state === 'offline') { await renderFromCache(board); return; }
    try {
      const next: ServerBoard = authRef.current ? await fetchDeviceBoard(authRef.current, board.date) : await fetchBoard(board.date);
      if (dbRef.current) { await applyBoard(dbRef.current, next); await renderFromCache(next as unknown as Board); } else setBoard(next as unknown as Board);
    } catch {
      await renderFromCache(board);
    }
  }, [board, renderFromCache]);

  // degraded: poll every 5 s; online: the reception channel pushes; offline: nothing to fetch
  useEffect(() => {
    if (mode !== 'degraded') return undefined;
    const id = setInterval(() => { void refresh(); }, BOARD_POLL_MS);
    return () => clearInterval(id);
  }, [mode, refresh]);
  useEffect(() => {
    if (!authRef.current) return undefined;
    const id = setInterval(() => { if (useConnection.getState().mode !== 'offline') void loadBootstrap().catch(() => undefined); }, BOOTSTRAP_REFRESH_MS);
    return () => clearInterval(id);
  }, [loadBootstrap, pointer]);
  useEffect(() => { if (mode === 'offline') void renderFromCache(board); }, [mode]); // eslint-disable-line react-hooks/exhaustive-deps

  useChannel(options.channel, {
    'board.updated': () => { void refresh(); },
    'serial.status_changed': () => { void refresh(); },
    'serial.called': () => { void refresh(); },
    'session.delayed': () => { void refresh(); },
    'session.cancelled': () => { void refresh(); },
  }, { enabled: mode === 'online' && Boolean(options.channel) });

  const registerFromResponse = useCallback(async (r: RegisterDeviceResponse, tenantId: string, actorPublicId: string): Promise<void> => {
    const next: DevicePointer = { tenantId, devicePublicId: r.device.public_id };
    const db = new ReceptionDB(next.tenantId, next.devicePublicId);
    await db.setMeta(META_KEYS.deviceToken, r.token);
    await db.setMeta(META_KEYS.device, r.device);
    await db.setMeta(META_KEYS.actorUser, { public_id: actorPublicId });
    db.close();
    actorRef.current = actorPublicId;
    writePointer(next);
    setPointer(next);
  }, []);

  const forgetDevice = useCallback(async (): Promise<void> => {
    writePointer(null);
    setPointer(null);
    setDevice(null);
    setBlocks([]);
    authRef.current = null;
  }, []);

  const ensureBlocks = useCallback(async (session: BoardSession): Promise<void> => {
    const db = dbRef.current;
    const auth = authRef.current;
    if (!db || !auth || useConnection.getState().mode === 'offline') return;
    if (!(session.status === 'scheduled' || session.status === 'running' || session.status === 'paused')) return;
    const active = (await db.blocks.where('[sessionId+status]').equals([session.public_id, 'active']).toArray()).filter((b) => b.nextNumber <= b.rangeEnd);
    if (!BlockIssuer.needsTopUp(active, settings.topupThreshold, settings.maxActiveBlocks)) return;
    try {
      const r = await leaseBlock(auth, session.public_id, settings.defaultBlockSize);
      await applyBlocks(db, [...(await db.blocks.toArray()).filter((b) => b.status === 'active').map((b) => ({ public_id: b.publicId, range_start: b.rangeStart, range_end: b.rangeEnd, next_number: b.nextNumber, status: b.status, expires_at: b.expiresAt, revoked_at: null, session: b.sessionId, session_code: b.sessionCode })), r.block]);
    } catch {
      /* pool exhausted / limit reached: the board shows what is left */
    }
    const all = await db.blocks.toArray();
    setBlocks(all);
    useConnection.getState().setSyncFacts({ activeBlock: activeBlockFacts(all) });
  }, [settings]);

  const afterEvent = useCallback(async (): Promise<void> => {
    syncRef.current?.requestFlush();
    if (dbRef.current) useConnection.getState().setSyncFacts({ activeBlock: activeBlockFacts(await dbRef.current.blocks.toArray()) });
    await renderFromCache(board);
  }, [board, renderFromCache]);

  const issueOffline = useCallback(async (input: DeskIssueInput): Promise<CachedSerial> => {
    const issuer = issuerRef.current;
    const log = logRef.current;
    const db = dbRef.current;
    if (!issuer || !log || !db) throw new Error('device not registered');
    let dependsOn: string | undefined;
    let patientRef = input.patientRef;
    if (input.stub) {
      const register = await log.append({ type: 'register_patient', payload: { localId: input.stub.localId, mobile: input.stub.mobile, name: input.stub.name, sex: input.stub.sex ?? null, ageYears: input.stub.ageYears ?? null, dob: null, relationToHolder: null } });
      await db.patients.put({ publicId: `local:${input.stub.localId}`, localId: input.stub.localId, mobile: input.stub.mobile, name: input.stub.name, nameTokens: tokens(input.stub.name), ...(input.stub.sex ? { sex: input.stub.sex } : {}), ...(input.stub.ageYears !== undefined ? { ageYears: input.stub.ageYears } : {}), updatedAt: Date.now() });
      dependsOn = register.clientEventId;
      patientRef = `local:${input.stub.localId}`;
    }
    const result = await issuer.issue(input.session.public_id, { patientRef, patientName: input.patientName, mobileMasked: input.mobileMasked, priority: input.priority ?? 'normal', appointmentType: input.appointmentType ?? 'new', feeAmountPaisa: input.feeAmountPaisa, ...(dependsOn ? { dependsOn } : {}) });
    await afterEvent();
    return result.serial;
  }, [afterEvent]);

  const checkInOffline = useCallback(async (serialRef: string): Promise<void> => {
    const log = logRef.current;
    const db = dbRef.current;
    if (!log || !db) throw new Error('device not registered');
    const serial = await db.serials.get(serialRef);
    await log.append({ type: 'check_in', payload: { serialRef }, ...(serial?.sessionId ? { sessionId: serial.sessionId } : {}), ...(serial?.clientEventId && serial.local ? { dependsOn: serial.clientEventId } : {}) });
    if (serial) await db.serials.update(serialRef, { status: 'checked_in', checkedInAt: new Date().toISOString(), updatedAt: Date.now() });
    await afterEvent();
  }, [afterEvent]);

  const collectCashOffline = useCallback(async (serialRef: string, amountPaisa: number, note?: string): Promise<string> => {
    const log = logRef.current;
    const db = dbRef.current;
    if (!log || !db) throw new Error('device not registered');
    const counter = ((await db.getMeta<number>(META_KEYS.receiptCounter)) ?? 0) + 1;
    await db.setMeta(META_KEYS.receiptCounter, counter);
    const receiptNo = `D${device?.number ?? 0}-${String(counter).padStart(6, '0')}`;
    const serial = await db.serials.get(serialRef);
    await log.append({ type: 'collect_cash', payload: { serialRef, amount: amountPaisa, currency: 'BDT', receiptNo, note: note ?? null }, ...(serial?.sessionId ? { sessionId: serial.sessionId } : {}), ...(serial?.clientEventId && serial.local ? { dependsOn: serial.clientEventId } : {}) });
    if (serial) await db.serials.update(serialRef, { cashCollected: amountPaisa, receiptNo, paymentStatus: amountPaisa >= (serial.feePaisa ?? 0) ? 'paid' : 'partial', updatedAt: Date.now() });
    await afterEvent();
    return receiptNo;
  }, [afterEvent, device]);

  const printed = useCallback(async (serialRef: string, format: PrintFormatId, copies = 1): Promise<void> => {
    const log = logRef.current;
    const db = dbRef.current;
    if (!log || !db) return;
    const serial = await db.serials.get(serialRef);
    await log.append({ type: 'print_token', payload: { serialRef, format, copies }, ...(serial?.sessionId ? { sessionId: serial.sessionId } : {}), ...(serial?.clientEventId && serial.local ? { dependsOn: serial.clientEventId } : {}) });
    syncRef.current?.requestFlush();
  }, []);

  const voidLocal = useCallback(async (clientEventId: string, reason: string): Promise<void> => {
    await issuerRef.current?.voidLocal(clientEventId, reason);
    await afterEvent();
  }, [afterEvent]);

  const resolve = useCallback(async (clientEventId: string, resolution: ConflictResolution, params: Record<string, unknown> = {}): Promise<void> => {
    const engine = syncRef.current;
    const db = dbRef.current;
    if (!engine || !db) throw new Error('device not registered');
    await engine.resolve(clientEventId, resolution, params);
    await useConflicts.getState().refresh(db);
    await refresh();
  }, [refresh]);

  const flush = useCallback(async (): Promise<void> => { await syncRef.current?.flush(); }, []);

  const cachedPatients = useCallback(async (q: string) => {
    const db = dbRef.current;
    if (!db || q.trim() === '') return [];
    const term = q.trim().toLowerCase();
    const digits = term.replace(/\D/g, '');
    const rows = digits.length >= 4
      ? await db.patients.filter((p) => p.mobile.includes(digits)).limit(20).toArray()
      : await db.patients.where('nameTokens').startsWith(term).limit(20).toArray();
    return rows.map((p) => ({ publicId: p.publicId, name: p.name, mobile: p.mobile, ageText: p.ageText ?? null }));
  }, []);

  return {
    mode, board, registered: pointer !== null, device, actorPublicId: actorRef.current, blocks, templates, settings,
    refresh, registerFromResponse, forgetDevice, ensureBlocks, issueOffline, checkInOffline, collectCashOffline, printed, voidLocal, resolve, flush, cachedPatients,
  };
}

function activeBlockFacts(blocks: CachedBlock[]): { displayFrom: string; displayTo: string; remaining: number } | null {
  const active = blocks.filter((b) => b.status === 'active' && b.nextNumber <= b.rangeEnd).sort((a, b) => a.rangeStart - b.rangeStart);
  const first = active[0];
  if (!first) return null;
  const pad = (n: number): string => `${first.sessionCode}-${String(n).padStart(3, '0')}`;
  return { displayFrom: pad(first.nextNumber), displayTo: pad(active[active.length - 1]?.rangeEnd ?? first.rangeEnd), remaining: BlockIssuer.remaining(active) };
}

/** `sha256(userAgent + screen + platform)` — the stable client fingerprint of OFFLINE §2.1. */
export async function deviceFingerprint(): Promise<string> {
  const raw = `${navigator.userAgent}|${screen.width}x${screen.height}|${navigator.platform}`;
  if (typeof crypto?.subtle?.digest === 'function') {
    const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(raw));
    return Array.from(new Uint8Array(buf)).map((b) => b.toString(16).padStart(2, '0')).join('');
  }
  return `ulid-${ulid()}`;
}
