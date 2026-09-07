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
import { LocalizationProvider } from '@mui/x-date-pickers/LocalizationProvider';
import { AdapterDayjs } from '@mui/x-date-pickers/AdapterDayjs';
import { I18nextProvider } from 'react-i18next';
import { bootShared, syncSharedOnNavigate } from '@shared/inertia';
import { ensureMessages, i18n, registerMessageLoader } from '@shared/i18n';
import { loadPanelMessages } from '@shared/lang/panel';
import { documentLocale } from '@shared/locale';
import { bootConnection } from '@shared/connection/boot';
import type { SharedProps, Locale } from '@shared/types/shared-props';
import '@shared/format/date'; // registers dayjs utc/timezone plugins + the bn locale for the pickers
import { theme } from './theme';
import { registerPanelServiceWorker } from './pwa';

type PageModule = { default: React.ComponentType<Record<string, unknown>> };

const appName = import.meta.env.VITE_APP_NAME ?? 'Clinic';

// One locale per visit (ARCHITECTURE §7.5): loaded in parallel with the page chunk, awaited before the first render.
registerMessageLoader(loadPanelMessages);
const messagesReady = ensureMessages(documentLocale());

function Providers({ locale, children }: { locale: Locale; children: ReactNode }) {
  return (
    <I18nextProvider i18n={i18n}>
      <ThemeProvider theme={theme}>
        <CssBaseline />
        <LocalizationProvider dateAdapter={AdapterDayjs} adapterLocale={locale}>
          {children}
        </LocalizationProvider>
      </ThemeProvider>
    </I18nextProvider>
  );
}

void createInertiaApp<SharedProps>({
  title: (title) => (title ? `${title} — ${appName}` : appName),
  resolve: (name) => Promise.all([
    resolvePageComponent<PageModule>(`./Pages/${name}.tsx`, import.meta.glob<PageModule>('./Pages/**/*.tsx')),
    messagesReady,
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
