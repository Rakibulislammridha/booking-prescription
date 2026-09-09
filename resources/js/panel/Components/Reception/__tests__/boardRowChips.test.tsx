// The two board-row indicators a receptionist reads at a glance: whether the compounder has been to this patient
// (and whether that answer is live or the last one the desk synced), and how long a payment hold has left before
// the serial goes back to the clinic. The countdown reaching zero has to TELL the board, not just re-colour itself.
import { describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { HoldChip, VitalsChip } from '../BoardRowChips';
import type { DeskVitals } from '@shared/types/models';

const vitals = (over: Partial<DeskVitals> = {}): DeskVitals => ({ recorded: true, readings: 1, recorded_at: '2026-09-09T04:10:00Z', reviewed: false, ...over });

describe('VitalsChip', () => {
  it('separates recorded from still due', () => {
    render(<VitalsChip vitals={vitals()} stale={false} locale="en" />);
    expect(screen.getByTestId('vitals-recorded')).toHaveTextContent('Vitals recorded');

    render(<VitalsChip vitals={vitals({ recorded: false, readings: 0, recorded_at: null })} stale={false} locale="en" />);
    expect(screen.getByTestId('vitals-due')).toHaveTextContent('Vitals due');
  });

  it('puts the detail in the tooltip: when it was taken, whether the doctor has it, and how fresh the answer is', async () => {
    render(<VitalsChip vitals={vitals({ reviewed: false })} stale locale="en" />);
    fireEvent.mouseOver(screen.getByTestId('vitals-recorded'));

    const tip = await screen.findByRole('tooltip');
    expect(tip).toHaveTextContent('Recorded');
    expect(tip).toHaveTextContent('Awaiting doctor review');
    expect(tip).toHaveTextContent('As of the last sync');
  });

  it('says the doctor has seen the reading once it is reviewed', async () => {
    render(<VitalsChip vitals={vitals({ reviewed: true })} stale={false} locale="en" />);
    fireEvent.mouseOver(screen.getByTestId('vitals-recorded'));

    const tip = await screen.findByRole('tooltip');
    expect(tip).toHaveTextContent('The doctor has seen these');
    expect(tip).not.toHaveTextContent('As of the last sync');
  });
});

describe('HoldChip', () => {
  it('counts a live hold down and fires once when it lapses', () => {
    vi.useFakeTimers();
    const onExpired = vi.fn();

    try {
      const expiresAt = new Date(Date.now() + 62_000).toISOString();
      render(<HoldChip expiresAt={expiresAt} locale="en" onExpired={onExpired} />);
      expect(screen.getByTestId('hold-pending')).toHaveTextContent('Held for payment · 1 min left');
      expect(onExpired).not.toHaveBeenCalled();

      act(() => { vi.advanceTimersByTime(10_000); });
      expect(screen.getByTestId('hold-pending')).toHaveTextContent('under a minute left');

      act(() => { vi.advanceTimersByTime(60_000); });
      expect(screen.getByTestId('hold-expired')).toHaveTextContent('Hold expired');
      expect(onExpired).toHaveBeenCalledTimes(1);

      act(() => { vi.advanceTimersByTime(30_000); });
      expect(onExpired).toHaveBeenCalledTimes(1);
    } finally {
      vi.useRealTimers();
    }
  });

  it('still marks the row held when the hold has no deadline to show', () => {
    render(<HoldChip expiresAt={null} locale="en" />);
    expect(screen.getByTestId('hold-pending')).toHaveTextContent('Held for payment');
    expect(screen.getByTestId('hold-pending')).not.toHaveTextContent('left');
  });
});
