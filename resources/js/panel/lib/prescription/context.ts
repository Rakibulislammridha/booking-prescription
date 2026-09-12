// ParseContext (PRESCRIPTION.md §2.15) built on the client from the picked presentation — the search document, a
// favourite/top-50 row or a persisted item's DrugRef. The server rebuilds the same thing from the catalog row, so
// the two parses see the same presentation.
import type { DrugRef, DrugSearchHit, ParseContext, TopDrug } from '@shared/types/models';
import { forms } from './shorthand/keywords';

const LIQUID_FORMS = new Set(['syr', 'susp', 'sol', 'oral_drop', 'eye_drop', 'ear_drop', 'nasal_drop', 'nasal_spray', 'neb']);

export interface ContextOptions {
  contDays?: number;
  locale?: 'bn' | 'en';
}

export function contextFor(drug: DrugRef | null, options: ContextOptions = {}): ParseContext | null {
  if (drug === null) return null;

  const formCode = drug.form_code ?? null;
  const formDef = formCode !== null ? forms()[formCode] : undefined;
  // The server resolves the dosage form's own default unit; fall back to the shared keyword table, then to tablets.
  const defaultUnit = drug.default_unit ?? formDef?.default_unit ?? 'tab';

  return {
    form_code: formCode,
    default_unit: defaultUnit as ParseContext['default_unit'],
    pack_size: drug.pack_size ?? null,
    pack_unit: drug.pack_unit ?? formDef?.pack_unit ?? null,
    strength_mg: drug.strength_mg ?? null,
    per_ml: drug.per_ml ?? null,
    is_liquid: formCode !== null ? LIQUID_FORMS.has(formCode) : false,
    cont_days: options.contDays ?? 30,
    locale: options.locale ?? 'en',
    route_code: drug.route_code ?? null,
    strength_label: drug.strength ?? null,
    form_label: drug.form ?? null,
  };
}

/** A search hit → the DrugRef the writer commits as the line's chip. */
export function drugFromHit(hit: DrugSearchHit): DrugRef {
  const kind: DrugRef['kind'] = hit.custom_brand_id != null ? 'custom' : hit.doc_type === 'generic' ? 'generic' : 'presentation';

  return {
    kind,
    generic_id: hit.generic_id,
    brand_id: hit.brand_id ?? null,
    custom_brand_id: hit.custom_brand_id ?? null,
    strength_id: hit.strength_id ?? null,
    generic_name: hit.generic_name,
    brand_name: hit.brand_name ?? null,
    strength: hit.strength_label ?? null,
    form: hit.form ?? null,
    form_code: hit.form_code ?? null,
    route: hit.route ?? null,
    route_code: hit.route_code ?? null,
    pack_size: hit.pack_size_value ?? null,
    pack_unit: hit.pack_unit ?? null,
    strength_mg: hit.strength_mg ?? null,
    per_ml: hit.per_ml ?? null,
    info_slug: hit.info_slug ?? null,
    default_unit: hit.default_unit ?? null,
    label: hit.label,
  };
}

/**
 * A quick-pick / favourite row → the DrugRef the writer commits as the line's chip. The row already carries the
 * presentation facts (§3.5), so the line parses against the real form on its FIRST render — no re-parse flicker
 * when the server echoes its own parse back.
 */
export function drugFromTop(row: TopDrug): DrugRef {
  const drug = row.drug;

  return {
    kind: drug.kind,
    generic_id: drug.generic_id ?? 0,
    brand_id: drug.brand_id ?? null,
    custom_brand_id: drug.custom_brand_id ?? null,
    strength_id: drug.strength_id ?? null,
    generic_name: drug.generic_name ?? row.label,
    brand_name: drug.brand_name ?? null,
    strength: drug.strength ?? null,
    form: drug.form ?? null,
    form_code: drug.form_code ?? null,
    default_unit: drug.default_unit ?? null,
    route: drug.route ?? null,
    route_code: drug.route_code ?? null,
    pack_size: drug.pack_size ?? null,
    pack_unit: drug.pack_unit ?? null,
    strength_mg: drug.strength_mg ?? null,
    per_ml: drug.per_ml ?? null,
    label: row.label,
  };
}

/** The one-line name of a drug as the pad shows it: brand first, generic in brackets. */
export function drugLabel(drug: DrugRef | null): string {
  if (drug === null) return '';
  const head = [drug.brand_name ?? drug.generic_name, drug.strength, drug.form].filter((p) => p != null && p !== '').join(' ');
  return drug.brand_name !== null && drug.brand_name !== drug.generic_name ? `${head} · ${drug.generic_name}` : head;
}

/** What the POST body carries for a line's drug (§4.13 — only the ids are read). */
export function drugInput(drug: DrugRef | null): { generic_id: number | null; brand_id: number | null; custom_brand_id: number | null; strength_id: number | null } | null {
  if (drug === null) return null;
  return { generic_id: drug.generic_id, brand_id: drug.brand_id, custom_brand_id: drug.custom_brand_id, strength_id: drug.strength_id };
}
