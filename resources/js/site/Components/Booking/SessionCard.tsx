// Step 2 of the booking page: one session of the chosen day as a selectable card — its label (morning/evening) and
// code, the hours, and the online capacity as a bar with the number ("12 serials left"), which is the fact the
// product sells (BRIEF §5.C: the calendar shows serials remaining). A running session says which serial it is
// serving; a full or closed one keeps its text readable and simply cannot be chosen. One line explains serial
// mode (a number, no fixed time) or slot mode (a fixed time), because patients new to the clinic ask exactly that.
import { useTranslation } from 'react-i18next';
import { formatTimeDhaka } from '@shared/format/date';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { capacityFraction, isBookable, isOpenStatus, timeOfDay, type PickableSession } from './availability';

interface Props {
  session: PickableSession;
  selected: boolean;
  onSelect: (session: PickableSession) => void;
}

export function SessionCard({ session: s, selected, onSelect }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const open = isOpenStatus(s.status);
  const bookable = isBookable(s);
  const fraction = capacityFraction(s);
  const scarce = bookable && (fraction <= 0.25 || s.online_remaining <= 3);

  // The one chip a card shows, in order of what matters most to the patient standing in front of it.
  const chip = !open ? { text: t('queue.page.session_ended'), tone: 'bg-slate-100 text-slate-600' }
    : !bookable ? { text: t('booking.doctor.full'), tone: 'bg-red-50 text-red-700' }
      : s.status === 'running' ? { text: s.now_serving ? t('booking.session.now_serving', { code: formatBn(s.now_serving, locale) }) : t('booking.session.running'), tone: 'bg-emerald-50 text-emerald-700', live: true }
        : s.status === 'paused' ? { text: t('queue.page.paused'), tone: 'bg-slate-100 text-slate-600' }
          : s.delay_minutes > 0 ? { text: t('booking.session.late', { minutes: formatBn(s.delay_minutes, locale) }), tone: 'bg-amber-50 text-amber-800' }
            : null;

  return (
    <button type="button" aria-pressed={selected} disabled={!bookable} onClick={() => onSelect(s)} data-testid={`session-${s.public_id}`}
      className={`bk-session relative grid w-full min-h-11 gap-2 rounded-xl border p-4 text-left transition-colors motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
        selected ? 'border-primary bg-primary/5 ring-2 ring-primary'
          : bookable ? 'border-slate-200 bg-white hover:border-primary/60'
            : 'cursor-not-allowed border-slate-200 bg-slate-50'}`}>
      <span className="flex items-start gap-2">
        <span className="min-w-0 flex-1">
          <span className={`block text-base font-bold ${bookable ? 'text-slate-900' : 'text-slate-500'}`}>
            {t(`booking.session.${timeOfDay(s.planned_start_at)}`)} <span className="font-medium text-slate-500">· {t('booking.session_label', { code: formatBn(s.code, locale) })}</span>
          </span>
          {s.doctorName ? <span className="block text-sm text-slate-700">{s.doctorName}</span> : null}
          <span className={`block text-sm tabular-nums ${bookable ? 'text-slate-700' : 'text-slate-500'}`}>
            {formatTimeDhaka(s.planned_start_at, locale)}{s.planned_end_at ? `–${formatTimeDhaka(s.planned_end_at, locale)}` : ''}
          </span>
        </span>
        {selected ? (
          <span aria-hidden="true" className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary text-on-primary">
            <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"><path d="M3 8.5l3 3 7-7" /></svg>
          </span>
        ) : chip ? (
          <span className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold ${chip.tone}`}>
            {chip.live ? <span aria-hidden="true" className="h-1.5 w-1.5 rounded-full bg-emerald-500 motion-safe:animate-pulse" /> : null}
            {chip.text}
          </span>
        ) : null}
      </span>

      <span className="grid gap-1">
        <span aria-hidden="true" className="h-1.5 overflow-hidden rounded-full bg-slate-200">
          <span className={`block h-full rounded-full transition-[width] motion-reduce:transition-none ${scarce ? 'bg-amber-500' : 'bg-primary'}`} style={{ width: `${Math.round(fraction * 100)}%` }} />
        </span>
        <span className={`text-sm font-semibold ${!bookable ? 'text-slate-500' : scarce ? 'text-amber-700' : 'text-slate-800'}`}>
          {bookable ? t('booking.doctor.remaining', { count: formatBn(s.online_remaining, locale) }) : open ? t('booking.doctor.full') : t('queue.page.session_ended')}
        </span>
      </span>

      {s.mode ? (
        <span className="text-xs leading-5 text-slate-500">
          {s.mode === 'slot' ? t('booking.session.mode_slot', { minutes: formatBn(s.slot_minutes ?? 0, locale) }) : t('booking.session.mode_serial')}
        </span>
      ) : null}
    </button>
  );
}
