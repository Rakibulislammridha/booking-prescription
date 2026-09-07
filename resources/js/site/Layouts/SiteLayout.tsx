// Public site shell: tenant identity from the shared props, Tailwind utilities, tenant colour tokens only.
import type { ReactNode } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { useSharedProps } from '@shared/inertia';
import { hasRoute, route } from '@shared/routes';

export interface SiteLayoutProps {
  title?: string; // i18n key
  children: ReactNode;
}

export function SiteLayout({ title, children }: SiteLayoutProps) {
  const { t } = useTranslation();
  const shared = useSharedProps();
  const tenant = shared.tenant;
  const name = tenant?.name ?? shared.app.name;
  const pageTitle = title ? t(title) : undefined;
  const nextLocale = shared.locale === 'bn' ? 'en' : 'bn';
  const patient = shared.auth.guard === 'patient' ? shared.auth.user : null;
  const home = hasRoute('site.home') ? route('site.home') : '/';

  return (
    <div className="min-h-screen flex flex-col">
      {pageTitle ? <Head title={pageTitle} /> : null}
      <header className="bg-primary text-on-primary">
        <div className="mx-auto max-w-3xl px-4 py-3 flex items-center gap-3">
          <Link href={home} className="flex items-center gap-2 min-w-0">
            {tenant?.logo_url ? (
              <img src={tenant.logo_url} alt="" className="h-9 w-9 rounded bg-white/90 object-contain" />
            ) : (
              <span className="h-9 w-9 rounded bg-white/20 flex items-center justify-center font-bold" aria-hidden="true">{name.slice(0, 1)}</span>
            )}
            <span className="font-semibold truncate">{name}</span>
          </Link>
          <nav className="ml-auto flex items-center gap-2 text-sm" aria-label={t('nav.menu')}>
            {patient ? (
              hasRoute('site.portal.logout') ? (
                <button type="button" className="rounded px-2 py-1 hover:bg-white/10" onClick={() => router.post(route('site.portal.logout'))}>
                  {t('auth.logout')}
                </button>
              ) : null
            ) : hasRoute('site.portal.login') ? (
              <Link href={route('site.portal.login')} className="rounded px-2 py-1 hover:bg-white/10">{t('site.nav.portal')}</Link>
            ) : null}
            {hasRoute('site.locale') ? (
              <button
                type="button"
                className="rounded border border-white/40 px-2 py-1 hover:bg-white/10"
                onClick={() => router.patch(route('site.locale'), { locale: nextLocale })}
                lang={nextLocale}
              >
                {nextLocale === 'bn' ? 'বাংলা' : 'English'}
              </button>
            ) : null}
          </nav>
        </div>
      </header>
      <main className="flex-1 mx-auto w-full max-w-3xl px-4 py-6">{children}</main>
      <footer className="mx-auto w-full max-w-3xl px-4 py-6 text-xs text-slate-500">
        {t('site.footer.powered_by', { app: shared.app.name })}
      </footer>
    </div>
  );
}

export default SiteLayout;
