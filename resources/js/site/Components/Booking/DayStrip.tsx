// Step 1 of the booking page: the next 14 days as a horizontally scrolling strip of day cells. The one bold element
// of the picker — big numerals, the chosen day filled in the clinic's colour — with everything else quiet.
// Closed days stay in the strip (a gap would make the patient wonder whether the calendar is broken) and say why
// on the cell; tapping one hands the reason to the page instead of selecting it. Keyboard: arrows/Home/End move
// between cells (closed ones included, so a screen reader hears the reason), Enter/Space picks.
// Loading renders the same cells as skeletons so the section never changes height when the data arrives.
import { useEffect, useRef, type KeyboardEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { formatDhaka } from '@shared/format/date';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { weekdayOf, type DayCell, type DayState } from './availability';

interface Props {
  days: DayCell[];
  selected: string | null;
  today: string;
  loading: boolean;
  onSelect: (date: string) => void;
  /** A closed day was tapped: the page shows the reason next to the strip. */
  onExplain: (day: DayCell) => void;
}

const SKELETON = Array.from({ length: 7 }, (_, i) => i);

/** The short label under the day number for a day that cannot be booked. */
export function stateLabelKey(state: Exclude<DayState, 'open'>): string {
  switch (state) {
    case 'holiday': return 'booking.day.holiday';
    case 'leave': return 'booking.day.leave';
    case 'full': return 'booking.doctor.full';
    default: return 'booking.day.off';
  }
}

export function DayStrip({ days, selected, today, loading, onSelect, onExplain }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const ref = useRef<HTMLDivElement>(null);

  // Keep the chosen day in view (it is the first bookable one on load, which may be a swipe away on a phone).
  useEffect(() => {
    const cell = ref.current?.querySelector<HTMLElement>('[aria-pressed="true"]');
    if (!cell || typeof cell.scrollIntoView !== 'function') return;
    const reduce = typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    cell.scrollIntoView({ inline: 'center', block: 'nearest', behavior: reduce ? 'auto' : 'smooth' });
  }, [selected, loading]);

  const onKeyDown = (e: KeyboardEvent<HTMLDivElement>): void => {
    const cells = Array.from(ref.current?.querySelectorAll<HTMLButtonElement>('button[data-date]') ?? []);
    const current = cells.indexOf(e.target as HTMLButtonElement);
    if (current < 0 || cells.length === 0) return;
    const next = e.key === 'ArrowRight' ? Math.min(cells.length - 1, current + 1)
      : e.key === 'ArrowLeft' ? Math.max(0, current - 1)
        : e.key === 'Home' ? 0
          : e.key === 'End' ? cells.length - 1
            : -1;
    if (next < 0) return;
    e.preventDefault();
    cells[next]?.focus();
  };

  const focusable = selected ?? days.find((d) => d.state === 'open')?.date ?? days[0]?.date ?? null;

  return (
    <div ref={ref} role="group" aria-label={t('booking.steps.day')} aria-busy={loading} data-testid="day-strip" onKeyDown={onKeyDown}
      className="bk-strip -mx-5 flex snap-x snap-mandatory gap-2 overflow-x-auto overscroll-x-contain px-5 py-1">
      {loading
        ? SKELETON.map((i) => <div key={i} aria-hidden="true" className="h-[4.5rem] w-14 shrink-0 animate-pulse rounded-xl bg-slate-100 motion-reduce:animate-none" />)
        : days.map((day, i) => {
          const open = day.state === 'open';
          const active = selected === day.date;
          const isToday = day.date === today;
          const monthStarts = i === 0 || day.date.slice(8) === '01';
          const foot = day.state !== 'open' ? t(stateLabelKey(day.state)) : isToday ? t('booking.day.today') : monthStarts ? formatDhaka(day.date, 'MMM', locale) : '';
          return (
            <button key={day.date} type="button" data-date={day.date} data-state={day.state} aria-pressed={active} aria-disabled={!open}
              tabIndex={day.date === focusable ? 0 : -1}
              onClick={() => (open ? onSelect(day.date) : onExplain(day))}
              className={`bk-day flex h-[4.5rem] w-14 shrink-0 snap-start flex-col items-center justify-center rounded-xl border text-center transition-colors motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
                active ? 'border-primary bg-primary text-on-primary shadow-sm'
                  : open ? 'border-slate-200 bg-white text-slate-900 hover:border-primary/60'
                    : 'border-dashed border-slate-200 bg-slate-50 text-slate-400'}`}>
              <span className="text-[11px] font-medium leading-4">{t(`booking.weekday_short.${weekdayOf(day.date)}`)}</span>
              <span className={`text-2xl font-bold tabular-nums leading-7 ${!open && !active ? 'line-through decoration-slate-300' : ''}`}>{formatBn(Number(day.date.slice(8)), locale)}</span>
              <span className={`text-[11px] leading-4 ${active ? 'text-on-primary/90' : open ? 'text-primary' : ''} ${isToday && open && !active ? 'font-semibold' : ''}`}>{foot || ' '}</span>
            </button>
          );
        })}
    </div>
  );
}
