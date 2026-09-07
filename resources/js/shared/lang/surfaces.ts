// Which translation keys each surface's bundle carries (docs/REALTIME.md §8 first-load budget).
//
// `resources/lang/{en,bn}.json` stay THE flat source of truth for PHP and the client (CONVENTIONS §7.5) —
// nothing here changes how modules add keys. At build time the Vite plugin `bp:lang-bundles` (vite.config.ts)
// slices those two files into one module per (surface, locale), and each app loads exactly one of them:
// the patient-facing site never ships the ~690 staff-only keys, and neither surface ships the other locale.
//
// Foundation-owned. A site page that needs a new block adds its prefix here (and only here).
export const LANG_SURFACES = ['panel', 'site'] as const;

export type LangSurface = (typeof LANG_SURFACES)[number];

/**
 * Key prefixes reachable from `resources/js/site/**` and the shared modules the site entry pulls in.
 * Matched with `startsWith`, so a prefix may be a whole block (`booking.`) or a sub-block
 * (`patients.gender.` — the site shows three labels out of the 183 `patients.*` keys).
 * `langBundleGuard.test.ts` fails if a literal `t('…')` key on the site falls outside this list.
 */
export const SITE_KEY_PREFIXES: readonly string[] = [
  'auth.',
  'booking.',
  'common.',
  'connection.',
  'nav.',
  'patients.gender.',
  'patients.relation.',
  'patients.timeline.kinds.',
  'portal.',
  'queue.',
  'site.',
  'tenancy.',
];

/** `null` = the whole file (the panel is a staff app on desks; it keeps every key, one locale at a time). */
export function langPrefixesFor(surface: LangSurface): readonly string[] | null {
  return surface === 'site' ? SITE_KEY_PREFIXES : null;
}

export function isLangSurface(value: string): value is LangSurface {
  return (LANG_SURFACES as readonly string[]).includes(value);
}

/** Slice a flat `resources/lang/*.json` down to one surface. Key order (CONVENTIONS §7.5) is preserved. */
export function messagesForSurface(messages: Record<string, string>, surface: LangSurface): Record<string, string> {
  const prefixes = langPrefixesFor(surface);
  if (prefixes === null) return messages;

  const out: Record<string, string> = {};
  for (const [key, value] of Object.entries(messages)) {
    if (prefixes.some((prefix) => key.startsWith(prefix))) out[key] = value;
  }

  return out;
}
