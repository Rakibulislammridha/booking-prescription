// The sidebar's "Today's session" count (PanelLayout) and where it comes from. Two feeds, one number:
//   • every navigation seeds it from the `today_session` shared prop (HandleInertiaRequests::todaySession — one
//     query, the doctor's current session and its checked-in count);
//   • on the session page itself, Queue/Doctor pushes the live `counts.checked_in` of the queue state it already
//     subscribes to (WebSocket, or the 5 s poll in degraded mode — REALTIME.md §6), so the badge moves with the
//     desk's check-ins without a poller of its own.
// A plain store rather than shared props alone because the layout stays mounted across visits while the page's
// subscription is the only live feed; the desk board's `board.updated` is the same idea on the reception side.
import { create } from 'zustand';

export interface TodaySessionBadgeState {
  sessionId: string | null;
  waiting: number | null;
  set(sessionId: string | null, waiting: number | null): void;
}

export const useTodaySessionBadge = create<TodaySessionBadgeState>((set) => ({
  sessionId: null,
  waiting: null,
  set: (sessionId, waiting) => set({ sessionId, waiting }),
}));
