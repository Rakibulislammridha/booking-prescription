// Site entry (docs/ARCHITECTURE.md §7, REALTIME.md §8): no MUI/Emotion, Tailwind 4 with per-tenant CSS variables,
// system font for Latin + Noto Sans Bengali subset. Echo is loaded lazily by the queue page after first paint.
// The production build resolves react/react-dom to preact/compat for this entry only (vite.config.ts).
import '@fontsource/noto-sans-bengali/bengali-400.css';
import '@fontsource/noto-sans-bengali/bengali-600.css';

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { I18nextProvider } from 'react-i18next';
import { bootShared, syncSharedOnNavigate } from '@shared/inertia';
import { ensureMessages, i18n, registerMessageLoader } from '@shared/i18n';
import { loadSiteMessages } from '@shared/lang/site';
import { documentLocale } from '@shared/locale';
import { bootConnection } from '@shared/connection/boot';
import type { SharedProps } from '@shared/types/shared-props';

type PageModule = { default: React.ComponentType<Record<string, unknown>> };

const appName = import.meta.env.VITE_APP_NAME ?? 'Clinic';

// One locale, sliced to the site's key prefixes. Started here (from <html lang>) so it downloads in parallel
// with the page chunk instead of after it, and awaited in resolve() so nothing ever renders untranslated.
registerMessageLoader(loadSiteMessages);
const messagesReady = ensureMessages(documentLocale());

void createInertiaApp<SharedProps>({
  title: (title) => (title ? `${title} — ${appName}` : appName),
  resolve: (name) => Promise.all([
    // The negative pattern keeps a page's `__tests__/*.test.tsx` out of the production bundle: a test file next to
    // a page is a Vitest input, not a route, and would otherwise ship (with its testing-library imports) as one.
    resolvePageComponent<PageModule>(`./Pages/${name}.tsx`, import.meta.glob<PageModule>(['./Pages/**/*.tsx', '!./Pages/**/__tests__/**'])),
    messagesReady,
  ]).then(([m]) => m.default),
  setup({ el, App, props }) {
    const shared = props.initialPage.props;
    bootShared(shared);
    syncSharedOnNavigate();
    bootConnection({ heartbeat: false }); // browser events only; the queue page starts the heartbeat when it mounts
    createRoot(el).render(
      <StrictMode>
        <I18nextProvider i18n={i18n}>
          <App {...props} />
        </I18nextProvider>
      </StrictMode>,
    );
  },
  progress: { color: 'var(--tenant-primary, #0f766e)' },
});
