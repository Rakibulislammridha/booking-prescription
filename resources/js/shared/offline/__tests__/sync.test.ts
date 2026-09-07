import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { loadIndexedDb, uniqueDevice, fakeTimers } from './setup';
import { ReceptionDB } from '../db';
import { EventLog } from '../eventLog';
import { BlockIssuer } from '../blocks';
import { SyncEngine, BACKOFF_MIN_MS, SYNC_BATCH, toWire } from '../sync';
import { listConflicts, useConflicts } from '../conflicts';
import { resetConnectionForTests, useConnection } from '../../connection/store';
import type { SyncRequest, SyncResponse, SyncEventResult } from '../types';

const hasIdb = await loadIndexedDb();

function accepted(id: string, extra: Record<string, unknown> = {}): SyncEventResult {
  return { client_event_id: id, status: 'accepted', server_result: extra };
}

describe.skipIf(!hasIdb)('sync engine (OFFLINE §7.3)', () => {
  let db: ReceptionDB;
  let log: EventLog;
  let issuer: BlockIssuer;
  let calls: SyncRequest[];
  let responder: (body: SyncRequest) => Promise<SyncResponse>;

  const engine = (extra: Partial<ConstructorParameters<typeof SyncEngine>[0]> = {}): SyncEngine => new SyncEngine({
    db, log, appVersion: '1.0.0', lockName: 'bp-sync-dev1',
    transport: { sync: async (body) => { calls.push(body); return responder(body); }, resolve: async () => { throw new Error('unused'); } },
    ...extra,
  });

  beforeEach(async () => {
    fakeTimers();
    resetConnectionForTests({ mode: 'degraded', lastHeartbeatOkAt: Date.now() });
    db = new ReceptionDB('t1', uniqueDevice());
    log = new EventLog(db, () => 'usr_1');
    issuer = new BlockIssuer(db, log);
    calls = [];
    responder = async (body) => ({ server_time: 'now', results: body.events.map((e) => accepted(e.client_event_id)), bootstrap_stale: false });
    await db.sessions.put({ publicId: 'ses_A', date: '2026-09-06', branchId: 'brn', doctorId: 'doc', code: 'A', status: 'running', mode: 'serial', plannedStartAt: '', plannedEndAt: '', delayMinutes: 0, doctor: { publicId: 'doc', slug: 'dr', name: 'Dr', nameBn: null, room: null }, nowServing: null, counts: {}, remaining: { counter: 0, released: 0, buffer: 0, online: 0, counterInBlocks: 0 }, feeNewPaisa: 50000, feeFollowupPaisa: 30000, maxSerials: 30, version: 1, updatedAt: 0 });
    await db.blocks.put({ publicId: 'blk_1', sessionId: 'ses_A', sessionCode: 'A', rangeStart: 1, rangeEnd: 300, nextNumber: 1, status: 'active', expiresAt: null });
  });
  afterEach(() => { db.close(); vi.useRealTimers(); });

  it('flush_sends_pending_in_seq_order_max_200_and_marks_sending', async () => {
    for (let i = 0; i < 205; i++) await log.append({ type: 'print_token', payload: { serialRef: `s${i}` } });
    let observed: string[] = [];
    responder = async (body) => {
      observed = (await db.events.where('status').equals('sending').toArray()).map((e) => e.clientEventId);
      return { server_time: 'now', results: body.events.map((e) => accepted(e.client_event_id)), bootstrap_stale: false };
    };
    const e = engine();
    expect(await e.flush()).toBe('done');
    expect(calls).toHaveLength(1);
    expect(calls[0]?.events).toHaveLength(SYNC_BATCH);
    expect(calls[0]?.events.map((x) => x.sequence_no)).toEqual([...Array(200).keys()].map((i) => i + 1));
    expect(calls[0]?.sequence_no_from).toBe(1);
    expect(observed).toHaveLength(200);
    expect(await db.events.where('status').equals('accepted').count()).toBe(200);
    expect(await db.events.where('status').equals('pending').count()).toBe(5);
    await e.flush();
    expect(calls[1]?.events).toHaveLength(5);
    expect(await db.events.where('status').equals('pending').count()).toBe(0);
    const wire = toWire((await db.events.toArray())[0]!);
    expect(wire).toMatchObject({ type: 'print_token', actor_user_id: 'usr_1', depends_on: null });
  });

  it('accepted_rekeys_local_ids_in_serials_and_dependants', async () => {
    const register = await log.append({ type: 'register_patient', payload: { localId: 'L1', mobile: '+8801712345678', name: 'Rahima' } });
    await db.patients.put({ publicId: 'local:L1', localId: 'L1', mobile: '+8801712345678', name: 'Rahima', nameTokens: ['rahima'], updatedAt: 0 });
    const issued = await issuer.issue('ses_A', { patientRef: 'local:L1', patientName: 'Rahima', mobileMasked: '017*****678', feeAmountPaisa: 50000, dependsOn: register.clientEventId });
    const checkIn = await log.append({ type: 'check_in', payload: { serialRef: issued.serial.publicId }, dependsOn: issued.event.clientEventId });
    const cash = await log.append({ type: 'collect_cash', payload: { serialRef: issued.serial.publicId, amount: 50000, receiptNo: 'D1-000001' }, dependsOn: issued.event.clientEventId });

    // the server answers the first two now and parks the cash event as pending (dependency), replay later
    responder = async (body) => ({ server_time: 'now', bootstrap_stale: true, results: body.events.map((e) => {
      if (e.client_event_id === register.clientEventId) return accepted(e.client_event_id, { patient: { public_id: 'PAT1', name: 'Rahima Begum', patient_code: 'P-000001' } });
      if (e.client_event_id === issued.event.clientEventId) return accepted(e.client_event_id, { serial: { public_id: 'SER1', display_code: 'A-001', number: 1, position: 1, status: 'booked', priority: 'normal', source: 'offline', patient: { public_id: 'PAT1', name: 'Rahima Begum', mobile_masked: '017*****678' }, appointment: { public_id: 'APT1', fee_paisa: 50000, payment_status: 'unpaid' } } });
      if (e.client_event_id === checkIn.clientEventId) return accepted(e.client_event_id, { serial: { public_id: 'SER1', display_code: 'A-001', number: 1, position: 1, status: 'checked_in', priority: 'normal', source: 'offline', patient: null, appointment: null, checked_in_at: '2026-09-06T05:00:00Z' } });
      return { client_event_id: e.client_event_id, status: 'pending', conflict_reason: 'dependency_unresolved', server_result: { depends_on: issued.event.clientEventId } };
    }) });
    const stale = vi.fn();
    const e = engine({ onBootstrapStale: stale });
    expect(await e.flush()).toBe('done');

    expect(await db.serials.get(issued.serial.publicId)).toBeUndefined();
    const server = await db.serials.get('SER1');
    expect(server).toMatchObject({ number: 1, status: 'checked_in', local: false, patientRef: 'PAT1', patientName: 'Rahima Begum', clientEventId: issued.event.clientEventId, checkedInAt: '2026-09-06T05:00:00Z' });
    expect(await db.patients.get('local:L1')).toBeUndefined();
    expect(await db.patients.get('PAT1')).toMatchObject({ localId: 'L1', name: 'Rahima Begum', patientCode: 'P-000001' });
    const parked = await db.events.get(cash.clientEventId);
    expect(parked?.status).toBe('deferred');
    expect(parked?.payload.serialRef).toBe('SER1');          // dependant rewritten to the server id
    expect(stale).toHaveBeenCalledTimes(1);
    expect(useConnection.getState().pendingEvents).toBe(1);
  });

  it('conflict_sets_sync_phase_and_keeps_event', async () => {
    const register = await log.append({ type: 'register_patient', payload: { localId: 'L1', mobile: '+8801712345678', name: 'Rahima' } });
    const issued = await issuer.issue('ses_A', { patientRef: 'local:L1', patientName: 'Rahima', mobileMasked: '', feeAmountPaisa: 0, dependsOn: register.clientEventId });
    responder = async (body) => ({ server_time: 'now', bootstrap_stale: false, results: body.events.map((e) => e.client_event_id === register.clientEventId
      ? { client_event_id: e.client_event_id, status: 'conflict' as const, conflict_reason: 'duplicate_patient', server_result: { candidates: [{ public_id: 'PAT9', name: 'Rahima Begum' }], stub: { localId: 'L1' } } }
      : { client_event_id: e.client_event_id, status: 'pending' as const, conflict_reason: 'dependency_unresolved', server_result: { depends_on: register.clientEventId } }) });
    const e = engine();
    await e.flush();
    const s = useConnection.getState();
    expect(s.conflicts).toBe(1);
    expect(s.syncPhase).toBe('conflicts');
    expect(s.pendingEvents).toBe(1);
    expect((await db.events.get(register.clientEventId))?.status).toBe('conflict');
    expect((await db.events.get(issued.event.clientEventId))?.status).toBe('deferred');
    const cards = await listConflicts(db);
    expect(cards).toHaveLength(1);
    expect(cards[0]).toMatchObject({ reason: 'duplicate_patient', resolutions: ['link_patient', 'family_member'], type: 'register_patient' });
    expect(cards[0]?.serverResult).toMatchObject({ candidates: [{ public_id: 'PAT9' }] });
    await useConflicts.getState().refresh(db);
    expect(useConflicts.getState().cards).toHaveLength(1);
    // never auto-merged: another flush re-sends only the deferred dependant (the server answers pending again)
    await e.flush();
    expect(calls[1]?.events.map((x) => x.client_event_id)).toEqual([issued.event.clientEventId]);
    expect((await db.events.get(register.clientEventId))?.status).toBe('conflict');
  });

  it('pending_dependency_unresolved_is_resent_after_resolution', async () => {
    const register = await log.append({ type: 'register_patient', payload: { localId: 'L1', mobile: '+8801712345678', name: 'Rahima' } });
    const issued = await issuer.issue('ses_A', { patientRef: 'local:L1', patientName: 'Rahima', mobileMasked: '', feeAmountPaisa: 0, dependsOn: register.clientEventId });
    let linked = false;
    responder = async (body) => ({ server_time: 'now', bootstrap_stale: false, results: body.events.map((e) => {
      if (e.client_event_id === register.clientEventId) return { client_event_id: e.client_event_id, status: 'conflict' as const, conflict_reason: 'duplicate_patient', server_result: { candidates: [] } };
      if (!linked) return { client_event_id: e.client_event_id, status: 'pending' as const, conflict_reason: 'dependency_unresolved', server_result: { depends_on: register.clientEventId } };
      expect(e.payload.patientRef).toBe('PAT9');
      return accepted(e.client_event_id, { serial: { public_id: 'SER9', display_code: 'A-001', number: 1, position: 1, status: 'booked', priority: 'normal', source: 'offline', patient: null, appointment: null } });
    }) });
    const e = new SyncEngine({ db, log, appVersion: '1', lockName: 'l', transport: {
      sync: async (body) => { calls.push(body); return responder(body); },
      resolve: async (body) => { linked = true; expect(body).toMatchObject({ client_event_id: register.clientEventId, resolution: 'link_patient', params: { patient: 'PAT9' } }); return accepted(body.client_event_id, { patient: { public_id: 'PAT9', name: 'Rahima Begum' } }); },
    } });
    await e.flush();
    expect(useConnection.getState().conflicts).toBe(1);

    const result = await e.resolve(register.clientEventId, 'link_patient', { patient: 'PAT9' });
    expect(result.status).toBe('accepted');
    expect(useConnection.getState().conflicts).toBe(0);
    expect((await db.events.get(issued.event.clientEventId))?.status).toBe('pending');
    await vi.advanceTimersByTimeAsync(400);   // debounced flush after the resolution
    await e.settle();
    expect((await db.events.get(issued.event.clientEventId))?.status).toBe('accepted');
    expect(await db.serials.get('SER9')).toBeTruthy();
    expect(useConnection.getState().pendingEvents).toBe(0);
    e.stop();
  });

  it('network_failure_returns_events_to_pending_with_backoff', async () => {
    await log.append({ type: 'print_token', payload: { serialRef: 's1' } });
    let attempts = 0;
    responder = async (body) => { attempts++; if (attempts < 3) throw new Error('ERR_NETWORK'); return { server_time: 'now', bootstrap_stale: false, results: body.events.map((x) => accepted(x.client_event_id)) }; };
    const e = engine();
    expect(await e.flush()).toBe('error');
    expect(await db.events.where('status').equals('pending').count()).toBe(1);
    expect(await db.events.where('status').equals('sending').count()).toBe(0);
    expect((await db.events.toArray())[0]?.attempts).toBe(1);
    expect(useConnection.getState().syncPhase).toBe('error');
    expect(e.currentBackoffMs()).toBe(BACKOFF_MIN_MS * 2);
    await vi.advanceTimersByTimeAsync(BACKOFF_MIN_MS);           // retry #1 fires after 2 s and fails again
    await e.settle();
    expect(attempts).toBe(2);
    expect(e.currentBackoffMs()).toBe(BACKOFF_MIN_MS * 4);
    await vi.advanceTimersByTimeAsync(BACKOFF_MIN_MS * 2);       // retry #2 after 4 s succeeds
    await e.settle();
    expect(attempts).toBe(3);
    expect(await db.events.where('status').equals('accepted').count()).toBe(1);
    expect(e.currentBackoffMs()).toBe(BACKOFF_MIN_MS);
    // offline: flush is a no-op
    useConnection.setState({ mode: 'offline' });
    expect(await e.flush()).toBe('offline');
    e.stop();
  });

  it('single_flusher_across_tabs_via_web_locks', async () => {
    vi.useRealTimers();
    for (let i = 0; i < 3; i++) await log.append({ type: 'print_token', payload: { serialRef: `s${i}` } });
    let inFlight = 0;
    let maxInFlight = 0;
    responder = async (body) => {
      inFlight++; maxInFlight = Math.max(maxInFlight, inFlight);
      await new Promise((r) => setTimeout(r, 50));
      inFlight--;
      return { server_time: 'now', bootstrap_stale: false, results: body.events.map((x) => accepted(x.client_event_id)) };
    };
    // a navigator.locks stand-in that serialises callbacks per lock name, like the browser does across tabs
    const chains = new Map<string, Promise<unknown>>();
    const requests: string[] = [];
    vi.stubGlobal('navigator', { ...navigator, locks: { request: (name: string, cb: () => Promise<unknown>) => {
      requests.push(name);
      const next = (chains.get(name) ?? Promise.resolve()).catch(() => undefined).then(cb);
      chains.set(name, next);
      return next;
    } } });
    const tabA = engine();
    const tabB = engine();
    const outcomes = await Promise.all([tabA.flush(), tabB.flush()]);
    expect(outcomes.sort()).toEqual(['done', 'idle']);
    expect(requests).toEqual(['bp-sync-dev1', 'bp-sync-dev1']);
    expect(maxInFlight).toBe(1);
    expect(calls).toHaveLength(1);
    expect(calls[0]?.events).toHaveLength(3);
    vi.unstubAllGlobals();
  });
});
