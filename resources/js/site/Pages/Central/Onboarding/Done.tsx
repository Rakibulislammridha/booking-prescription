// Provisioning finished (Inertia::render('Central/Onboarding/Done')). The panel lives on ANOTHER host, so the
// big call to action is a real <a href>, not an Inertia <Link> — an XHR visit across hosts would fail.
import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { CentralLayout } from '@site/Components/Central/CentralLayout';
import { makeCopy, type Copy } from '@site/Components/Central/copy';
import type { CentralLinks } from '@site/Components/Central/types';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  copy: Copy;
  tenant: {
    name: string;
    slug: string;
    panel_url: string;
    admin_email: string;
    trial_ends_at: string | null;
  };
  links: CentralLinks;
}>;

const NEXT_STEPS = [1, 2, 3] as const;

/** "https://demo.example.com/" → "demo.example.com" — the address as a person would say it. */
function hostOf(url: string, slug: string): string {
  const stripped = url.replace(/^[a-z]+:\/\//i, '').replace(/\/+$/, '');
  return stripped === '' ? slug : stripped;
}

export default function Done({ tenant, links, copy }: Props) {
  const c = makeCopy(copy);
  const locale = getLocale();
  const address = hostOf(tenant.panel_url, tenant.slug);

  return (
    <div className="mx-auto max-w-2xl px-4 py-10 md:py-14">
      <div className="rounded-2xl border border-slate-200 bg-white p-6 md:p-8">
        <span aria-hidden="true" className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary">
          <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
            <path d="M20 6 9 17l-5-5" />
          </svg>
        </span>

        <h1 className="mt-4 text-2xl font-black tracking-tight md:text-3xl">{c('onboarding.done_title')}</h1>
        <p className="mt-2 text-slate-600">{c('onboarding.done_subtitle', { clinic: tenant.name })}</p>

        <dl className="mt-6 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
          <div>
            <dt className="text-slate-600">{c('onboarding.your_address')}</dt>
            <dd dir="ltr" className="mt-0.5 break-all text-base font-bold text-slate-900">{address}</dd>
          </div>
          <div>
            <dt className="text-slate-600">{c('onboarding.admin_email')}</dt>
            <dd dir="ltr" className="mt-0.5 break-all font-semibold text-slate-900">{tenant.admin_email}</dd>
            <dd className="mt-0.5 text-xs text-slate-500">{c('onboarding.admin_email_help')}</dd>
          </div>
          <div>
            <dt className="text-slate-600">{c('onboarding.trial_label')}</dt>
            <dd className="mt-0.5 font-semibold text-slate-900">
              {tenant.trial_ends_at === null
                ? c('onboarding.no_trial')
                : c('onboarding.trial_ends', { date: formatDhaka(tenant.trial_ends_at, 'D MMM YYYY', locale) })}
            </dd>
          </div>
        </dl>

        <a
          href={tenant.panel_url}
          className="mt-6 block rounded-xl bg-primary px-5 py-4 text-center text-base font-black text-on-primary hover:opacity-90"
        >
          {c('onboarding.go_to_panel')}
        </a>
        <p className="mt-2 text-center text-xs text-slate-500">{c('onboarding.bookmark')}</p>
      </div>

      <section className="mt-8" aria-labelledby="next-steps">
        <h2 id="next-steps" className="text-lg font-bold tracking-tight">{c('onboarding.next_title')}</h2>
        <ol className="mt-3 grid gap-2.5">
          {NEXT_STEPS.map((step) => (
            <li key={step} className="flex gap-3 rounded-xl border border-slate-200 bg-white p-4">
              <span aria-hidden="true" className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                {step}
              </span>
              <span className="text-sm leading-relaxed text-slate-700">{c(`onboarding.next.${step}`)}</span>
            </li>
          ))}
        </ol>
      </section>

      <p className="mt-6 text-sm text-slate-600">
        {c('onboarding.docs_hint')}{' '}
        <Link href={links.docs} className="font-semibold text-primary underline-offset-4 hover:underline">{c('nav.docs')}</Link>
      </p>
    </div>
  );
}

Done.layout = (page: ReactNode): ReactNode => <CentralLayout title="onboarding.done_title">{page}</CentralLayout>;
