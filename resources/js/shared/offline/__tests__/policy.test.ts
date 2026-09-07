import { describe, expect, it } from 'vitest';
import { isAllowedOffline, offlineReason, OFFLINE_ALLOWED, OFFLINE_BLOCKED, type DeskAction } from '../policy';
import { RESOLUTIONS_BY_REASON, isLocalRef, localRef } from '../types';
import { requiresAdminPin } from '../conflicts';

describe('offline policy (OFFLINE §6.2)', () => {
  it('allows exactly the LOCKED list offline and everything online/degraded', () => {
    const allowed: DeskAction[] = ['issue_block', 'check_in', 'print', 'view_history', 'create_patient_stub', 'collect_cash', 'void_local'];
    const blocked: DeskAction[] = ['refund', 'discount', 'cancel', 'issue_online', 'issue_buffer', 'priority_insert', 'reorder', 'call_next', 'transfer', 'postpone', 'session_lifecycle', 'card_payment', 'prescription', 'edit_patient', 'merge_patient'];
    for (const a of allowed) { expect(isAllowedOffline(a, 'offline'), a).toBe(true); expect(OFFLINE_ALLOWED.has(a)).toBe(true); }
    for (const a of blocked) { expect(isAllowedOffline(a, 'offline'), a).toBe(false); expect(OFFLINE_BLOCKED.has(a)).toBe(true); expect(isAllowedOffline(a, 'degraded')).toBe(true); expect(isAllowedOffline(a, 'online')).toBe(true); }
    expect(new Set([...OFFLINE_ALLOWED, ...OFFLINE_BLOCKED]).size).toBe(allowed.length + blocked.length);
    expect(offlineReason('refund')).toBe('reception.offline.reason.money');
    expect(offlineReason('call_next')).toBe('reception.offline.reason.server_pool');
  });

  it('conflict cards offer the §8 resolutions and admin-only ones are flagged', () => {
    expect(RESOLUTIONS_BY_REASON.session_closed).toEqual(['move_to_session', 'record_in_closed', 'discard']);
    expect(RESOLUTIONS_BY_REASON.already_paid).toEqual(['refund_cash', 'credit', 'discard']);
    expect(requiresAdminPin('issue_serial', 'record_in_closed')).toBe(true);
    expect(requiresAdminPin('collect_cash', 'discard')).toBe(true);
    expect(requiresAdminPin('issue_serial', 'discard')).toBe(false);
    expect(isLocalRef(localRef('01ABC'))).toBe(true);
    expect(isLocalRef('01ABC')).toBe(false);
  });
});
