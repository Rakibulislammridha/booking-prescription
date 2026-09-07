// The QueueState document (docs/REALTIME.md §4.1): the same JSON is the WebSocket payload (`queue.state`) and
// the polling response body. Delegated subtree: engineer Q owns resources/js/shared/realtime/** (CONVENTIONS §2).
export type SerialShort = 'b' | 'c' | 'i';      // booked | checked_in | in_consultation (terminal serials are not listed)
export type QueuePriority = 'e' | 'v' | 'el';   // emergency | vip | elderly
export type QueueSessionStatus = 'scheduled' | 'running' | 'paused' | 'closed' | 'cancelled';
export type QueueSessionMode = 'serial' | 'slot';

export interface QueueSerial {
  id: string;
  c: string;            // display code A-042
  n: number;            // number
  p: number;            // position
  s: SerialShort;
  pr?: QueuePriority;
  eta: string | null;   // ISO
  ahead: number;
}

export interface QueueState {
  v: 1;
  session: {
    id: string; code: string; date: string; status: QueueSessionStatus; mode: QueueSessionMode;
    planned_start_at: string; expected_start_at: string; delay_minutes: number;
    doctor: { id: string; slug: string; name: string; name_bn: string | null; room: string | null };
    branch: { id: string; name: string };
  };
  now_serving: { id: string; c: string; n: number; called_at: string } | null;
  last_called: Array<{ c: string; called_at: string }>;                  // up to 3, most recent first
  counts: { booked: number; checked_in: number; in_consultation: number; completed: number; no_show: number; cancelled: number; postponed: number; waiting: number };
  avg_consult_seconds: number;
  eta_confidence: 'low' | 'normal';
  serials: QueueSerial[];                                                 // ordered by p asc; ≤ max_serials entries
  truncated?: true;
  updated_at: string;
  version: number;
}

/** Wire names of the broadcast events (CONVENTIONS §15). Listen with a leading dot: `.queue.state`. */
export const QUEUE_EVENTS = {
  state: 'queue.state',
  serialCalled: 'serial.called',
  serialStatusChanged: 'serial.status_changed',
  sessionDelayed: 'session.delayed',
  sessionCancelled: 'session.cancelled',
  doctorArrived: 'doctor.arrived',
  boardUpdated: 'board.updated',
  callNext: 'call.next',
} as const;

export type QueueEventName = (typeof QUEUE_EVENTS)[keyof typeof QUEUE_EVENTS];

/** Channel names (CONVENTIONS §15): tenant.{tenantPublicId}.{kind}.{publicId}. */
export const tenantChannel = {
  queue: (tenantId: string, sessionInstanceId: string) => `tenant.${tenantId}.queue.${sessionInstanceId}`,
  reception: (tenantId: string, branchId: string) => `tenant.${tenantId}.reception.${branchId}`,
  doctor: (tenantId: string, doctorId: string) => `tenant.${tenantId}.doctor.${doctorId}`,
  display: (tenantId: string, branchId: string) => `tenant.${tenantId}.display.${branchId}`,
  prescription: (tenantId: string, prescriptionId: string) => `tenant.${tenantId}.prescription.${prescriptionId}`,
} as const;
