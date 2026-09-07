// The site's translation bundle: one chunk per locale, sliced to SITE_KEY_PREFIXES by the `bp:lang-bundles`
// Vite plugin. Imported only by resources/js/site/app.tsx, so the panel build never emits these chunks.
import type { Locale } from '../types/shared-props';
import type { Messages } from '../i18n';

export function loadSiteMessages(locale: Locale): Promise<Messages> {
  return locale === 'bn'
    ? import('@lang/bn.json?site').then((m) => m.default)
    : import('@lang/en.json?site').then((m) => m.default);
}
