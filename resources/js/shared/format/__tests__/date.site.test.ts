// The site build swaps @shared/format/date for date.site.ts (Intl, no dayjs — REALTIME.md §8). The two must
// agree byte for byte, or a booking slip would read one time on the desk and another on the patient's phone.
import { describe, expect, it } from 'vitest';
import { formatDateDhaka, formatDhaka, formatTimeDhaka, dhakaDateString } from '../date';
import {
  formatDateDhaka as siteFormatDateDhaka,
  formatDhaka as siteFormatDhaka,
  formatTimeDhaka as siteFormatTimeDhaka,
  dhakaDateString as siteDhakaDateString,
} from '../date.site';
import type { Locale } from '../../types/shared-props';

const INSTANTS = [
  '2026-09-06T04:42:00Z',   // 10:42 Dhaka
  '2026-09-06T17:59:59Z',   // 23:59:59 Dhaka
  '2026-09-06T18:00:00Z',   // rolls over to the next Dhaka day
  '2026-01-31T20:15:00Z',   // month boundary
  '2026-12-31T18:30:00Z',   // year boundary
  '2026-06-01T06:00:00Z',   // noon Dhaka (12 h edge)
  '2026-06-01T00:00:00Z',   // 06:00 Dhaka
  '2026-03-15T12:05:09Z',
];

const FORMATS = ['D MMM, h:mm a', 'HH:mm', 'D MMM YYYY', 'D MMMM', 'D MMM YYYY, h:mm a', 'YYYY-MM-DD', 'DD/MM/YYYY', 'D MMM, HH:mm:ss', 'h:mm A'];
const LOCALES: Locale[] = ['en', 'bn'];

describe('date.site (Intl) matches date (dayjs)', () => {
  it('formatDhaka agrees for every format the site uses, in both locales', () => {
    for (const iso of INSTANTS) {
      for (const format of FORMATS) {
        for (const locale of LOCALES) {
          expect(siteFormatDhaka(iso, format, locale), `${iso} ${format} ${locale}`).toBe(formatDhaka(iso, format, locale));
        }
      }
    }
  });

  it('the named helpers agree', () => {
    for (const iso of INSTANTS) {
      for (const locale of LOCALES) {
        expect(siteFormatTimeDhaka(iso, locale)).toBe(formatTimeDhaka(iso, locale));
        expect(siteFormatDateDhaka(iso, locale)).toBe(formatDateDhaka(iso, locale));
      }
      expect(siteDhakaDateString(iso)).toBe(dhakaDateString(iso));
    }
  });

  it('accepts Date and epoch millis like dayjs does', () => {
    const epoch = Date.parse('2026-09-06T04:42:00Z');
    expect(siteFormatDhaka(epoch, 'D MMM YYYY, HH:mm', 'en')).toBe(formatDhaka(epoch, 'D MMM YYYY, HH:mm', 'en'));
    expect(siteFormatDhaka(new Date(epoch), 'D MMM YYYY, HH:mm', 'en')).toBe(formatDhaka(new Date(epoch), 'D MMM YYYY, HH:mm', 'en'));
  });

  it('still renders the documented examples', () => {
    expect(siteFormatDhaka('2026-09-06T04:42:00Z', 'D MMM, HH:mm', 'en')).toBe('6 Sep, 10:42');
    expect(siteFormatTimeDhaka('2026-09-06T04:42:00Z', 'bn')).toBe('১০:৪২');
    expect(siteFormatDhaka('2026-09-06T04:42:00Z', 'D MMMM', 'bn')).toMatch(/৬ /);
  });
});
