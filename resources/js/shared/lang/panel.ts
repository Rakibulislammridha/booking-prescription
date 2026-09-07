// The panel's translation bundle: one chunk per locale (every key — the desk is a staff app, ARCHITECTURE §7.5).
// Imported only by resources/js/panel/app.tsx.
import type { Locale } from '../types/shared-props';
import type { Messages } from '../i18n';

export function loadPanelMessages(locale: Locale): Promise<Messages> {
  return locale === 'bn'
    ? import('@lang/bn.json?panel').then((m) => m.default)
    : import('@lang/en.json?panel').then((m) => m.default);
}
