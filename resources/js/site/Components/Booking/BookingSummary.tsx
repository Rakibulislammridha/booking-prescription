// The line that follows the patient from the picker into the form: "Wed 11 Sep · Session A · 10:00–13:00 · ৳500".
// Sticky, so on a phone the choice stays in view while the mobile number and name are typed; with no choice yet
// it points back up instead of sitting empty, and either way it has the same height (no layout shift on tap).
import { useTranslation } from 'react-i18next';
import { formatDhaka, formatTimeDhaka } from '@shared/format/date';
import { formatBdt } from '@shared/format/money';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { weekdayOf, type PickableSession } from './availability';

interface Props {
  picked: PickableSession | null;
  feePaisa: number;
  className?: string;
}

export function BookingSummary({ picked, feePaisa, className = '' }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();

  return (
    <div role="status" data-testid="booking-summary"
      className={`flex min-h-12 items-center gap-3 rounded-xl border px-4 py-2 text-sm shadow-sm ${picked ? 'border-primary/30 bg-white text-slate-900' : 'border-slate-200 bg-slate-50 text-slate-500'} ${className}`}>
      <span aria-hidden="true" className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full ${picked ? 'bg-primary text-on-primary' : 'bg-slate-200 text-slate-500'}`}>
        <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
          {picked ? <path d="M3 8.5l3 3 7-7" /> : <path d="M8 12V4M4.5 7.5L8 4l3.5 3.5" />}
        </svg>
      </span>
      {picked ? (
        <span className="min-w-0 truncate font-semibold">
          {t(`booking.weekday_short.${weekdayOf(picked.date)}`)} {formatDhaka(picked.date, 'D MMM', locale)}
          {' · '}{t('booking.session_label', { code: formatBn(picked.code, locale) })}
          {' · '}<span className="tabular-nums">{formatTimeDhaka(picked.planned_start_at, locale)}{picked.planned_end_at ? `–${formatTimeDhaka(picked.planned_end_at, locale)}` : ''}</span>
          {feePaisa > 0 ? <> · <span className="text-primary">{formatBdt(feePaisa, locale)}</span></> : null}
        </span>
      ) : (
        <span className="min-w-0 truncate">{t('booking.doctor.pick_session')}</span>
      )}
    </div>
  );
}
