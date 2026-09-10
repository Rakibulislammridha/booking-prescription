// The portal's "Upcoming serials" card (GET /portal `upcoming`, BRIEF §5.E + §5.H): every household booking from
// today on, the serial code first, and for today's serials the one button that matters — the public queue page with
// this serial pinned (`?s=`, REALTIME §5.3, built server-side), which owns "N ahead · estimated HH:MM" and keeps it
// live. The card only echoes the existing QueueState's "now serving · N ahead" as a hint. Tailwind + inline SVG only:
// the portal is a site route under the 95 KB first-load budget.
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { formatDhaka, formatTimeDhaka } from '@shared/format/date';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { UpcomingSerial } from '@shared/types/models';
import { weekdayOf } from '@site/Components/Booking/availability';

interface Props {
  rows: UpcomingSerial[];
  /** `site.booking.index` — the doctor list; null hides the empty-state button when the module is not routed. */
  bookUrl: string | null;
  /** Name the member on each row when the phone books for more than one person. */
  showPatient?: boolean;
}

export type Chip = 'held' | UpcomingSerial['serial']['status'];

/** The status chip: a hold for money comes first; otherwise the serial's own state, in the queue page's words. */
export function chipOf(row: UpcomingSerial): Chip {
  return row.status === 'pending' ? 'held' : row.serial.status;
}

const CHIP_KEY: Record<Chip, string> = {
  held: 'portal.upcoming.status.held',
  booked: 'queue.status.booked',
  checked_in: 'queue.status.checked_in',
  in_consultation: 'queue.status.in_consultation',
  completed: 'portal.upcoming.status.completed',
  no_show: 'portal.upcoming.status.no_show',
  cancelled: 'queue.status.cancelled',
  postponed: 'portal.upcoming.status.postponed',
};

const CHIP_TONE: Record<Chip, string> = {
  held: 'bg-amber-100 text-amber-900',
  booked: 'bg-slate-100 text-slate-700',
  checked_in: 'bg-primary/10 text-primary',
  in_consultation: 'bg-green-600 text-white',
  completed: 'bg-slate-100 text-slate-700',
  no_show: 'bg-amber-100 text-amber-900',
  cancelled: 'bg-slate-100 text-slate-500',
  postponed: 'bg-slate-100 text-slate-500',
};

export function UpcomingSerials({ rows, bookUrl, showPatient = false }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();

  return (
    <section className="rounded-xl bg-white p-5 shadow-sm" aria-labelledby="upcoming-title">
      <h2 id="upcoming-title" className="text-base font-semibold">{t('portal.upcoming.title')}</h2>

      {rows.length === 0 ? (
        <div className="mt-2">
          <p className="text-sm text-slate-600">{t('portal.upcoming.empty')}</p>
          {bookUrl ? (
            <Link href={bookUrl} className="mt-3 inline-block rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-on-primary">
              {t('portal.upcoming.book')}
            </Link>
          ) : null}
        </div>
      ) : (
        <ul className="mt-3 grid gap-3">
          {rows.map((row) => {
            const chip = chipOf(row);
            const doctorName = locale === 'bn' && row.doctor.name_bn ? row.doctor.name_bn : row.doctor.name;
            const day = row.is_today
              ? t('portal.upcoming.today')
              : `${t(`booking.weekday_short.${weekdayOf(row.session.date)}`)} ${formatDhaka(row.session.date, 'D MMM', locale)}`;
            const hours = `${formatTimeDhaka(row.session.planned_start_at, locale)}–${formatTimeDhaka(row.session.planned_end_at, locale)}`;
            const label = chip === 'held'
              ? t('portal.upcoming.status.held', { time: row.hold_expires_at ? formatTimeDhaka(row.hold_expires_at, locale) : '—' })
              : t(CHIP_KEY[chip]);

            return (
              <li key={row.appointment_id} className="rounded-xl border border-slate-200 p-4" data-testid="upcoming-row">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-3xl font-black tracking-wider text-primary" data-testid="upcoming-code">{formatBn(row.serial.display_code, locale)}</p>
                    <p className="mt-1 font-semibold">{doctorName}</p>
                    <p className="text-sm text-slate-600">{row.branch.name}</p>
                    <p className="text-sm text-slate-700">
                      {day}{' · '}{t('booking.session_label', { code: formatBn(row.session.code, locale) })}{' · '}<span className="tabular-nums">{hours}</span>
                    </p>
                    {showPatient ? <p className="mt-1 text-xs text-slate-500">{row.patient.name}</p> : null}
                  </div>
                  <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${CHIP_TONE[chip]}`} data-testid="upcoming-status">{label}</span>
                </div>

                {row.live && chip === 'in_consultation' ? (
                  <p role="status" className="mt-3 rounded-lg bg-green-600 px-3 py-2 text-sm font-semibold text-white">{t('queue.page.your_turn')}</p>
                ) : row.live && (row.live.now_serving !== null || row.live.ahead !== null) ? (
                  <p role="status" className="mt-3 rounded-lg bg-primary/10 px-3 py-2 text-sm font-medium text-slate-800">
                    {row.live.now_serving !== null ? t('portal.upcoming.now_serving', { code: formatBn(row.live.now_serving, locale) }) : null}
                    {row.live.now_serving !== null && row.live.ahead !== null ? ' · ' : null}
                    {row.live.ahead !== null ? t('queue.page.ahead', { count: formatBn(row.live.ahead, locale) }) : null}
                  </p>
                ) : null}

                {row.queue_url ? (
                  <Link href={row.queue_url} className="mt-3 flex min-h-12 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-base font-semibold text-on-primary">
                    <svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                      <path d="M1 8h3l2-5 3 10 2-5h4" />
                    </svg>
                    {t('portal.upcoming.track')}
                  </Link>
                ) : (
                  <button type="button" disabled className="mt-3 flex min-h-12 w-full items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-4 text-sm font-semibold text-slate-500">
                    {t('portal.upcoming.opens_on', { day: formatDhaka(row.session.date, 'D MMM', locale) })}
                  </button>
                )}

                {chip === 'held' && row.pay_url ? (
                  <Link href={row.pay_url} className="mt-2 block text-center text-sm font-semibold text-primary underline">{t('booking.confirmed.pay_now')}</Link>
                ) : null}
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}
