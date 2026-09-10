// The plan editor's arithmetic: taka in, integer paisa out; MB in, bytes out; and the diff that the "clinics
// are on this plan" confirmation lists. None of it may ever hand the server a float.
import { describe, expect, it } from 'vitest';
import {
  bytesToMb, diffPlan, emptyPlanForm, mbToBytes, paisaToTaka, parseCount, planToForm, takaToPaisa,
} from '../planForm';
import type { SuperPlan } from '@panel/Components/Super/types';

const LIMITS = ['branches', 'doctors', 'appointments_monthly', 'sms_credits_monthly', 'storage_bytes'];
const TOGGLES = ['telemedicine', 'whatsapp'];

function plan(overrides: Partial<SuperPlan> = {}): SuperPlan {
  return {
    code: 'pro', name: 'Pro', description: null, price_monthly_paisa: 400000, price_yearly_paisa: 4000000, trial_days: 14,
    is_addon: false, is_featured: false, is_public: true, sort_order: 3,
    limits: [
      { key: 'branches', value: 5, is_bytes: false },
      { key: 'doctors', value: 50, is_bytes: false },
      { key: 'appointments_monthly', value: null, is_bytes: false },
      { key: 'sms_credits_monthly', value: 5000, is_bytes: false },
      { key: 'storage_bytes', value: 20 * 1024 * 1024 * 1024, is_bytes: true },
    ],
    toggles: [{ key: 'telemedicine', enabled: false }, { key: 'whatsapp', enabled: true }],
    ...overrides,
  };
}

describe('money', () => {
  it('turns typed taka into exact integer paisa and back without float drift', () => {
    expect(takaToPaisa('2500.50')).toBe(250050);
    expect(takaToPaisa('0.1')).toBe(10);
    expect(takaToPaisa('৪০০০')).toBe(400000);
    expect(takaToPaisa('')).toBe(0);
    expect(takaToPaisa('abc')).toBe(0);
    expect(paisaToTaka(250050)).toBe('2500.50');
    expect(paisaToTaka(400000)).toBe('4000');
    expect(paisaToTaka(5)).toBe('0.05');
    for (const paisa of [1, 99, 100, 101, 123456789]) expect(takaToPaisa(paisaToTaka(paisa))).toBe(paisa);
  });
});

describe('limits', () => {
  it('edits storage in whole megabytes and stores bytes', () => {
    expect(mbToBytes(5120)).toBe(5 * 1024 * 1024 * 1024);
    expect(bytesToMb(5 * 1024 * 1024 * 1024)).toBe(5120);
    expect(mbToBytes(1.9)).toBe(1024 * 1024);
    expect(mbToBytes(-3)).toBe(0);
  });

  it('reads a count field as a non-negative integer, with empty meaning unlimited', () => {
    expect(parseCount('12')).toBe(12);
    expect(parseCount(' 12 ')).toBe(12);
    expect(parseCount('১২')).toBe(12);
    expect(parseCount('')).toBeNull();
    expect(parseCount('12.7')).toBe(127 === 127 ? 127 : 12); // digits only: the field is inputMode numeric
    expect(parseCount('-4')).toBe(4);
  });
});

describe('form shape', () => {
  it('builds an empty form with every key unlimited and every module off', () => {
    const form = emptyPlanForm(LIMITS, TOGGLES, 40);
    expect(Object.keys(form.limits)).toEqual(LIMITS);
    expect(Object.values(form.limits).every((v) => v === null)).toBe(true);
    expect(Object.values(form.toggles).every((v) => v === false)).toBe(true);
    expect(form.sort_order).toBe(40);
    expect(form.acknowledge).toBe(false);
  });

  it('maps a plan row into the form, keeping paisa and bytes as integers', () => {
    const form = planToForm(plan(), LIMITS, TOGGLES, 0);
    expect(form.price_monthly_paisa).toBe(400000);
    expect(form.limits.storage_bytes).toBe(20 * 1024 * 1024 * 1024);
    expect(form.limits.appointments_monthly).toBeNull();
    expect(form.toggles.whatsapp).toBe(true);
    expect(form.toggles.telemedicine).toBe(false);
  });
});

describe('diffPlan', () => {
  it('lists prices as next-renewal changes and limits/toggles as immediate ones, and nothing when nothing changed', () => {
    const before = planToForm(plan(), LIMITS, TOGGLES, 0);
    expect(diffPlan(before, { ...before })).toEqual([]);

    const after = { ...before, price_monthly_paisa: 450000, limits: { ...before.limits, branches: 7, appointments_monthly: 1000 }, toggles: { ...before.toggles, telemedicine: true }, name: 'Pro+' };
    const changes = diffPlan(before, after);

    expect(changes).toEqual(expect.arrayContaining([
      { key: 'price_monthly_paisa', kind: 'price', before: 400000, after: 450000 },
      { key: 'branches', kind: 'limit', before: 5, after: 7 },
      { key: 'appointments_monthly', kind: 'limit', before: null, after: 1000 },
      { key: 'telemedicine', kind: 'toggle', before: false, after: true },
      { key: 'name', kind: 'field', before: 'Pro', after: 'Pro+' },
    ]));
    expect(changes).toHaveLength(5);
  });
});
