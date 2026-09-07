// Display code = {session_code}-{number zero-padded to 3}: ('A', 42) → 'A-042'; ('B', 1204) → 'B-1204' (SERIAL_ENGINE.md §5.2).
import { formatBn } from './number';
import type { Locale } from '../types/shared-props';

export function serialCode(sessionCode: string, number: number): string {
  return `${sessionCode.toUpperCase()}-${String(number).padStart(3, '0')}`;
}

/** Codes keep ASCII digits everywhere (slips, QR, URLs); use this only for on-screen Bangla display. */
export function serialCodeLocalised(sessionCode: string, number: number, locale: Locale): string {
  return formatBn(serialCode(sessionCode, number), locale);
}

export function parseSerialCode(code: string): { session_code: string; number: number } | null {
  const m = /^([A-Za-z][A-Za-z0-9]*)-(\d{1,6})$/.exec(code.trim());
  if (!m || m[1] === undefined || m[2] === undefined) return null;
  return { session_code: m[1].toUpperCase(), number: Number(m[2]) };
}
