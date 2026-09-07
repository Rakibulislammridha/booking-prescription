import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { loadIndexedDb, uniqueDevice } from './setup';
import { ReceptionDB, META_KEYS } from '../db';
import { EventLog } from '../eventLog';
import { BlockIssuer, BlockExhausted } from '../blocks';
import { isUlid } from '../../ulid';

const hasIdb = await loadIndexedDb();

describe.skipIf(!hasIdb)('event log (OFFLINE §6)', () => {
  let db: ReceptionDB;
  let log: EventLog;

  let deviceId: string;

  beforeEach(async () => {
    deviceId = uniqueDevice();
    db = new ReceptionDB('t1', deviceId);
    log = new EventLog(db, () => 'usr_1');
    await db.sessions.put({ publicId: 'ses_A', date: '2026-09-06', branchId: 'brn', doctorId: 'doc', code: 'A', status: 'running', mode: 'serial', plannedStartAt: '', plannedEndAt: '', delayMinutes: 0, doctor: { publicId: 'doc', slug: 'dr', name: 'Dr', nameBn: null, room: null }, nowServing: null, counts: {}, remaining: { counter: 0, released: 0, buffer: 0, online: 0, counterInBlocks: 0 }, feeNewPaisa: 50000, feeFollowupPaisa: 30000, maxSerials: 30, version: 1, updatedAt: 0 });
    await db.blocks.put({ publicId: 'blk_1', sessionId: 'ses_A', sessionCode: 'A', rangeStart: 21, rangeEnd: 23, nextNumber: 21, status: 'active', expiresAt: null });
  });
  afterEach(async () => { db.close(); });

  it('append_assigns_monotonic_sequence_no_and_ulid_in_one_transaction', async () => {
    const a = await log.append({ type: 'print_token', payload: { serialRef: 'x' } });
    const b = await log.append({ type: 'print_token', payload: { serialRef: 'y' } });
    const c = await log.append({ type: 'print_token', payload: { serialRef: 'z' } });
    expect([a.sequenceNo, b.sequenceNo, c.sequenceNo]).toEqual([1, 2, 3]);
    expect(isUlid(a.clientEventId)).toBe(true);
    expect(a.clientEventId < b.clientEventId).toBe(true);
    expect(await db.getMeta<number>(META_KEYS.sequenceNo)).toBe(3);
    expect(a.status).toBe('pending');
    expect(a.actorUserId).toBe('usr_1');
    // concurrent appends still get distinct numbers
    const burst = await Promise.all([1, 2, 3, 4, 5].map((i) => log.append({ type: 'print_token', payload: { i } })));
    expect(new Set(burst.map((e) => e.sequenceNo)).size).toBe(5);
    expect(Math.max(...burst.map((e) => e.sequenceNo))).toBe(8);
  });

  it('issue_from_block_advances_cursor_and_refuses_when_exhausted', async () => {
    const issuer = new BlockIssuer(db, log);
    const first = await issuer.issue('ses_A', { patientRef: 'pat_1', patientName: 'Rahima', mobileMasked: '017*****678', feeAmountPaisa: 50000 });
    expect(first.serial.number).toBe(21);
    expect(first.serial.displayCode).toBe('A-021');
    expect(first.serial.publicId).toBe('local:' + first.event.clientEventId);
    expect(first.serial.local).toBe(true);
    expect(first.event.type).toBe('issue_serial');
    expect(first.event.payload).toMatchObject({ sessionId: 'ses_A', blockId: 'blk_1', number: 21, displayCode: 'A-021', patientRef: 'pat_1', feeSnapshot: { amount: 50000, currency: 'BDT' } });
    expect((await db.blocks.get('blk_1'))?.nextNumber).toBe(22);

    await issuer.issue('ses_A', { patientRef: 'pat_2', patientName: 'B', mobileMasked: '', feeAmountPaisa: 0 });
    const last = await issuer.issue('ses_A', { patientRef: 'pat_3', patientName: 'C', mobileMasked: '', feeAmountPaisa: 0 });
    expect(last.block.status).toBe('exhausted');
    await expect(issuer.issue('ses_A', { patientRef: 'pat_4', patientName: 'D', mobileMasked: '', feeAmountPaisa: 0 })).rejects.toBeInstanceOf(BlockExhausted);
    expect(await db.serials.where('sessionId').equals('ses_A').count()).toBe(3);
    expect(BlockIssuer.needsTopUp([last.block], 3)).toBe(true);
    expect(BlockIssuer.needsTopUp([{ ...last.block, status: 'active', nextNumber: 21 }, { ...last.block, publicId: 'b2', status: 'active', nextNumber: 21 }], 3)).toBe(false);
  });

  it('two_tabs_cannot_issue_same_number', async () => {
    const tab1 = new ReceptionDB('t1', deviceId);
    const tab2 = new ReceptionDB('t1', deviceId);
    const i1 = new BlockIssuer(tab1, new EventLog(tab1, () => 'u'));
    const i2 = new BlockIssuer(tab2, new EventLog(tab2, () => 'u'));
    const results = await Promise.all([
      i1.issue('ses_A', { patientRef: 'p1', patientName: 'a', mobileMasked: '', feeAmountPaisa: 0 }),
      i2.issue('ses_A', { patientRef: 'p2', patientName: 'b', mobileMasked: '', feeAmountPaisa: 0 }),
      i1.issue('ses_A', { patientRef: 'p3', patientName: 'c', mobileMasked: '', feeAmountPaisa: 0 }),
    ]);
    const numbers = results.map((r) => r.serial.number).sort();
    expect(numbers).toEqual([21, 22, 23]);
    const sequences = results.map((r) => r.event.sequenceNo).sort();
    expect(sequences).toEqual([1, 2, 3]);
    tab1.close(); tab2.close();
  });

  it('void_local_restores_cursor_only_for_last_number', async () => {
    const issuer = new BlockIssuer(db, log);
    const a = await issuer.issue('ses_A', { patientRef: 'p1', patientName: 'a', mobileMasked: '', feeAmountPaisa: 0 });
    const b = await issuer.issue('ses_A', { patientRef: 'p2', patientName: 'b', mobileMasked: '', feeAmountPaisa: 0 });
    await log.append({ type: 'check_in', payload: { serialRef: b.serial.publicId }, dependsOn: b.event.clientEventId });

    await issuer.voidLocal(a.event.clientEventId, 'misprint');   // not the last number: cursor stays at 23
    expect((await db.blocks.get('blk_1'))?.nextNumber).toBe(23);
    expect(await db.events.get(a.event.clientEventId)).toBeUndefined();
    expect(await db.serials.get(a.serial.publicId)).toBeUndefined();

    await issuer.voidLocal(b.event.clientEventId, 'duplicate');  // the last number: cursor restored, dependants removed
    expect((await db.blocks.get('blk_1'))?.nextNumber).toBe(22);
    expect(await db.events.filter((e) => e.type === 'check_in').count()).toBe(0);
    const voids = await db.events.where('status').equals('pending').toArray();
    expect(voids.map((e) => e.type)).toEqual(['void_local', 'void_local']);
    expect(voids[1]?.payload).toMatchObject({ voidedClientEventId: b.event.clientEventId, reason: 'duplicate', number: 22 });
  });
});
