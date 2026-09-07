// The one place the client keeps "which language is active"; written by i18n.ts, read by the formatters.
import type { Locale } from './types/shared-props';

export const SUPPORTED_LOCALES: readonly Locale[] = ['bn', 'en'] as const;
export const DEFAULT_LOCALE: Locale = 'en';

let current: Locale = DEFAULT_LOCALE;

export function isLocale(value: unknown): value is Locale {
  return value === 'bn' || value === 'en';
}

export function getLocale(): Locale {
  return current;
}

export function setActiveLocale(locale: Locale): void {
  current = locale;
  if (typeof document !== 'undefined') {
    document.documentElement.lang = locale;
    document.documentElement.dir = 'ltr'; // Bangla and English are both left-to-right
  }
}

/**
 * The locale the server rendered this document with (`<html lang>` in the root Blade views). Available
 * synchronously at module scope, before Inertia parses its page props — which is what lets each app entry
 * start the translation chunk in parallel with the page chunk instead of after it.
 */
export function documentLocale(): Locale {
  if (typeof document === 'undefined') return DEFAULT_LOCALE;
  const lang = document.documentElement.lang.slice(0, 2).toLowerCase();
  return isLocale(lang) ? lang : DEFAULT_LOCALE;
}
