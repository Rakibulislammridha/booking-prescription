// Typed calls to the serial engine's panel JSON endpoints (routes/panel/serials.php) — CONVENTIONS §7.2: every
// axios call lives here and goes through the shared instance; callers branch on ApiError.code.
import { http } from '@shared/http';
import { route } from '@shared/routes';
import type { CallNextResponse, CancelReason, PoolRanges, Serial, SerialBlock, SerialPriority, SessionInstance, SessionRemaining } from '@shared/types/models';

type SerialEnvelope = { serial: Serial };
type SessionEnvelope = { session: SessionInstance };

export async function fetchSession(session: string): Promise<SessionInstance> {
  const { data } = await http.get<SessionEnvelope>(route('panel.sessions.show', { session }));
  return data.session;
}

export async function fetchCapacity(session: string): Promise<SessionRemaining> {
  const { data } = await http.get<SessionRemaining>(route('panel.sessions.capacity', { session }));
  return data;
}

export async function issueSerial(session: string, body: { source: 'counter' | 'walkin' | 'followup'; patient_id?: number | null; priority?: SerialPriority; priority_reason?: string | null; client_event_id?: string }): Promise<Serial> {
  const { data } = await http.post<{ data: Serial }>(route('panel.sessions.serials.store', { session }), body);
  return data.data;
}

export async function callNext(session: string): Promise<CallNextResponse> {
  const { data } = await http.post<CallNextResponse>(route('panel.sessions.call-next', { session }));
  return data;
}

export async function sessionAction(session: string, action: 'start' | 'pause' | 'resume' | 'close' | 'cancel', reason?: string | null): Promise<SessionInstance> {
  const name = `panel.sessions.${action}` as const;
  const { data } = await http.post<SessionEnvelope>(route(name, { session }), { reason: reason ?? null });
  return data.session;
}

export async function delaySession(session: string, delayMinutes: number, message?: string | null): Promise<SessionInstance> {
  const { data } = await http.post<SessionEnvelope>(route('panel.sessions.delay', { session }), { delay_minutes: delayMinutes, message: message ?? null });
  return data.session;
}

export async function extendSession(session: string, extra: number, reason?: string | null): Promise<{ session: SessionInstance; pools: PoolRanges }> {
  const { data } = await http.post<{ session: SessionInstance; pools: PoolRanges }>(route('panel.sessions.extend', { session }), { extra, reason: reason ?? null });
  return data;
}

export async function releaseOnline(session: string, count?: number | null): Promise<{ pools: PoolRanges; released_block: SerialBlock | null }> {
  const { data } = await http.post<{ pools: PoolRanges; released_block: SerialBlock | null }>(route('panel.sessions.pools.release-online', { session }), { count: count ?? null });
  return data;
}

export async function changeSplit(session: string, counterQuota: number, onlineQuota: number): Promise<{ session: SessionInstance; pools: PoolRanges }> {
  const { data } = await http.put<{ session: SessionInstance; pools: PoolRanges }>(route('panel.sessions.pools.split', { session }), { counter_quota: counterQuota, online_quota: onlineQuota });
  return data;
}

export type SerialSimpleAction = 'check-in' | 'call' | 'start' | 'complete' | 'skip' | 'return' | 'no-show';

export async function serialAction(serial: string, action: SerialSimpleAction, reason?: string | null): Promise<Serial> {
  const name = `panel.serials.${action}` as const;
  const { data } = await http.post<SerialEnvelope>(route(name, { serial }), { reason: reason ?? null });
  return data.serial;
}

export async function reinstateSerial(serial: string, present: boolean): Promise<Serial> {
  const { data } = await http.post<SerialEnvelope>(route('panel.serials.reinstate', { serial }), { present });
  return data.serial;
}

export async function cancelSerial(serial: string, reasonCode: CancelReason, note?: string | null): Promise<{ serial: Serial; refund_eligible: boolean }> {
  const { data } = await http.post<{ serial: Serial; refund_eligible: boolean }>(route('panel.serials.cancel', { serial }), { reason_code: reasonCode, note: note ?? null });
  return data;
}

export async function reorderSerial(serial: string, after: string | null, before: string | null, reason?: string | null): Promise<Serial> {
  const { data } = await http.post<SerialEnvelope>(route('panel.serials.reorder', { serial }), { after, before, reason: reason ?? null });
  return data.serial;
}

export async function setPriority(serial: string, priority: SerialPriority, reason?: string | null): Promise<Serial> {
  const { data } = await http.post<SerialEnvelope>(route('panel.serials.priority', { serial }), { priority, reason: reason ?? null });
  return data.serial;
}

export async function postponeSerial(serial: string, targetSession?: string | null, reason?: string | null): Promise<{ old: Serial; new: Serial }> {
  const { data } = await http.post<{ old: Serial; new: Serial }>(route('panel.serials.postpone', { serial }), { target_session: targetSession ?? null, reason: reason ?? null });
  return data;
}

export async function transferSerial(serial: string, targetSession: string, reason?: string | null): Promise<{ old: Serial; new: Serial; fee_delta_expected: number }> {
  const { data } = await http.post<{ old: Serial; new: Serial; fee_delta_expected: number }>(route('panel.serials.transfer', { serial }), { target_session: targetSession, reason: reason ?? null });
  return data;
}
