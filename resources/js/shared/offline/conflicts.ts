// docs/OFFLINE.md §8 — the conflict cards. A `conflict` row in the event log is a decision the receptionist must take;
// cards are shown one at a time in sequence order, "decide later" keeps the badge, nothing is ever auto-merged.
import { create } from 'zustand';
import type { ReceptionDB } from './db';
import { RESOLUTIONS_BY_REASON, type ConflictCard, type ConflictResolution } from './types';

export interface ConflictsState {
  cards: ConflictCard[];
  open: boolean;
  index: number;
  busy: boolean;
  error: string | null;
  refresh(db: ReceptionDB): Promise<void>;
  show(): void;
  hide(): void;
  next(): void;
  setBusy(busy: boolean, error?: string | null): void;
}

export async function listConflicts(db: ReceptionDB): Promise<ConflictCard[]> {
  const rows = await db.events.where('status').equals('conflict').toArray();
  return rows.sort((a, b) => a.sequenceNo - b.sequenceNo).map((r) => ({
    clientEventId: r.clientEventId, sequenceNo: r.sequenceNo, type: r.type, reason: r.conflictReason ?? 'unknown_serial', payload: r.payload,
    serverResult: r.result ?? {}, resolutions: RESOLUTIONS_BY_REASON[r.conflictReason ?? ''] ?? ['discard'],
  }));
}

export const useConflicts = create<ConflictsState>()((set, get) => ({
  cards: [], open: false, index: 0, busy: false, error: null,
  refresh: async (db) => {
    const cards = await listConflicts(db);
    set({ cards, index: Math.min(get().index, Math.max(0, cards.length - 1)), open: get().open && cards.length > 0 });
  },
  show: () => set({ open: true, index: 0 }),
  hide: () => set({ open: false }),
  next: () => set((s) => ({ index: s.cards.length === 0 ? 0 : (s.index + 1) % s.cards.length })),
  setBusy: (busy, error = null) => set({ busy, error }),
}));

/** Resolutions that need the Hospital Admin PIN on the device (OFFLINE §8.3, §8.5) — the server re-checks the role. */
export function requiresAdminPin(type: string, resolution: ConflictResolution): boolean {
  return resolution === 'record_in_closed' || (type === 'collect_cash' && resolution === 'discard');
}
