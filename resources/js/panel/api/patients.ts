// Patients module XHR (CONVENTIONS §7.2): the only place the panel talks JSON to the Patients endpoints.
// Quick search → api.patients.search (reception desk, writer); timeline / vitals-trend → the panel JSON
// endpoints of PRESCRIPTION.md §8. Every call goes through the shared axios instance (errors are ApiError).
import { http } from '@shared/http';
import { route } from '@shared/routes';
import type { FamilyMember, PatientClinicalSummary, PatientSummary, TimelinePage, VitalsTrendPoint } from '@shared/types/models';

export interface SearchMeta {
  engine: 'meilisearch' | 'database';
}

export async function searchPatients(q: string, limit = 20, signal?: AbortSignal): Promise<{ data: PatientSummary[]; meta: SearchMeta }> {
  const { data } = await http.get<{ data: PatientSummary[]; meta: SearchMeta }>(route('api.patients.search'), { params: { q, limit }, signal });
  return data;
}

/** Most recently seen patients — the reception PWA warms its offline cache from this. */
export async function recentPatients(limit = 50): Promise<PatientSummary[]> {
  const { data } = await http.get<{ data: PatientSummary[] }>(route('api.patients.recent'), { params: { limit } });
  return data.data;
}

/** Everyone registered on a mobile number, owner first (the booking-flow household list). */
export async function householdByMobile(mobile: string): Promise<{ data: FamilyMember[]; meta: { mobile: string | null; valid: boolean } }> {
  const { data } = await http.get<{ data: FamilyMember[]; meta: { mobile: string | null; valid: boolean } }>(route('api.patients.by_mobile', { mobile }));
  return data;
}

/** PRESCRIPTION.md §1.2 PatientSummary for the writer's left pane. */
export async function patientClinicalSummary(patient: string): Promise<PatientClinicalSummary> {
  const { data } = await http.get<{ data: PatientClinicalSummary }>(route('api.patients.summary', { patient }));
  return data.data;
}

export async function fetchTimeline(patient: string, cursor: string | null = null, limit = 25, kinds?: string[]): Promise<TimelinePage> {
  const { data } = await http.get<TimelinePage>(route('panel.patients.timeline', { patient }), {
    params: { cursor: cursor ?? undefined, limit, kinds: kinds?.length ? kinds.join(',') : undefined },
  });
  return data;
}

export async function fetchVitalsTrend(patient: string, limit = 12): Promise<{ data: VitalsTrendPoint[]; available: boolean }> {
  const { data } = await http.get<{ data: VitalsTrendPoint[]; available: boolean }>(route('panel.patients.vitals_trend', { patient }), { params: { limit } });
  return data;
}
