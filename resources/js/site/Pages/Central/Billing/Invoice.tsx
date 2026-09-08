// Subscription invoice on the central host (Inertia::render('Central/Billing/Invoice')).
//
// It is reachable on a SIGNED url by a signed-out clinic owner, so the pay action is a plain
// <form method="post"> with the CSRF token from the shared props — no XHR, no session assumption, and it still
// works if JavaScript never boots.
import type { ReactNode } from 'react';
import { CentralLayout } from '@site/Components/Central/CentralLayout';
import { makeCopy, type Copy } from '@site/Components/Central/copy';
import type { CentralLinks } from '@site/Components/Central/types';
import { useSharedProps } from '@shared/inertia';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';

interface InvoiceLine {
  description: string;
  quantity: number;
  unit_paisa: number;
  total_paisa: number;
}

type Props = PageProps<{
  copy: Copy;
  invoice: {
    public_id: string;
    number: string;
    status: string;
    total_paisa: number;
    paid_paisa: number;
    due_paisa: number;
    issued_at: string | null;
    due_at: string | null;
    period_start: string | null;
    period_end: string | null;
    line_items: InvoiceLine[];
  };
  tenant: { name: string; slug: string };
  gateways: { value: string; label: string }[];
  pay_url: string | null;
  links: CentralLinks;
  suspended: boolean;
}>;

const STATUS_STYLE: Record<string, string> = {
  draft: 'bg-slate-100 text-slate-700',
  issued: 'bg-primary/10 text-primary',
  paid: 'bg-primary text-on-primary',
  overdue: 'bg-amber-100 text-amber-900',
  void: 'bg-slate-200 text-slate-500 line-through',
};

export default function Invoice({ invoice, tenant, gateways, pay_url, suspended, copy }: Props) {
  const c = makeCopy(copy);
  const locale = getLocale();
  const csrf = useSharedProps().csrf_token;
  const date = (value: string | null): string => (value === null ? '—' : formatDhaka(value, 'D MMM YYYY', locale));
  const badge = STATUS_STYLE[invoice.status] ?? 'bg-slate-100 text-slate-700';

  return (
    <div className="mx-auto max-w-3xl px-4 py-8 md:py-12">
      {suspended ? (
        <p role="alert" className="mb-5 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm font-semibold leading-relaxed text-amber-900">
          {c('invoice.suspended_notice')}
        </p>
      ) : null}

      <article className="rounded-2xl border border-slate-200 bg-white p-5 md:p-8">
        <header className="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-5">
          <div className="min-w-0">
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{c('invoice.heading')}</p>
            <h1 className="mt-1 text-2xl font-black tracking-tight">{invoice.number}</h1>
            <p className="mt-1 text-sm text-slate-600">{c('invoice.billed_to')}: <span className="font-semibold text-slate-900">{tenant.name}</span></p>
          </div>
          <span className={`rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wide ${badge}`}>
            {c(`invoice.status.${invoice.status}`)}
          </span>
        </header>

        <dl className="grid gap-3 border-b border-slate-200 py-5 text-sm sm:grid-cols-3">
          <div>
            <dt className="text-slate-500">{c('invoice.period')}</dt>
            <dd className="mt-0.5 font-semibold text-slate-900">
              {invoice.period_start === null && invoice.period_end === null
                ? '—'
                : `${date(invoice.period_start)} — ${date(invoice.period_end)}`}
            </dd>
          </div>
          <div>
            <dt className="text-slate-500">{c('invoice.issued_at')}</dt>
            <dd className="mt-0.5 font-semibold text-slate-900">{date(invoice.issued_at)}</dd>
          </div>
          <div>
            <dt className="text-slate-500">{c('invoice.due_at')}</dt>
            <dd className="mt-0.5 font-semibold text-slate-900">{date(invoice.due_at)}</dd>
          </div>
        </dl>

        <div className="overflow-x-auto py-5">
          {invoice.line_items.length === 0 ? (
            <p className="text-sm text-slate-600">{c('invoice.no_lines')}</p>
          ) : (
            <table className="w-full min-w-[30rem] border-collapse text-sm">
              <caption className="sr-only">{c('invoice.lines_caption')}</caption>
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                  <th scope="col" className="py-2 pr-3 font-semibold">{c('invoice.description')}</th>
                  <th scope="col" className="py-2 px-3 text-right font-semibold">{c('invoice.quantity')}</th>
                  <th scope="col" className="py-2 px-3 text-right font-semibold">{c('invoice.unit')}</th>
                  <th scope="col" className="py-2 pl-3 text-right font-semibold">{c('invoice.line_total')}</th>
                </tr>
              </thead>
              <tbody>
                {invoice.line_items.map((line, index) => (
                  <tr key={index} className="border-b border-slate-100 align-top">
                    <td className="py-2.5 pr-3 text-slate-800">{line.description}</td>
                    <td className="py-2.5 px-3 text-right tabular-nums text-slate-700">{formatNumber(line.quantity, locale)}</td>
                    <td className="py-2.5 px-3 text-right tabular-nums text-slate-700">{formatBdt(line.unit_paisa, locale)}</td>
                    <td className="py-2.5 pl-3 text-right font-semibold tabular-nums text-slate-900">{formatBdt(line.total_paisa, locale)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <dl className="ml-auto grid max-w-xs gap-2 border-t border-slate-200 pt-5 text-sm">
          <div className="flex justify-between gap-4">
            <dt className="text-slate-600">{c('invoice.total')}</dt>
            <dd className="font-semibold tabular-nums text-slate-900">{formatBdt(invoice.total_paisa, locale)}</dd>
          </div>
          <div className="flex justify-between gap-4">
            <dt className="text-slate-600">{c('invoice.paid')}</dt>
            <dd className="font-semibold tabular-nums text-slate-900">{formatBdt(invoice.paid_paisa, locale)}</dd>
          </div>
          <div className="flex justify-between gap-4 border-t border-slate-200 pt-2">
            <dt className="text-base font-bold text-slate-900">{c('invoice.due')}</dt>
            <dd className="text-base font-black tabular-nums text-primary">{formatBdt(invoice.due_paisa, locale)}</dd>
          </div>
        </dl>
      </article>

      {pay_url !== null && gateways.length > 0 ? (
        <form method="post" action={pay_url} className="mt-6 rounded-2xl border border-slate-200 bg-white p-5 md:p-6 print:hidden">
          <input type="hidden" name="_token" value={csrf} />
          <h2 className="text-lg font-bold tracking-tight">{c('invoice.pay_title')}</h2>
          <p className="mt-1 text-sm text-slate-600">{c('invoice.pay_help', { amount: formatBdt(invoice.due_paisa, locale) })}</p>
          <div className="mt-4 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
            <div className="grid gap-1.5">
              <label htmlFor="gateway" className="text-sm font-semibold text-slate-800">{c('invoice.pay_method')}</label>
              <select
                id="gateway"
                name="gateway"
                defaultValue={gateways[0]?.value}
                className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
              >
                {gateways.map((gateway) => (
                  <option key={gateway.value} value={gateway.value}>{gateway.label}</option>
                ))}
              </select>
            </div>
            <button type="submit" className="rounded-lg bg-primary px-5 py-3 font-bold text-on-primary hover:opacity-90">
              {c('invoice.pay_now')}
            </button>
          </div>
        </form>
      ) : (
        <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-5 md:p-6 print:hidden">
          <h2 className="text-lg font-bold tracking-tight">{c('invoice.pay_title')}</h2>
          <p className="mt-1 text-sm leading-relaxed text-slate-700">{c('invoice.pay_offline')}</p>
        </section>
      )}

      <p className="mt-4 text-xs text-slate-500 print:hidden">{c('invoice.reference', { id: invoice.public_id })}</p>
    </div>
  );
}

Invoice.layout = (page: ReactNode): ReactNode => <CentralLayout title="invoice.title">{page}</CentralLayout>;
