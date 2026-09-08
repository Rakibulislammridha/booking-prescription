// Confirmation. The join link is shown here as well as sent by SMS: a patient who books from the phone they are
// holding should not have to wait for a message to arrive before they can bookmark the room.
import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { SiteLayout } from '@site/Layouts/SiteLayout';
import { formatDateDhaka, formatTimeDhaka } from '@shared/format/date';
import type { PageProps } from '@shared/types/inertia';
import type { Appointment } from '@shared/types/models';

type Props = PageProps<{
  appointment: Appointment;
  join_url: string | null;
  room: string | null;
  queue_url: string;
  branch: { name: string; phone: string | null };
}>;

export default function Booked({ appointment, join_url, queue_url, branch }: Props) {
  const { t } = useTranslation();

  return (
    <div className="grid gap-4">
      <section className="rounded-xl bg-white p-5 shadow-sm">
        <h1 className="text-2xl font-bold text-slate-900">{t('telemedicine.booked.title')}</h1>
        <p className="mt-1 text-sm text-slate-600">{t('telemedicine.booked.intro')}</p>
        <p className="mt-3 text-3xl font-bold text-primary" data-testid="serial-code">{appointment.serial?.display_code ?? '—'}</p>
        <p className="mt-1 text-sm text-slate-700">
          {appointment.doctor?.name}
          {appointment.session ? ` · ${formatDateDhaka(appointment.session.date)} · ${formatTimeDhaka(appointment.session.planned_start_at)}` : ''}
        </p>
        <p className="mt-1 text-sm">{t('telemedicine.booked.fee')}: <strong className="text-primary">{appointment.fee.formatted}</strong></p>
      </section>

      {join_url ? (
        <section className="rounded-xl bg-white p-5 shadow-sm">
          <h2 className="text-lg font-semibold">{t('telemedicine.booked.join_title')}</h2>
          <p className="mt-1 text-sm text-slate-600">{t('telemedicine.booked.join_body')}</p>
          <Link href={join_url} data-testid="join-link" className="mt-3 inline-block rounded-lg bg-primary px-4 py-3 text-base font-semibold text-on-primary">
            {t('telemedicine.booked.open_room')}
          </Link>
        </section>
      ) : null}

      <section className="rounded-xl bg-white p-5 shadow-sm">
        <h2 className="text-lg font-semibold">{t('telemedicine.booked.queue_title')}</h2>
        <a href={queue_url} className="mt-2 inline-block text-sm font-semibold text-primary underline">{t('telemedicine.booked.queue_link')}</a>
        {branch.phone ? <p className="mt-2 text-xs text-slate-500">{t('telemedicine.room.help', { phone: branch.phone })}</p> : null}
      </section>
    </div>
  );
}

Booked.layout = (page: ReactNode) => <SiteLayout title="telemedicine.booked.title">{page}</SiteLayout>;
