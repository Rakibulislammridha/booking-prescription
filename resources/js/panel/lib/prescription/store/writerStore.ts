// The writer's client state (PRESCRIPTION.md §1.5). One vanilla zustand store per open writer, created by the page
// and read through the WriterStoreContext, so tests can drive it without React.
//
// Rules this store enforces:
//  · the client parse is instant feedback only — the server's parse always replaces it on echo (§2.13, §4.13);
//  · a 400 ms trailing debounce PATCHes the whole draft; while offline it retries 1 s, 2 s, 5 s, 10 s… and the
//    Issue button stays disabled until the last save succeeded;
//  · a 250 ms debounce re-runs POST …/check so alerts appear as each line commits, without waiting for the save;
//  · overrides are keyed by alert fingerprint: change the item and the fingerprint changes, so the alert returns.
import { createStore, type StoreApi } from 'zustand/vanilla';
import { ulid } from '@shared/ulid';
import { isApiError, type ApiError } from '@shared/http';
import type {
  AdviceLineRow,
  Complaint,
  Diagnosis,
  DrugRef,
  DraftSaveRequest,
  DraftSaveResponse,
  InvestigationLineRow,
  IssueResult,
  ItemDisplay,
  ParseIssue,
  ParsedLine,
  PrescriptionDraft,
  PrescriptionItemRow,
  PrescriptionLanguage,
  ReferralRow,
  SafetyAlert,
  SafetyComputed,
  SafetyOverride,
  VitalsRow,
  WriterPageProps,
} from '@shared/types/models';
import { applyTemplate as applyTemplateCall, checkSafety, issuePrescription, saveDraft, type IssueBody } from '@panel/api/prescription';
import { contextFor, drugInput } from '../context';
import { itemDisplay } from '../display';
import { parseLine } from '../shorthand/parse';

export const SAVE_DEBOUNCE_MS = 400;
export const CHECK_DEBOUNCE_MS = 250;
export const RETRY_BACKOFF_MS = [1000, 2000, 5000, 10_000, 10_000, 30_000];
export const OVERRIDE_MIN_REASON = 10;

export type ItemStatus = 'editing' | 'committed' | 'saving' | 'error';

export interface RxItemDraft {
  key: string;
  id: number | null;
  sort_order: number;
  drug: DrugRef | null;
  shorthand: string;
  parsed: ParsedLine | null;
  display: { bn: ItemDisplay; en: ItemDisplay } | null;
  status: ItemStatus;
  /** Issues the SERVER reported for this line (422) — shown exactly like local errors. */
  serverIssues: ParseIssue[];
  safety_overrides: SafetyOverride[];
}

export interface FollowUp {
  on: string | null;
  days: number | null;
  note: string | null;
  create_booking: boolean;
}

export type FocusZone = 'complaints' | 'vitals' | 'findings' | 'diagnosis' | 'rx' | 'investigations' | 'advice' | 'follow_up' | 'referral' | 'issue';

export interface WriterApi {
  saveDraft: typeof saveDraft;
  checkSafety: typeof checkSafety;
  issuePrescription: typeof issuePrescription;
  applyTemplate: typeof applyTemplateCall;
}

const REAL_API: WriterApi = { saveDraft, checkSafety, issuePrescription, applyTemplate: applyTemplateCall };

/** ApiError only lifts `message`/`code`/`errors`; the 409 draft and the 422 issue-block carry their payload in the
 *  raw response body, so read it back off the cause (CONVENTIONS §13 keeps the dotted code as the branch key). */
export function responseBody(error: ApiError): Record<string, unknown> {
  const cause = error.cause as { response?: { data?: unknown } } | undefined;
  const data = cause?.response?.data;
  return typeof data === 'object' && data !== null ? (data as Record<string, unknown>) : {};
}

export interface WriterState {
  prescriptionId: string;
  visitId: string;
  version: number;
  language: PrescriptionLanguage;
  contDays: number;

  complaints: Complaint[];
  findings: string;
  diagnoses: Diagnosis[];
  items: RxItemDraft[];
  investigations: InvestigationLineRow[];
  advice: AdviceLineRow[];
  referrals: ReferralRow[];
  followUp: FollowUp;

  vitals: VitalsRow | null;
  vitalsReviewed: boolean;

  alerts: SafetyAlert[];
  issueBlockedBy: string[];
  computed: Record<string, SafetyComputed>;
  /** Local, not-yet-persisted overrides keyed by fingerprint. */
  overrides: Record<string, string>;
  acknowledged: string[];

  dirty: boolean;
  saving: boolean;
  checking: boolean;
  lastSavedAt: string | null;
  serverUpdatedAt: string | null;
  saveError: string | null;
  retries: number;
  conflict: PrescriptionDraft | null;
  templateSkipped: string[];
  focus: { zone: FocusZone; itemKey?: string };

  // ---- actions
  setLanguage(language: PrescriptionLanguage): void;
  setComplaints(complaints: Complaint[]): void;
  setFindings(text: string): void;
  setDiagnoses(diagnoses: Diagnosis[]): void;
  setInvestigations(rows: InvestigationLineRow[]): void;
  setAdvice(rows: AdviceLineRow[]): void;
  setReferrals(rows: ReferralRow[]): void;
  setFollowUp(next: Partial<FollowUp>): void;
  setVitals(vitals: VitalsRow | null): void;
  markVitalsReviewed(): void;

  addItem(at?: number, seed?: Partial<RxItemDraft>, options?: { focus?: boolean }): string;
  setDrug(key: string, drug: DrugRef | null): void;
  setShorthand(key: string, text: string): void;
  commitItem(key: string): void;
  removeItem(key: string): void;
  duplicateItem(key: string): string;
  moveItem(key: string, delta: number): void;

  override(fingerprint: string, reason: string): boolean;
  acknowledge(fingerprint: string): void;
  applyTemplate(templateId: number, mode?: 'append' | 'replace'): Promise<void>;

  save(immediate?: boolean): Promise<void>;
  scheduleSave(): void;
  scheduleCheck(): void;
  issue(options: { print: boolean; addToMedicationList?: boolean }): Promise<IssueResult>;
  reloadFromServer(draft: PrescriptionDraft): void;
  setFocus(zone: FocusZone, itemKey?: string): void;
  dismissConflict(): void;
  destroy(): void;
}

export type WriterStore = StoreApi<WriterState>;

// ---- derived selectors (pure, exported for the components and the tests) --------------------------------------

export function itemErrors(item: RxItemDraft): ParseIssue[] {
  return [...(item.parsed?.issues.filter((i) => i.severity === 'error') ?? []), ...item.serverIssues];
}

export function itemWarnings(item: RxItemDraft): ParseIssue[] {
  return item.parsed?.issues.filter((i) => i.severity === 'warning') ?? [];
}

export function itemInfos(item: RxItemDraft): ParseIssue[] {
  return item.parsed?.issues.filter((i) => i.severity === 'info') ?? [];
}

/** A line is usable when it has a drug and a clean parse. */
export function itemIsUsable(item: RxItemDraft): boolean {
  return item.drug !== null && itemErrors(item).length === 0;
}

export function blockingAlerts(state: Pick<WriterState, 'alerts'>): SafetyAlert[] {
  return state.alerts.filter((a) => a.severity === 'critical' && (a.overridden === null || !a.overridable));
}

export function terminalAlerts(state: Pick<WriterState, 'alerts'>): SafetyAlert[] {
  return state.alerts.filter((a) => a.severity === 'critical' && !a.overridable);
}

export function unacknowledgedWarnings(state: Pick<WriterState, 'alerts' | 'acknowledged'>): SafetyAlert[] {
  return state.alerts.filter((a) => a.severity === 'warning' && !state.acknowledged.includes(a.fingerprint));
}

export function alertsForItem(alerts: SafetyAlert[], key: string): SafetyAlert[] {
  return alerts.filter((a) => a.item_keys.includes(key));
}

export interface IssueReadiness {
  ready: boolean;
  reason: 'saving' | 'unsaved' | 'parse_error' | 'blocked' | 'empty' | null;
  blocking: SafetyAlert[];
}

export function issueReadiness(state: WriterState): IssueReadiness {
  const blocking = blockingAlerts(state);
  const filled = state.items.filter((i) => i.drug !== null || i.shorthand.trim() !== '');
  if (state.saving) return { ready: false, reason: 'saving', blocking };
  if (state.dirty || state.saveError !== null) return { ready: false, reason: 'unsaved', blocking };
  if (filled.some((i) => itemErrors(i).length > 0)) return { ready: false, reason: 'parse_error', blocking };
  if (blocking.length > 0) return { ready: false, reason: 'blocked', blocking };
  if (filled.length === 0 && state.investigations.length === 0 && state.advice.length === 0) return { ready: false, reason: 'empty', blocking };
  return { ready: true, reason: null, blocking };
}

// ---- helpers ---------------------------------------------------------------------------------------------------

function emptyItem(sortOrder: number, seed: Partial<RxItemDraft> = {}): RxItemDraft {
  return {
    key: ulid(),
    id: null,
    sort_order: sortOrder,
    drug: null,
    shorthand: '',
    parsed: null,
    display: null,
    status: 'editing',
    serverIssues: [],
    safety_overrides: [],
    ...seed,
  };
}

function fromServerItem(row: PrescriptionItemRow): RxItemDraft {
  return {
    key: row.key,
    id: row.id,
    sort_order: row.sort_order,
    drug: row.drug,
    shorthand: row.shorthand,
    parsed: row.parsed,
    display: row.display,
    status: 'committed',
    serverIssues: [],
    safety_overrides: row.safety_overrides,
  };
}

/** The full §4.13 body. `items` is always present so the server treats the save as a complete replacement. */
export function buildDraftBody(state: WriterState): DraftSaveRequest {
  return {
    language: state.language,
    visit: {
      chief_complaints: state.complaints.map((c, sort) => ({ text: c.text, text_bn: c.text_bn, duration: c.duration, sort })),
      examination_findings: state.findings.trim() === '' ? null : state.findings,
      diagnoses: state.diagnoses.map((d, sort) => ({ icd10_code: d.icd10_code, title: d.title, kind: d.kind, sort })),
      follow_up_on: state.followUp.on,
      follow_up_note: state.followUp.note,
    },
    follow_up_days: state.followUp.days,
    create_booking: state.followUp.create_booking,
    vitals_reviewed: state.vitalsReviewed,
    items: state.items
      .filter((i) => i.drug !== null || i.shorthand.trim() !== '')
      .map((i, sort) => ({
        key: i.key,
        id: i.id,
        sort_order: sort,
        drug: drugInput(i.drug),
        shorthand: i.shorthand,
        safety_overrides: overridesForItem(state, i),
      })),
    investigations: state.investigations.map((x, sort) => ({
      key: x.key,
      id: x.id,
      sort_order: sort,
      investigation_catalog_id: x.investigation_catalog_id,
      name: x.name,
      name_bn: x.name_bn,
      external_diagnostic_centre_id: x.external_diagnostic_centre_id,
      referral_note: x.referral_note,
      is_urgent: x.is_urgent,
    })),
    advice: state.advice.map((a, sort) => ({ key: a.key, id: a.id, sort_order: sort, advice_snippet_id: a.advice_snippet_id, text: a.text, text_bn: a.text_bn })),
    referrals: state.referrals.map((r, sort) => ({
      key: r.key,
      id: r.id,
      sort_order: sort,
      type: r.type,
      referred_to_doctor_id: r.referred_to_doctor_id,
      external_diagnostic_centre_id: r.external_diagnostic_centre_id,
      referred_to_name: r.referred_to_name,
      referred_to_specialty: r.referred_to_specialty,
      note: r.note,
      is_urgent: r.is_urgent,
    })),
    expected_updated_at: state.serverUpdatedAt,
  };
}

/** Overrides that name this item, persisted on the row (§4.13, §5.2). */
function overridesForItem(state: WriterState, item: RxItemDraft): Array<{ fingerprint: string; reason: string }> {
  const kept = item.safety_overrides.filter((o) => state.alerts.some((a) => a.fingerprint === o.fingerprint)).map((o) => ({ fingerprint: o.fingerprint, reason: o.reason }));
  for (const [fingerprint, reason] of Object.entries(state.overrides)) {
    const alert = state.alerts.find((a) => a.fingerprint === fingerprint);
    if (alert === undefined || !alert.item_keys.includes(item.key)) continue;
    if (kept.some((o) => o.fingerprint === fingerprint)) continue;
    kept.push({ fingerprint, reason });
  }
  return kept;
}

export interface WriterStoreOptions {
  api?: WriterApi;
  /** Test seam: replaces window.setTimeout so the debounce can be driven synchronously. */
  timers?: { setTimeout: (fn: () => void, ms: number) => number; clearTimeout: (id: number) => void };
}

export function createWriterStore(props: WriterPageProps, options: WriterStoreOptions = {}): WriterStore {
  const api = options.api ?? REAL_API;
  const timers = options.timers ?? { setTimeout: (fn, ms) => window.setTimeout(fn, ms), clearTimeout: (id) => window.clearTimeout(id) };

  let saveTimer: number | null = null;
  let checkTimer: number | null = null;
  let retryTimer: number | null = null;
  let checkAbort: AbortController | null = null;
  let inFlight: Promise<void> | null = null;
  let destroyed = false;

  const store = createStore<WriterState>((set, get) => {
    /** Re-parse a line against its drug's presentation. */
    const reparse = (item: RxItemDraft, contDays: number): RxItemDraft => {
      if (item.drug === null && item.shorthand.trim() === '') return { ...item, parsed: null, display: null, serverIssues: [] };
      const ctx = contextFor(item.drug, { contDays });
      const parsed = parseLine(item.shorthand, ctx);
      return { ...item, parsed, display: { bn: itemDisplay('bn', parsed), en: itemDisplay('en', parsed) }, serverIssues: [] };
    };

    const touch = (): void => {
      set({ dirty: true });
      get().scheduleSave();
      get().scheduleCheck();
    };

    const applyServerDraft = (draft: PrescriptionDraft, sentShorthand: Map<string, string>): void => {
      const state = get();
      const byKey = new Map(draft.items.map((row) => [row.key, row]));
      const items = state.items.map((item) => {
        const row = byKey.get(item.key);
        if (row === undefined) return item;
        // Only adopt the server parse when the doctor has not typed on since the request left.
        const unchanged = sentShorthand.get(item.key) === item.shorthand;
        return {
          ...item,
          id: row.id,
          drug: row.drug ?? item.drug,
          parsed: unchanged ? row.parsed : item.parsed,
          display: unchanged ? row.display : item.display,
          safety_overrides: row.safety_overrides,
          serverIssues: [],
          status: unchanged ? ('committed' as ItemStatus) : item.status,
        };
      });
      // Rows the server created that the client does not know about (apply-template, another tab).
      for (const row of draft.items) if (!items.some((i) => i.key === row.key)) items.push(fromServerItem(row));

      set({
        items: items.sort((a, b) => a.sort_order - b.sort_order),
        investigations: draft.investigations,
        advice: draft.advice,
        referrals: draft.referrals,
        alerts: draft.alerts,
        issueBlockedBy: draft.issue_blocked_by,
        version: draft.version,
        language: draft.language,
        serverUpdatedAt: draft.updated_at,
        overrides: {},
      });
    };

    return {
      prescriptionId: props.prescription.id,
      visitId: props.visit.id,
      version: props.prescription.version,
      language: props.prescription.language,
      contDays: props.doctor.prefs.cont_days,

      complaints: props.visit.chief_complaints.map((c, sort) => ({ key: c.key ?? ulid(), ...c, sort })),
      findings: props.visit.examination_findings ?? '',
      diagnoses: props.visit.diagnoses.map((d, sort) => ({ key: d.key ?? ulid(), ...d, sort })),
      items: props.prescription.items.map(fromServerItem),
      investigations: props.prescription.investigations,
      advice: props.prescription.advice,
      referrals: props.prescription.referrals,
      followUp: { on: props.visit.follow_up_on, days: null, note: props.visit.follow_up_note, create_booking: true },

      vitals: props.vitals,
      vitalsReviewed: props.vitals?.reviewed_by_doctor_at != null,

      alerts: props.prescription.alerts,
      issueBlockedBy: props.prescription.issue_blocked_by,
      computed: {},
      overrides: {},
      acknowledged: [],

      dirty: false,
      saving: false,
      checking: false,
      lastSavedAt: null,
      serverUpdatedAt: props.prescription.updated_at,
      saveError: null,
      retries: 0,
      conflict: null,
      templateSkipped: [],
      focus: { zone: props.visit.chief_complaints.length === 0 ? 'complaints' : 'rx' },

      setLanguage(language) {
        set({ language });
        touch();
      },
      setComplaints(complaints) {
        set({ complaints: complaints.map((c, sort) => ({ ...c, sort })) });
        touch();
      },
      setFindings(findings) {
        set({ findings });
        touch();
      },
      setDiagnoses(diagnoses) {
        set({ diagnoses: diagnoses.map((d, sort) => ({ ...d, sort })) });
        touch();
      },
      setInvestigations(investigations) {
        set({ investigations });
        touch();
      },
      setAdvice(advice) {
        set({ advice });
        touch();
      },
      setReferrals(referrals) {
        set({ referrals });
        touch();
      },
      setFollowUp(next) {
        set({ followUp: { ...get().followUp, ...next } });
        touch();
      },
      setVitals(vitals) {
        set({ vitals });
      },
      markVitalsReviewed() {
        set({ vitalsReviewed: true });
        touch();
      },

      addItem(at, seed, options) {
        const items = [...get().items];
        const item = emptyItem(items.length, seed);
        const index = at ?? items.length;
        items.splice(index, 0, item);
        const next = items.map((i, sort) => ({ ...i, sort_order: sort }));
        // The always-present trailing blank line must never steal focus from the zone the doctor is in.
        set(options?.focus === false ? { items: next } : { items: next, focus: { zone: 'rx', itemKey: item.key } });
        if (seed?.drug !== undefined || seed?.shorthand !== undefined) touch();
        return item.key;
      },

      setDrug(key, drug) {
        const contDays = get().contDays;
        set({ items: get().items.map((i) => (i.key === key ? reparse({ ...i, drug, status: 'editing' }, contDays) : i)) });
        touch();
      },

      setShorthand(key, text) {
        const contDays = get().contDays;
        set({ items: get().items.map((i) => (i.key === key ? reparse({ ...i, shorthand: text, status: 'editing' }, contDays) : i)) });
        set({ dirty: true });
        get().scheduleSave();
      },

      commitItem(key) {
        const item = get().items.find((i) => i.key === key);
        if (item === undefined) return;
        const status: ItemStatus = itemErrors(item).length > 0 ? 'error' : 'committed';
        set({ items: get().items.map((i) => (i.key === key ? { ...i, status } : i)) });
        if (status === 'committed') touch();
      },

      removeItem(key) {
        set({ items: get().items.filter((i) => i.key !== key).map((i, sort) => ({ ...i, sort_order: sort })) });
        touch();
      },

      duplicateItem(key) {
        const items = [...get().items];
        const index = items.findIndex((i) => i.key === key);
        const source = items[index];
        if (source === undefined) return key;
        const copy: RxItemDraft = { ...source, key: ulid(), id: null, safety_overrides: [], status: 'editing' };
        items.splice(index + 1, 0, copy);
        set({ items: items.map((i, sort) => ({ ...i, sort_order: sort })), focus: { zone: 'rx', itemKey: copy.key } });
        touch();
        return copy.key;
      },

      moveItem(key, delta) {
        const items = [...get().items];
        const index = items.findIndex((i) => i.key === key);
        const target = index + delta;
        if (index < 0 || target < 0 || target >= items.length) return;
        const [moved] = items.splice(index, 1);
        if (moved === undefined) return;
        items.splice(target, 0, moved);
        set({ items: items.map((i, sort) => ({ ...i, sort_order: sort })) });
        touch();
      },

      override(fingerprint, reason) {
        const alert = get().alerts.find((a) => a.fingerprint === fingerprint);
        if (alert === undefined || !alert.overridable || reason.trim().length < OVERRIDE_MIN_REASON) return false;
        set({ overrides: { ...get().overrides, [fingerprint]: reason.trim() } });
        touch();
        return true;
      },

      acknowledge(fingerprint) {
        if (get().acknowledged.includes(fingerprint)) return;
        set({ acknowledged: [...get().acknowledged, fingerprint] });
      },

      async applyTemplate(templateId, mode = 'append') {
        const state = get();
        await state.save(true);
        const response = await api.applyTemplate(state.prescriptionId, templateId, mode);
        set({ templateSkipped: response.prescription.template_skipped ?? [] });
        get().reloadFromServer(response.prescription);
      },

      scheduleSave() {
        if (destroyed) return;
        if (saveTimer !== null) timers.clearTimeout(saveTimer);
        saveTimer = timers.setTimeout(() => {
          saveTimer = null;
          void get().save();
        }, SAVE_DEBOUNCE_MS);
      },

      scheduleCheck() {
        if (destroyed) return;
        if (checkTimer !== null) timers.clearTimeout(checkTimer);
        checkTimer = timers.setTimeout(() => {
          checkTimer = null;
          const state = get();
          const body = buildDraftBody(state);
          const items = body.items ?? [];
          if (items.length === 0) return;
          checkAbort?.abort();
          checkAbort = new AbortController();
          set({ checking: true });
          api
            .checkSafety(state.prescriptionId, { items, overrides: Object.entries(state.overrides).map(([fingerprint, reason]) => ({ fingerprint, reason })) }, checkAbort.signal)
            .then((report) => {
              if (destroyed) return;
              set({ alerts: report.alerts, issueBlockedBy: report.issue_blocked_by, computed: report.computed.items, checking: false });
            })
            .catch(() => {
              if (!destroyed) set({ checking: false });
            });
        }, CHECK_DEBOUNCE_MS);
      },

      async save(immediate = false) {
        if (destroyed) return;
        if (saveTimer !== null) {
          timers.clearTimeout(saveTimer);
          saveTimer = null;
        }
        if (retryTimer !== null) {
          timers.clearTimeout(retryTimer);
          retryTimer = null;
        }
        if (inFlight !== null) {
          await inFlight;
          if (!get().dirty && !immediate) return;
        }
        const state = get();
        if (!state.dirty && !immediate) return;
        if (state.conflict !== null) return;

        const body = buildDraftBody(state);
        const sent = new Map((body.items ?? []).map((i) => [i.key, i.shorthand]));
        set({ saving: true, dirty: false });

        const run = async (): Promise<void> => {
          try {
            const response: DraftSaveResponse = await api.saveDraft(state.prescriptionId, body);
            if (destroyed) return;
            applyServerDraft(response.prescription, sent);
            set({ saving: false, saveError: null, retries: 0, lastSavedAt: new Date().toISOString(), alerts: response.alerts, issueBlockedBy: response.issue_blocked_by });
          } catch (error) {
            if (destroyed) return;
            set({ saving: false });
            if (!isApiError(error)) {
              set({ dirty: true, saveError: String(error) });
              return;
            }
            if (error.is('prescriptions.draft_conflict')) {
              const conflict = (responseBody(error).prescription as PrescriptionDraft | undefined) ?? null;
              set({ conflict, saveError: error.message, dirty: true });
              return;
            }
            if (error.is('prescriptions.parse_error')) {
              const errors = error.errors as unknown as Record<string, ParseIssue[]>;
              set({
                items: get().items.map((item) => {
                  const issues = errors[`items.${item.key}`];
                  return issues === undefined ? item : { ...item, serverIssues: issues, status: 'error' as ItemStatus };
                }),
                saveError: error.message,
                dirty: true,
              });
              return;
            }
            // Network / server: keep the body dirty and retry with backoff.
            const retries = get().retries;
            set({ dirty: true, saveError: error.message, retries: retries + 1 });
            const wait = RETRY_BACKOFF_MS[Math.min(retries, RETRY_BACKOFF_MS.length - 1)] ?? 10_000;
            retryTimer = timers.setTimeout(() => {
              retryTimer = null;
              void get().save();
            }, wait);
          }
        };

        inFlight = run().finally(() => {
          inFlight = null;
        });
        await inFlight;
      },

      async issue(options) {
        await get().save(true);
        const state = get();
        const body: IssueBody = {
          language: state.language,
          print: options.print,
          acknowledged_warnings: state.acknowledged,
          expected_updated_at: state.serverUpdatedAt,
          add_to_medication_list: options.addToMedicationList ?? true,
        };

        try {
          return await api.issuePrescription(state.prescriptionId, body);
        } catch (error) {
          // The server is the authority on blocking: adopt its alert set so the strip shows exactly what stopped it.
          if (isApiError(error) && error.is('prescriptions.issue_blocked')) {
            const payload = responseBody(error);
            set({ alerts: (payload.alerts as SafetyAlert[] | undefined) ?? get().alerts, issueBlockedBy: (payload.issue_blocked_by as string[] | undefined) ?? get().issueBlockedBy });
          }
          if (isApiError(error) && error.is('prescriptions.parse_error')) {
            const errors = error.errors as unknown as Record<string, ParseIssue[]>;
            set({
              items: get().items.map((item) => {
                const issues = errors[`items.${item.key}`];
                return issues === undefined ? item : { ...item, serverIssues: issues, status: 'error' as ItemStatus };
              }),
            });
          }
          throw error;
        }
      },

      reloadFromServer(draft) {
        set({
          items: draft.items.map(fromServerItem),
          investigations: draft.investigations,
          advice: draft.advice,
          referrals: draft.referrals,
          alerts: draft.alerts,
          issueBlockedBy: draft.issue_blocked_by,
          version: draft.version,
          language: draft.language,
          serverUpdatedAt: draft.updated_at,
          overrides: {},
          conflict: null,
          dirty: false,
          saveError: null,
          retries: 0,
        });
      },

      setFocus(zone, itemKey) {
        set({ focus: { zone, itemKey } });
      },

      dismissConflict() {
        set({ conflict: null });
      },

      destroy() {
        destroyed = true;
        if (saveTimer !== null) timers.clearTimeout(saveTimer);
        if (checkTimer !== null) timers.clearTimeout(checkTimer);
        if (retryTimer !== null) timers.clearTimeout(retryTimer);
        checkAbort?.abort();
      },
    };
  });

  return store;
}
