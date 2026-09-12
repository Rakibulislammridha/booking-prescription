// The board's reading rules, which BOTH paths that build a board have to apply identically — the server JSON
// rendered straight into the tile, and the Dexie cache a registered desk renders from even while it is online
// (useDesk.boardFromCache). If they ever disagreed, the same clinic would read a different order — or a different
// set of patients — on the same screen depending on whether the device happened to be registered.
import { describe, expect, it } from 'vitest';
import { byNumber, isAwaitingPrint, nextToCall, visibleRows } from '../board';
import { toCachedSerial, type ServerSerial } from '../bootstrap';

const row = (number: number, position: number, status = 'booked') => ({ number, position, status });

const rx = (printed: boolean) => ({ public_id: 'rx_1', verification_code: 'A1B2C3D4', version: 1, printed });
const printRow = (number: number, status: string, prescription: { printed: boolean } | null = null) =>
  ({ ...row(number, number * 1_000_000, status), prescription });

describe('byNumber (the order the waiting room reads)', () => {
  it('sorts by serial number, whatever the queue positions say', () => {
    // The board as the owner saw it: B-001, B-011, B-012, B-013, B-021, B-002, B-003, B-014 by position.
    const queueOrder = [
      row(1, 1_000_000), row(11, 2_000_000), row(12, 3_000_000), row(13, 4_000_000),
      row(21, 5_000_000), row(2, 6_000_000), row(3, 7_000_000), row(14, 8_000_000),
    ];

    expect(byNumber(queueOrder).map((r) => r.number)).toEqual([1, 2, 3, 11, 12, 13, 14, 21]);
  });

  it('does not mutate the array it was given', () => {
    const rows = [row(3, 1), row(1, 2)];
    byNumber(rows);
    expect(rows.map((r) => r.number)).toEqual([3, 1]);
  });

  it('breaks a number tie on position, so the order is total', () => {
    expect(byNumber([row(5, 9_000_000), row(5, 1_000_000)]).map((r) => r.position)).toEqual([1_000_000, 9_000_000]);
  });
});

describe('nextToCall (CallNext::nextOf, on the client)', () => {
  it('is the checked-in row with the lowest position, not the lowest number', () => {
    const rows = [
      row(1, 1_000_000, 'booked'),          // never arrived: not a candidate however low its number
      row(2, 6_000_000, 'checked_in'),
      row(3, 4_000_000, 'checked_in'),
      row(4, 2_000_000, 'in_consultation'), // already with the doctor
    ];

    expect(nextToCall(rows)?.number).toBe(3);
  });

  it('is the row a priority insert moved to the head of the queue', () => {
    const rows = [
      row(1, 3_000_000, 'checked_in'),
      row(2, 4_000_000, 'checked_in'),
      row(9, 1_500_000, 'checked_in'),   // emergency, inserted right after now-serving
    ];

    expect(nextToCall(rows)?.number).toBe(9);
  });

  it('breaks a position tie by number, exactly as ORDER BY position, number does', () => {
    expect(nextToCall([row(7, 2_000_000, 'checked_in'), row(4, 2_000_000, 'checked_in')])?.number).toBe(4);
  });

  it('is null when nobody is waiting to be called', () => {
    expect(nextToCall([])).toBeNull();
    expect(nextToCall([row(1, 1, 'booked'), row(2, 2, 'completed'), row(3, 3, 'no_show')])).toBeNull();
  });
});

/**
 * The desk's default view is "who still needs something from me?". Issuing a prescription COMPLETES the serial, so
 * without this rule the one patient who is definitely still at the counter — the one waiting for their printout —
 * would be the one the default view hid. The rule is self-clearing: it is true until somebody prints, and
 * `printed` comes off `prescriptions.printed_count`, which the print route already bumps.
 */
describe('isAwaitingPrint (who is still waiting for paper)', () => {
  it('is true only for a completed row whose issued prescription has never been printed', () => {
    expect(isAwaitingPrint(printRow(1, 'completed', rx(false)))).toBe(true);
  });

  it('is false the moment the sheet is printed — no timer, no window', () => {
    expect(isAwaitingPrint(printRow(1, 'completed', rx(true)))).toBe(false);
  });

  it('is false for a completed row with nothing to print (no prescription, or still a draft)', () => {
    // A draft never reaches the board as a handle: the server sends `prescription: null` for it.
    expect(isAwaitingPrint(printRow(1, 'completed', null))).toBe(false);
  });

  it('is false for a row that has not finished, whatever it carries', () => {
    for (const status of ['booked', 'checked_in', 'in_consultation', 'cancelled', 'no_show']) {
      expect(isAwaitingPrint(printRow(1, status, rx(false)))).toBe(false);
    }
  });
});

describe('visibleRows (the default view, and "Show all")', () => {
  const board = [
    printRow(1, 'checked_in'),
    printRow(2, 'completed', rx(false)),   // just issued: at the counter, waiting for paper
    printRow(3, 'completed', rx(true)),    // printed and gone home
    printRow(4, 'completed', null),        // finished without a prescription
    printRow(5, 'no_show'),
    printRow(6, 'booked'),
  ];

  it('shows the active rows AND the one waiting for a printout, in number order', () => {
    expect(visibleRows(board, false).map((r) => r.number)).toEqual([1, 2, 6]);
  });

  it('drops that row as soon as the prescription is printed', () => {
    const printed = board.map((r) => (r.number === 2 ? printRow(2, 'completed', rx(true)) : r));
    expect(visibleRows(printed, false).map((r) => r.number)).toEqual([1, 6]);
  });

  it('"Show all" still means everything, so the toggle can keep counting the whole list', () => {
    expect(visibleRows(board, true).map((r) => r.number)).toEqual([1, 2, 3, 4, 5, 6]);
    expect(visibleRows(board, true)).toHaveLength(board.length);
  });

  it('does not mutate the rows it was given', () => {
    const rows = [printRow(3, 'booked'), printRow(1, 'booked')];
    visibleRows(rows, false);
    expect(rows.map((r) => r.number)).toEqual([3, 1]);
  });

  /**
   * The same rule over a row that came back through the device cache: a registered desk renders the board out of
   * Dexie even while it is online, so `printed` has to survive `toCachedSerial` or the desk would decide who is on
   * screen from a field it lost. An older cached row that predates the flag reads as "not printed" — the safe
   * direction: it keeps a finished patient visible one sync too long, never hides one who is waiting.
   */
  it('applies to a row rebuilt from the device cache, and treats a missing flag as not printed', () => {
    const server = (printed: boolean | undefined): ServerSerial => ({
      public_id: 'ser_2', display_code: 'B-002', number: 2, position: 2_000_000, status: 'completed', priority: 'normal', source: 'counter',
      patient: { public_id: 'pat_2', name: 'রহিমা বেগম', mobile_masked: '017*****21' }, appointment: null, vitals: null,
      prescription: { public_id: 'rx_1', verification_code: 'A1B2C3D4', version: 1, ...(printed === undefined ? {} : { printed }) } as ServerSerial['prescription'],
    });
    const cached = (printed: boolean | undefined) => {
      const c = toCachedSerial(server(printed), 'ses_1');
      return { number: c.number, position: c.position, status: c.status, prescription: c.prescriptionId ? { printed: c.prescriptionPrinted ?? false } : null };
    };

    expect(isAwaitingPrint(cached(false))).toBe(true);
    expect(isAwaitingPrint(cached(true))).toBe(false);
    expect(isAwaitingPrint(cached(undefined))).toBe(true);
    expect(visibleRows([cached(false)], false)).toHaveLength(1);
    expect(visibleRows([cached(true)], false)).toHaveLength(0);
  });
});
