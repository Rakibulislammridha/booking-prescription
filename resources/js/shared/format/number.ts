// Digits and numbers. CONVENTIONS §7.5: formatBn() converts numbers on display; Bangla digits are never stored.
import { getLocale } from '../locale';
import type { Locale } from '../types/shared-props';

const BN_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'] as const;

/** Replace ASCII digits with Bangla digits; everything else is untouched. */
export function toBanglaDigits(input: string | number): string {
  return String(input).replace(/[0-9]/g, (d) => BN_DIGITS[Number(d)] ?? d);
}

/** Replace Bangla digits with ASCII digits (for parsing user input; never persist Bangla digits). */
export function toAsciiDigits(input: string): string {
  return input.replace(/[০-৯]/g, (d) => String(BN_DIGITS.indexOf(d as (typeof BN_DIGITS)[number])));
}

/** Locale-aware digit conversion: Bangla digits when the locale is `bn`, unchanged otherwise. */
export function formatBn(value: string | number, locale: Locale = getLocale()): string {
  return locale === 'bn' ? toBanglaDigits(value) : String(value);
}

/** Grouped number (BD lakh grouping, 12,34,567) with locale digits. */
export function formatNumber(value: number, locale: Locale = getLocale(), options: Intl.NumberFormatOptions = {}): string {
  const grouped = new Intl.NumberFormat('en-IN', { maximumFractionDigits: 2, ...options }).format(value);
  return formatBn(grouped, locale);
}
