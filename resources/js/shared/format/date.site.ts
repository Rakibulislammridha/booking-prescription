// The site build's stand-in for ./date.ts (REALTIME.md §8: "no date library" on the public queue route).
//
// vite.config.ts's `bp:site-date-formatter` plugin swaps this in for every `@shared/format/date` import when
// BP_SURFACE=site, so the site's pages keep their imports and lose dayjs + utc + timezone + the bn locale
// (5.5 KB gzip). The panel keeps ./date.ts: MUI's AdapterDayjs needs the real dayjs anyway.
//
// Output is byte-identical to dayjs for every format the site uses — `date.site.test.ts` asserts that against
// the dayjs implementation, so the two can never drift apart silently.
//
// The zone conversion goes through Intl (the only part of a date library that is hard to do by hand), but the
// month names come from the tables below rather than from the browser's locale data: a cheap Android WebView
// may ship no Bangla ICU data at all, and dayjs's own tables are what the panel renders.
import { formatBn } from './number';
import { getLocale } from '../locale';
import type { Locale } from '../types/shared-props';

export const DHAKA_TZ = 'Asia/Dhaka';
export type DateInput = string | number | Date;

// Copied verbatim from dayjs's en + bn locales (node_modules/dayjs/locale/bn.js) — see date.site.test.ts.
const MONTHS: Record<Locale, { short: readonly string[]; long: readonly string[] }> = {
  en: {
    short: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
    long: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
  },
  bn: {
    short: ['জানু', 'ফেব্রু', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্ট', 'অক্টো', 'নভে', 'ডিসে'],
    long: ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'],
  },
};

let parts: Intl.DateTimeFormat | null = null;

interface DhakaParts { year: number; month: number; day: number; hour: number; minute: number; second: number }

function dhakaParts(input: DateInput): DhakaParts {
  parts ??= new Intl.DateTimeFormat('en-US', {
    timeZone: DHAKA_TZ, hourCycle: 'h23',
    year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit',
  });

  const date = input instanceof Date ? input : new Date(input);
  const out: Record<string, number> = {};
  for (const part of parts.formatToParts(date)) {
    if (part.type !== 'literal') out[part.type] = Number(part.value);
  }

  return { year: out.year ?? 0, month: out.month ?? 1, day: out.day ?? 1, hour: out.hour ?? 0, minute: out.minute ?? 0, second: out.second ?? 0 };
}

const pad = (n: number): string => (n < 10 ? `0${n}` : String(n));

// One pass over the format string; the alternation is ordered longest-first so MMMM never matches as MMM + M.
const TOKENS = /YYYY|MMMM|MMM|MM|DD|HH|mm|ss|D|h|A|a/g;

/** dayjs-compatible formatting for the tokens the site uses (YYYY MMMM MMM MM DD D HH h mm ss a A). */
export function formatDhaka(input: DateInput, format = 'D MMM, h:mm a', locale: Locale = getLocale()): string {
  const p = dhakaParts(input);
  const months = MONTHS[locale] ?? MONTHS.en;

  const formatted = format.replace(TOKENS, (token) => {
    switch (token) {
      case 'YYYY': return String(p.year);
      case 'MMMM': return months.long[p.month - 1] ?? '';
      case 'MMM': return months.short[p.month - 1] ?? '';
      case 'MM': return pad(p.month);
      case 'DD': return pad(p.day);
      case 'D': return String(p.day);
      case 'HH': return pad(p.hour);
      case 'h': return String(p.hour % 12 === 0 ? 12 : p.hour % 12);
      case 'mm': return pad(p.minute);
      case 'ss': return pad(p.second);
      case 'A': return p.hour < 12 ? 'AM' : 'PM';
      default: return p.hour < 12 ? 'am' : 'pm';
    }
  });

  return formatBn(formatted, locale);
}

/** 24-hour clock "10:42" — the form the connection banner uses ("OFFLINE since 10:42"). */
export function formatTimeDhaka(input: DateInput, locale: Locale = getLocale()): string {
  return formatDhaka(input, 'HH:mm', locale);
}

/** "6 Sep 2026" / Bangla equivalent. */
export function formatDateDhaka(input: DateInput, locale: Locale = getLocale()): string {
  return formatDhaka(input, 'D MMM YYYY', locale);
}

/** Calendar date in Dhaka as YYYY-MM-DD (the wire form for `date` query params). Always ASCII digits. */
export function dhakaDateString(input: DateInput = Date.now()): string {
  const p = dhakaParts(input);
  return `${p.year}-${pad(p.month)}-${pad(p.day)}`;
}
