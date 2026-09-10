// Shell for the central host (the commercial front door: marketing, pricing, docs, signup, invoices).
//
// Deliberately NOT SiteLayout: that one links to `site.booking.*` / `site.portal.*`, which only exist on a
// tenant host and 404 here. Every URL arrives in `links` (CONVENTIONS §7.4 budget: no Ziggy lookup, no extra
// chunk). Tailwind utilities only; brand colour through the `primary` / `on-primary` tokens.
import type { ReactNode } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useSharedProps } from '@shared/inertia';
import { formatBn } from '@shared/format/number';
import { makeCopy, type Copy } from './copy';
import type { CentralLinks, PlatformProps } from './types';

const EMPTY_LINKS: CentralLinks = { home: '/', pricing: '/pricing', docs: '/docs', changelog: '/changelog', signup: '/signup', signup_store: '/signup', locale: '' };

export interface CentralLayoutProps {
  /** A key of `copy` (`pricing.title`), resolved server-side like every other string on this surface. */
  title?: string;
  children: ReactNode;
}

/**
 * `links` and `copy` are read from the page props here rather than passed in by the page's `.layout`, because
 * Inertia 3 calls a layout resolver TWICE — first with the raw props object, to find out whether the function
 * returns an element or a layout component, and only then with the page element (`@inertiajs/react`'s
 * `renderChildren`). A resolver that reaches into `page.props` therefore throws on that first probe call, which
 * is exactly the "Cannot read properties of undefined" a browser sees. Every page assigns the plain
 * `(page) => <CentralLayout title="…">{page}</CentralLayout>` form, and the shell asks Inertia for what it needs.
 */
export function CentralLayout({ title, children }: CentralLayoutProps) {
  const page = usePage<{ links?: CentralLinks; copy?: Copy; platform?: PlatformProps }>();
  const links = page.props.links ?? EMPTY_LINKS;
  const c = makeCopy(page.props.copy ?? {});
  const shared = useSharedProps();
  // The platform's own identity (`platform.*` settings) when the page passes it; the app name otherwise.
  const platform = page.props.platform;
  const appName = platform?.name && platform.name !== '' ? platform.name : shared.app.name;
  const signupOpen = platform?.signup_open ?? true;
  const banner = platform?.maintenance_banner ?? '';
  const pageTitle = title ? c(title) : undefined;
  const nextLocale = shared.locale === 'bn' ? 'en' : 'bn';
  const year = formatBn(new Date().getFullYear(), shared.locale);

  const nav: { href: string; label: string }[] = [
    { href: links.pricing, label: c('nav.pricing') },
    { href: links.docs, label: c('nav.docs') },
    { href: links.changelog, label: c('nav.changelog') },
  ];

  return (
    <div className="flex min-h-screen flex-col bg-slate-50 text-slate-900">
      {pageTitle ? <Head title={pageTitle} /> : null}

      {banner !== '' ? (
        <div role="status" data-testid="maintenance-banner" className="border-b border-amber-300 bg-amber-50 px-4 py-2 text-center text-sm font-medium text-amber-900">
          {banner}
        </div>
      ) : null}

      <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
        <div className="mx-auto flex max-w-5xl items-center gap-3 px-4 py-3">
          <Link href={links.home} className="flex min-w-0 items-center gap-2" aria-label={c('nav.home')}>
            <span aria-hidden="true" className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary text-on-primary">
              <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <path d="M4 6h10M4 12h7M4 18h10" />
                <path d="M18 8v8M14 12h8" />
              </svg>
            </span>
            <span className="truncate text-base font-bold tracking-tight">{appName}</span>
          </Link>

          <nav className="ml-auto flex items-center gap-1 text-sm" aria-label={c('nav.menu')}>
            <span className="hidden items-center gap-1 sm:flex">
              {nav.map((item) => (
                <Link key={item.href} href={item.href} className="rounded-lg px-2.5 py-2 font-medium text-slate-700 hover:bg-slate-100 hover:text-slate-900">
                  {item.label}
                </Link>
              ))}
            </span>
            {links.locale ? (
              <button
                type="button"
                lang={nextLocale}
                className="rounded-lg border border-slate-300 px-2.5 py-1.5 font-medium text-slate-700 hover:bg-slate-100"
                onClick={() => router.patch(links.locale, { locale: nextLocale })}
              >
                {nextLocale === 'bn' ? 'বাংলা' : 'English'}
              </button>
            ) : null}
            {signupOpen ? (
              <Link href={links.signup} className="rounded-lg bg-primary px-3 py-2 font-semibold text-on-primary hover:opacity-90">
                {c('nav.signup')}
              </Link>
            ) : null}
          </nav>
        </div>

        <nav className="flex gap-1 overflow-x-auto border-t border-slate-200 px-4 py-1.5 text-sm sm:hidden" aria-label={c('nav.menu')}>
          {nav.map((item) => (
            <Link key={item.href} href={item.href} className="whitespace-nowrap rounded-lg px-2.5 py-1.5 font-medium text-slate-700 hover:bg-slate-100">
              {item.label}
            </Link>
          ))}
        </nav>
      </header>

      <main className="flex-1">{children}</main>

      <footer className="border-t border-slate-200 bg-white">
        <div className="mx-auto grid max-w-5xl gap-4 px-4 py-8 md:grid-cols-2 md:items-center">
          <div>
            <p className="text-sm font-bold text-slate-900">{appName}</p>
            <p className="mt-1 text-sm text-slate-600">{c('footer.tagline')}</p>
          </div>
          <nav className="flex flex-wrap gap-x-4 gap-y-2 text-sm md:justify-end" aria-label={c('footer.links')}>
            {nav.map((item) => (
              <Link key={item.href} href={item.href} className="text-slate-600 underline-offset-2 hover:text-primary hover:underline">
                {item.label}
              </Link>
            ))}
            {signupOpen ? (
              <Link href={links.signup} className="font-semibold text-primary underline-offset-2 hover:underline">
                {c('nav.signup')}
              </Link>
            ) : null}
          </nav>
          {platform && (platform.support_email !== '' || platform.support_phone !== '') ? (
            <p className="text-sm text-slate-600 md:col-span-2" data-testid="support-contact">
              {c('footer.support')}{' '}
              {platform.support_email !== '' ? <a href={`mailto:${platform.support_email}`} className="font-medium text-primary underline-offset-2 hover:underline">{platform.support_email}</a> : null}
              {platform.support_email !== '' && platform.support_phone !== '' ? ' · ' : ''}
              {platform.support_phone !== '' ? <a href={`tel:${platform.support_phone.replace(/[^+0-9]/g, '')}`} className="font-medium text-primary underline-offset-2 hover:underline">{platform.support_phone}</a> : null}
            </p>
          ) : null}
          <p className="text-xs text-slate-500 md:col-span-2">{c('footer.rights', { year, app: appName })}</p>
        </div>
      </footer>
    </div>
  );
}

export default CentralLayout;
