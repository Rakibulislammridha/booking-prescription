// Central pricing (Inertia::render('Central/Pricing')). Cards stacked on a phone, one comparison table from
// `md:` up — the same rows either way, so nothing is hidden from a 360px screen (CONVENTIONS §7.4).
import { useMemo, type ReactNode } from 'react';
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
  addons: PricingPlan[];
  links: CentralLinks;
  feature_labels: Record<string, string>;
}>;

const BYTES_PER_GB = 1024 * 1024 * 1024;

function Tick({ label }: { label: string }) {
  return (
    <>
      <svg viewBox="0 0 24 24" className="inline-block h-4 w-4 text-primary" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M20 6 9 17l-5-5" />
      </svg>
      <span className="sr-only">{label}</span>
    </>
  );
}

function Cross({ label }: { label: string }) {
  return (
    <>
      <svg viewBox="0 0 24 24" className="inline-block h-4 w-4 text-slate-300" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M18 6 6 18M6 6l12 12" />
      </svg>
      <span className="sr-only">{label}</span>
    </>
  );
}

/** Union of the keys the plans declare, in first-seen order, so every plan gets the same rows. */
function unionKeys(plans: PricingPlan[], pick: (plan: PricingPlan) => { key: string }[]): string[] {
  const keys: string[] = [];
  for (const plan of plans) {
    for (const entry of pick(plan)) if (!keys.includes(entry.key)) keys.push(entry.key);
  }
  return keys;
}

export default function Pricing({ plans, addons, links, feature_labels, copy }: Props) {
  const c = makeCopy(copy);
  const locale: Locale = getLocale();

  const limitKeys = useMemo(() => unionKeys(plans, (plan) => plan.limits), [plans]);
  const toggleKeys = useMemo(() => unionKeys(plans, (plan) => plan.toggles), [plans]);

  const label = (key: string): string => feature_labels[key] ?? c(`feature.${key}`);

  const limitText = (limit: PricingPlan['limits'][number] | undefined): string => {
    if (limit === undefined || limit.value === 0) return c('pricing.not_included');
    if (limit.value === null) return c('pricing.unlimited');
    if (limit.is_bytes) return c('pricing.gb', { size: formatNumber(limit.value / BYTES_PER_GB, locale) });
    return formatNumber(limit.value, locale);
  };

  const monthly = (plan: PricingPlan): string =>
    plan.price_monthly_paisa === 0 ? c('pricing.free') : formatBdt(plan.price_monthly_paisa, locale);

  const yearlySaving = (plan: PricingPlan): number => Math.max(0, plan.price_monthly_paisa * 12 - plan.price_yearly_paisa);

  const yearlyText = (plan: PricingPlan): string =>
    plan.price_yearly_paisa === 0 ? c('pricing.free') : formatBdt(plan.price_yearly_paisa, locale);

  const trialText = (plan: PricingPlan): string =>
    plan.trial_days > 0 ? c('pricing.trial', { days: formatNumber(plan.trial_days, locale) }) : c('pricing.no_trial');

  const signupHref = (plan: PricingPlan): string => `${links.signup}?plan=${encodeURIComponent(plan.code)}`;

  return (
    <div className="mx-auto max-w-5xl px-4 py-10 md:py-14">
      <header className="max-w-2xl">
        <h1 className="text-3xl font-black tracking-tight md:text-4xl">{c('pricing.title')}</h1>
        <p className="mt-3 text-base leading-relaxed text-slate-600">{c('pricing.subtitle')}</p>
      </header>

      {/* Phone: one card per plan, every row spelled out. */}
      <ul className="mt-8 grid gap-4 md:hidden">
        {plans.map((plan) => (
          <li
            key={plan.code}
            className={`rounded-2xl border bg-white p-5 ${plan.is_featured ? 'border-primary ring-2 ring-primary' : 'border-slate-200'}`}
          >
            <div className="flex items-start justify-between gap-3">
              <h2 className="text-lg font-bold">{plan.name}</h2>
              {plan.is_featured ? (
                <span className="rounded-full bg-primary px-2.5 py-1 text-xs font-bold text-on-primary">{c('pricing.popular')}</span>
              ) : null}
            </div>
            {plan.description ? <p className="mt-1 text-sm text-slate-600">{plan.description}</p> : null}

            <p className="mt-4 text-3xl font-black">{monthly(plan)}</p>
            <p className="text-xs text-slate-500">{plan.price_monthly_paisa === 0 ? c('pricing.free_forever') : c('pricing.per_month')}</p>
            <p className="mt-2 text-sm text-slate-600">
              {c('pricing.yearly', { amount: yearlyText(plan) })}
              {yearlySaving(plan) > 0 ? (
                <span className="ml-1 font-semibold text-primary">{c('pricing.yearly_save', { amount: formatBdt(yearlySaving(plan), locale) })}</span>
              ) : null}
            </p>
            <p className="mt-1 text-sm text-slate-600">{trialText(plan)}</p>

            <h3 className="mt-5 text-xs font-bold uppercase tracking-wide text-slate-500">{c('pricing.limits_title')}</h3>
            <dl className="mt-2 grid gap-1.5 text-sm">
              {limitKeys.map((key) => (
                <div key={key} className="flex justify-between gap-3 border-b border-slate-100 pb-1.5">
                  <dt className="text-slate-600">{label(key)}</dt>
                  <dd className="text-right font-semibold">{limitText(plan.limits.find((limit) => limit.key === key))}</dd>
                </div>
              ))}
            </dl>

            {toggleKeys.length > 0 ? (
              <>
                <h3 className="mt-5 text-xs font-bold uppercase tracking-wide text-slate-500">{c('pricing.features_title')}</h3>
                <ul className="mt-2 grid gap-1.5 text-sm">
                  {toggleKeys.map((key) => {
                    const on = plan.toggles.find((toggle) => toggle.key === key)?.enabled ?? false;
                    return (
                      <li key={key} className="flex items-center justify-between gap-3 border-b border-slate-100 pb-1.5">
                        <span className={on ? 'text-slate-800' : 'text-slate-400'}>{label(key)}</span>
                        {on ? <Tick label={c('pricing.yes')} /> : <Cross label={c('pricing.no')} />}
                      </li>
                    );
                  })}
                </ul>
              </>
            ) : null}

            <Link href={signupHref(plan)} className="mt-5 block rounded-xl bg-primary px-4 py-3 text-center font-bold text-on-primary hover:opacity-90">
              {c('pricing.choose', { plan: plan.name })}
            </Link>
          </li>
        ))}
      </ul>

      {/* md and up: one table so the plans can actually be compared side by side. */}
      <div className="mt-10 hidden overflow-x-auto md:block">
        <table className="w-full min-w-[42rem] border-collapse text-sm">
          <caption className="sr-only">{c('pricing.compare_caption')}</caption>
          <thead>
            <tr>
              <th scope="col" className="w-56 border-b border-slate-200 p-3 text-left align-bottom text-slate-500">
                {c('pricing.plan_column')}
              </th>
              {plans.map((plan) => (
                <th
                  key={plan.code}
                  scope="col"
                  className={`border-b p-3 text-left align-bottom ${plan.is_featured ? 'border-primary bg-primary/5' : 'border-slate-200'}`}
                >
                  {plan.is_featured ? (
                    <span className="mb-1 inline-block rounded-full bg-primary px-2 py-0.5 text-xs font-bold text-on-primary">{c('pricing.popular')}</span>
                  ) : null}
                  <span className="block text-base font-bold text-slate-900">{plan.name}</span>
                  {plan.description ? <span className="mt-1 block text-xs font-normal text-slate-600">{plan.description}</span> : null}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            <tr>
              <th scope="row" className="border-b border-slate-100 p-3 text-left font-semibold text-slate-700">{c('pricing.per_month')}</th>
              {plans.map((plan) => (
                <td key={plan.code} className={`border-b border-slate-100 p-3 text-lg font-black ${plan.is_featured ? 'bg-primary/5' : ''}`}>
                  {monthly(plan)}
                </td>
              ))}
            </tr>
            <tr>
              <th scope="row" className="border-b border-slate-100 p-3 text-left font-semibold text-slate-700">{c('pricing.per_year')}</th>
              {plans.map((plan) => (
                <td key={plan.code} className={`border-b border-slate-100 p-3 ${plan.is_featured ? 'bg-primary/5' : ''}`}>
                  <span className="font-semibold">{yearlyText(plan)}</span>
                  {yearlySaving(plan) > 0 ? (
                    <span className="mt-0.5 block text-xs font-semibold text-primary">
                      {c('pricing.yearly_save', { amount: formatBdt(yearlySaving(plan), locale) })}
                    </span>
                  ) : null}
                </td>
              ))}
            </tr>
            <tr>
              <th scope="row" className="border-b border-slate-100 p-3 text-left font-semibold text-slate-700">{c('pricing.trial_row')}</th>
              {plans.map((plan) => (
                <td key={plan.code} className={`border-b border-slate-100 p-3 ${plan.is_featured ? 'bg-primary/5' : ''}`}>{trialText(plan)}</td>
              ))}
            </tr>

            <tr>
              <th scope="colgroup" colSpan={plans.length + 1} className="border-b border-slate-200 bg-slate-50 p-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                {c('pricing.limits_title')}
              </th>
            </tr>
            {limitKeys.map((key) => (
              <tr key={key}>
                <th scope="row" className="border-b border-slate-100 p-3 text-left font-medium text-slate-700">{label(key)}</th>
                {plans.map((plan) => (
                  <td key={plan.code} className={`border-b border-slate-100 p-3 ${plan.is_featured ? 'bg-primary/5' : ''}`}>
                    {limitText(plan.limits.find((limit) => limit.key === key))}
                  </td>
                ))}
              </tr>
            ))}

            {toggleKeys.length > 0 ? (
              <tr>
                <th scope="colgroup" colSpan={plans.length + 1} className="border-b border-slate-200 bg-slate-50 p-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                  {c('pricing.features_title')}
                </th>
              </tr>
            ) : null}
            {toggleKeys.map((key) => (
              <tr key={key}>
                <th scope="row" className="border-b border-slate-100 p-3 text-left font-medium text-slate-700">{label(key)}</th>
                {plans.map((plan) => {
                  const on = plan.toggles.find((toggle) => toggle.key === key)?.enabled ?? false;
                  return (
                    <td key={plan.code} className={`border-b border-slate-100 p-3 ${plan.is_featured ? 'bg-primary/5' : ''}`}>
                      {on ? <Tick label={c('pricing.yes')} /> : <Cross label={c('pricing.no')} />}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr>
              <td />
              {plans.map((plan) => (
                <td key={plan.code} className={`p-3 ${plan.is_featured ? 'bg-primary/5' : ''}`}>
                  <Link href={signupHref(plan)} className="block rounded-lg bg-primary px-3 py-2.5 text-center font-bold text-on-primary hover:opacity-90">
                    {c('pricing.choose', { plan: plan.name })}
                  </Link>
                </td>
              ))}
            </tr>
          </tfoot>
        </table>
      </div>

      <p className="mt-4 text-xs text-slate-500">{c('pricing.vat_note')}</p>

      {addons.length > 0 ? (
        <section className="mt-12 border-t border-slate-200 pt-8" aria-labelledby="addons-heading">
          <h2 id="addons-heading" className="text-xl font-bold tracking-tight">{c('pricing.addons_title')}</h2>
          <p className="mt-1.5 max-w-2xl text-sm text-slate-600">{c('pricing.addons_subtitle')}</p>
          <ul className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {addons.map((addon) => (
              <li key={addon.code} className="rounded-xl border border-slate-200 bg-white p-4">
                <h3 className="text-sm font-bold">{addon.name}</h3>
                {addon.description ? <p className="mt-1 text-xs leading-relaxed text-slate-600">{addon.description}</p> : null}
                <p className="mt-3 text-lg font-black">{monthly(addon)}</p>
                <p className="text-xs text-slate-500">{c('pricing.per_month')}</p>
                {addon.toggles.length > 0 ? (
                  <ul className="mt-3 grid gap-1 text-xs text-slate-600">
                    {addon.toggles.filter((toggle) => toggle.enabled).map((toggle) => (
                      <li key={toggle.key} className="flex items-center gap-1.5">
                        <Tick label={c('pricing.yes')} />
                        <span>{label(toggle.key)}</span>
                      </li>
                    ))}
                  </ul>
                ) : null}
              </li>
            ))}
          </ul>
        </section>
      ) : null}
    </div>
  );
}

Pricing.layout = (page: ReactNode): ReactNode => <CentralLayout title="pricing.title">{page}</CentralLayout>;
