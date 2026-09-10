// Temperature (BRIEF §5.G.2 vitals). The server stores CELSIUS — `vitals.temperature_c` is the clinical canonical
// unit (SCHEMA §3.4) — and every screen shows FAHRENHEIT, the unit a compounder reads off the thermometer. This is
// the one client-side conversion; its PHP twin is `App\Domain\Prescription\Support\Temperature` with the same
// one-decimal rounding, so 98.6 °F → 37.0 °C → 98.6 °F is stable across the wire.
import { formatBn, formatNumber } from './number';
import { getLocale } from '../locale';
import type { Locale } from '../types/shared-props';

/** SCHEMA §3.4 `vitals_temperature_c_check` (30–45 °C) in the entry unit. */
export const TEMPERATURE_F_MIN = 86;
export const TEMPERATURE_F_MAX = 113;
/** A value in this range typed into the °F field is almost certainly a °C reading — the hint the field shows. */
export const TEMPERATURE_C_MIN = 30;
export const TEMPERATURE_C_MAX = 45;

const UNIT_F: Record<Locale, string> = { en: '°F', bn: '°ফা' };

/** 37.0 → 98.6; 38.0 → 100.4 (one decimal). */
export function cToF(celsius: number): number {
  return Math.round((celsius * 9 / 5 + 32) * 10) / 10;
}

/** 98.6 → 37.0; 100.4 → 38.0 (one decimal — the column's precision). */
export function fToC(fahrenheit: number): number {
  return Math.round(((fahrenheit - 32) * 5 / 9) * 10) / 10;
}

export function temperatureUnit(locale: Locale = getLocale()): string {
  return UNIT_F[locale];
}

/** The °F number alone, always one decimal, locale digits: 37 → "98.6" / "৯৮.৬"; 38 → "100.4"; 40 → "104.0". */
export function formatTemperatureF(celsius: number, locale: Locale = getLocale()): string {
  return formatNumber(cToF(celsius), locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
}

/** The reading with its unit: "98.6 °F" / "৯৮.৬ °ফা"; null when nothing was recorded. */
export function formatTemperature(celsius: number | null | undefined, locale: Locale = getLocale()): string | null {
  if (celsius === null || celsius === undefined) return null;
  return `${formatTemperatureF(celsius, locale)} ${temperatureUnit(locale)}`;
}

/** The example a °F field shows while empty ("98.6" / "৯৮.৬"). */
export function temperaturePlaceholder(locale: Locale = getLocale()): string {
  return formatBn('98.6', locale);
}
