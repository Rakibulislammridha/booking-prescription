// The plan editor's arithmetic, kept pure so it can be pinned by a test: what the operator types (taka, MB, a
// count) versus what the wire carries (integer paisa, bytes, the count) — and the diff shown before a plan that
// clinics are already on is saved. Nothing here is a float that reaches the server.
import { parseBdt } from '@shared/format/money';
import type { SuperPlan } from '@panel/Components/Super/types';

export const MB = 1024 * 1024;

/** `storage_bytes` is edited in whole megabytes; everything else is a plain count. */
export function isBytesKey(key: string): boolean {
  return key === 'storage_bytes';
}

export function bytesToMb(bytes: number): number {
  return Math.floor(bytes / MB);
}

export function mbToBytes(mb: number): number {
  return Math.max(0, Math.trunc(mb)) * MB;
}

/** "12" → 12, "" → null (unlimited), junk → null. Never negative, never fractional. */
export function parseCount(raw: string): number | null {
  const trimmed = raw.trim().replace(/[০-৯]/g, (d) => String('০১২৩৪৫৬৭৮৯'.indexOf(d)));
  if (trimmed === '') return null;
  const value = Number(trimmed.replace(/[^\d]/g, ''));
  return Number.isFinite(value) ? Math.max(0, Math.trunc(value)) : null;
}

/** Taka typed in a field → integer paisa on the wire; an empty or unreadable field is 0. */
export function takaToPaisa(raw: string): number {
  return parseBdt(raw) ?? 0;
}

/** Integer paisa → the taka string the field shows ("1,234.50" without the sign, no float rounding). */
export function paisaToTaka(paisa: number): string {
  const abs = Math.abs(paisa);
  const whole = Math.floor(abs / 100);
  const frac = abs % 100;
  return `${paisa < 0 ? '-' : ''}${whole}${frac === 0 ? '' : `.${String(frac).padStart(2, '0')}`}`;
}

export interface PlanFormData {
  code: string;
  name: string;
  description: string;
  price_monthly_paisa: number;
  price_yearly_paisa: number;
  trial_days: number;
  is_public: boolean;
  is_addon: boolean;
  sort_order: number;
  limits: Record<string, number | null>;
  toggles: Record<string, boolean>;
  acknowledge: boolean;
}

export function emptyPlanForm(limitKeys: string[], toggleKeys: string[], sortOrder: number): PlanFormData {
  const limits: Record<string, number | null> = {};
  const toggles: Record<string, boolean> = {};
  for (const key of limitKeys) limits[key] = null;
  for (const key of toggleKeys) toggles[key] = false;

  return {
    code: '', name: '', description: '', price_monthly_paisa: 0, price_yearly_paisa: 0, trial_days: 14,
    is_public: true, is_addon: false, sort_order: sortOrder, limits, toggles, acknowledge: false,
  };
}

export function planToForm(plan: SuperPlan, limitKeys: string[], toggleKeys: string[], fallbackOrder: number): PlanFormData {
  const limits: Record<string, number | null> = {};
  const toggles: Record<string, boolean> = {};
  for (const key of limitKeys) limits[key] = plan.limits.find((row) => row.key === key)?.value ?? null;
  for (const key of toggleKeys) toggles[key] = plan.toggles.find((row) => row.key === key)?.enabled ?? false;

  return {
    code: plan.code,
    name: plan.name,
    description: plan.description ?? '',
    price_monthly_paisa: plan.price_monthly_paisa,
    price_yearly_paisa: plan.price_yearly_paisa,
    trial_days: plan.trial_days,
    is_public: plan.is_public ?? true,
    is_addon: plan.is_addon,
    sort_order: plan.sort_order ?? fallbackOrder,
    limits,
    toggles,
    acknowledge: false,
  };
}

export type PlanChangeKind = 'price' | 'limit' | 'toggle' | 'field';

export interface PlanChange {
  key: string;
  kind: PlanChangeKind;
  before: string | number | boolean | null;
  after: string | number | boolean | null;
}

/**
 * What saving would change, field by field, for the confirmation shown when the plan has live subscribers.
 * Prices are listed as `price` so the dialog can say "at the next renewal"; limits and toggles as what they are,
 * so it can say "immediately".
 */
export function diffPlan(before: PlanFormData, after: PlanFormData): PlanChange[] {
  const changes: PlanChange[] = [];

  if (before.price_monthly_paisa !== after.price_monthly_paisa) changes.push({ key: 'price_monthly_paisa', kind: 'price', before: before.price_monthly_paisa, after: after.price_monthly_paisa });
  if (before.price_yearly_paisa !== after.price_yearly_paisa) changes.push({ key: 'price_yearly_paisa', kind: 'price', before: before.price_yearly_paisa, after: after.price_yearly_paisa });

  for (const key of Object.keys({ ...before.limits, ...after.limits })) {
    const a = before.limits[key] ?? null;
    const b = after.limits[key] ?? null;
    if (a !== b) changes.push({ key, kind: 'limit', before: a, after: b });
  }

  for (const key of Object.keys({ ...before.toggles, ...after.toggles })) {
    const a = before.toggles[key] ?? false;
    const b = after.toggles[key] ?? false;
    if (a !== b) changes.push({ key, kind: 'toggle', before: a, after: b });
  }

  for (const key of ['code', 'name', 'description', 'trial_days', 'is_public', 'is_addon', 'sort_order'] as const) {
    if (before[key] !== after[key]) changes.push({ key, kind: 'field', before: before[key], after: after[key] });
  }

  return changes;
}
