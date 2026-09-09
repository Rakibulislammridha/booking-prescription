// LazyChart is the one thing standing between the panel and 97 KB gzip of recharts on a first load
// (scripts/check-panel-budget.sh). Two promises worth pinning down: an empty card never MOUNTS its chart, so the
// chunk is never requested; and a card with rows does mount it, so the split did not quietly delete a chart.
import { lazy } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { ThemeProvider } from '@mui/material/styles';
import { I18nextProvider } from 'react-i18next';
import { LazyChart } from '../LazyChart';
import { theme } from '@panel/theme';
import { addMessages, i18n, initI18n } from '@shared/i18n';
import en from '@lang/en.json';

initI18n('en');
addMessages('en', en as Record<string, string>);

const show = (node: React.ReactNode) => render(
  <I18nextProvider i18n={i18n}><ThemeProvider theme={theme}>{node}</ThemeProvider></I18nextProvider>,
);

describe('LazyChart', () => {
  it('does not even construct the chart when there is nothing to draw', () => {
    const load = vi.fn(() => Promise.resolve({ default: () => <div data-testid="chart" /> }));
    const Chart = lazy(load);

    show(<LazyChart height={200} empty emptyLabel="No data for this range."><Chart /></LazyChart>);

    expect(screen.getByTestId('chart-empty')).toHaveTextContent('No data for this range.');
    expect(screen.queryByTestId('chart')).not.toBeInTheDocument();
    expect(load, 'the chart chunk must not be requested for an empty range').not.toHaveBeenCalled();
  });

  it('falls back to the shared no-data sentence when the caller gives no label', () => {
    show(<LazyChart height={120} empty><div data-testid="chart" /></LazyChart>);

    expect(screen.getByTestId('chart-empty')).toHaveTextContent(en['common.status.no_data'] as string);
  });

  it('mounts the chart — and therefore fetches its chunk — as soon as there are rows', async () => {
    const load = vi.fn(() => Promise.resolve({ default: () => <div data-testid="chart">drawn</div> }));
    const Chart = lazy(load);

    show(<LazyChart height={200} empty={false}><Chart /></LazyChart>);

    await waitFor(() => expect(screen.getByTestId('chart')).toBeInTheDocument());
    expect(load).toHaveBeenCalledTimes(1);
    expect(screen.queryByTestId('chart-empty')).not.toBeInTheDocument();
  });
});

describe('the chart components every page lazies', () => {
  // A rename in Components/Charts that a page's `import(...).then((m) => m.X)` misses fails at RUNTIME, on a
  // report an owner opens once a week. Importing them here turns that into a build failure.
  it('every named export the pages reach for exists', async () => {
    const [dashboard, appointments, clinical, mix, peak, revenue, wait, superb] = await Promise.all([
      import('../DashboardCharts'), import('../AppointmentCharts'), import('../ClinicalCharts'),
      import('../PatientMixCharts'), import('../PeakHourCharts'), import('../RevenueCharts'),
      import('../WaitTimeCharts'), import('../SuperCharts'),
    ]);

    const exports: Array<[string, unknown]> = [
      ['DashboardCharts.TrendAreaChart', dashboard.TrendAreaChart],
      ['AppointmentCharts.ByPeriodChart', appointments.ByPeriodChart],
      ['AppointmentCharts.ByDoctorChart', appointments.ByDoctorChart],
      ['AppointmentCharts.BySourceChart', appointments.BySourceChart],
      ['ClinicalCharts.TrendLineChart', clinical.TrendLineChart],
      ['PatientMixCharts.MixAreaChart', mix.MixAreaChart],
      ['PeakHourCharts.ByHourChart', peak.ByHourChart],
      ['RevenueCharts.ByDayChart', revenue.ByDayChart],
      ['RevenueCharts.ByMethodChart', revenue.ByMethodChart],
      ['WaitTimeCharts.WaitTrendChart', wait.WaitTrendChart],
      ['SuperCharts.UsageSeriesChart', superb.UsageSeriesChart],
      ['SuperCharts.TenantHistoryChart', superb.TenantHistoryChart],
    ];

    for (const [name, value] of exports) expect(typeof value, name).toBe('function');
  });

  describe('the two super-admin charts really draw their points', () => {
    // jsdom has no layout and no ResizeObserver, so recharts' ResponsiveContainer sits at 0×0 and renders NOTHING
    // inside — a test that only checks "no throw" would pass for an empty component. Hand the container a size the
    // way the browser would (one ResizeObserver callback with the parent's content box) and assert the drawing.
    class StubResizeObserver {
      constructor(private readonly cb: ResizeObserverCallback) {}

      observe(): void { this.cb([{ contentRect: { width: 600, height: 240 } } as ResizeObserverEntry], this as unknown as ResizeObserver); }

      unobserve(): void {}

      disconnect(): void {}
    }

    beforeEach(() => vi.stubGlobal('ResizeObserver', StubResizeObserver));
    afterEach(() => vi.unstubAllGlobals());

    it('UsageSeriesChart draws one bar per period, labelled with the metric', async () => {
      const { UsageSeriesChart } = await import('../SuperCharts');

      const { container } = show(<div style={{ width: 600, height: 260 }}><UsageSeriesChart series={[{ period: '2026-07', total: 38000, tenants: 11 }, { period: '2026-08', total: 41000, tenants: 12 }]} label="SMS" /></div>);

      await waitFor(() => expect(container.querySelector('svg.recharts-surface')).toBeInTheDocument());
      expect(container.querySelectorAll('.recharts-bar-rectangle')).toHaveLength(2);
      expect(container.querySelector('.recharts-cartesian-axis-tick-value')?.textContent).toBe('2026-07');
      expect(container.textContent).toContain('2026-08');
    });

    it('TenantHistoryChart draws a line through its points', async () => {
      const { TenantHistoryChart } = await import('../SuperCharts');

      const { container } = show(<div style={{ width: 600, height: 220 }}><TenantHistoryChart points={[{ period: '2026-07', value: 700 }, { period: '2026-08', value: 900 }]} label="Appointments" /></div>);

      await waitFor(() => expect(container.querySelector('svg.recharts-surface')).toBeInTheDocument());
      // The line is drawn without dots (dot={false}), so the curve's path is the drawing: one segment between two points.
      const curve = container.querySelector('path.recharts-line-curve');
      expect(curve).toBeInTheDocument();
      expect(curve?.getAttribute('d')).toMatch(/^M[\d.,-]+/);
      expect(container.querySelectorAll('.recharts-xAxis-tick-labels .recharts-cartesian-axis-tick-value')).toHaveLength(2);
      expect(container.textContent).toContain('2026-07');
      expect(container.textContent).toContain('2026-08');
    });
  });
});
