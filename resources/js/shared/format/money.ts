// Money is paisa on the wire ({ paisa, formatted } via MoneyResource, CONVENTIONS §13); display via formatBdt().
import { formatBn } from './number';
import { getLocale } from '../locale';
import type { Locale } from '../types/shared-props';

export const BDT_SIGN = '৳';

/** 50000 → "৳500.00" (en) / "৳৫০০.০০" (bn). Lakh grouping above 999.99: 12,34,567.00. */
export function formatBdt(paisa: number, locale: Locale = getLocale(), options: { sign?: boolean } = {}): string {
  const sign = options.sign ?? true;
  const negative = paisa < 0;
  const amount = Math.abs(paisa) / 100;
  const grouped = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount);
  return `${negative ? '-' : ''}${sign ? BDT_SIGN : ''}${formatBn(grouped, locale)}`;
}

/** "500", "500.5", "৳1,200.75", "৫০০" → paisa; null when not a number. */
export function parseBdt(input: string): number | null {
  const cleaned = input
    .replace(/[০-৯]/g, (d) => String('০১২৩৪৫৬৭৮৯'.indexOf(d)))
    .replace(/[^\d.-]/g, '');
  if (cleaned === '' || cleaned === '-' || cleaned === '.') return null;
  const value = Number(cleaned);
  return Number.isFinite(value) ? Math.round(value * 100) : null;
}
