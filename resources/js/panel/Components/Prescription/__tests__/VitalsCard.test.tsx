// BRIEF §5.G.2: the compounder enters vitals, the doctor reviews them on the writer. The card shows the reading in
// °F from the °C the row stores, prefills its edit form in °F, and sends °F back — a doctor never reads a compounder's
// 100.4 °F as "100.4 °C", and never sees the stored 38.0 as the temperature.
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { VitalsRow } from '@shared/types/models';
import { setActiveLocale } from '@shared/locale';
import { VitalsCard } from '../VitalsCard';

const updateVitals = vi.fn();
const recordVitals = vi.fn();
vi.mock('@panel/api/prescription', () => ({
  updateVitals: (vital: number, body: unknown) => updateVitals(vital, body),
  recordVitals: (visit: string, body: unknown) => recordVitals(visit, body),
}));

const row = (over: Partial<VitalsRow> = {}): VitalsRow => ({
  id: 11, visit_id: 2, bp_systolic: 128, bp_diastolic: 84, pulse_bpm: 78, temperature_c: 38, temperature_f: 100.4, spo2_percent: 97,
  respiratory_rate: null, weight_kg: 68.5, height_cm: 170, bmi: 23.7, blood_glucose_mgdl: null, notes: null,
  recorded_by: { id: 3, name: 'Compounder' }, recorded_at: '2026-09-08T04:00:00+06:00',
  edited_by_doctor: false, reviewed_by_doctor_at: null, ...over,
});

// The writer store keeps the row and hands the saved one back to the card; this stands in for it.
function Harness({ initial }: { initial: VitalsRow | null }) {
  const [vitals, setVitals] = useState<VitalsRow | null>(initial);
  return <VitalsCard visitId="v1" vitals={vitals} reviewed={false} ageYears={41} onChange={setVitals} onReviewed={() => undefined} />;
}

const show = (vitals: VitalsRow | null) => render(<Harness initial={vitals} />);

describe('VitalsCard', () => {
  it('shows the compounder\'s reading in °F, converted from the stored °C', () => {
    setActiveLocale('en');
    show(row({ temperature_c: 38 }));
    expect(screen.getByText('100.4 °F')).toBeInTheDocument();
    expect(screen.queryByText(/°C/)).not.toBeInTheDocument();
    expect(screen.queryByText(/^38/)).not.toBeInTheDocument();
    expect(screen.getByText('128/84')).toBeInTheDocument();
    expect(screen.getByText('by Compounder')).toBeInTheDocument();
  });

  it('reads Bangla digits and °ফা when the doctor works in Bangla', () => {
    setActiveLocale('bn');
    try {
      show(row({ temperature_c: 37 }));
      expect(screen.getByText('৯৮.৬ °ফা')).toBeInTheDocument();
    } finally {
      setActiveLocale('en');
    }
  });

  it('prefills the edit form in °F, labels the field °F, and PATCHes temperature_f — never temperature_c', async () => {
    setActiveLocale('en');
    updateVitals.mockResolvedValue(row({ temperature_c: 38.9, temperature_f: 102, edited_by_doctor: true }));
    show(row({ temperature_c: 38 }));

    fireEvent.click(screen.getByRole('button', { name: /edit/i }));
    const field = screen.getByRole('textbox', { name: 'Temp' });
    expect(field).toHaveValue('100.4');
    expect(screen.getByLabelText('Temp (°F)')).toBe(field);
    expect(field).toHaveAttribute('placeholder', '98.6');

    fireEvent.change(field, { target: { value: '102' } });
    fireEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(updateVitals).toHaveBeenCalledTimes(1));
    const body = updateVitals.mock.calls[0]?.[1] as Record<string, unknown>;
    expect(body.temperature_f).toBe(102);
    expect(body).not.toHaveProperty('temperature_c');
    expect(await screen.findByText('102.0 °F')).toBeInTheDocument();
  });

  it('calls out a °C-looking value typed into the °F field instead of converting it silently', () => {
    setActiveLocale('en');
    show(null);
    const field = screen.getByRole('textbox', { name: 'Temp' });
    fireEvent.change(field, { target: { value: '37.2' } });
    expect(screen.getByText('Enter °F — 37.2 °C is 99.0 °F.')).toBeInTheDocument();
    fireEvent.change(field, { target: { value: '99' } });
    expect(screen.queryByText(/Enter °F/)).not.toBeInTheDocument();
  });
});
