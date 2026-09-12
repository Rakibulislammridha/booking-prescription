// A registered desk renders the board out of Dexie even while it is online (useDesk.refresh writes the server board
// into the cache and re-renders from it), so the two new board answers — "has the compounder been?" and "is this
// number only held?" — have to survive the round trip through the cache or they are invisible on the very device
// the clinic uses. This pins the round trip, and pins that a row which cannot have vitals comes back saying so
// (null) rather than saying "no reading" about a patient who has not arrived.
import { describe, expect, it } from 'vitest';
import { toCachedSerial, type ServerSerial } from '@shared/offline';
import type { CachedSession } from '@shared/offline';
import { boardFromCache } from '../useDesk';
import type { Board } from '@shared/types/models';

const serial = (over: Partial<ServerSerial> = {}): ServerSerial => ({
  public_id: 'ser_1', display_code: 'A-001', number: 1, position: 1_000_000, status: 'checked_in', priority: 'normal', source: 'counter',
  patient: { public_id: 'pat_1', name: 'রহিমা বেগম', mobile_masked: '017*****21' },
  appointment: { public_id: 'apt_1', fee_paisa: 50_000, payment_status: 'unpaid', status: 'confirmed', hold_expires_at: null },
  vitals: { recorded: true, readings: 2, recorded_at: '2026-09-09T04:10:00Z', reviewed: false },
  checked_in_at: '2026-09-09T03:55:00Z',
  ...over,
});

const session: CachedSession = {
  publicId: 'ses_1', date: '2026-09-09', branchId: 'brn_1', doctorId: 'doc_1', code: 'A', status: 'running', mode: 'serial',
  plannedStartAt: '2026-09-09T03:00:00Z', plannedEndAt: '2026-09-09T07:00:00Z', delayMinutes: 0,
  doctor: { publicId: 'doc_1', slug: 'dr-a', name: 'Dr A', nameBn: null, room: null },
  nowServing: null, counts: {}, remaining: { counter: 5, released: 0, buffer: 2, online: 3, counterInBlocks: 0 },
  feeNewPaisa: 80_000, feeFollowupPaisa: 50_000, maxSerials: 30, version: 1, updatedAt: Date.now(),
};

const base: Board = { date: '2026-09-09', branch: { public_id: 'brn_1', name: 'Main', code: 'DHK', slug: 'main' }, sessions: [], generated_at: '2026-09-09T04:00:00Z' };

const rowFor = (s: ServerSerial) => boardFromCache([session], [toCachedSerial(s, session.publicId)], base).sessions[0]?.serials[0];

describe('boardFromCache (OFFLINE §5.1)', () => {
  it('keeps the vitals answer across the cache for a row that can have one', () => {
    const row = rowFor(serial());
    expect(row?.vitals).toEqual({ recorded: true, readings: 2, recorded_at: '2026-09-09T04:10:00Z', reviewed: false });
  });

  it('gives no answer at all for a row the question is not about', () => {
    expect(rowFor(serial({ status: 'booked', vitals: null }))?.vitals).toBeNull();
    expect(rowFor(serial({ status: 'completed', vitals: null }))?.vitals).toBeNull();
    expect(rowFor(serial({ status: 'in_consultation', vitals: null }))?.vitals).toEqual({ recorded: false, readings: 0, recorded_at: null, reviewed: false });
  });

  it('keeps a payment hold and its absolute deadline, which stays true while the desk is offline', () => {
    const row = rowFor(serial({
      status: 'booked',
      vitals: null,
      appointment: { public_id: 'apt_2', fee_paisa: 80_000, payment_status: 'unpaid', status: 'pending', hold_expires_at: '2026-09-09T04:30:00Z' },
    }));

    expect(row?.appointment?.status).toBe('pending');
    expect(row?.appointment?.hold_expires_at).toBe('2026-09-09T04:30:00Z');
  });

  it('a serial issued offline on this device is an ordinary confirmed row, not a hold', () => {
    const row = rowFor(serial({ appointment: { public_id: 'apt_3', fee_paisa: 50_000, payment_status: 'unpaid' } }));
    expect(row?.appointment?.status).toBe('confirmed');
    expect(row?.appointment?.hold_expires_at).toBeNull();
  });
});

/**
 * The order the desk reads and the row "Call next" would take are NOT cached fields — they are re-derived from
 * `number` / `position` / `status`, which every cached row already carries (shared/offline/board.ts). That is the
 * point: a patient this device checked in while offline moves the Next chip here exactly as the server will say
 * once the event syncs, instead of the board waiting for a server answer it cannot get.
 */
describe('boardFromCache: number order and the Next chip', () => {
  const sessionOf = (...rows: ServerSerial[]) =>
    boardFromCache([session], rows.map((s) => toCachedSerial(s, session.publicId)), base).sessions[0];

  const at = (number: number, position: number, status: string): ServerSerial =>
    serial({ public_id: `ser_${number}`, display_code: `B-${String(number).padStart(3, '0')}`, number, position, status, vitals: null });

  it('lists the cached rows by serial number, not by queue position', () => {
    // The board the owner complained about: position order reads B-001, B-011, B-012, B-002, B-003.
    const built = sessionOf(
      at(1, 1_000_000, 'checked_in'),
      at(11, 2_000_000, 'booked'),
      at(12, 3_000_000, 'booked'),
      at(2, 4_000_000, 'checked_in'),
      at(3, 5_000_000, 'booked'),
    );

    expect(built?.serials.map((s) => s.display_code)).toEqual(['B-001', 'B-002', 'B-003', 'B-011', 'B-012']);
  });

  it('names the row CallNext would take, including after a priority insert', () => {
    const built = sessionOf(
      at(1, 3_000_000, 'checked_in'),
      at(2, 4_000_000, 'checked_in'),
      at(9, 1_500_000, 'checked_in'),   // emergency: moved ahead in the queue, still last in the list
    );

    expect(built?.serials.map((s) => s.display_code)).toEqual(['B-001', 'B-002', 'B-009']);
    expect(built?.next_serial).toEqual({ public_id: 'ser_9', display_code: 'B-009' });
  });

  it('names nobody when nobody has arrived', () => {
    expect(sessionOf(at(1, 1_000_000, 'booked'), at(2, 2_000_000, 'completed'))?.next_serial).toBeNull();
  });

  it('keeps the issued prescription handle across the cache, and only the handle', () => {
    const row = rowFor(serial({ prescription: { public_id: 'rx_1', verification_code: 'A1B2C3D4', version: 2 } }));

    expect(row?.prescription).toEqual({ public_id: 'rx_1', verification_code: 'A1B2C3D4', version: 2 });
    expect(Object.keys(row?.prescription ?? {})).toEqual(['public_id', 'verification_code', 'version']);
  });

  it('says there is nothing to print when the row has no issued prescription', () => {
    expect(rowFor(serial())?.prescription).toBeNull();
    expect(rowFor(serial({ prescription: null }))?.prescription).toBeNull();
  });
});
