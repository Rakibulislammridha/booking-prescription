// Which translation keys each bundle carries (docs/REALTIME.md §8, ARCHITECTURE §7.5 — first-load budget).
//
// `resources/lang/{en,bn}.json` stay THE flat source of truth for PHP and the client (CONVENTIONS §7.5) —
// nothing here changes how modules add keys. At build time the Vite plugin `bp:lang-bundles` (vite.config.ts)
// slices those two files into one module per (bundle, locale), and each app loads exactly the ones it needs:
// the patient-facing site never ships the staff-only keys, the panel never ships the other locale, and a
// reception desk never ships `reports.*`, `saas.*`, `super.*`, `catalog.*` or `telemedicine.*` at all.
//
// Three bundle kinds:
//   'site'            one chunk, everything the public site reaches.
//   'panel'           the panel's BASE: the shell's own copy (nav, flash, the connection indicator, the PWA
//                     prompt, auth, validation) plus the few pages that carry no module of their own. Every
//                     panel route pays for it, so it is deliberately tiny.
//   'panel-<module>'  one chunk per panel module, loaded IN PARALLEL with the page chunk (panel/app.tsx's
//                     `resolve()` knows the page name before either request goes out — never an extra
//                     round trip). Modules overlap freely: only one of them is ever loaded per route.
//
// Foundation-owned. A page that needs a new block adds its prefix here (and only here);
// `lang/__tests__/bundles.test.ts` fails the build if a page uses a key its bundle does not carry. A shared file
// whose copy renders only on one module's pages (the console drawer PanelLayout mounts on the super surface alone)
// declares it with a first-line `// @lang-module <module>` pragma so that test checks it against that module only.

export const BASE_SURFACES = ['panel', 'site'] as const;

/** One per directory under `resources/js/panel/Pages/` that carries copy of its own. */
export const PANEL_MODULES = [
  'billing', 'catalog', 'clinic', 'notifications', 'patients', 'prescription',
  'queue', 'reception', 'reports', 'saas', 'scheduling', 'super', 'telemedicine',
] as const;

export type PanelModule = (typeof PANEL_MODULES)[number];

export type LangSurface = (typeof BASE_SURFACES)[number] | `panel-${PanelModule}`;

/**
 * Key prefixes reachable from `resources/js/site/**` and the shared modules the site entry pulls in.
 * Matched with `startsWith`, so a prefix may be a whole block (`booking.`) or a sub-block
 * (`patients.gender.` — the site shows three labels out of the 183 `patients.*` keys).
 * `bundles.test.ts` fails if a literal `t('…')` key on the site falls outside this list.
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
  // Only the patient-facing half of the module: the doctor console's and the board's strings (`telemedicine.
  // console.*`, `.board.*`, `.errors.*`, `.flash.*`) are staff copy and never reach a phone.
  'telemedicine.book.',
  'telemedicine.booked.',
  'telemedicine.call.',
  'telemedicine.join.',
  'telemedicine.preflight.',
  'telemedicine.room.',
  'telemedicine.unavailable.',
];

/**
 * The panel's base slice: what the shell itself renders on every route, plus the blocks small enough — and
 * cross-cutting enough — that splitting them would only buy a flash of untranslated text. `auth.` covers the
 * login screens and the user menu; `validation.` is the client's copy of the server's field errors, which can
 * arrive on any form; `dashboard.` and `tenancy.` are four and six keys behind pages that have no module.
 * Together this is ~160 of the ~2 900 keys — under 4 KB gzip in Bangla, against 51 KB for the whole file.
 */
export const PANEL_BASE_PREFIXES: readonly string[] = [
  'auth.',
  'common.',
  'connection.',
  'dashboard.',
  'nav.',
  'passwords.',
  'pwa.',
  'roles.',
  'tenancy.',
  'validation.',
];

/**
 * Per-module slices, on top of PANEL_BASE_PREFIXES (never repeating it). Derived from what each module's pages
 * and the components they import actually key, so a module that borrows another's copy declares it: the
 * reception board shows serials, money and a patient's details, so it carries those blocks too.
 */
export const PANEL_MODULE_PREFIXES: Readonly<Record<PanelModule, readonly string[]>> = {
  billing: ['billing.'],
  catalog: ['catalog.'],
  clinic: ['clinic.'],
  notifications: ['notifications.'],
  patients: ['billing.', 'patients.', 'portal.'],
  prescription: ['prescriptions.'],
  queue: ['queue.', 'reception.', 'serials.'],
  reception: ['billing.', 'booking.', 'patients.', 'prescriptions.', 'reception.', 'scheduling.', 'serials.'],
  reports: ['billing.', 'reports.'],
  saas: ['saas.'],
  scheduling: ['scheduling.', 'serials.'],
  super: ['saas.', 'super.'],
  telemedicine: ['prescriptions.', 'telemedicine.'],
};

/**
 * Inertia page name (`Reception/Board`, `Super/Tenants/Index`, `Suspended`) → the module bundle it needs, or
 * `null` when the base carries everything (Auth, Dashboard, Suspended). The directory under `Pages/` IS the
 * module, so a new page inside an existing directory needs no change here.
 */
const PANEL_PAGE_MODULES: Readonly<Record<string, PanelModule>> = {
  Billing: 'billing',
  Catalog: 'catalog',
  Clinic: 'clinic',
  Notifications: 'notifications',
  Patients: 'patients',
  Prescription: 'prescription',
  Queue: 'queue',
  Reception: 'reception',
  Reports: 'reports',
  SaaS: 'saas',
  Scheduling: 'scheduling',
  Super: 'super',
  Telemedicine: 'telemedicine',
};

export function panelModuleForPage(page: string): PanelModule | null {
  return PANEL_PAGE_MODULES[page.split('/')[0] ?? ''] ?? null;
}

export function isPanelModule(value: string): value is PanelModule {
  return (PANEL_MODULES as readonly string[]).includes(value);
}

export function isLangSurface(value: string): value is LangSurface {
  if ((BASE_SURFACES as readonly string[]).includes(value)) return true;
  return value.startsWith('panel-') && isPanelModule(value.slice('panel-'.length));
}

/** `null` = the whole file. Nothing returns `null` today; the site and the panel are both sliced. */
export function langPrefixesFor(surface: LangSurface): readonly string[] | null {
  if (surface === 'site') return SITE_KEY_PREFIXES;
  if (surface === 'panel') return PANEL_BASE_PREFIXES;

  const module = surface.slice('panel-'.length);

  return isPanelModule(module) ? PANEL_MODULE_PREFIXES[module] : null;
}

/**
 * Slice a flat `resources/lang/*.json` down to one bundle. Key order (CONVENTIONS §7.5) is preserved. A panel
 * module chunk NEVER repeats a base key: the base is already loaded on every route, so a duplicate would be
 * paid for twice on the wire.
 */
export function messagesForSurface(messages: Record<string, string>, surface: LangSurface): Record<string, string> {
  const prefixes = langPrefixesFor(surface);
  if (prefixes === null) return messages;

  const exclude = surface.startsWith('panel-') ? PANEL_BASE_PREFIXES : [];
  const out: Record<string, string> = {};

  for (const [key, value] of Object.entries(messages)) {
    if (!prefixes.some((prefix) => key.startsWith(prefix))) continue;
    if (exclude.some((prefix) => key.startsWith(prefix))) continue;
    out[key] = value;
  }

  return out;
}
