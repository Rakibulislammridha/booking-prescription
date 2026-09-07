// PRESCRIPTION.md §2.12 — families, rounding, overrides, the "never guess silently" quantity issues.
import { describe, expect, it } from 'vitest';
import type { ParseContext } from '@shared/types/models';
import { parseLine } from '../parse';

const tab: ParseContext = { form_code: 'tab', default_unit: 'tab', pack_size: null, pack_unit: null, strength_mg: 500, per_ml: null, is_liquid: false, cont_days: 30, locale: 'en', route_code: 'po', strength_label: '500 mg', form_label: 'tablet' };
const syr: ParseContext = { form_code: 'syr', default_unit: 'tsp', pack_size: 100, pack_unit: 'bottle', strength_mg: 120, per_ml: 24, is_liquid: true, cont_days: 30, locale: 'en', route_code: 'po', strength_label: '120 mg/5 ml', form_label: 'syrup' };
const inh: ParseContext = { form_code: 'inh_mdi', default_unit: 'puff', pack_size: 200, pack_unit: 'inhaler', strength_mg: 0.1, per_ml: null, is_liquid: false, cont_days: 30, locale: 'en', route_code: 'inh', strength_label: '100 mcg/puff', form_label: 'inhaler' };
const cream: ParseContext = { form_code: 'cream', default_unit: 'app', pack_size: 20, pack_unit: 'tube', strength_mg: null, per_ml: null, is_liquid: false, cont_days: 30, locale: 'en', route_code: 'top', strength_label: '1 %', form_label: 'cream' };

describe('quantity', () => {
  it('rounds counted units up over the duration', () => {
    expect(parseLine('1+0+1 10d af', tab).quantity).toMatchObject({ value: 20, unit: 'tab', source: 'auto' });
    expect(parseLine('1/2+0+1/2 5d', tab).quantity).toMatchObject({ value: 5, unit: 'tab' });
    expect(parseLine('1/2 tds 5d', tab).quantity).toMatchObject({ value: 8, unit: 'tab' }); // 7.5 → 8
  });

  it('converts liquids to whole bottles when the pack size is known', () => {
    const q = parseLine('1 tsp tds 5d', syr).quantity;
    expect(q).toMatchObject({ value: 1, unit: 'bottle', source: 'auto' });
    expect(q.basis).toContain('ml');
  });

  it('counts inhaler actuations against the pack', () => {
    expect(parseLine('2 bd 30d', inh).quantity).toMatchObject({ value: 1, unit: 'inhaler' });
  });

  it('gives one pack per started 28 days for creams and drops', () => {
    expect(parseLine('1 bd 30d', cream).quantity).toMatchObject({ value: 2, unit: 'tube' });
  });

  it('honours an explicit x override and its pack word', () => {
    expect(parseLine('1+0+1 10d x30', tab).quantity).toMatchObject({ value: 30, unit: 'tab', source: 'override' });
    expect(parseLine('x2 bot', syr).quantity).toMatchObject({ value: 2, unit: 'bottle', source: 'override' });
  });

  it('refuses to guess without a duration or a bounded sos', () => {
    const noDuration = parseLine('1+0+1', tab);
    expect(noDuration.quantity.value).toBeNull();
    expect(noDuration.issues.map((i) => i.code)).toContain('missing_duration');

    const sos = parseLine('1 sos 5d', tab);
    expect(sos.quantity.value).toBeNull();
    expect(sos.issues.map((i) => i.code)).toContain('quantity_unknown');

    expect(parseLine('1 sos max 3 5d', tab).quantity.value).toBe(15);
  });

  it('assumes the doctor cont_days for `cont` and says so', () => {
    const line = parseLine('1+0+0 cont', tab);
    expect(line.duration).toEqual({ type: 'continuous', assumed_days: 30 });
    expect(line.issues.some((i) => i.code === 'continuous_assumed' && i.severity === 'info')).toBe(true);
    expect(line.quantity.value).toBe(30);
  });

  it('blocks the line and clears the interpretation on an error', () => {
    const line = parseLine('1+0+1 10d aff', tab);
    expect(line.schedule).toBeNull();
    expect(line.quantity).toMatchObject({ value: null, source: 'none' });
    expect(line.issues.every((i) => i.severity === 'error')).toBe(true);
  });
});
