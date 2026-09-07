// Every clinic runs on Asia/Dhaka; timestamps arrive as ISO-8601 UTC (…Z). No inline dayjs/Intl in components.
import dayjs, { type Dayjs } from 'dayjs';
import utc from 'dayjs/plugin/utc';
import timezone from 'dayjs/plugin/timezone';
import 'dayjs/locale/bn';
import { formatBn } from './number';
import { getLocale } from '../locale';
import type { Locale } from '../types/shared-props';

dayjs.extend(utc);
dayjs.extend(timezone);

export const DHAKA_TZ = 'Asia/Dhaka';
export type DateInput = string | number | Date | Dayjs;

export function toDhaka(input: DateInput): Dayjs {
  return dayjs(input).tz(DHAKA_TZ);
}

export function nowDhaka(): Dayjs {
  return dayjs().tz(DHAKA_TZ);
}

/** formatDhaka('2026-09-06T04:42:00Z', 'D MMM, h:mm a') → "6 Sep, 10:42 am" (en) / Bangla month + digits (bn). */
export function formatDhaka(input: DateInput, format = 'D MMM, h:mm a', locale: Locale = getLocale()): string {
  return formatBn(toDhaka(input).locale(locale).format(format), locale);
}

/** 24-hour clock "10:42" — the form the connection banner uses ("OFFLINE since 10:42"). */
export function formatTimeDhaka(input: DateInput, locale: Locale = getLocale()): string {
  return formatDhaka(input, 'HH:mm', locale);
}

/** "6 Sep 2026" / Bangla equivalent. */
export function formatDateDhaka(input: DateInput, locale: Locale = getLocale()): string {
  return formatDhaka(input, 'D MMM YYYY', locale);
}

/** Calendar date in Dhaka as YYYY-MM-DD (the wire form for `date` query params). */
export function dhakaDateString(input: DateInput = Date.now()): string {
  return toDhaka(input).format('YYYY-MM-DD');
}

export { dayjs };
