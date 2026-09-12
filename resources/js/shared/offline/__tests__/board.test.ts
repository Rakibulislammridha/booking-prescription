// The board's two reading rules, which BOTH paths that build a board have to apply identically — the server JSON
// rendered straight into the tile, and the Dexie cache a registered desk renders from even while it is online
// (useDesk.boardFromCache). If they ever disagreed, the same clinic would read a different order on the same
// screen depending on whether the device happened to be registered.
import { describe, expect, it } from 'vitest';
import { byNumber, nextToCall } from '../board';

const row = (number: number, position: number, status = 'booked') => ({ number, position, status });

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
