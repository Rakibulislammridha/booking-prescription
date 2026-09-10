import { describe, expect, it } from 'vitest';
import { bmiOf, draftFrom, draftIsEmpty, draftNumber, draftTemperatureC, draftToInput, fieldPlaceholder, fieldUnit, temperatureHint, VITALS_FIELDS } from '../vitals';
import type { VitalsRow } from '@shared/types/models';

const row = (over: Partial<VitalsRow> = {}): VitalsRow => ({
  id: 1, visit_id: 2, bp_systolic: 128, bp_diastolic: 84, pulse_bpm: 78, temperature_c: 37.2, temperature_f: 99, spo2_percent: 97,
  respiratory_rate: null, weight_kg: 68.5, height_cm: 170, bmi: 23.7, blood_glucose_mgdl: null, notes: null,
  recorded_by: { id: 3, name: 'Compounder' }, recorded_at: '2026-09-08T04:00:00+06:00',
  edited_by_doctor: false, reviewed_by_doctor_at: null, ...over,
});

const temperature = VITALS_FIELDS.find((f) => f.key === 'temperature_f')!;

describe('vitals draft', () => {
  // The bug this exists to prevent: parsing on every keystroke turns 98.6 into 986, because the intermediate
  // value is `Number('98.')` === 98 and the re-render drops the point the compounder just typed.
  it('keeps a decimal point that is still being typed', () => {
    expect(draftNumber({ temperature_f: '98.' }, 'temperature_f')).toBe(98);
    expect(draftNumber({ temperature_f: '98.6' }, 'temperature_f')).toBe(98.6);
    expect(draftToInput({ temperature_f: '98.6', weight_kg: '77.5' })).toMatchObject({ temperature_f: 98.6, weight_kg: 77.5 });
  });

  it('reads Bangla digits from a Bangla keyboard and never returns them', () => {
    expect(draftNumber({ weight_kg: '৭৭.৫' }, 'weight_kg')).toBe(77.5);
    expect(draftNumber({ temperature_f: '৯৮.৬' }, 'temperature_f')).toBe(98.6);
    expect(draftToInput({ bp_systolic: '১৩৪' }).bp_systolic).toBe(134);
    expect(draftToInput({ temperature_f: '১০০.৪' }).temperature_f).toBe(100.4);
  });

  // The field is °F; the body is °F (`temperature_f`); the SERVER stores °C. A typed unit is tolerated and dropped.
  it('sends temperature in °F, never a temperature_c, and tolerates a typed unit', () => {
    const body = draftToInput({ temperature_f: '100.4' });
    expect(body.temperature_f).toBe(100.4);
    expect(body).not.toHaveProperty('temperature_c');
    expect(draftNumber({ temperature_f: '98.6°F' }, 'temperature_f')).toBe(98.6);
    expect(draftNumber({ temperature_f: '98.6 F' }, 'temperature_f')).toBe(98.6);
    expect(draftNumber({ temperature_f: '৯৮.৬°ফা' }, 'temperature_f')).toBe(98.6);
    expect(draftNumber({ temperature_f: '37.2C' }, 'temperature_f')).toBeNull();
    expect(draftTemperatureC({ temperature_f: '98.6' })).toBe(37);
    expect(draftTemperatureC({ temperature_f: '100.4' })).toBe(38);
    expect(draftTemperatureC({})).toBeNull();
  });

  // A compounder who reads 37.2 off a Celsius thermometer and types it into the °F field gets told, not converted.
  it('calls out a Celsius-looking reading in the Fahrenheit field instead of guessing', () => {
    expect(temperatureHint({ temperature_f: '37.2' }, 'en')).toEqual({ c: '37.2', f: '99.0' });
    expect(temperatureHint({ temperature_f: '৩৮' }, 'bn')).toEqual({ c: '৩৮', f: '১০০.৪' });
    expect(temperatureHint({ temperature_f: '98.6' }, 'en')).toBeNull();
    expect(temperatureHint({ temperature_f: '' }, 'en')).toBeNull();
    expect(temperatureHint({}, 'en')).toBeNull();
  });

  it('labels the temperature field in °F with a °F example, in both scripts', () => {
    expect(temperature.unit).toBe('°F');
    expect(fieldUnit(temperature, 'en')).toBe('°F');
    expect(fieldUnit(temperature, 'bn')).toBe('°ফা');
    expect(fieldPlaceholder(temperature, 'en')).toBe('98.6');
    expect(fieldPlaceholder(temperature, 'bn')).toBe('৯৮.৬');
    expect(fieldUnit(VITALS_FIELDS.find((f) => f.key === 'weight_kg')!, 'bn')).toBe('kg');
    expect(VITALS_FIELDS.map((f) => f.key)).not.toContain('temperature_c');
  });

  it('treats blank and unparsable input as "not taken"', () => {
    expect(draftNumber({}, 'pulse_bpm')).toBeNull();
    expect(draftNumber({ pulse_bpm: '   ' }, 'pulse_bpm')).toBeNull();
    expect(draftNumber({ pulse_bpm: 'abc' }, 'pulse_bpm')).toBeNull();
    expect(draftToInput({ pulse_bpm: '' }).pulse_bpm).toBeNull();
  });

  // The saved row carries °C (37.2); the form it prefills must show the °F the compounder would type (99.0).
  it('round-trips a saved reading back into the form, in °F', () => {
    const draft = draftFrom(row());
    expect(draft).toMatchObject({ bp_systolic: '128', temperature_f: '99', weight_kg: '68.5' });
    expect(draft).not.toHaveProperty('temperature_c');
    expect(draft.respiratory_rate).toBeUndefined();
    expect(draftToInput(draft)).toMatchObject({ bp_systolic: 128, temperature_f: 99, respiratory_rate: null });
    expect(draftFrom(row({ temperature_c: null, temperature_f: null })).temperature_f).toBeUndefined();
    expect(draftFrom(row({ temperature_c: 38, temperature_f: 100.4 })).temperature_f).toBe('100.4');
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
