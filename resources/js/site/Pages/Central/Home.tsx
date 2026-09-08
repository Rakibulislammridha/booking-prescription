// Central marketing home (Inertia::render('Central/Home') on the SaaS control-plane host).
//
// The commercial front door: a clinic manager in Dhaka, usually in Bangla, usually on a cheap Android over a
// 3G connection. Tailwind utilities only, no icon font (inline SVG at `currentColor`), no route() — every URL
// arrives in `links` (CONVENTIONS §7.4).
import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { CentralLayout } from '@site/Components/Central/CentralLayout';
import { makeCopy, type Copy } from '@site/Components/Central/copy';
import type { CentralLinks, PricingPlan } from '@site/Components/Central/types';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { Locale } from '@shared/types/shared-props';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  copy: Copy;
  plans: PricingPlan[];
  links: CentralLinks;
  highlights: string[];
}>;

const BYTES_PER_GB = 1024 * 1024 * 1024;

/** The six feature cards, in the order they are shown; `n` indexes `saas.marketing.feature.<n>.*`. */
const FEATURES: { n: number; path: ReactNode }[] = [
  // online serial booking
  { n: 1, path: <><path d="M8 2v4M16 2v4M3 10h18" /><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M8 15h4" /></> },
  // live queue + SMS
  { n: 2, path: <><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" /><path d="M8 10h8" /></> },
  // prescription writer with drug-safety checks
  { n: 3, path: <><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><path d="M14 2v6h6" /><path d="M9 13h6M9 17h4" /></> },
  // reception desk that works offline
  { n: 4, path: <><path d="M2 20h20" /><path d="M4 20V9a8 8 0 0 1 16 0v11" /><path d="M12 4V2" /></> },
  // reports
  { n: 5, path: <><path d="M3 3v18h18" /><path d="M7 15l4-5 3 3 5-7" /></> },
  // multi-branch
  { n: 6, path: <><path d="M3 21h18" /><path d="M5 21V7l7-4 7 4v14" /><path d="M10 21v-6h4v6" /></> },
];

const STEPS = [1, 2, 3] as const;

export default function Home({ plans, links, highlights, copy }: Props) {
  const c = makeCopy(copy);
  const locale = getLocale();
  const teaser = plans.filter((plan) => !plan.is_addon);

  // `null` = unlimited, `0` = not included (CentralLinks/PricingPlan contract); byte limits read as GB.
  const limitValue = (limit: PricingPlan['limits'][number], at: Locale): string => {
    if (limit.value === null) return c('pricing.unlimited');
    if (limit.value === 0) return c('pricing.not_included');
    if (limit.is_bytes) return c('pricing.gb', { size: formatNumber(limit.value / BYTES_PER_GB, at) });
    return formatNumber(limit.value, at);
  };

  return (
    <>
      <section className="bg-slate-900 text-white">
        <div className="mx-auto max-w-5xl px-4 py-12 md:py-20">
          <p className="text-xs font-semibold uppercase tracking-[0.2em] text-accent">{c('marketing.eyebrow')}</p>
          <h1 className="mt-3 max-w-3xl text-3xl font-black leading-tight tracking-tight md:text-5xl">
            {c('marketing.hero_title')}
          </h1>
          <p className="mt-4 max-w-2xl text-base leading-relaxed text-slate-300 md:text-lg">
            {c('marketing.hero_subtitle')}
          </p>
          <div className="mt-7 flex flex-col gap-3 sm:flex-row">
            <Link href={links.signup} className="rounded-xl bg-primary px-5 py-3.5 text-center text-base font-bold text-on-primary hover:opacity-90">
              {c('marketing.hero_cta')}
            </Link>
            <Link href={links.pricing} className="rounded-xl border border-white/30 px-5 py-3.5 text-center text-base font-semibold text-white hover:bg-white/10">
              {c('marketing.hero_secondary')}
            </Link>
          </div>
          <p className="mt-4 text-sm text-slate-400">{c('marketing.hero_note')}</p>

          {highlights.length > 0 ? (
            <ul className="mt-8 grid gap-2 border-t border-white/10 pt-6 sm:grid-cols-2">
              {highlights.map((key) => (
                <li key={key} className="flex items-start gap-2 text-sm text-slate-200">
                  <svg viewBox="0 0 24 24" className="mt-0.5 h-4 w-4 shrink-0 text-accent" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="M20 6 9 17l-5-5" />
                  </svg>
                  <span>{c(key)}</span>
                </li>
              ))}
            </ul>
          ) : null}
        </div>
      </section>

      <section className="mx-auto max-w-5xl px-4 py-12 md:py-16" aria-labelledby="features-heading">
        <h2 id="features-heading" className="text-2xl font-bold tracking-tight md:text-3xl">{c('marketing.features_title')}</h2>
        <p className="mt-2 max-w-2xl text-slate-600">{c('marketing.features_subtitle')}</p>
        <ul className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {FEATURES.map((feature) => (
            <li key={feature.n} className="rounded-2xl border border-slate-200 bg-white p-5">
              <span aria-hidden="true" className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                  {feature.path}
                </svg>
              </span>
              <h3 className="mt-4 text-base font-bold">{c(`marketing.feature.${feature.n}.title`)}</h3>
              <p className="mt-1.5 text-sm leading-relaxed text-slate-600">{c(`marketing.feature.${feature.n}.body`)}</p>
            </li>
          ))}
        </ul>
      </section>

      <section className="border-y border-slate-200 bg-white" aria-labelledby="how-heading">
        <div className="mx-auto max-w-5xl px-4 py-12 md:py-16">
          <h2 id="how-heading" className="text-2xl font-bold tracking-tight md:text-3xl">{c('marketing.how_title')}</h2>
          <p className="mt-2 max-w-2xl text-slate-600">{c('marketing.how_subtitle')}</p>
          <ol className="mt-8 grid gap-6 md:grid-cols-3">
            {STEPS.map((step) => (
              <li key={step} className="relative border-t-2 border-primary pt-4">
                <span className="text-sm font-black text-primary">{formatNumber(step, locale)}</span>
                <h3 className="mt-1 text-base font-bold">{c(`marketing.how.${step}.title`)}</h3>
                <p className="mt-1.5 text-sm leading-relaxed text-slate-600">{c(`marketing.how.${step}.body`)}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      {teaser.length > 0 ? (
        <section className="mx-auto max-w-5xl px-4 py-12 md:py-16" aria-labelledby="plans-heading">
          <div className="flex flex-wrap items-end justify-between gap-3">
            <div>
              <h2 id="plans-heading" className="text-2xl font-bold tracking-tight md:text-3xl">{c('marketing.plans_title')}</h2>
              <p className="mt-2 max-w-2xl text-slate-600">{c('marketing.plans_subtitle')}</p>
            </div>
            <Link href={links.pricing} className="text-sm font-semibold text-primary underline-offset-4 hover:underline">
              {c('marketing.plans_all')}
            </Link>
          </div>

          <ul className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {teaser.map((plan) => (
              <li
                key={plan.code}
                className={`rounded-2xl border bg-white p-5 ${plan.is_featured ? 'border-primary ring-1 ring-primary' : 'border-slate-200'}`}
              >
                <h3 className="text-base font-bold">{plan.name}</h3>
                <p className="mt-2 text-2xl font-black text-slate-900">
                  {plan.price_monthly_paisa === 0 ? c('pricing.free') : formatBdt(plan.price_monthly_paisa, locale)}
                </p>
                <p className="text-xs text-slate-500">{plan.price_monthly_paisa === 0 ? c('pricing.free_forever') : c('pricing.per_month')}</p>
                <ul className="mt-4 grid gap-1.5 text-sm text-slate-600">
                  {plan.limits.slice(0, 3).map((limit) => (
                    <li key={limit.key} className="flex justify-between gap-2">
                      <span>{c(`feature.${limit.key}`)}</span>
                      <span className="font-semibold text-slate-900">{limitValue(limit, locale)}</span>
                    </li>
                  ))}
                </ul>
                <Link
                  href={`${links.signup}?plan=${encodeURIComponent(plan.code)}`}
                  className="mt-5 block rounded-lg border border-primary px-4 py-2 text-center text-sm font-semibold text-primary hover:bg-primary hover:text-on-primary"
                >
                  {c('pricing.choose', { plan: plan.name })}
                </Link>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      <section className="border-t border-slate-200 bg-white">
        <div className="mx-auto max-w-3xl px-4 py-12 text-center md:py-16">
          <h2 className="text-2xl font-bold tracking-tight md:text-3xl">{c('marketing.closing_title')}</h2>
          <p className="mx-auto mt-3 max-w-xl text-slate-600">{c('marketing.closing_body')}</p>
          <Link href={links.signup} className="mt-6 inline-block rounded-xl bg-primary px-6 py-3.5 text-base font-bold text-on-primary hover:opacity-90">
            {c('marketing.closing_cta')}
          </Link>
        </div>
      </section>
    </>
  );
}

Home.layout = (page: ReactNode): ReactNode => <CentralLayout title="marketing.title">{page}</CentralLayout>;
