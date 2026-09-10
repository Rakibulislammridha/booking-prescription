// The portal's upcoming-serials card (site/Components/Portal/UpcomingSerials): the status chip in the queue page's
// words, the live hint from the existing QueueState, "Track my serial" only for today's serials (the link comes from
// the server), a disabled "opens on" for later days, the hold with its expiry and pay link, Bangla digits, and the
// empty state's "Book a serial" button.
import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import { i18n, initI18n } from '@shared/i18n';
import { setActiveLocale } from '@shared/locale';
import type { UpcomingSerial } from '@shared/types/models';
import { UpcomingSerials, chipOf } from '@site/Components/Portal/UpcomingSerials';

vi.mock('@inertiajs/react', () => ({
  Link: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => <a href={href} {...rest}>{children}</a>,
}));

const today: UpcomingSerial = {
  appointment_id: 'apt_today', status: 'checked_in', is_telemedicine: false, is_today: true,
  patient: { public_id: 'pat_1', name: 'Rakibul Islam' },
  doctor: { slug: 'dr-rahman', name: 'Dr. Md. Abdur Rahman', name_bn: 'ডা. মোঃ আব্দুর রহমান', room: 'Room 1' },
  branch: { name: 'Demo Hospital' },
  session: { public_id: 'ses_1', code: 'B', date: '2026-09-10', status: 'running', planned_start_at: '2026-09-10T09:00:00Z', planned_end_at: '2026-09-10T13:00:00Z', delay_minutes: 0 },
  serial: { public_id: 'ser_1', display_code: 'B-011', number: 11, status: 'checked_in' },
  queue_url: '/q/dr-rahman/today?s=ser_1',
  hold_expires_at: null, pay_url: null,
  live: { now_serving: 'B-005', ahead: 3 },
};

const tomorrow: UpcomingSerial = {
  ...today,
  appointment_id: 'apt_tomorrow', status: 'confirmed', is_today: false,
  session: { ...today.session, public_id: 'ses_2', code: 'A', date: '2026-09-11', status: 'scheduled', planned_start_at: '2026-09-11T03:00:00Z', planned_end_at: '2026-09-11T07:00:00Z' },
  serial: { public_id: 'ser_2', display_code: 'A-004', number: 4, status: 'booked' },
  queue_url: null, live: null,
};

const held: UpcomingSerial = {
  ...today,
  appointment_id: 'apt_held', status: 'pending',
  serial: { public_id: 'ser_3', display_code: 'B-012', number: 12, status: 'booked' },
  queue_url: '/q/dr-rahman/today?s=ser_3',
  hold_expires_at: '2026-09-10T04:30:00Z', pay_url: '/booking/confirmed/apt_held', live: null,
};

function show(rows: UpcomingSerial[], extra: { bookUrl?: string | null; showPatient?: boolean } = {}) {
  const bookUrl = 'bookUrl' in extra ? extra.bookUrl ?? null : '/booking';
  return render(<I18nextProvider i18n={i18n}><UpcomingSerials rows={rows} bookUrl={bookUrl} showPatient={extra.showPatient} /></I18nextProvider>);
}

afterEach(() => {
  initI18n('en');
  setActiveLocale('en');
});

describe('UpcomingSerials', () => {
  it("shows today's serial with its status, the live hint, and the Track button pointing at the server-built queue link", () => {
    show([today]);
    const row = screen.getByTestId('upcoming-row');
    expect(within(row).getByTestId('upcoming-code')).toHaveTextContent('B-011');
    expect(within(row).getByTestId('upcoming-status')).toHaveTextContent('Arrived');
    expect(row).toHaveTextContent('Dr. Md. Abdur Rahman');
    expect(row).toHaveTextContent('Demo Hospital');
    expect(row).toHaveTextContent('Today · Session B · 15:00–19:00');
    expect(within(row).getByRole('status')).toHaveTextContent('Now serving B-005 · 3 ahead of you');
    const track = within(row).getByRole('link', { name: 'Track my serial' });
    expect(track).toHaveAttribute('href', '/q/dr-rahman/today?s=ser_1');
    expect(track.querySelector('svg')).not.toBeNull();
    expect(within(row).queryByRole('button')).toBeNull();
  });

  it('a later day gets a disabled "opens on" button instead of a link, and no live hint', () => {
    show([tomorrow]);
    const row = screen.getByTestId('upcoming-row');
    expect(within(row).getByTestId('upcoming-status')).toHaveTextContent('Booked');
    expect(row).toHaveTextContent('Fri 11 Sep · Session A · 09:00–13:00');
    expect(within(row).queryByRole('link')).toBeNull();
    expect(within(row).queryByRole('status')).toBeNull();
    const button = within(row).getByRole('button', { name: 'Queue opens on 11 Sep' });
    expect(button).toBeDisabled();
  });

  it('a serial held for advance payment shows the hold with its expiry and a pay link, and can still be tracked', () => {
    show([held]);
    const row = screen.getByTestId('upcoming-row');
    expect(within(row).getByTestId('upcoming-status')).toHaveTextContent('Held — pay by 10:30');
    expect(within(row).getByRole('link', { name: 'Pay now' })).toHaveAttribute('href', '/booking/confirmed/apt_held');
    expect(within(row).getByRole('link', { name: 'Track my serial' })).toHaveAttribute('href', '/q/dr-rahman/today?s=ser_3');
  });

  it('renders every status in the queue page\'s vocabulary and names the member only for a household', () => {
    const rows: UpcomingSerial[] = [
      { ...today, appointment_id: 'a1', serial: { ...today.serial, status: 'in_consultation' }, live: null },
      { ...today, appointment_id: 'a2', status: 'no_show', serial: { ...today.serial, status: 'no_show' }, live: null },
    ];
    show(rows, { showPatient: true });
    const chips = screen.getAllByTestId('upcoming-status').map((el) => el.textContent);
    expect(chips).toEqual(['In chamber', 'No-show']);
    expect(screen.getAllByText('Rakibul Islam')).toHaveLength(2);
    expect(chipOf(held)).toBe('held');
    expect(chipOf(today)).toBe('checked_in');
  });

  it('orders rows as given, keeps the live hint when only one half of it is known, and says "your turn" once called', () => {
    show([
      today,
      { ...tomorrow, is_today: true, queue_url: '/q/x/today?s=ser_2', live: { now_serving: null, ahead: 7 } },
      { ...today, appointment_id: 'apt_called', serial: { ...today.serial, status: 'in_consultation' }, live: { now_serving: 'B-011', ahead: 0 } },
    ]);
    const statuses = screen.getAllByRole('status');
    expect(statuses[0]).toHaveTextContent('Now serving B-005 · 3 ahead of you');
    expect(statuses[1]).toHaveTextContent('7 ahead of you');
    expect(statuses[1]).not.toHaveTextContent('Now serving');
    expect(statuses[2]).toHaveTextContent('It is your turn — please go in');
    expect(statuses[2]).not.toHaveTextContent('ahead');
  });

  it('speaks Bangla with Bangla digits', () => {
    initI18n('bn');
    setActiveLocale('bn');
    show([today]);
    const row = screen.getByTestId('upcoming-row');
    expect(within(row).getByTestId('upcoming-code')).toHaveTextContent('B-০১১');
    expect(row).toHaveTextContent('ডা. মোঃ আব্দুর রহমান');
    expect(row).toHaveTextContent('আজ · সেশন B · ১৫:০০–১৯:০০');
    expect(within(row).getByRole('status')).toHaveTextContent('এখন চলছে B-০০৫ · আপনার আগে ৩ জন');
    expect(within(row).getByRole('link', { name: 'আমার সিরিয়াল দেখুন' })).toHaveAttribute('href', '/q/dr-rahman/today?s=ser_1');
  });

  it('with nothing booked offers "Book a serial" to the doctor list, or only the sentence when booking is not routed', () => {
    const { unmount } = show([]);
    expect(screen.getByText('No upcoming serials.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Book a serial' })).toHaveAttribute('href', '/booking');
    unmount();
    show([], { bookUrl: null });
    expect(screen.queryByRole('link')).toBeNull();
  });
});
