// The offline desk's data shapes (docs/OFFLINE.md §5.2, §6.1, §7.1). Server wire keys are snake_case; the local
// cache is camelCase (the payloads the device sends are camelCase by spec, stored verbatim server-side).

export type OfflineEventType = 'register_patient' | 'issue_serial' | 'check_in' | 'collect_cash' | 'print_token' | 'void_local';
export type LocalEventStatus = 'pending' | 'sending' | 'accepted' | 'conflict' | 'rejected' | 'deferred';
export type ServerEventStatus = 'accepted' | 'conflict' | 'rejected' | 'pending';
export type ConflictReason = 'duplicate_patient' | 'patient_mismatch' | 'serial_already_used' | 'session_closed' | 'status_regression' | 'already_paid' | 'dependency_unresolved' | 'block_released' | 'unknown_serial';
export type ConflictResolution = 'link_patient' | 'family_member' | 'reissue' | 'move_to_session' | 'record_in_closed' | 'reinstate' | 'refund_cash' | 'credit' | 'discard';
export type PrintFormat = '58' | '80' | 'a5';

export interface MetaRow { key: string; value: unknown }

export interface CachedSession {
  publicId: string; date: string; branchId: string; doctorId: string; code: string; status: string; mode: 'serial' | 'slot';
  plannedStartAt: string; plannedEndAt: string; delayMinutes: number;
  doctor: { publicId: string; slug: string; name: string; nameBn: string | null; room: string | null };
  nowServing: string | null;
  counts: Record<string, number>;
  remaining: { counter: number; released: number; buffer: number; online: number; counterInBlocks: number };
  feeNewPaisa: number; feeFollowupPaisa: number; maxSerials: number; version: number; updatedAt: number;
}

export interface CachedSerial {
  publicId: string; sessionId: string; number: number; displayCode: string; position: number; status: string; priority: string; source: string;
  patientRef: string; patientName: string; mobileMasked: string; appointmentId: string | null; feePaisa: number | null; paymentStatus: string | null;
  clientEventId?: string; blockId?: string; local: boolean; cashCollected?: number; receiptNo?: string; checkedInAt?: string | null; updatedAt: number;
}

export interface CachedBlock {
  publicId: string; sessionId: string; sessionCode: string; rangeStart: number; rangeEnd: number; nextNumber: number;
  status: 'active' | 'released' | 'exhausted' | 'revoked'; expiresAt: string | null;
}

export interface CachedPatient {
  publicId: string; localId?: string; mobile: string; name: string; nameTokens: string[]; sex?: 'm' | 'f' | 'o'; dob?: string; ageYears?: number;
  ageText?: string | null; patientCode?: string; relationToHolder?: string; updatedAt: number;
}

export interface CachedHistory { patientId: string; visits: Array<{ date: string; doctor: string; dx: string; rxCount: number }>; fetchedAt: number }

export interface PrintTemplate { id: PrintFormat; version: number; html: string; css: string }

export interface OfflineEventRecord {
  clientEventId: string; sequenceNo: number; type: OfflineEventType; payload: Record<string, unknown>; clientOccurredAt: string;
  actorUserId: string; sessionId?: string; dependsOn?: string;
  status: LocalEventStatus; result?: Record<string, unknown>; conflictReason?: ConflictReason | string; attempts: number;
}

/** POST /api/reception/sync body (OFFLINE §7.1). */
export interface WireEvent {
  client_event_id: string; sequence_no: number; type: OfflineEventType; client_occurred_at: string; actor_user_id: string;
  depends_on: string | null; session_id?: string; payload: Record<string, unknown>;
}
export interface SyncRequest { sequence_no_from: number; app_version: string; events: WireEvent[] }
export interface SyncEventResult { client_event_id: string; status: ServerEventStatus; conflict_reason?: string; server_result: Record<string, unknown> }
export interface SyncResponse { server_time: string; results: SyncEventResult[]; bootstrap_stale: boolean }
export interface ResolveRequest { client_event_id: string; resolution: ConflictResolution; params?: Record<string, unknown> }

/** A conflict card (OFFLINE §8): the event, what the server saw, and the decisions the card offers. */
export interface ConflictCard {
  clientEventId: string; sequenceNo: number; type: OfflineEventType; reason: ConflictReason | string; payload: Record<string, unknown>;
  serverResult: Record<string, unknown>; resolutions: ConflictResolution[];
}

export const RESOLUTIONS_BY_REASON: Record<string, ConflictResolution[]> = {
  duplicate_patient: ['link_patient', 'family_member'],
  patient_mismatch: ['link_patient', 'family_member'],
  serial_already_used: ['reissue', 'discard'],
  block_released: ['reissue', 'discard'],
  session_closed: ['move_to_session', 'record_in_closed', 'discard'],
  status_regression: ['reinstate', 'discard'],
  already_paid: ['refund_cash', 'credit', 'discard'],
  unknown_serial: ['discard'],
  dependency_unresolved: [],
};

export const LOCAL_PREFIX = 'local:';
export const isLocalRef = (ref: string | null | undefined): boolean => typeof ref === 'string' && ref.startsWith(LOCAL_PREFIX);
export const localRef = (id: string): string => LOCAL_PREFIX + id;
