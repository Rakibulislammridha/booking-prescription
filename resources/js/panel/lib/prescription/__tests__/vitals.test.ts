import { describe, expect, it } from 'vitest';
import { bmiOf, draftFrom, draftIsEmpty, draftNumber, draftToInput } from '../vitals';
import type { VitalsRow } from '@shared/types/models';

const row = (over: Partial<VitalsRow> = {}): VitalsRow => ({
  id: 1, visit_id: 2, bp_systolic: 128, bp_diastolic: 84, pulse_bpm: 78, temperature_c: 37.2, spo2_percent: 97,
  respiratory_rate: null, weight_kg: 68.5, height_cm: 170, bmi: 23.7, blood_glucose_mgdl: null, notes: null,
  recorded_by: { id: 3, name: 'Compounder' }, recorded_at: '2026-09-08T04:00:00+06:00',
  edited_by_doctor: false, reviewed_by_doctor_at: null, ...over,
});

describe('vitals draft', () => {
  // The bug this exists to prevent: parsing on every keystroke turns 37.6 into 376, because the intermediate
  // value is `Number('37.')` === 37 and the re-render drops the point the compounder just typed.
  it('keeps a decimal point that is still being typed', () => {
    expect(draftNumber({ temperature_c: '37.' }, 'temperature_c')).toBe(37);
    expect(draftNumber({ temperature_c: '37.6' }, 'temperature_c')).toBe(37.6);
    expect(draftToInput({ temperature_c: '37.6', weight_kg: '77.5' })).toMatchObject({ temperature_c: 37.6, weight_kg: 77.5 });
  });

  it('reads Bangla digits from a Bangla keyboard and never returns them', () => {
    expect(draftNumber({ weight_kg: '৭৭.৫' }, 'weight_kg')).toBe(77.5);
    expect(draftToInput({ bp_systolic: '১৩৪' }).bp_systolic).toBe(134);
  });

  it('treats blank and unparsable input as "not taken"', () => {
    expect(draftNumber({}, 'pulse_bpm')).toBeNull();
    expect(draftNumber({ pulse_bpm: '   ' }, 'pulse_bpm')).toBeNull();
    expect(draftNumber({ pulse_bpm: 'abc' }, 'pulse_bpm')).toBeNull();
    expect(draftToInput({ pulse_bpm: '' }).pulse_bpm).toBeNull();
  });

  it('round-trips a saved reading back into the form', () => {
    const draft = draftFrom(row());
    expect(draft).toMatchObject({ bp_systolic: '128', temperature_c: '37.2', weight_kg: '68.5' });
    expect(draft.respiratory_rate).toBeUndefined();
    expect(draftToInput(draft)).toMatchObject({ bp_systolic: 128, temperature_c: 37.2, respiratory_rate: null });
  });

  it('knows when nothing has been entered', () => {
    expect(draftIsEmpty({})).toBe(true);
    expect(draftIsEmpty({ notes: 'patient was anxious' })).toBe(true);
    expect(draftIsEmpty({ pulse_bpm: '80' })).toBe(false);
  });

  it('trims the note and drops an empty one', () => {
    expect(draftToInput({ pulse_bpm: '80', notes: '  after a walk  ' }).notes).toBe('after a walk');
    expect(draftToInput({ pulse_bpm: '80', notes: '   ' }).notes).toBeNull();
  });

  it('computes BMI the way the server does, and refuses impossible input', () => {
    expect(bmiOf(68.5, 170)).toBe(23.7);
    expect(bmiOf(77.5, 171)).toBe(26.5);
    expect(bmiOf(null, 170)).toBeNull();
    expect(bmiOf(70, 0)).toBeNull();
  });
});
