// Panel entry (docs/ARCHITECTURE.md §7): MUI + Inertia + i18n + connection store + PWA. Pages resolve from ./Pages/**.
import '@fontsource/inter/400.css';
import '@fontsource/inter/500.css';
import '@fontsource/inter/600.css';
import '@fontsource/inter/700.css';
import '@fontsource/noto-sans-bengali/bengali-400.css';
import '@fontsource/noto-sans-bengali/bengali-500.css';
import '@fontsource/noto-sans-bengali/bengali-700.css';

import { StrictMode, type ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ThemeProvider } from '@mui/material/styles';
import CssBaseline from '@mui/material/CssBaseline';
import { I18nextProvider } from 'react-i18next';
import { bootShared, syncSharedOnNavigate } from '@shared/inertia';
import { ensureMessages, ensureModuleMessages, i18n, registerMessageLoader, registerModuleLoader } from '@shared/i18n';
import { loadPanelMessages, loadPanelModuleMessages } from '@shared/lang/panel';
import { panelModuleForPage } from '@shared/lang/surfaces';
import { documentLocale } from '@shared/locale';
import { bootConnection } from '@shared/connection/boot';
import type { SharedProps, Locale } from '@shared/types/shared-props';
import '@shared/format/date'; // registers dayjs utc/timezone plugins + the bn locale for the pickers
import { theme } from './theme';
import { registerPanelServiceWorker } from './pwa';

type PageModule = { default: React.ComponentType<Record<string, unknown>> };

const appName = import.meta.env.VITE_APP_NAME ?? 'Clinic';

// One locale per visit, split two ways (ARCHITECTURE §7.5). The BASE chunk — the shell's own copy, ~3 KB gzip —
// starts here, at module evaluation, so it is already in flight while the browser fetches the page chunk. The
// MODULE chunk (`reception`, `reports`, …) starts inside resolve() below, which is the first moment the page's
// name is known and still the same tick in which Inertia requests the page chunk: two parallel requests, never
// a second round trip. Shipping all ~2 900 keys to every route instead cost 51 KB gzip on the reception desk.
registerMessageLoader(loadPanelMessages);
registerModuleLoader(loadPanelModuleMessages);
const messagesReady = ensureMessages(documentLocale());

// No LocalizationProvider here on purpose: @mui/x-date-pickers is ~12 KB gzip of the panel's shared first load
// (ARCHITECTURE §7.6) and NOT ONE page mounts a picker — every date on the panel is a plain field or a server
// choice. A page that needs a picker wraps itself in `<LocalizationProvider dateAdapter={AdapterDayjs}
// adapterLocale={locale}>` from its own lazily loaded chunk, so the cost lands on that page and nowhere else.
// `@shared/format/date` below still registers the dayjs utc/timezone plugins and the bn locale for whoever does.
function Providers({ children }: { locale: Locale; children: ReactNode }) {
  return (
    <I18nextProvider i18n={i18n}>
      <ThemeProvider theme={theme}>
        <CssBaseline />
        {children}
      </ThemeProvider>
    </I18nextProvider>
  );
}

void createInertiaApp<SharedProps>({
  title: (title) => (title ? `${title} — ${appName}` : appName),
  resolve: (name) => Promise.all([
    resolvePageComponent<PageModule>(`./Pages/${name}.tsx`, import.meta.glob<PageModule>('./Pages/**/*.tsx')),
    messagesReady,
    ensureModuleMessages(panelModuleForPage(name), documentLocale()),
  ]).then(([m]) => m.default),
  setup({ el, App, props }) {
    const shared = props.initialPage.props;
    bootShared(shared);
    syncSharedOnNavigate();
    bootConnection();
    registerPanelServiceWorker();
    createRoot(el).render(
      <StrictMode>
        <Providers locale={shared.locale}>
          <App {...props} />
        </Providers>
      </StrictMode>,
    );
  },
  progress: { color: '#0f766e' },
});
