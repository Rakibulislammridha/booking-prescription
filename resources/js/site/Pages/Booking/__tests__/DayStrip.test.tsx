// The day strip (site/Components/Booking/DayStrip): every day of the range is a cell, closed days say why and hand
// the reason to the page instead of selecting, the chosen day is the pressed one, arrows/Home/End move focus, and
// loading renders skeletons of the same height so the section never jumps when the calendar arrives.
import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import { i18n } from '@shared/i18n';
import { DayStrip } from '@site/Components/Booking/DayStrip';
import { capacityFraction, timeOfDay, toDayCells, weekdayOf, type DayCell, type PickableSession } from '@site/Components/Booking/availability';
import type { AvailabilityDay } from '@shared/types/models';

const session = (over: Partial<AvailabilityDay['sessions'][number]> = {}): AvailabilityDay['sessions'][number] => ({
  code: 'A', public_id: '01J8ZK4V2Q3W5X6Y7Z8A9B0C2A', status: 'scheduled', mode: 'serial', planned_start_at: '2026-09-10T03:00:00Z', planned_end_at: '2026-09-10T07:00:00Z',
  delay_minutes: 0, online_remaining: 5, online_quota: 10, slot_minutes: null, now_serving: null, free_slots: null, ...over,
});

const days: AvailabilityDay[] = [
  { date: '2026-09-10', closed: null, sessions: [session()] },                                         // Thu, today
  { date: '2026-09-11', closed: 'holiday', sessions: [] },
  { date: '2026-09-12', closed: 'leave', sessions: [] },
  { date: '2026-09-13', closed: null, sessions: [session({ public_id: 'full', online_remaining: 0 })] },
  { date: '2026-09-14', closed: null, sessions: [session({ public_id: 'cancelled', status: 'cancelled', online_remaining: 0 })] },
  { date: '2026-09-15', closed: 'off', sessions: [] },
  { date: '2026-10-01', closed: null, sessions: [session({ public_id: 'oct' })] },
];

function show(over: Partial<Parameters<typeof DayStrip>[0]> = {}) {
  const onSelect = vi.fn();
  const onExplain = vi.fn();
  const cells = toDayCells(days);
  render(
    <I18nextProvider i18n={i18n}>
      <DayStrip days={cells} selected="2026-09-10" today="2026-09-10" loading={false} onSelect={onSelect} onExplain={onExplain} {...over} />
    </I18nextProvider>,
  );
  return { onSelect, onExplain, cells };
}

describe('availability helpers', () => {
  it('derives each day state from the payload: open, full, closed, or the calendar’s own reason', () => {
    expect(toDayCells(days).map((c: DayCell) => c.state)).toEqual(['open', 'holiday', 'leave', 'full', 'closed', 'off', 'open']);
  });

  it('knows weekdays of calendar dates, the time of day of a Dhaka start, and the capacity share', () => {
    expect(weekdayOf('2026-09-10')).toBe(4);   // Thursday
    expect(weekdayOf('2026-09-13')).toBe(0);   // Sunday
    expect(timeOfDay('2026-09-10T03:00:00Z')).toBe('morning');    // 09:00 Dhaka
    expect(timeOfDay('2026-09-10T08:00:00Z')).toBe('afternoon');  // 14:00
    expect(timeOfDay('2026-09-10T12:00:00Z')).toBe('evening');    // 18:00
    const s = toDayCells(days)[0]!.sessions[0] as PickableSession;
    expect(capacityFraction(s)).toBe(0.5);
    expect(capacityFraction({ ...s, online_remaining: 0 })).toBe(0);
    expect(capacityFraction({ ...s, online_quota: null })).toBe(1);
  });
});

describe('DayStrip', () => {
  it('renders every day with its weekday, number and reason, marks today and a new month, presses the selected one', () => {
    show();
    const cells = screen.getAllByRole('button');
    expect(cells).toHaveLength(7);
    expect(cells[0]).toHaveAttribute('aria-pressed', 'true');
    expect(cells[0]).toHaveTextContent('Thu');
    expect(cells[0]).toHaveTextContent('10');
    expect(cells[0]).toHaveTextContent('Today');
    expect(cells[1]).toHaveAttribute('aria-disabled', 'true');
    expect(cells[1]).toHaveTextContent('Holiday');
    expect(cells[2]).toHaveTextContent('On leave');
    expect(cells[3]).toHaveTextContent('Full');
    expect(cells[4]).toHaveTextContent('Off');
    expect(cells[5]).toHaveTextContent('Off');
    expect(cells[6]).toHaveTextContent('Oct');
    expect(cells[6]).toHaveAttribute('aria-disabled', 'false');
  });

  it('selects an open day and explains a closed one instead of selecting it', () => {
    const { onSelect, onExplain } = show();
    const cells = screen.getAllByRole('button');
    fireEvent.click(cells[6]!);
    expect(onSelect).toHaveBeenCalledWith('2026-10-01');
    fireEvent.click(cells[1]!);
    expect(onExplain).toHaveBeenCalledWith(expect.objectContaining({ date: '2026-09-11', state: 'holiday' }));
    expect(onSelect).toHaveBeenCalledTimes(1);
  });

  it('is keyboard navigable: one tab stop, arrows and Home/End move focus across closed days too', () => {
    show();
    const cells = screen.getAllByRole('button');
    expect(cells.filter((c) => c.tabIndex === 0)).toEqual([cells[0]]);
    cells[0]!.focus();
    fireEvent.keyDown(cells[0]!, { key: 'ArrowRight' });
    expect(document.activeElement).toBe(cells[1]);
    fireEvent.keyDown(cells[1]!, { key: 'End' });
    expect(document.activeElement).toBe(cells[6]);
    fireEvent.keyDown(cells[6]!, { key: 'ArrowRight' });
    expect(document.activeElement).toBe(cells[6]);
    fireEvent.keyDown(cells[6]!, { key: 'Home' });
    expect(document.activeElement).toBe(cells[0]);
    fireEvent.keyDown(cells[0]!, { key: 'ArrowLeft' });
    expect(document.activeElement).toBe(cells[0]);
  });

  it('shows skeleton cells of the same height while loading, and no buttons', () => {
    show({ loading: true, days: [] });
    expect(screen.queryAllByRole('button')).toHaveLength(0);
    const strip = screen.getByTestId('day-strip');
    expect(strip).toHaveAttribute('aria-busy', 'true');
    expect(strip.querySelectorAll('.animate-pulse')).toHaveLength(7);
    expect(strip.querySelector('.animate-pulse')!.className).toContain('h-[4.5rem]');
  });
});
