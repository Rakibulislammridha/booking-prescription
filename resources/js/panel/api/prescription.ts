// Prescription module XHR (CONVENTIONS §7.2, PRESCRIPTION.md §3.8, §4.13, §5.5, §6): the only place the writer
// talks JSON to the panel prescription endpoints. Every call goes through the shared axios instance, so callers
// branch on ApiError.code (`prescriptions.draft_conflict`, `prescriptions.parse_error`, `prescriptions.issue_blocked`).
import { http } from '@shared/http';
import { route } from '@shared/routes';
import type {
  AdviceSnippet,
  AiDifferentialsResponse,
  AiSummaryResponse,
  DrugSearchResponse,
  Icd10SearchResponse,
  InvestigationCatalogRow,
  IssuedPrescription,
  IssueResult,
  PrescriptionBrief,
  PrescriptionDraft,
  SafetyCheckResponse,
  TemplateBrief,
  TemplateFull,
  TopDrug,
  VitalsInput,
  VitalsRow,
  DraftSaveRequest,
  DraftSaveResponse,
  ExternalCentreBrief,
  DrawingJson,
  VisitRow,
} from '@shared/types/models';
import type { KeywordTable } from '@panel/lib/prescription/shorthand/keywords';

// ---- visit ------------------------------------------------------------------------------------------------------

/**
 * Open (idempotently) the visit of a called serial and get the writer's URL — `panel.prescription.visits.start`,
 * the same endpoint the telemedicine console and the desk rely on. The doctor screen's "Prescribe" lands on
 * `writer_url`; issuing from the writer completes the consultation (CompleteConsultationOnPrescriptionIssued).
 */
export async function startVisit(serial: string): Promise<{ visit: VisitRow; writer_url: string }> {
  const { data } = await http.post<{ visit: VisitRow; writer_url: string }>(route('panel.prescription.visits.start', { serial }));
  return data;
}

// ---- draft ------------------------------------------------------------------------------------------------------

export async function saveDraft(prescription: string, body: DraftSaveRequest, signal?: AbortSignal): Promise<DraftSaveResponse> {
  const { data } = await http.patch<DraftSaveResponse>(route('panel.prescription.prescriptions.draft', { prescription }), body, { signal });
  return data;
}

export async function checkSafety(
  prescription: string,
  body: { items: DraftSaveRequest['items']; overrides?: Array<{ fingerprint: string; reason: string }> },
  signal?: AbortSignal,
): Promise<SafetyCheckResponse> {
  const { data } = await http.post<SafetyCheckResponse>(route('panel.prescription.prescriptions.check', { prescription }), body, { signal });
  return data;
}

export async function deleteDraft(prescription: string): Promise<void> {
  await http.delete(route('panel.prescription.prescriptions.destroy', { prescription }));
}

// ---- issue / amend / void / delivery ----------------------------------------------------------------------------

export interface IssueBody {
  language?: string | null;
  print?: boolean;
  acknowledged_warnings?: string[];
  expected_updated_at?: string | null;
  add_to_medication_list?: boolean;
}

export async function issuePrescription(prescription: string, body: IssueBody): Promise<IssueResult> {
  const { data } = await http.post<IssueResult>(route('panel.prescription.prescriptions.issue', { prescription }), body);
  return data;
}

export async function amendPrescription(prescription: string, reason: string): Promise<{ prescription: PrescriptionDraft; writer_url: string }> {
  const { data } = await http.post<{ prescription: PrescriptionDraft; writer_url: string }>(route('panel.prescription.prescriptions.amend', { prescription }), { reason });
  return data;
}

export async function voidPrescription(prescription: string, reason: string): Promise<{ prescription: PrescriptionBrief }> {
  const { data } = await http.post<{ prescription: PrescriptionBrief }>(route('panel.prescription.prescriptions.void', { prescription }), { reason });
  return data;
}

export async function sendPrescription(prescription: string, channel: 'sms' | 'whatsapp' | 'email', to?: string | null): Promise<{ queued: boolean; channel: string; pdf_status: 'pending' | 'ready' }> {
  const { data } = await http.post<{ queued: boolean; channel: string; pdf_status: 'pending' | 'ready' }>(route('panel.prescription.prescriptions.send', { prescription }), { channel, to: to ?? null });
  return data;
}

export async function fetchPrescription(prescription: string): Promise<{ prescription: IssuedPrescription }> {
  const { data } = await http.get<{ prescription: IssuedPrescription }>(route('panel.prescription.prescriptions.show', { prescription }), { params: { format: 'json' } });
  return data;
}

export async function fetchVersions(prescription: string): Promise<PrescriptionBrief[]> {
  const { data } = await http.get<{ data: PrescriptionBrief[] }>(route('panel.prescription.prescriptions.versions', { prescription }));
  return data.data;
}

// ---- attachments (tablet affordances) ---------------------------------------------------------------------------

export async function saveHandwritingPage(prescription: string, page: number, png: Blob): Promise<{ page: number; path: string; handwriting_image_path: string | null; mode: 'handwriting' }> {
  const form = new FormData();
  form.append('page', String(page));
  form.append('png', png, `handwriting-${page}.png`);
  const { data } = await http.post<{ page: number; path: string; handwriting_image_path: string | null; mode: 'handwriting' }>(
    route('panel.prescription.prescriptions.handwriting', { prescription }),
    form,
    { timeout: 60_000 },
  );
  return data;
}

export async function saveDrawing(prescription: string, json: DrawingJson, png: Blob | null): Promise<{ drawing_json: DrawingJson | null; drawing_image_path: string | null }> {
  const form = new FormData();
  form.append('json', JSON.stringify(json));
  if (png !== null) form.append('png', png, 'drawing.png');
  const { data } = await http.post<{ drawing_json: DrawingJson | null; drawing_image_path: string | null }>(route('panel.prescription.prescriptions.drawing', { prescription }), form, { timeout: 60_000 });
  return data;
}

// ---- search (debounced by the caller; server caches 30 s and throttles 20 rps) -----------------------------------

export async function searchDrugs(q: string, options: { dx?: string[]; limit?: number; strengthMg?: number } = {}, signal?: AbortSignal): Promise<DrugSearchResponse> {
  const { data } = await http.get<DrugSearchResponse>(route('panel.prescription.search.drugs'), {
    params: { q, dx: options.dx?.length ? options.dx : undefined, limit: options.limit ?? 12, strength_mg: options.strengthMg },
    signal,
  });
  return data;
}

export async function searchIcd(q: string, limit = 10, signal?: AbortSignal): Promise<Icd10SearchResponse> {
  const { data } = await http.get<Icd10SearchResponse>(route('panel.prescription.search.icd'), { params: { q, limit }, signal });
  return data;
}

export interface DoctorHit {
  id: number;
  public_id: string;
  name: string;
  name_bn: string | null;
  specialty: string | null;
}

export async function searchDoctors(q: string, signal?: AbortSignal): Promise<DoctorHit[]> {
  const { data } = await http.get<{ hits: DoctorHit[] }>(route('panel.prescription.search.doctors'), { params: { q }, signal });
  return data.hits;
}

export async function searchInvestigations(q: string, signal?: AbortSignal): Promise<InvestigationCatalogRow[]> {
  const { data } = await http.get<{ data: InvestigationCatalogRow[] }>(route('panel.prescription.search.investigations'), { params: { q }, signal });
  return data.data;
}

// ---- quick-pick / learning ---------------------------------------------------------------------------------------

export async function fetchTopDrugs(): Promise<TopDrug[]> {
  const { data } = await http.get<{ data: TopDrug[] }>(route('panel.prescription.favourites.top-drugs'));
  return data.data;
}

export async function fetchFavourites(icd?: string | null, signal?: AbortSignal): Promise<TopDrug[]> {
  const { data } = await http.get<{ data: TopDrug[] }>(route('panel.prescription.favourites.index'), { params: { icd: icd ?? undefined }, signal });
  return data.data;
}

export async function pinFavourite(body: { icd10_code?: string | null; drug: { generic_id?: number | null; brand_id?: number | null; custom_brand_id?: number | null; strength_id?: number | null }; default_dose?: { shorthand?: string | null } }): Promise<TopDrug> {
  const { data } = await http.post<{ data: TopDrug }>(route('panel.prescription.favourites.store'), body);
  return data.data;
}

export async function updateFavourite(favourite: number, body: { is_pinned?: boolean; rank?: number }): Promise<TopDrug> {
  const { data } = await http.patch<{ data: TopDrug }>(route('panel.prescription.favourites.update', { favourite }), body);
  return data.data;
}

export async function removeFavourite(favourite: number): Promise<void> {
  await http.delete(route('panel.prescription.favourites.destroy', { favourite }));
}

// ---- templates ---------------------------------------------------------------------------------------------------

export async function fetchTemplates(): Promise<TemplateBrief[]> {
  const { data } = await http.get<{ data: TemplateBrief[] }>(route('panel.prescription.templates.index'));
  return data.data;
}

export async function fetchTemplate(template: number): Promise<TemplateFull> {
  const { data } = await http.get<{ data: TemplateFull }>(route('panel.prescription.templates.show', { template }));
  return data.data;
}

export interface SaveTemplateBody {
  name: string;
  shorthand?: string | null;
  icd10_code?: string | null;
  diagnosis_title?: string | null;
  is_shared?: boolean;
  from_prescription_id?: string | null;
  include_clinical?: boolean;
}

export async function saveTemplate(body: SaveTemplateBody): Promise<TemplateFull> {
  const { data } = await http.post<{ data: TemplateFull }>(route('panel.prescription.templates.store'), body);
  return data.data;
}

export async function deleteTemplate(template: number): Promise<void> {
  await http.delete(route('panel.prescription.templates.destroy', { template }));
}

export async function applyTemplate(prescription: string, template: number, mode: 'append' | 'replace' = 'append'): Promise<DraftSaveResponse> {
  const { data } = await http.post<DraftSaveResponse>(route('panel.prescription.prescriptions.apply-template', { prescription, template }), { mode });
  return data;
}

// ---- advice snippets ---------------------------------------------------------------------------------------------

export async function fetchSnippets(params: { q?: string; category?: string } = {}, signal?: AbortSignal): Promise<AdviceSnippet[]> {
  const { data } = await http.get<{ data: AdviceSnippet[] }>(route('panel.prescription.snippets.index'), { params, signal });
  return data.data;
}

export async function saveSnippet(body: { shorthand?: string | null; category?: string | null; text: string; text_bn?: string | null; is_shared?: boolean; clinic?: boolean }): Promise<AdviceSnippet> {
  const { data } = await http.post<{ data: AdviceSnippet }>(route('panel.prescription.snippets.store'), body);
  return data.data;
}

// ---- clinic catalog ----------------------------------------------------------------------------------------------

export async function fetchInvestigationCatalog(): Promise<InvestigationCatalogRow[]> {
  const { data } = await http.get<{ data: InvestigationCatalogRow[] }>(route('panel.prescription.investigations.index'));
  return data.data;
}

export async function fetchCentres(): Promise<ExternalCentreBrief[]> {
  const { data } = await http.get<{ data: ExternalCentreBrief[] }>(route('panel.prescription.centres.index'));
  return data.data;
}

// ---- vitals ------------------------------------------------------------------------------------------------------

export async function recordVitals(visit: string, body: VitalsInput): Promise<VitalsRow> {
  const { data } = await http.post<{ vitals: VitalsRow }>(route('panel.prescription.vitals.store', { visit }), body);
  return data.vitals;
}

export async function updateVitals(vital: number, body: VitalsInput): Promise<VitalsRow> {
  const { data } = await http.patch<{ vitals: VitalsRow }>(route('panel.prescription.vitals.update', { vital }), body);
  return data.vitals;
}

// ---- help / AI ---------------------------------------------------------------------------------------------------

export interface ShorthandHelp {
  version: string;
  keywords: KeywordTable;
  try: unknown | null;
}

export async function fetchShorthandHelp(tryText?: string): Promise<ShorthandHelp> {
  const { data } = await http.get<ShorthandHelp>(route('panel.prescription.help.shorthand'), { params: tryText ? { try: tryText } : undefined });
  return data;
}

export async function aiSummary(visit: string): Promise<AiSummaryResponse> {
  const { data } = await http.post<AiSummaryResponse>(route('panel.prescription.ai.summary', { visit }), {});
  return data;
}

export async function aiDifferentials(visit: string, body: { complaints?: string[]; findings?: string | null } = {}): Promise<AiDifferentialsResponse> {
  const { data } = await http.post<AiDifferentialsResponse>(route('panel.prescription.ai.differentials', { visit }), body);
  return data;
}
