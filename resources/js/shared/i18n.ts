// i18n bootstrap (docs/ARCHITECTURE.md §7.5). resources/lang/{en,bn}.json are THE translation files for PHP and
// the client. Keys are flat, dot-namespaced (`module.screen.label`), so key/namespace separators are disabled.
// Placeholders: Laravel `:name` in the JSON; converted once here to i18next `{{name}}`, so call sites just
// write t('booking.confirm.title', { serial }).
//
// The messages are NOT bundled into the entry any more (REALTIME.md §8): each app registers a loader
// (`resources/js/shared/lang/{site,panel}.ts`) that dynamically imports one chunk per (surface, locale), and
// the entry awaits it alongside the page chunk — so a visitor downloads one locale, sliced to one surface.
import i18next, { type i18n as I18nInstance } from 'i18next';
import { initReactI18next } from 'react-i18next';
import { DEFAULT_LOCALE, getLocale, isLocale, setActiveLocale } from './locale';
import type { Locale } from './types/shared-props';

export type Messages = Record<string, string>;

/** Fetches the flat Laravel messages for one locale. Registered once per app by `registerMessageLoader`. */
export type MessageLoader = (locale: Locale) => Promise<Messages>;

/** `:name` → `{{name}}` (letters/underscore names only, so "10:42" and URLs stay untouched). `{{name}}` passes through. */
export function laravelToI18next(messages: Messages): Messages {
  const out: Messages = {};
  for (const [key, value] of Object.entries(messages)) {
    out[key] = value.replace(/(^|[^\w:]):([A-Za-z_][A-Za-z0-9_]*)/g, '$1{{$2}}');
  }
  return out;
}

let initialised = false;
let loader: MessageLoader | null = null;
const loaded = new Set<Locale>();
const inflight = new Map<Locale, Promise<void>>();

/** Idempotent; synchronous. Resources arrive through `addMessages`/`ensureMessages`. */
export function initI18n(locale: Locale | string | undefined = DEFAULT_LOCALE): I18nInstance {
  const lng: Locale = isLocale(locale) ? locale : DEFAULT_LOCALE;
  if (!initialised) {
    i18next.use(initReactI18next);
    // initAsync:false + in-memory resources → init resolves synchronously; nothing renders untranslated.
    void i18next.init({
      resources: {},
      lng,
      fallbackLng: 'en',
      supportedLngs: ['bn', 'en'],
      keySeparator: false,
      nsSeparator: false,
      interpolation: { escapeValue: false },
      initAsync: false,
      returnEmptyString: false,
    });
    initialised = true;
  } else if (i18next.language !== lng) {
    void i18next.changeLanguage(lng);
  }
  setActiveLocale(lng);
  return i18next;
}

/** Install a locale's messages (Laravel placeholders converted once). Safe before or after `initI18n`. */
export function addMessages(locale: Locale, messages: Messages): void {
  if (!initialised) initI18n(locale);
  i18next.addResourceBundle(locale, 'translation', laravelToI18next(messages), true, true);
  loaded.add(locale);
}

export function hasMessages(locale: Locale): boolean {
  return loaded.has(locale);
}

/** Called once per app entry with the surface's bundle loader (`@shared/lang/site` or `@shared/lang/panel`). */
export function registerMessageLoader(next: MessageLoader): void {
  loader = next;
}

/** Resolves once the locale's messages are installed. De-duplicated, so calling it per navigation is free. */
export function ensureMessages(locale: Locale | string | undefined): Promise<void> {
  const lng: Locale = isLocale(locale) ? locale : DEFAULT_LOCALE;
  if (loaded.has(lng)) return Promise.resolve();

  const pending = inflight.get(lng);
  if (pending) return pending;

  if (!loader) return Promise.resolve();

  const promise = loader(lng)
    .then((messages) => { addMessages(lng, messages); })
    .catch(() => { /* keep rendering: i18next falls back to the key */ })
    .finally(() => { inflight.delete(lng); });

  inflight.set(lng, promise);
  return promise;
}

/** Load (if needed) then switch. Used on Inertia navigations, where PATCH /locale changes the language. */
export function setLocale(locale: Locale): Promise<void> {
  return ensureMessages(locale).then(() => { initI18n(locale); });
}

export function currentLocale(): Locale {
  return getLocale();
}

/** Test seam: forget which locales are loaded (the i18next instance itself is a singleton). */
export function resetMessagesForTests(): void {
  loaded.clear();
  inflight.clear();
  loader = null;
}

export const i18n = i18next;

export { formatBn, toBanglaDigits } from './format/number';
