// Booking confirmation (Inertia::render('Booking/Confirmed')): the serial code, when to come, "pay at counter",
// and the public queue link + QR (the Queue module's page; the placeholder path is /q/{doctor-slug}/today).
// A serial HELD for advance payment (BRIEF §5.C) is NOT confirmed: the page says so, says when the hold lapses,
// and links straight to the checkout instead of congratulating the patient on a booking they do not have yet.
import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { QRCodeSVG } from 'qrcode.react';
import { SiteLayout } from '@site/Layouts/SiteLayout';
import { formatBn } from '@shared/format/number';
import { formatDateDhaka, formatTimeDhaka } from '@shared/format/date';
import { formatBdt } from '@shared/format/money';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { Appointment } from '@shared/types/models';

type Props = PageProps<{
  appointment: Appointment;
  queue_url: string;
  branch: { name: string; phone: string | null; address: string | null };
  pay_at_counter: boolean;
  held_for_payment: boolean;
  hold_minutes: number | null;
  checkout_url: string | null;
}>;

export default function Confirmed({ appointment, queue_url, branch, pay_at_counter, held_for_payment, hold_minutes, checkout_url }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const doctor = appointment.doctor;
  const session = appointment.session;
  const absoluteQueue = typeof window === 'undefined' ? queue_url : new URL(queue_url, window.location.origin).toString();

  return (
    <div className="mx-auto max-w-md grid gap-4">
      <section className="rounded-xl bg-white p-6 text-center shadow-sm" aria-live="polite">
        <p className={`text-sm font-semibold uppercase tracking-wide ${held_for_payment ? 'text-amber-700' : 'text-green-700'}`}>{held_for_payment ? t('booking.confirmed.held_title') : t('booking.confirmed.title')}</p>
        <p className="mt-2 text-6xl font-black tracking-wider text-primary" data-testid="serial-code">{formatBn(appointment.serial?.display_code ?? '—', locale)}</p>
        <p className="mt-2 text-lg font-semibold">{locale === 'bn' && doctor?.name_bn ? doctor.name_bn : doctor?.name}</p>
        {session ? <p className="text-slate-700">{formatDateDhaka(session.date)} · {t('booking.session_label', { code: session.code })} · {formatTimeDhaka(session.planned_start_at)}</p> : null}
        <p className="mt-1 text-sm text-slate-600">{appointment.patient?.name}</p>
      </section>

      <section className="rounded-xl bg-white p-5 shadow-sm grid gap-2 text-sm">
        <div className="flex justify-between"><span>{t('booking.confirmed.fee')}</span><strong>{formatBdt(appointment.fee.paisa)}</strong></div>
        {appointment.fee_rule_reason ? <p className="text-xs text-slate-500">{appointment.fee_rule_reason}</p> : null}
        {pay_at_counter ? <p className="rounded-lg bg-amber-50 p-2 text-amber-900">{t('booking.confirmed.pay_at_counter')}</p> : null}
        {held_for_payment ? (
          <div className="grid gap-2 rounded-lg bg-amber-50 p-3 text-amber-900">
            <p>{t('booking.confirmed.held_notice', { minutes: formatBn(hold_minutes ?? 0, locale) })}</p>
            {checkout_url ? <a href={checkout_url} className="rounded-lg bg-primary px-4 py-2 text-center text-base font-semibold text-on-primary">{t('booking.confirmed.pay_now')}</a> : null}
          </div>
        ) : null}
        <p className="text-slate-700">{branch.name}{branch.address ? ` · ${branch.address}` : ''}</p>
        {branch.phone ? <p className="text-slate-700">{t('booking.confirmed.phone')}: {formatBn(branch.phone, locale)}</p> : null}
      </section>

      <section className="rounded-xl bg-white p-5 shadow-sm text-center">
        <p className="text-sm font-semibold">{t('booking.confirmed.queue_title')}</p>
        <div className="mx-auto my-3 w-40" aria-hidden="true"><QRCodeSVG value={absoluteQueue} size={160} includeMargin /></div>
        <a href={queue_url} className="break-all text-sm font-semibold text-primary underline">{absoluteQueue}</a>
        <p className="mt-2 text-xs text-slate-500">{t('booking.confirmed.queue_help')}</p>
      </section>

      <div className="flex justify-between text-sm">
        {hasRoute('site.booking.index') ? <Link href={route('site.booking.index')} className="text-primary underline">{t('booking.confirmed.book_another')}</Link> : <span />}
        <button type="button" onClick={() => window.print()} className="text-primary underline">{t('common.actions.print')}</button>
      </div>
    </div>
  );
}

Confirmed.layout = (page: ReactNode) => <SiteLayout title="booking.confirmed.title">{page}</SiteLayout>;
