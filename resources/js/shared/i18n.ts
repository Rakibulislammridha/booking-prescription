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

/** Fetches one module's extra slice (`resources/js/shared/lang/panel.ts`). Registered by the panel entry. */
export type ModuleLoader = (module: string, locale: Locale) => Promise<Messages>;

let initialised = false;
let loader: MessageLoader | null = null;
let moduleLoader: ModuleLoader | null = null;
const loaded = new Set<Locale>();
const inflight = new Map<Locale, Promise<void>>();
// Which module bundles this session has asked for, and which (locale, module) pairs are already installed.
// The first set is what a language switch has to re-fetch; the second is the de-duplication.
const modulesSeen = new Set<string>();
const modulesLoaded = new Set<string>();
const modulesInflight = new Map<string, Promise<void>>();

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

function install(locale: Locale, messages: Messages): void {
  if (!initialised) initI18n(locale);
  i18next.addResourceBundle(locale, 'translation', laravelToI18next(messages), true, true);
}

/** Install a locale's messages (Laravel placeholders converted once). Safe before or after `initI18n`. */
export function addMessages(locale: Locale, messages: Messages): void {
  install(locale, messages);
  loaded.add(locale);
}

export function hasMessages(locale: Locale): boolean {
  return loaded.has(locale);
}

/** Called once per app entry with the surface's bundle loader (`@shared/lang/site` or `@shared/lang/panel`). */
export function registerMessageLoader(next: MessageLoader): void {
  loader = next;
}

/**
 * Called once by an entry whose surface is split further than the base bundle (the panel: one chunk per
 * module, ARCHITECTURE §7.5). The site registers none, so everything below is inert there.
 */
export function registerModuleLoader(next: ModuleLoader): void {
  moduleLoader = next;
}

/**
 * Install one module's extra slice. De-duplicated per (locale, module), so calling it from `resolve()` on every
 * navigation costs nothing after the first visit to that module. `null` (a page whose module is the base) and a
 * missing loader both resolve immediately, which is what keeps this a no-op for the site.
 */
export function ensureModuleMessages(module: string | null, locale: Locale | string | undefined): Promise<void> {
  if (module === null || moduleLoader === null) return Promise.resolve();

  const lng: Locale = isLocale(locale) ? locale : DEFAULT_LOCALE;
  const id = `${lng}:${module}`;
  modulesSeen.add(module);

  if (modulesLoaded.has(id)) return Promise.resolve();

  const pending = modulesInflight.get(id);
  if (pending) return pending;

  const load = moduleLoader;
  const promise = load(module, lng)
    .then((messages) => { install(lng, messages); modulesLoaded.add(id); })
    .catch(() => { /* keep rendering: i18next falls back to the key */ })
    .finally(() => { modulesInflight.delete(id); });

  modulesInflight.set(id, promise);
  return promise;
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

/**
 * Load (if needed) then switch. Used on Inertia navigations, where PATCH /locale changes the language. Every
 * module bundle this session has already shown is re-fetched in the new locale first, so the switch never
 * leaves half the screen in raw keys.
 */
export function setLocale(locale: Locale): Promise<void> {
  const bundles = [ensureMessages(locale), ...[...modulesSeen].map((module) => ensureModuleMessages(module, locale))];

  return Promise.all(bundles).then(() => { initI18n(locale); });
}

export function currentLocale(): Locale {
  return getLocale();
}

/** Test seam: forget which locales are loaded (the i18next instance itself is a singleton). */
export function resetMessagesForTests(): void {
  loaded.clear();
  inflight.clear();
  loader = null;
  modulesSeen.clear();
  modulesLoaded.clear();
  modulesInflight.clear();
  moduleLoader = null;
}

export const i18n = i18next;

export { formatBn, toBanglaDigits } from './format/number';
