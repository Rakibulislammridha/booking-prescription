// docs/OFFLINE.md §6.2 — the allowed / blocked matrix. Components ask `isAllowedOffline(action, mode)` and show the
// "Not available offline" reason instead of guessing; the server re-validates everything on replay anyway.
import type { ConnectionMode } from '../connection/store';

export type DeskAction =
  | 'issue_block' | 'check_in' | 'print' | 'view_history' | 'create_patient_stub' | 'collect_cash' | 'void_local'
  | 'refund' | 'discount' | 'cancel' | 'issue_online' | 'issue_buffer' | 'priority_insert' | 'reorder' | 'call_next' | 'transfer' | 'postpone'
  | 'session_lifecycle' | 'card_payment' | 'prescription' | 'edit_patient' | 'merge_patient';

export const OFFLINE_ALLOWED: ReadonlySet<DeskAction> = new Set<DeskAction>(['issue_block', 'check_in', 'print', 'view_history', 'create_patient_stub', 'collect_cash', 'void_local']);

export const OFFLINE_BLOCKED: ReadonlySet<DeskAction> = new Set<DeskAction>([
  'refund', 'discount', 'cancel', 'issue_online', 'issue_buffer', 'priority_insert', 'reorder', 'call_next', 'transfer', 'postpone',
  'session_lifecycle', 'card_payment', 'prescription', 'edit_patient', 'merge_patient',
]);

/** i18n key of the reason a button is disabled offline (OFFLINE §6.2 right column). */
export const OFFLINE_REASON: Record<DeskAction, string> = {
  issue_block: 'reception.offline.allowed', check_in: 'reception.offline.allowed', print: 'reception.offline.allowed', view_history: 'reception.offline.allowed',
  create_patient_stub: 'reception.offline.allowed', collect_cash: 'reception.offline.allowed', void_local: 'reception.offline.allowed',
  refund: 'reception.offline.reason.money', discount: 'reception.offline.reason.money', cancel: 'reception.offline.reason.cancel',
  issue_online: 'reception.offline.reason.server_pool', issue_buffer: 'reception.offline.reason.server_pool', priority_insert: 'reception.offline.reason.server_pool',
  reorder: 'reception.offline.reason.server_pool', call_next: 'reception.offline.reason.server_pool', transfer: 'reception.offline.reason.server_pool',
  postpone: 'reception.offline.reason.server_pool', session_lifecycle: 'reception.offline.reason.server_pool', card_payment: 'reception.offline.reason.gateway',
  prescription: 'reception.offline.reason.clinical', edit_patient: 'reception.offline.reason.reconcile', merge_patient: 'reception.offline.reason.reconcile',
};

export function isAllowedOffline(action: DeskAction, mode: ConnectionMode): boolean {
  return mode !== 'offline' || OFFLINE_ALLOWED.has(action);
}

export function offlineReason(action: DeskAction): string {
  return OFFLINE_REASON[action];
}
