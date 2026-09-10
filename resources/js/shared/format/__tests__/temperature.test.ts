// The one client-side temperature conversion. Its contract is shared with App\Domain\Prescription\Support\Temperature
// (TemperatureTest.php pins the same table): one decimal both ways, and the readings a clinic actually types round-trip.
import { describe, expect, it } from 'vitest';
import { cToF, formatTemperature, formatTemperatureF, fToC, TEMPERATURE_F_MAX, TEMPERATURE_F_MIN, temperaturePlaceholder, temperatureUnit } from '../temperature';

describe('temperature', () => {
  it('converts both ways to one decimal', () => {
    expect(fToC(98.6)).toBe(37);
    expect(fToC(100.4)).toBe(38);
    expect(fToC(104)).toBe(40);
    expect(fToC(97.7)).toBe(36.5);
    expect(cToF(37)).toBe(98.6);
    expect(cToF(38)).toBe(100.4);
    expect(cToF(36.5)).toBe(97.7);
    expect(cToF(39.4)).toBe(102.9);
    expect(fToC(98.7)).toBe(37.1);      // 37.06 → 37.1, the column's precision
  });

  it('round-trips the readings a clinic types', () => {
    for (const f of [98.6, 100.4, 102.2, 104, 97.7, 86, 113]) {
      expect(cToF(fToC(f))).toBe(f);
    }
    for (const c of [36.5, 37, 37.2, 38, 39.4, 30, 45]) {
      expect(fToC(cToF(c))).toBe(c);
    }
  });

  it('carries the stored bounds (30–45 °C) into the entry unit', () => {
    expect(TEMPERATURE_F_MIN).toBe(86);
    expect(TEMPERATURE_F_MAX).toBe(113);
    expect(fToC(TEMPERATURE_F_MIN)).toBe(30);
    expect(fToC(TEMPERATURE_F_MAX)).toBe(45);
  });

  it('formats a reading for a person, always one decimal, in the locale digits and unit', () => {
    expect(formatTemperatureF(37, 'en')).toBe('98.6');
    expect(formatTemperatureF(40, 'en')).toBe('104.0');
    expect(formatTemperatureF(38, 'bn')).toBe('১০০.৪');
    expect(formatTemperature(38, 'en')).toBe('100.4 °F');
    expect(formatTemperature(38, 'bn')).toBe('১০০.৪ °ফা');
    expect(formatTemperature(null, 'en')).toBeNull();
    expect(formatTemperature(undefined, 'bn')).toBeNull();
    expect(temperatureUnit('en')).toBe('°F');
    expect(temperatureUnit('bn')).toBe('°ফা');
    expect(temperaturePlaceholder('en')).toBe('98.6');
    expect(temperaturePlaceholder('bn')).toBe('৯৮.৬');
  });
});
