// A minimal but realistic WriterPageProps so the store and component tests exercise the real wire shapes.
import type { DrugRef, PrescriptionDraft, WriterPageProps } from '@shared/types/models';

export const NAPA: DrugRef = {
  kind: 'presentation',
  generic_id: 17,
  brand_id: 88,
  custom_brand_id: null,
  strength_id: 1234,
  generic_name: 'Paracetamol',
  brand_name: 'Napa',
  strength: '500 mg',
  form: 'Tablet',
  form_code: 'tab',
  route: 'Oral',
  route_code: 'po',
  pack_size: null,
  pack_unit: null,
  strength_mg: 500,
  per_ml: null,
};

export const SYRUP: DrugRef = {
  ...NAPA,
  strength_id: 4321,
  brand_name: 'Napa Syrup',
  strength: '120 mg/5 ml',
  form: 'Syrup',
  form_code: 'syr',
  pack_size: 100,
  pack_unit: 'bottle',
  strength_mg: 120,
  per_ml: 24,
};

export function emptyDraft(overrides: Partial<PrescriptionDraft> = {}): PrescriptionDraft {
  return {
    id: '01JRXWRITERDRAFT0000000001',
    version: 1,
    status: 'draft',
    language: 'both',
    root_id: '01JRXWRITERDRAFT0000000001',
    supersedes_id: null,
    amend_reason: null,
    updated_at: '2026-09-07T10:00:00+06:00',
    mode: 'structured',
    handwriting_image_path: null,
    drawing_json: null,
    drawing_image_path: null,
    items: [],
    investigations: [],
    advice: [],
    referrals: [],
    alerts: [],
    issue_blocked_by: [],
    ...overrides,
  };
}

export function writerProps(overrides: Partial<WriterPageProps> = {}): WriterPageProps {
  return {
    visit: {
      id: '01JRXVISIT00000000000000001',
      status: 'open',
      type: 'opd',
      serial_display: 'A-042',
      session_code: 'A',
      started_at: '2026-09-07T09:55:00+06:00',
      ended_at: null,
      appointment_id: null,
      chief_complaints: [],
      examination_findings: null,
      diagnoses: [],
      follow_up_on: null,
      follow_up_note: null,
      current_prescription_id: null,
    },
    patient: {
      public_id: '01JRXPATIENT0000000000001',
      patient_code: 'P-000331',
      name: 'Rahima Khatun',
      age_text: '34 y',
      age_years: 34,
      age_months: 412,
      sex: 'female',
      phone: '01712345678',
      mobile: '+8801712345678',
      mobile_masked: '017*****678',
      blood_group: 'B+',
      dob: '1992-01-01',
      family_head: null,
      allergies: [],
      conditions: [],
      medications: [],
      flags: { pregnant: false, lactating: false, renal: false, hepatic: false },
    },
    vitals: null,
    recent_visits: [],
    prescription: emptyDraft(),
    doctor: {
      id: 7,
      public_id: '01JRXDOCTOR000000000000001',
      name: 'Dr. Karim',
      pad: { paper_size: 'A4', letterhead_enabled: true, preprinted_mode: false, default_language: 'both', show_qr: true, show_vitals: true, layout: {} },
      prefs: { default_duration_days: 5, cont_days: 30, dictation_lang: 'bn-BD', cheatsheet_seen_count: 0 },
    },
    quick_pick: { top_drugs: [], templates: [], snippets: [], investigations: [], external_centres: [] },
    features: { ai: false, voice: true, handwriting: true, drawing_backgrounds: ['blank', 'dental_adult'] },
    cheat_sheet_version: '1.deadbeef',
    ...overrides,
  };
}

/** A hand-driven scheduler so the 400 ms autosave debounce is deterministic in tests. */
export function manualTimers() {
  const queue = new Map<number, () => void>();
  let next = 1;
  return {
    api: {
      setTimeout: (fn: () => void): number => {
        const id = next++;
        queue.set(id, fn);
        return id;
      },
      clearTimeout: (id: number): void => {
        queue.delete(id);
      },
    },
    pending: (): number => queue.size,
    run(): void {
      const entries = [...queue.values()];
      queue.clear();
      for (const fn of entries) fn();
    },
  };
}
