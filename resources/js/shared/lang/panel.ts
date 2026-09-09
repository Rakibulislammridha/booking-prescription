// The panel's translation bundles (ARCHITECTURE §7.5): a small BASE chunk per locale that every route pays for,
// plus one chunk per module that only that module's routes load. `bp:lang-bundles` (vite.config.ts) slices
// resources/lang/{en,bn}.json into them at build time from the prefix lists in ./surfaces.ts.
//
// Shipping all ~2 900 keys to every route cost ~51 KB gzip in Bangla — a fifth of the panel's shared first load —
// so a reception desk downloaded `reports.*`, `saas.*`, `super.*`, `catalog.*` and `telemedicine.*` it can never
// see. The base is now ~3 KB and the heaviest module ~17 KB.
//
// The table below is written out on purpose: 13 modules × 2 locales of literal `import()` calls is what Rollup
// needs to emit them as separate chunks. A template-literal specifier would resolve to nothing.
import type { Locale } from '../types/shared-props';
import type { Messages } from '../i18n';
import { isPanelModule, type PanelModule } from './surfaces';

export function loadPanelMessages(locale: Locale): Promise<Messages> {
  return locale === 'bn'
    ? import('@lang/bn.json?panel').then((m) => m.default)
    : import('@lang/en.json?panel').then((m) => m.default);
}

type Bundle = Record<Locale, () => Promise<{ default: Messages }>>;

const MODULE_BUNDLES: Readonly<Record<PanelModule, Bundle>> = {
  billing: { en: () => import('@lang/en.json?panel-billing'), bn: () => import('@lang/bn.json?panel-billing') },
  catalog: { en: () => import('@lang/en.json?panel-catalog'), bn: () => import('@lang/bn.json?panel-catalog') },
  clinic: { en: () => import('@lang/en.json?panel-clinic'), bn: () => import('@lang/bn.json?panel-clinic') },
  notifications: { en: () => import('@lang/en.json?panel-notifications'), bn: () => import('@lang/bn.json?panel-notifications') },
  patients: { en: () => import('@lang/en.json?panel-patients'), bn: () => import('@lang/bn.json?panel-patients') },
  prescription: { en: () => import('@lang/en.json?panel-prescription'), bn: () => import('@lang/bn.json?panel-prescription') },
  queue: { en: () => import('@lang/en.json?panel-queue'), bn: () => import('@lang/bn.json?panel-queue') },
  reception: { en: () => import('@lang/en.json?panel-reception'), bn: () => import('@lang/bn.json?panel-reception') },
  reports: { en: () => import('@lang/en.json?panel-reports'), bn: () => import('@lang/bn.json?panel-reports') },
  saas: { en: () => import('@lang/en.json?panel-saas'), bn: () => import('@lang/bn.json?panel-saas') },
  scheduling: { en: () => import('@lang/en.json?panel-scheduling'), bn: () => import('@lang/bn.json?panel-scheduling') },
  super: { en: () => import('@lang/en.json?panel-super'), bn: () => import('@lang/bn.json?panel-super') },
  telemedicine: { en: () => import('@lang/en.json?panel-telemedicine'), bn: () => import('@lang/bn.json?panel-telemedicine') },
};

/** Registered on the i18n singleton by panel/app.tsx; called with the module `panelModuleForPage()` returned. */
export function loadPanelModuleMessages(module: string, locale: Locale): Promise<Messages> {
  if (!isPanelModule(module)) return Promise.resolve({});

  return MODULE_BUNDLES[module][locale]().then((m) => m.default);
}
