// The one client-side vitals helper, shared by the doctor's writer card and the compounder's desk screen
// (BRIEF §5.G.2 — the same reading, entered at the desk and reviewed in the writer). BMI is computed here so it
// moves while typing; the server recomputes it in `RecordVitals` and its value is the one that is stored.
// Temperature is typed and shown in °F (`temperature_f` on the wire in) while the row and the database keep °C
// (`temperature_c`): `@shared/format/temperature` is the one conversion, applied when a saved row is loaded into
// the form and when a row is displayed — never on a keystroke.
import { formatBn, toAsciiDigits } from '@shared/format/number';
import { cToF, fToC, TEMPERATURE_C_MAX, TEMPERATURE_C_MIN, temperaturePlaceholder, temperatureUnit } from '@shared/format/temperature';
import { getLocale } from '@shared/locale';
import type { Locale } from '@shared/types/shared-props';
import type { VitalsInput, VitalsRow } from '@shared/types/models';

export function bmiOf(weightKg: number | null | undefined, heightCm: number | null | undefined): number | null {
  if (weightKg == null || heightCm == null || heightCm <= 0) return null;
  return Math.round((weightKg / (heightCm / 100) ** 2) * 10) / 10;
}

export type VitalsMeasurementKey =
  | 'bp_systolic' | 'bp_diastolic' | 'pulse_bpm' | 'temperature_f' | 'spo2_percent'
  | 'respiratory_rate' | 'weight_kg' | 'height_cm' | 'blood_glucose_mgdl';

export interface VitalsFieldSpec {
  key: VitalsMeasurementKey;
  labelKey: string;
  /** the adornment; `unitBn` when the symbol itself has a Bangla form (°F → °ফা), else the Latin one is shown in both */
  unit: string;
  unitBn?: string;
  /** an example shown while the field is empty (ASCII digits; localised by `fieldPlaceholder`) */
  placeholder?: string;
  width: number;
}

/** The measurement columns of `vitals`, in the order a compounder takes them (PRESCRIPTION.md §4.2). */
export const VITALS_FIELDS: readonly VitalsFieldSpec[] = [
  { key: 'bp_systolic', labelKey: 'prescriptions.vitals.bp_systolic', unit: '', width: 66 },
  { key: 'bp_diastolic', labelKey: 'prescriptions.vitals.bp_diastolic', unit: '', width: 66 },
  { key: 'pulse_bpm', labelKey: 'prescriptions.vitals.pulse', unit: 'bpm', width: 66 },
  { key: 'temperature_f', labelKey: 'prescriptions.vitals.temperature', unit: temperatureUnit('en'), unitBn: temperatureUnit('bn'), placeholder: '98.6', width: 78 },
  { key: 'spo2_percent', labelKey: 'prescriptions.vitals.spo2', unit: '%', width: 66 },
  { key: 'respiratory_rate', labelKey: 'prescriptions.vitals.resp_rate', unit: '/min', width: 66 },
  { key: 'weight_kg', labelKey: 'prescriptions.vitals.weight', unit: 'kg', width: 72 },
  { key: 'height_cm', labelKey: 'prescriptions.vitals.height', unit: 'cm', width: 72 },
  { key: 'blood_glucose_mgdl', labelKey: 'prescriptions.vitals.glucose', unit: 'mg/dL', width: 80 },
];

export function fieldUnit(field: VitalsFieldSpec, locale: Locale = getLocale()): string {
  return locale === 'bn' && field.unitBn !== undefined ? field.unitBn : field.unit;
}

export function fieldPlaceholder(field: VitalsFieldSpec, locale: Locale = getLocale()): string | undefined {
  if (field.placeholder === undefined) return undefined;
  return field.key === 'temperature_f' ? temperaturePlaceholder(locale) : formatBn(field.placeholder, locale);
}

/**
 * Measurements are held as TEXT while they are being typed. Parsing on every keystroke looks harmless and is not:
 * `Number('37.')` is `37`, so the field re-renders without the point the compounder just pressed and 37.6 °C is
 * silently recorded as 376. Numbers are produced once, on save.
 */
export type VitalsDraft = { [K in VitalsMeasurementKey]?: string } & { notes?: string | null };

/** The saved row back into the form: the °C the row carries becomes the °F the compounder typed. */
export function draftFrom(row: VitalsRow | null): VitalsDraft {
  if (row === null) return {};
  const draft: VitalsDraft = { notes: row.notes };
  for (const field of VITALS_FIELDS) {
    const value = field.key === 'temperature_f'
      ? (row.temperature_c === null ? null : cToF(row.temperature_c))
      : row[field.key];
    if (typeof value === 'number') draft[field.key] = String(value);
  }

  return draft;
}

// A typed unit is tolerated and dropped: "98.6°F", "98.6 F", "৯৮.৬°ফা" all read as 98.6. The unit does not change
// what the field means — a °C value typed with a "C" is unparsable, and `temperatureHint` covers the bare one.
const UNIT_SUFFIX = /\s*(°\s*)?(f|ফা)\.?$/iu;

/** One measurement as a number, tolerating a Bangla-keyboard entry (CONVENTIONS §7.5: never store Bangla digits). */
export function draftNumber(draft: VitalsDraft, key: VitalsMeasurementKey): number | null {
  const raw = draft[key];

  if (raw === undefined || raw.trim() === '') return null;

  const text = key === 'temperature_f' ? raw.trim().replace(UNIT_SUFFIX, '') : raw.trim();
  const value = Number(toAsciiDigits(text));

  return Number.isFinite(value) ? value : null;
}

/**
 * A reading of 30–45 in the °F field is a thermometer read in °C: nobody alive has a temperature of 37 °F. The
 * form does not convert it silently (PRESCRIPTION.md §2.13 — never guess) — it says what the °F value would be
 * and lets the compounder type it; the server refuses the value regardless.
 */
export function temperatureHint(draft: VitalsDraft, locale: Locale = getLocale()): { c: string; f: string } | null {
  const value = draftNumber(draft, 'temperature_f');
  if (value === null || value < TEMPERATURE_C_MIN || value > TEMPERATURE_C_MAX) return null;

  return { c: formatBn(value, locale), f: formatBn(cToF(value).toFixed(1), locale) };
}

/** The °C a °F entry will be stored as (what `VitalsData::fromRequest` computes on the server). */
export function draftTemperatureC(draft: VitalsDraft): number | null {
  const value = draftNumber(draft, 'temperature_f');
  return value === null ? null : fToC(value);
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
