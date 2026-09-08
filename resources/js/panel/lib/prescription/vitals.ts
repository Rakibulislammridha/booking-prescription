// The one client-side vitals helper, shared by the doctor's writer card and the compounder's desk screen
// (BRIEF §5.G.2 — the same reading, entered at the desk and reviewed in the writer). BMI is computed here so it
// moves while typing; the server recomputes it in `RecordVitals` and its value is the one that is stored.
import { toAsciiDigits } from '@shared/format/number';
import type { VitalsInput, VitalsRow } from '@shared/types/models';

export function bmiOf(weightKg: number | null | undefined, heightCm: number | null | undefined): number | null {
  if (weightKg == null || heightCm == null || heightCm <= 0) return null;
  return Math.round((weightKg / (heightCm / 100) ** 2) * 10) / 10;
}

export type VitalsMeasurementKey =
  | 'bp_systolic' | 'bp_diastolic' | 'pulse_bpm' | 'temperature_c' | 'spo2_percent'
  | 'respiratory_rate' | 'weight_kg' | 'height_cm' | 'blood_glucose_mgdl';

export interface VitalsFieldSpec {
  key: VitalsMeasurementKey;
  labelKey: string;
  unit: string;
  width: number;
}

/** The measurement columns of `vitals`, in the order a compounder takes them (PRESCRIPTION.md §4.2). */
export const VITALS_FIELDS: readonly VitalsFieldSpec[] = [
  { key: 'bp_systolic', labelKey: 'prescriptions.vitals.bp_systolic', unit: '', width: 66 },
  { key: 'bp_diastolic', labelKey: 'prescriptions.vitals.bp_diastolic', unit: '', width: 66 },
  { key: 'pulse_bpm', labelKey: 'prescriptions.vitals.pulse', unit: 'bpm', width: 66 },
  { key: 'temperature_c', labelKey: 'prescriptions.vitals.temperature', unit: '°C', width: 72 },
  { key: 'spo2_percent', labelKey: 'prescriptions.vitals.spo2', unit: '%', width: 66 },
  { key: 'respiratory_rate', labelKey: 'prescriptions.vitals.resp_rate', unit: '/min', width: 66 },
  { key: 'weight_kg', labelKey: 'prescriptions.vitals.weight', unit: 'kg', width: 72 },
  { key: 'height_cm', labelKey: 'prescriptions.vitals.height', unit: 'cm', width: 72 },
  { key: 'blood_glucose_mgdl', labelKey: 'prescriptions.vitals.glucose', unit: 'mg/dL', width: 80 },
];

/**
 * Measurements are held as TEXT while they are being typed. Parsing on every keystroke looks harmless and is not:
 * `Number('37.')` is `37`, so the field re-renders without the point the compounder just pressed and 37.6 °C is
 * silently recorded as 376. Numbers are produced once, on save.
 */
export type VitalsDraft = { [K in VitalsMeasurementKey]?: string } & { notes?: string | null };

export function draftFrom(row: VitalsRow | null): VitalsDraft {
  if (row === null) return {};
  const draft: VitalsDraft = { notes: row.notes };
  for (const field of VITALS_FIELDS) {
    const value = row[field.key as keyof VitalsRow];
    if (typeof value === 'number') draft[field.key] = String(value);
  }

  return draft;
}

/** One measurement as a number, tolerating a Bangla-keyboard entry (CONVENTIONS §7.5: never store Bangla digits). */
export function draftNumber(draft: VitalsDraft, key: VitalsMeasurementKey): number | null {
  const raw = draft[key];

  if (raw === undefined || raw.trim() === '') return null;

  const value = Number(toAsciiDigits(raw.trim()));

  return Number.isFinite(value) ? value : null;
}

export function draftIsEmpty(draft: VitalsDraft): boolean {
  return VITALS_FIELDS.every((field) => draftNumber(draft, field.key) === null);
}

/** The POST/PATCH body: every measurement the compounder actually filled, plus the note. */
export function draftToInput(draft: VitalsDraft): VitalsInput {
  const input: VitalsInput = {};

  for (const field of VITALS_FIELDS) {
    input[field.key] = draftNumber(draft, field.key);
  }

  const notes = (draft.notes ?? '').trim();

  return { ...input, notes: notes === '' ? null : notes };
}
