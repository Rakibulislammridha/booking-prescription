import { describe, expect, it } from 'vitest';
import en from '@lang/en.json';
import bn from '@lang/bn.json';
import { i18n, initI18n, laravelToI18next } from '../i18n';
import { formatBn, formatNumber, toAsciiDigits, toBanglaDigits } from '../format/number';
import { formatBdt, parseBdt } from '../format/money';
import { formatDhaka, formatTimeDhaka } from '../format/date';
import { parseSerialCode, serialCode } from '../format/serial';

/** CONVENTIONS §7.5: keys are sorted WITHIN their first-segment block; blocks may be appended in any order. */
function unsortedWithinPrefix(keys: string[]): Array<[string, string]> {
  const last = new Map<string, string>();
  const broken: Array<[string, string]> = [];
  for (const key of keys) {
    const prefix = key.split('.', 1)[0] ?? key;
    const prev = last.get(prefix);
    if (prev !== undefined && prev > key) broken.push([key, prev]);
    last.set(prefix, key);
  }
  return broken;
}

describe('translation files', () => {
  it('en.json and bn.json have identical key sets, sorted within each prefix block, and no empty values', () => {
    const ek = Object.keys(en);
    const bk = Object.keys(bn);
    expect(bk).toEqual(ek);
    expect(unsortedWithinPrefix(ek)).toEqual([]);
    for (const v of [...Object.values(en), ...Object.values(bn)]) expect(String(v).trim()).not.toBe('');
  });

  it('unsortedWithinPrefix flags order only inside a block', () => {
    expect(unsortedWithinPrefix(['common.b', 'catalog.a', 'common.c', 'auth.z'])).toEqual([]);
    expect(unsortedWithinPrefix(['common.b', 'common.a'])).toEqual([['common.a', 'common.b']]);
  });

  it('bn.json is real Bangla (every value has Bengali script unless it is a pure placeholder)', () => {
    for (const [k, v] of Object.entries(bn)) {
      expect(/[ঀ-৿]/.test(v) || /^:\w+$/.test(v), `bn.${k} should be Bangla`).toBe(true);
    }
  });
});

describe('placeholder conversion', () => {
  it('converts Laravel :name to {{name}} and leaves times, URLs and Bangla digits alone', () => {
    expect(laravelToI18next({ a: 'Serial :serial confirmed' })).toEqual({ a: 'Serial {{serial}} confirmed' });
    expect(laravelToI18next({ a: 'OFFLINE since :time · 10:42 · http://x' })).toEqual({ a: 'OFFLINE since {{time}} · 10:42 · http://x' });
    expect(laravelToI18next({ a: ':countটি কাজ অপেক্ষমাণ' })).toEqual({ a: '{{count}}টি কাজ অপেক্ষমাণ' });
    expect(laravelToI18next({ a: 'already {{done}}' })).toEqual({ a: 'already {{done}}' });
  });

  it('t() interpolates Laravel-style keys with plain call sites in both languages', () => {
    initI18n('en');
    expect(i18n.t('connection.offline_since', { time: '10:42' })).toBe('OFFLINE since 10:42');
    expect(i18n.t('connection.issuing_from_block', { from: 'A-021', to: 'A-030', remaining: 7 })).toBe('issuing from block A-021–A-030 (7 left)');
    initI18n('bn');
    expect(i18n.t('connection.offline_since', { time: '১০:৪২' })).toBe('অফলাইন ১০:৪২ থেকে');
    expect(i18n.t('connection.actions_queued', { count: '৪' })).toBe('৪টি কাজ অপেক্ষমাণ');
    initI18n('en');
  });

  it('dotted keys are flat (no nesting) and unknown keys fall back to the key', () => {
    expect(i18n.t('nav.dashboard')).toBe('Dashboard');
    expect(i18n.t('nope.missing')).toBe('nope.missing');
  });
});

describe('numerals', () => {
  it('toBanglaDigits / toAsciiDigits round-trip', () => {
    expect(toBanglaDigits('A-042 at 10:42')).toBe('A-০৪২ at ১০:৪২');
    expect(toAsciiDigits('০১৭১২৩৪৫৬৭৮')).toBe('01712345678');
  });

  it('formatBn converts only for bn', () => {
    expect(formatBn(42, 'bn')).toBe('৪২');
    expect(formatBn(42, 'en')).toBe('42');
  });

  it('formatNumber groups in lakhs and localises digits', () => {
    expect(formatNumber(1234567, 'en')).toBe('12,34,567');
    expect(formatNumber(1234567, 'bn')).toBe('১২,৩৪,৫৬৭');
  });
});

describe('money, dates, serials', () => {
  it('formatBdt renders paisa with the taka sign', () => {
    expect(formatBdt(50000, 'en')).toBe('৳500.00');
    expect(formatBdt(50000, 'bn')).toBe('৳৫০০.০০');
    expect(formatBdt(-123456789, 'en')).toBe('-৳12,34,567.89');
    expect(formatBdt(50000, 'en', { sign: false })).toBe('500.00');
  });

  it('parseBdt accepts Bangla and ASCII input', () => {
    expect(parseBdt('৳500.50')).toBe(50050);
    expect(parseBdt('৫০০')).toBe(50000);
    expect(parseBdt('abc')).toBeNull();
  });

  it('formatDhaka converts UTC to Asia/Dhaka (+06:00) and localises digits', () => {
    expect(formatDhaka('2026-09-06T04:42:00Z', 'D MMM, HH:mm', 'en')).toBe('6 Sep, 10:42');
    expect(formatTimeDhaka('2026-09-06T04:42:00Z', 'bn')).toBe('১০:৪২');
    expect(formatDhaka('2026-09-06T04:42:00Z', 'D MMMM', 'bn')).toMatch(/৬ /);
  });

  it('serialCode pads to three digits and never truncates', () => {
    expect(serialCode('a', 42)).toBe('A-042');
    expect(serialCode('B', 1204)).toBe('B-1204');
    expect(parseSerialCode('A-042')).toEqual({ session_code: 'A', number: 42 });
    expect(parseSerialCode('nope')).toBeNull();
  });
});
