// Panel queue XHR (CONVENTIONS §7.2). The queue's own mutations are the serial engine's endpoints
// (@panel/api/serials); this file carries the board poll the overview page uses in degraded mode, the doctor's
// session roster (re-read when the queue state's version moves — never a poller of its own) and the post-issue
// "call next patient", which is call-next plus the called serial's visit in one request.
import { http } from '@shared/http';
import { route } from '@shared/routes';
import type { CallNextVisitResponse, QueueBoard, QueueDoctor, QueueSessionRow } from '@shared/types/models';

export interface QueueTodayData {
  board: QueueBoard;
  doctors: Record<string, Omit<QueueDoctor, 'public_id'>>;
}

export async function fetchQueueToday(): Promise<QueueTodayData> {
  const { data } = await http.get<QueueTodayData>(route('panel.queue.today.data'));
  return data;
}

export interface RosterResponse {
  session_id: string | null;
  version: number | null;
  roster: Record<string, QueueSessionRow>;
}

/** The doctor's session roster (`panel.queue.doctor.roster`); `doctor` is the slug an operator's screen carries. */
export async function fetchRoster(session: string, doctor?: string | null): Promise<RosterResponse> {
  const { data } = await http.get<RosterResponse>(route('panel.queue.doctor.roster', { session, doctor: doctor ?? undefined }));
  return data;
}

/**
 * `panel.queue.call-next-visit`: `called: null` means no one has arrived; a 409 `queue.chamber_occupied` means a
 * serial is still in consultation and the doctor must complete or return it first — surfaced, never swallowed.
 */
export async function callNextVisit(session: string): Promise<CallNextVisitResponse> {
  const { data } = await http.post<CallNextVisitResponse>(route('panel.queue.call-next-visit', { session }));
  return data;
}
