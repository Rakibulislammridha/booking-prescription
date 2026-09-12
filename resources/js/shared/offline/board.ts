// How the desk reads a session's rows — the one place both board paths (server JSON in the Inertia props, and the
// Dexie cache a registered desk renders from, useDesk.boardFromCache) get their order and their "Next" from.
//
// The list is in serial NUMBER order (B-001, B-002, B-003 …): the order the tokens were handed out and the one the
// waiting room recognises. The engine's queue `position` (SERIAL_ENGINE §7: check-in order, priority inserts,
// reorders) is a different order and is what "Call next" consumes — listing by it read as random at the desk. So the
// rows are sorted by number and the row CallNext would take is MARKED instead: `nextToCall` is CallNext's own rule
// (the checked_in row with the lowest position, ties by number — app/Domain/Serials/Actions/CallNext.php `nextOf`)
// applied to the rows on screen. Offline the desk checks patients in locally, so the pick is always derived from the
// rows rather than cached: the fields it needs (`number`, `position`, `status`) are already on every cached row.

export interface BoardRowKeys { number: number; position: number; status: string }

/** Stable copy sorted by serial number (ties — never for one session — fall back to queue position). */
export function byNumber<T extends Pick<BoardRowKeys, 'number' | 'position'>>(rows: readonly T[]): T[] {
  return [...rows].sort((a, b) => a.number - b.number || a.position - b.position);
}

/** The row "Call next" would call now, or null when nobody is checked in. Mirrors CallNext::nextOf exactly. */
export function nextToCall<T extends BoardRowKeys>(rows: readonly T[]): T | null {
  let next: T | null = null;
  for (const row of rows) {
    if (row.status !== 'checked_in') continue;
    if (next === null || row.position < next.position || (row.position === next.position && row.number < next.number)) next = row;
  }
  return next;
}
