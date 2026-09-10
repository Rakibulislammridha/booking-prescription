// A session card (site/Components/Booking/SessionCard) in each state the availability payload can put it in, plus the
// sticky summary line (BookingSummary) that follows the patient into the form.
import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import { i18n, initI18n } from '@shared/i18n';
import { setActiveLocale } from '@shared/locale';
import { SessionCard } from '@site/Components/Booking/SessionCard';
import { BookingSummary } from '@site/Components/Booking/BookingSummary';
import type { PickableSession } from '@site/Components/Booking/availability';

const base: PickableSession = {
  public_id: '01J8ZK4V2Q3W5X6Y7Z8A9B0C2A', code: 'A', date: '2026-09-10', status: 'scheduled', mode: 'serial',
  planned_start_at: '2026-09-10T03:00:00Z', planned_end_at: '2026-09-10T07:00:00Z', delay_minutes: 0,
  online_remaining: 12, online_quota: 20, slot_minutes: null, now_serving: null, doctorName: null, fee_paisa: null,
};

function card(over: Partial<PickableSession> = {}, selected = false) {
  const onSelect = vi.fn();
  render(<I18nextProvider i18n={i18n}><SessionCard session={{ ...base, ...over }} selected={selected} onSelect={onSelect} /></I18nextProvider>);
  return { onSelect, button: screen.getByRole('button') };
}

describe('SessionCard', () => {
  it('shows the label, code, hours, the serials left with a bar, and the serial-mode line; picks on tap', () => {
    const { onSelect, button } = card();
    expect(button).toHaveTextContent('Morning');
    expect(button).toHaveTextContent('Session A');
    expect(button).toHaveTextContent('09:00–13:00');
    expect(button).toHaveTextContent('12 serials left');
    expect(button).toHaveTextContent('Serial number, no fixed time');
    expect(button).toBeEnabled();
    expect(button).toHaveAttribute('aria-pressed', 'false');
    expect((button.querySelector('.bg-primary.h-full') as HTMLElement).style.width).toBe('60%');
    fireEvent.click(button);
    expect(onSelect).toHaveBeenCalledWith(expect.objectContaining({ public_id: base.public_id }));
  });

  it('is pressed and ticked when selected', () => {
    const { button } = card({}, true);
    expect(button).toHaveAttribute('aria-pressed', 'true');
    expect(button.querySelector('svg')).not.toBeNull();
  });

  it('turns the bar amber when few serials are left', () => {
    const { button } = card({ online_remaining: 3 });
    expect(button.querySelector('.bg-amber-500')).not.toBeNull();
    expect(button).toHaveTextContent('3 serials left');
  });

  it('a full session cannot be chosen and says so', () => {
    const { onSelect, button } = card({ online_remaining: 0 });
    expect(button).toBeDisabled();
    expect(button).toHaveTextContent('Full');
    expect(button).not.toHaveTextContent('serials left');
    fireEvent.click(button);
    expect(onSelect).not.toHaveBeenCalled();
  });

  it('a closed or cancelled session cannot be chosen either', () => {
    const { button } = card({ status: 'closed', online_remaining: 0 });
    expect(button).toBeDisabled();
    expect(button).toHaveTextContent('This session has ended');
  });

  it('a running session says which serial it is serving', () => {
    const { button } = card({ status: 'running', now_serving: 'A-007' });
    expect(button).toBeEnabled();
    expect(button).toHaveTextContent('Running · now A-007');
  });

  it('a late start and slot mode are explained in one line each', () => {
    card({ delay_minutes: 40 });
    expect(screen.getByRole('button')).toHaveTextContent('Starting 40 min late');
  });

  it('slot mode names the appointment length; the kiosk shape (no mode, no end) shows the doctor instead', () => {
    const { button } = card({ mode: 'slot', slot_minutes: 15, planned_start_at: '2026-09-10T11:00:00Z' });
    expect(button).toHaveTextContent('Fixed 15-minute appointment time.');
    expect(button).toHaveTextContent('Evening');
  });

  it('kiosk sessions carry the doctor’s name and no mode line', () => {
    const { button } = card({ mode: null, planned_end_at: null, doctorName: 'Dr Sultana' });
    expect(button).toHaveTextContent('Dr Sultana');
    expect(button).toHaveTextContent('09:00');
    expect(button).not.toHaveTextContent('09:00–');
    expect(button).not.toHaveTextContent('fixed time');
  });
});

describe('BookingSummary', () => {
  it('points back to the picker until a session is chosen, then reads day · session · hours · fee', () => {
    const { rerender } = render(<I18nextProvider i18n={i18n}><BookingSummary picked={null} feePaisa={50000} /></I18nextProvider>);
    expect(screen.getByTestId('booking-summary')).toHaveTextContent('Choose a day and session');
    rerender(<I18nextProvider i18n={i18n}><BookingSummary picked={base} feePaisa={50000} /></I18nextProvider>);
    expect(screen.getByTestId('booking-summary')).toHaveTextContent('Thu 10 Sep · Session A · 09:00–13:00 · ৳500.00');
  });

  it('renders Bangla digits, weekday and month in Bangla', () => {
    initI18n('bn');
    setActiveLocale('bn');
    try {
      render(<I18nextProvider i18n={i18n}><BookingSummary picked={{ ...base, online_remaining: 12 }} feePaisa={80000} /></I18nextProvider>);
      expect(screen.getByTestId('booking-summary')).toHaveTextContent('বৃহঃ ১০ সেপ্ট · সেশন A · ০৯:০০–১৩:০০ · ৳৮০০.০০');
    } finally {
      initI18n('en');
      setActiveLocale('en');
    }
  });
});
