// Patient-facing online payment (Inertia::render('Billing/Pay'), BRIEF §5.I): pick bKash / Nagad / SSLCommerz
// and be handed to the gateway.
//
// Every caption arrives ALREADY TRANSLATED from the server (`labels`), not through a client `t('billing.…')`
// lookup: the site bundle carries only the prefixes in `shared/lang/surfaces.ts`, and an unlisted key would
// render as its raw name in production while looking fine in tests (CONVENTIONS §7.5). Server-side `__()` reads
// the whole lang file, so this needs no foundation change to the allowlist.
import type { ReactNode } from 'react';
import { useState } from 'react';
import { router } from '@inertiajs/react';
import { SiteLayout } from '@site/Layouts/SiteLayout';
import { formatBdt } from '@shared/format/money';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { BillingGateway, InvoiceStatus } from '@shared/types/models';

type Props = PageProps<{
  appointment: { public_id: string; scheduled_date: string | null };
  invoice: { number: string; total_paisa: number; due_paisa: number; status: InvoiceStatus };
  gateways: BillingGateway[];
  labels: Record<string, string>;
}>;

export default function Pay({ appointment, invoice, gateways, labels }: Props) {
  const locale = getLocale();
  const [busy, setBusy] = useState(false);

  const start = (gateway: BillingGateway) => {
    setBusy(true);
    router.post(route('site.billing.checkout.start', { appointment: appointment.public_id }), { gateway }, { onFinish: () => setBusy(false) });
  };

  return (
    <div className="mx-auto grid max-w-md gap-4">
      <section className="rounded-xl bg-white p-6 text-center shadow-sm">
        <p className="text-sm font-semibold uppercase tracking-wide text-slate-500">{labels.invoice_no}</p>
        <p className="mt-1 text-lg font-semibold">{invoice.number}</p>
        <p className="mt-4 text-4xl font-black text-primary" data-testid="due">{formatBdt(invoice.due_paisa, locale)}</p>
        <p className="mt-1 text-sm text-slate-600">{labels.due}</p>
      </section>

      {invoice.due_paisa === 0 ? (
        <section className="rounded-xl bg-green-50 p-5 text-center text-green-800 shadow-sm" aria-live="polite">
          {labels.already_paid}
        </section>
      ) : gateways.length === 0 ? (
        <section className="rounded-xl bg-white p-5 text-center text-slate-700 shadow-sm">{labels.pay_at_counter}</section>
      ) : (
        <section className="grid gap-3">
          <p className="text-center text-sm text-slate-600">{labels.choose_method}</p>
          {gateways.map((gateway) => (
            <button
              key={gateway}
              type="button"
              disabled={busy}
              onClick={() => start(gateway)}
              className="rounded-xl bg-primary px-5 py-4 text-lg font-semibold text-white shadow-sm disabled:opacity-60"
            >
              {labels[`method_${gateway}`] ?? gateway}
            </button>
          ))}
          <p className="text-center text-xs text-slate-500">{labels.secure_notice}</p>
        </section>
      )}
    </div>
  );
}

Pay.layout = (page: ReactNode) => <SiteLayout>{page}</SiteLayout>;
