// Kiosk-mode self-healing and tile pagination (REALTIME.md §9.3). Pure functions so display.test.ts can drive them
// with a fake clock; Board.tsx owns the timers, the fullscreen request and the wake lock.

export const STALE_RELOAD_MS = 180_000;   // no state update / successful poll for 3 min while a session runs
export const FULL_RELOAD_MS = 21_600_000; // 6 h
export const QUIET_MS = 60_000;           // … at the quietest moment: no call.next in the last minute
export const ERROR_RELOAD_MS = 5_000;
export const ERROR_COOLDOWN_MS = 60_000;
export const ROTATE_MS = 12_000;
export const TILES_PER_PAGE = 9;

export interface KioskHealth {
  now: number;
  bootedAt: number;
  lastUpdateAt: number | null;   // last applied QueueState or successful poll
  lastCallAt: number | null;     // last call.next frame
  anyRunning: boolean;
}

/** Why the page should reload itself, or null when it should not. */
export function reloadReason(h: KioskHealth): 'stale' | 'scheduled' | null {
  if (h.anyRunning && h.lastUpdateAt !== null && h.now - h.lastUpdateAt >= STALE_RELOAD_MS) return 'stale';
  const quiet = h.lastCallAt === null || h.now - h.lastCallAt >= QUIET_MS;
  if (h.now - h.bootedAt >= FULL_RELOAD_MS && quiet) return 'scheduled';
  return null;
}

export function pageCount(total: number, perPage = TILES_PER_PAGE): number {
  return Math.max(1, Math.ceil(total / perPage));
}

export function paginate<T>(items: T[], page: number, perPage = TILES_PER_PAGE): T[] {
  const pages = pageCount(items.length, perPage);
  const index = ((page % pages) + pages) % pages;
  return items.slice(index * perPage, index * perPage + perPage);
}

/** 1–2 tiles → 1 column, 3–4 → 2, more → 3 (auto-scaling grid of §9.1). */
export function gridColumns(count: number): number {
  if (count <= 1) return 1;
  if (count <= 4) return 2;
  return 3;
}
