// How the desk reads a session's rows — the one place both board paths (server JSON in the Inertia props, and the
// Dexie cache a registered desk renders from, useDesk.boardFromCache) get their order, their "Next" and the set of
// rows the default view shows from.
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

// ---------------------------------------------------------------------------------------------------------------
// Which rows the desk sees WITHOUT pressing "Show all".
//
// The default view is the active rows, because by the end of a session a board that kept everyone would bury the
// people still waiting under the people already finished. But issuing a prescription COMPLETES the serial
// (CompleteConsultationOnPrescriptionIssued), so the patient standing at the counter waiting for their printout
// used to disappear from the desk's view at the exact moment the desk had a job to do for them.
//
// So the default view is "active + awaiting print": a completed row stays visible while its issued prescription
// has never been printed, and leaves the moment somebody prints it (`prescriptions.printed_count`, bumped by
// PrintController). The rule clears itself — no timer, no arbitrary window — and it is one function because the
// board is built on two paths (the server JSON in the Inertia props and the Dexie cache, useDesk.boardFromCache)
// which must not disagree about who is on screen.

/** The statuses the desk means by "still waiting": the patient is booked, here, or with the doctor. */
export const ACTIVE_STATUSES: ReadonlySet<string> = new Set(['booked', 'checked_in', 'in_consultation']);

export interface PrintableRow { status: string; prescription: { printed: boolean } | null }

/**
 * "This patient is finished, and is standing at the counter waiting for paper." True only for a COMPLETED row
 * whose issued prescription has never been printed. It is not a status — the row still reads `Completed` — it is
 * a job the desk has not done yet.
 */
export function isAwaitingPrint(row: PrintableRow): boolean {
  return row.status === 'completed' && row.prescription !== null && !row.prescription.printed;
}

/**
 * The session's rows as the tile renders them: number order always, and — unless the desk asked for everything —
 * only the ones it is currently about. `showAll` is exactly "everything", so the toggle's count stays the whole
 * list.
 */
export function visibleRows<T extends BoardRowKeys & PrintableRow>(rows: readonly T[], showAll: boolean): T[] {
  const ordered = byNumber(rows);

  return showAll ? ordered : ordered.filter((row) => ACTIVE_STATUSES.has(row.status) || isAwaitingPrint(row));
}
