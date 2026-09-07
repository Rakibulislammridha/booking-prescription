// Reception module XHR (CONVENTIONS §7.2): the device API (bearer device token + X-Actor-User, OFFLINE §2.2) and the
// staff-session desk endpoints. Every call goes through the shared axios instance; callers branch on ApiError.code.
import { http } from '@shared/http';
import { route } from '@shared/routes';
import type { BootstrapPayload, ResolveRequest, ServerBlock, ServerBoard, SyncEventResult, SyncRequest, SyncResponse } from '@shared/offline';
import type { PrintTemplate } from '@shared/offline';
import type { CounterBookingResponse, DeskPatientLookup, ReceptionDevice, RegisterDeviceResponse } from '@shared/types/models';
import type { SerialBlock } from '@shared/types/models';

export interface DeviceAuth {
  token: string;
  actorPublicId: string;
  appVersion?: string;
}

function deviceHeaders(auth: DeviceAuth): Record<string, string> {
  return { Authorization: `Bearer ${auth.token}`, 'X-Actor-User': auth.actorPublicId, ...(auth.appVersion ? { 'X-App-Version': auth.appVersion } : {}) };
}

// ---- registration (staff session) -----------------------------------------------------------------------------------

export async function registerDevice(body: { name: string; branch: string; kind?: 'reception' | 'display'; device_fingerprint: string; app_version?: string; block_size?: number }): Promise<RegisterDeviceResponse> {
  const { data } = await http.post<RegisterDeviceResponse>(route('api.reception.devices.register'), body);
  return data;
}

// ---- device-token API (works after the staff session expired) -----------------------------------------------------

export async function fetchBootstrap(auth: DeviceAuth, date?: string): Promise<BootstrapPayload> {
  const { data } = await http.get<BootstrapPayload>(route('api.reception.bootstrap'), { headers: deviceHeaders(auth), params: date ? { date } : undefined });
  return data;
}

export async function fetchDeviceBoard(auth: DeviceAuth, date?: string): Promise<ServerBoard> {
  const { data } = await http.get<ServerBoard>(route('api.reception.board'), { headers: deviceHeaders(auth), params: date ? { date } : undefined });
  return data;
}

export async function leaseBlock(auth: DeviceAuth, session: string, size: number): Promise<{ block: ServerBlock; pool_remaining: { counter: number; released: number } }> {
  const { data } = await http.post<{ block: ServerBlock; pool_remaining: { counter: number; released: number } }>(route('api.reception.blocks.lease'), { session, size }, { headers: deviceHeaders(auth) });
  return data;
}

export async function listBlocks(auth: DeviceAuth, date?: string): Promise<ServerBlock[]> {
  const { data } = await http.get<{ blocks: ServerBlock[] }>(route('api.reception.blocks.index'), { headers: deviceHeaders(auth), params: date ? { date } : undefined });
  return data.blocks;
}

export async function releaseBlock(auth: DeviceAuth, block: string, reason?: string): Promise<{ released_unused: number; block: SerialBlock }> {
  const { data } = await http.post<{ released_unused: number; block: SerialBlock }>(route('api.reception.blocks.release', { block }), { reason }, { headers: deviceHeaders(auth) });
  return data;
}

export async function syncEvents(auth: DeviceAuth, body: SyncRequest): Promise<SyncResponse> {
  const { data } = await http.post<SyncResponse>(route('api.reception.sync'), body, { headers: deviceHeaders(auth), timeout: 60_000 });
  return data;
}

export async function resolveConflict(auth: DeviceAuth, body: ResolveRequest): Promise<SyncEventResult> {
  const { data } = await http.post<SyncEventResult>(route('api.reception.sync.resolve'), body, { headers: deviceHeaders(auth) });
  return data;
}

export async function fetchRecentPatients(auth: DeviceAuth, limit = 200): Promise<DeskPatientLookup[]> {
  const { data } = await http.get<{ data: DeskPatientLookup[] }>(route('api.reception.patients.recent'), { headers: deviceHeaders(auth), params: { limit } });
  return data.data;
}

export async function devicePatientLookup(auth: DeviceAuth, q: string): Promise<DeskPatientLookup[]> {
  const { data } = await http.get<{ data: DeskPatientLookup[] }>(route('api.reception.patients.lookup'), { headers: deviceHeaders(auth), params: { q } });
  return data.data;
}

export async function fetchDevicePrintTemplates(auth: DeviceAuth): Promise<{ templates: PrintTemplate[]; default: PrintTemplate['id']; version: number }> {
  const { data } = await http.get<{ templates: PrintTemplate[]; default: PrintTemplate['id']; version: number }>(route('api.reception.print-templates'), { headers: deviceHeaders(auth) });
  return data;
}

// ---- staff-session desk endpoints (routes/panel/reception.php) ------------------------------------------------------

export async function fetchBoard(date?: string): Promise<ServerBoard> {
  const { data } = await http.get<ServerBoard>(route('panel.reception.board.data'), { params: date ? { date } : undefined });
  return data;
}

export async function patientLookup(q: string, signal?: AbortSignal): Promise<{ data: DeskPatientLookup[]; meta: { engine: string } }> {
  const { data } = await http.get<{ data: DeskPatientLookup[]; meta: { engine: string } }>(route('panel.reception.patients.lookup'), { params: { q }, signal });
  return data;
}

export async function fetchPrintTemplates(): Promise<{ templates: PrintTemplate[]; default: PrintTemplate['id']; version: number }> {
  const { data } = await http.get<{ templates: PrintTemplate[]; default: PrintTemplate['id']; version: number }>(route('panel.reception.print_templates'));
  return data;
}

export async function fetchKioskUrl(session?: string | null): Promise<{ url: string; expires_in_hours: number }> {
  const { data } = await http.get<{ url: string; expires_in_hours: number }>(route('panel.reception.kiosk_url'), { params: session ? { session } : undefined });
  return data;
}

export async function collectFee(appointment: string, amountPaisa?: number | null, note?: string | null): Promise<{ payment: { receipt_no: string; amount_paisa: number; payment_status: string } }> {
  const { data } = await http.post<{ payment: { receipt_no: string; amount_paisa: number; payment_status: string } }>(route('panel.reception.appointments.collect', { appointment }), { amount_paisa: amountPaisa ?? null, note: note ?? null });
  return data;
}

export async function cancelBooking(appointment: string, reasonCode: string, note?: string | null): Promise<{ refund_eligible: boolean }> {
  const { data } = await http.post<{ refund_eligible: boolean }>(route('panel.reception.appointments.cancel', { appointment }), { reason_code: reasonCode, note: note ?? null });
  return data;
}

export async function rescheduleBooking(appointment: string, targetSession: string, reason?: string | null): Promise<{ new: { display_code: string } }> {
  const { data } = await http.post<{ new: { display_code: string } }>(route('panel.reception.appointments.reschedule', { appointment }), { target_session: targetSession, reason: reason ?? null });
  return data;
}

export type { CounterBookingResponse, ReceptionDevice };
