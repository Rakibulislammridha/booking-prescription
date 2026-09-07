// Site home placeholder (Inertia::render('Home/Index') from the site surface).
import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { SiteLayout } from '@site/Layouts/SiteLayout';
import { useSharedProps } from '@shared/inertia';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<Record<never, never>>;

export default function Index(_props: Props) {
  const { t } = useTranslation();
  const shared = useSharedProps();
  const name = shared.tenant?.name ?? shared.app.name;
  const booking = hasRoute('site.booking.index') ? route('site.booking.index') : null;
  const portal = hasRoute('site.portal.login') ? route('site.portal.login') : null;

  return (
    <div className="grid gap-6">
      <section className="rounded-xl bg-white p-6 shadow-sm">
        <h1 className="text-2xl font-bold text-primary">{name}</h1>
        <p className="mt-2 text-slate-600">{t('site.home.tagline')}</p>
        <div className="mt-5 flex flex-wrap gap-3">
          {booking ? (
            <Link href={booking} className="rounded-lg bg-primary px-4 py-2 font-semibold text-on-primary">{t('site.home.book')}</Link>
          ) : (
            <span className="rounded-lg bg-slate-200 px-4 py-2 font-semibold text-slate-500" aria-disabled="true">{t('site.home.book')}</span>
          )}
          {portal ? (
            <Link href={portal} className="rounded-lg border border-primary px-4 py-2 font-semibold text-primary">{t('site.nav.portal')}</Link>
          ) : null}
        </div>
      </section>
      <section className="rounded-xl bg-white p-6 shadow-sm">
        <h2 className="text-lg font-semibold">{t('site.home.queue_title')}</h2>
        <p className="mt-1 text-sm text-slate-600">{t('site.home.queue_help')}</p>
      </section>
    </div>
  );
}

Index.layout = (page: ReactNode) => <SiteLayout title="site.home.title">{page}</SiteLayout>;
