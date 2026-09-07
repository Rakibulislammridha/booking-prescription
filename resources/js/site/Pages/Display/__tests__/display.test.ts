// REALTIME.md §9.1/§9.3 — kiosk self-healing and tile pagination.
import { describe, expect, it } from 'vitest';
import { FULL_RELOAD_MS, QUIET_MS, STALE_RELOAD_MS, TILES_PER_PAGE, gridColumns, pageCount, paginate, reloadReason } from '../kiosk';

const base = { now: 10_000_000, bootedAt: 10_000_000, lastUpdateAt: 10_000_000, lastCallAt: null, anyRunning: true };

describe('kiosk self-healing', () => {
  it('reloads after 3 minutes without an update while a session is running', () => {
    expect(reloadReason({ ...base, now: base.now + STALE_RELOAD_MS - 1 })).toBeNull();
    expect(reloadReason({ ...base, now: base.now + STALE_RELOAD_MS })).toBe('stale');
  });

  it('does not reload when nothing is running', () => {
    expect(reloadReason({ ...base, now: base.now + STALE_RELOAD_MS * 4, anyRunning: false })).toBeNull();
  });

  it('does not reload before it has ever seen an update', () => {
    expect(reloadReason({ ...base, lastUpdateAt: null, now: base.now + STALE_RELOAD_MS * 4 })).toBeNull();
  });

  it('does the 6-hourly reload only at a quiet moment', () => {
    const now = base.now + FULL_RELOAD_MS;
    expect(reloadReason({ ...base, now, lastUpdateAt: now, lastCallAt: now - QUIET_MS + 1 })).toBeNull();
    expect(reloadReason({ ...base, now, lastUpdateAt: now, lastCallAt: now - QUIET_MS })).toBe('scheduled');
    expect(reloadReason({ ...base, now, lastUpdateAt: now, lastCallAt: null })).toBe('scheduled');
  });

  it('prefers the stale reason over the scheduled one', () => {
    const now = base.now + FULL_RELOAD_MS;
    expect(reloadReason({ ...base, now, lastUpdateAt: base.now, lastCallAt: null })).toBe('stale');
  });
});

describe('tile pagination', () => {
  const tiles = (n: number): number[] => Array.from({ length: n }, (_, i) => i);

  it('shows everything up to nine tiles on one page', () => {
    expect(pageCount(0)).toBe(1);
    expect(pageCount(9)).toBe(1);
    expect(paginate(tiles(9), 0)).toHaveLength(9);
  });

  it('paginates beyond nine and wraps around', () => {
    expect(pageCount(12)).toBe(2);
    expect(paginate(tiles(12), 0)).toEqual(tiles(TILES_PER_PAGE));
    expect(paginate(tiles(12), 1)).toEqual([9, 10, 11]);
    expect(paginate(tiles(12), 2)).toEqual(tiles(TILES_PER_PAGE));   // wraps
    expect(paginate(tiles(12), -1)).toEqual([9, 10, 11]);
  });

  it('auto-scales the grid: 1, 2 or 3 columns', () => {
    expect(gridColumns(1)).toBe(1);
    expect(gridColumns(2)).toBe(2);
    expect(gridColumns(4)).toBe(2);
    expect(gridColumns(5)).toBe(3);
    expect(gridColumns(9)).toBe(3);
  });
});
