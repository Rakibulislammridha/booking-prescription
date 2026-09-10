import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { ScheduleDialog } from '../ScheduleDialog';
import { OverrideDialog } from '../OverrideDialog';

// Regression: both dialogs re-seed their form in an effect that used to depend on Inertia's useForm
// callbacks. Those callbacks change identity on every edit, so the effect ran after each keystroke and
// put the initial value straight back — the field looked read-only. A change must stick.
const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

describe('ScheduleDialog', () => {
  it('keeps what the user types instead of resetting on every render', () => {
    render(<ScheduleDialog open onClose={vi.fn()} doctorId={1} branchId={1} weekday={3} schedule={null} weekdayLabels={weekdays} />);

    const counter = screen.getByLabelText(/counter serials/i) as HTMLInputElement;
    expect(counter.value).toBe('10');
    fireEvent.change(counter, { target: { value: '15' } });
    expect(counter.value).toBe('15');

    const label = screen.getByLabelText(/^label$/i) as HTMLInputElement;
    fireEvent.change(label, { target: { value: 'Evening OPD' } });
    expect(label.value).toBe('Evening OPD');
    expect(counter.value).toBe('15');                       // an unrelated edit must not reset the first one
  });

  it('re-seeds when the dialog is reopened for a different weekday', () => {
    const { rerender } = render(<ScheduleDialog open onClose={vi.fn()} doctorId={1} branchId={1} weekday={3} schedule={null} weekdayLabels={weekdays} />);
    fireEvent.change(screen.getByLabelText(/counter serials/i), { target: { value: '15' } });
    rerender(<ScheduleDialog open={false} onClose={vi.fn()} doctorId={1} branchId={1} weekday={3} schedule={null} weekdayLabels={weekdays} />);
    rerender(<ScheduleDialog open onClose={vi.fn()} doctorId={1} branchId={1} weekday={4} schedule={null} weekdayLabels={weekdays} />);
    expect((screen.getByLabelText(/counter serials/i) as HTMLInputElement).value).toBe('10');
  });
});

describe('OverrideDialog', () => {
  it('keeps what the user types', () => {
    render(<OverrideDialog open onClose={vi.fn()} doctorId={1} branchId={1} today="2026-09-10" sessionCodes={['A', 'B']} />);
    const delay = screen.getByLabelText(/delay/i) as HTMLInputElement;
    fireEvent.change(delay, { target: { value: '45' } });
    expect(delay.value).toBe('45');
    const reason = screen.getByLabelText(/reason/i) as HTMLInputElement;
    fireEvent.change(reason, { target: { value: 'Traffic' } });
    expect(reason.value).toBe('Traffic');
    expect(delay.value).toBe('45');
  });
});
