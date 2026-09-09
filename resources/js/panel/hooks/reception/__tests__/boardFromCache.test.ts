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
