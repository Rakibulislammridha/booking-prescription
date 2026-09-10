// BRIEF §5.H asks for trend charts. Thin data is the normal case in a BD OPD clinic — a patient's third visit has
// three readings, and half of them skipped SpO2 — so what the component does with nothing, with one reading, and
// with a metric that was never taken is the part worth pinning down.
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { VitalsTrendCharts } from '../VitalsTrendCharts';
import type { VitalsTrendPoint } from '@shared/types/models';

const point = (at: string, over: Partial<VitalsTrendPoint> = {}): VitalsTrendPoint => ({
  id: Math.floor(Math.random() * 1e6), visit_id: 1, recorded_at: at,
  bp_systolic: 128, bp_diastolic: 84, pulse_bpm: 78, temperature_c: 37.1, temperature_f: 98.8, spo2_percent: 97,
  respiratory_rate: null, weight_kg: 70, height_cm: 170, bmi: 24.2, blood_glucose_mgdl: null, ...over,
});

const show = (points: VitalsTrendPoint[], locale: 'en' | 'bn' = 'en') => render(<VitalsTrendCharts points={points} locale={locale} />);

describe('VitalsTrendCharts', () => {
  it('says there is nothing rather than drawing an empty chart', () => {
    show([]);
    expect(screen.getByText(/No vitals have been recorded/i)).toBeInTheDocument();
    expect(screen.queryByTestId('vitals-chart-bp')).not.toBeInTheDocument();
  });

  it('shows a single reading as a value with its date, not as a "trend" of one point', () => {
    show([point('2026-09-01T04:00:00+06:00')]);
    expect(screen.getAllByText(/one reading — a trend needs two/i).length).toBeGreaterThan(0);
    expect(screen.queryByTestId('vitals-chart-bp')).not.toBeInTheDocument();
    expect(screen.queryByTestId('vitals-chart-weight_kg')).not.toBeInTheDocument();
  });

  it('draws one chart per metric once there are two readings to join', () => {
    show([point('2026-08-01T04:00:00+06:00'), point('2026-09-01T04:00:00+06:00')]);
    for (const key of ['bp', 'pulse_bpm', 'weight_kg', 'temperature_f', 'spo2_percent']) {
      expect(screen.getByTestId(`vitals-chart-${key}`)).toBeInTheDocument();
    }
    expect(screen.queryByTestId('vitals-chart-temperature_c')).not.toBeInTheDocument();
  });

  // The rows carry the stored °C; a person reads °F everywhere on this screen — the latest-value chip, the single
  // reading, the table header and its cells — and the Bangla screen reads Bangla digits with °ফা.
  it('shows temperature in °F from the stored °C, in the chip, the table and both scripts', () => {
    show([point('2026-09-01T04:00:00+06:00', { temperature_c: 38 })]);
    expect(screen.getAllByText(/100\.4/).length).toBeGreaterThan(0);          // 38 °C → 100.4 °F
    expect(screen.getByText('°F')).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: /Temperature \(°F\)/ })).toBeInTheDocument();
    expect(screen.queryByText(/38\b/)).not.toBeInTheDocument();
    expect(screen.queryByText(/°C/)).not.toBeInTheDocument();
  });

  it('reads Bangla digits and °ফা on the Bangla screen', () => {
    show([point('2026-08-01T04:00:00+06:00', { temperature_c: 37 }), point('2026-09-01T04:00:00+06:00', { temperature_c: 38 })], 'bn');
    expect(screen.getByText('১০০.৪ °ফা')).toBeInTheDocument();                // the latest-value chip
    expect(screen.getByRole('columnheader', { name: /\(°ফা\)/ })).toBeInTheDocument();
    expect(screen.getAllByText('৯৮.৬').length).toBeGreaterThan(0);            // 37 °C in the table
    expect(screen.queryByText(/°F/)).not.toBeInTheDocument();
  });

  it('is honest about a metric nobody ever recorded, while still charting the others', () => {
    const points = [
      point('2026-08-01T04:00:00+06:00', { spo2_percent: null }),
      point('2026-09-01T04:00:00+06:00', { spo2_percent: null }),
    ];
    show(points);
    expect(screen.getByText(/Never recorded for this patient/i)).toBeInTheDocument();
    expect(screen.queryByTestId('vitals-chart-spo2_percent')).not.toBeInTheDocument();
    expect(screen.getByTestId('vitals-chart-bp')).toBeInTheDocument();
  });

  it('lists every reading in the table underneath, newest first, with a dash for what was not taken', () => {
    show([
      point('2026-08-01T04:00:00+06:00', { pulse_bpm: 66 }),
      point('2026-09-01T04:00:00+06:00', { pulse_bpm: null }),
    ]);
    const rows = screen.getAllByRole('row').slice(1);   // drop the header
    expect(rows).toHaveLength(2);
    expect(rows[0]).toHaveTextContent('1 Sep 2026');
    expect(rows[1]).toHaveTextContent('66');
  });
});
