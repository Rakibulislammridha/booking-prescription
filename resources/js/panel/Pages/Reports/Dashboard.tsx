// The clinic owner's Sunday morning (BRIEF §5.L). Tiles are always TODAY — that is the page's promise — while
// the range in the filter bar drives the trend strip underneath. The money tile only exists for a scope that
// may see money; a doctor's dashboard never even queries the clinic's revenue.
import { lazy, useEffect, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Chip from '@mui/material/Chip';
import Grid from '@mui/material/Grid';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { LazyChart } from '@panel/Components/Charts/LazyChart';
import { ChartCard } from '@panel/Components/Reports/ChartCard';
import { DataTable, type Column } from '@panel/Components/Reports/DataTable';
import { FilterBar } from '@panel/Components/Reports/FilterBar';
import { Footnotes } from '@panel/Components/Reports/Footnotes';
import { ReportTabs } from '@panel/Components/Reports/ReportTabs';
import { StatCard } from '@panel/Components/Reports/StatCard';
import { fetchDashboard } from '@panel/api/reports';
import { formatBdt } from '@shared/format/money';
import { formatBn } from '@shared/format/number';
import { formatTimeDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type {
  AppointmentVolumeReport, DashboardSessionRow, ReportDashboard, ReportFilterState,
  ReportOptionsProps, ReportScopeProps,
} from '@shared/types/models';

type Props = PageProps<{
  report: 'dashboard';
  filters: ReportFilterState;
  scope: ReportScopeProps;
  options: ReportOptionsProps;
  data: ReportDashboard;
  trend: AppointmentVolumeReport;
  generated_at: string;
  cached: boolean;
}>;

/** Keep the tiles current while the page stays open on a screen at the desk. */
const REFRESH_MS = 60_000;

// recharts is ~97 KB gzip — more than the tiles, the table and this page's own code together. It arrives after
// first paint, and only when the range actually has periods to draw (Components/Charts/LazyChart.tsx).
const TrendAreaChart = lazy(() => import('@panel/Components/Charts/DashboardCharts').then((m) => ({ default: m.TrendAreaChart })));

export default function Dashboard({ filters, scope, options, data, trend, generated_at, cached }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [live, setLive] = useState<{ data: ReportDashboard; generated_at: string; cached: boolean }>({ data, generated_at, cached });
  const initial = useRef(true);

  useEffect(() => {
    if (initial.current) initial.current = false;
    else setLive({ data, generated_at, cached });
  }, [data, generated_at, cached]);

  useEffect(() => {
    const controller = new AbortController();
    const timer = window.setInterval(() => {
      void fetchDashboard({ from: filters.from, to: filters.to, branch: filters.branch, doctor: filters.doctor }, controller.signal)
        .then(setLive)
        .catch(() => undefined);   // a poll that fails leaves the last good numbers on screen
    }, REFRESH_MS);

    return () => { window.clearInterval(timer); controller.abort(); };
  }, [filters.from, filters.to, filters.branch, filters.doctor]);

  const today = live.data;
  const arrived = today.appointments.completed + today.live.waiting + today.live.in_consultation;
  const trendRows = trend.by_period ?? [];

  const sessionColumns: Column<DashboardSessionRow>[] = [
    { key: 'doctor', label: t('reports.column.doctor'), bn: true, render: (r) => r.doctor_name ?? '—' },
    { key: 'branch', label: t('reports.filter.branch'), bn: true, render: (r) => r.branch_name ?? '—' },
    { key: 'time', label: t('reports.column.session'), render: (r) => `${r.session_code} · ${r.planned_start_at ? formatTimeDhaka(r.planned_start_at, locale) : '—'}` },
    { key: 'status', label: t('reports.column.status'), render: (r) => <Chip size="small" variant="outlined" label={t(`reports.session_status.${r.status}`, { defaultValue: r.status })} /> },
    { key: 'issued', label: t('reports.column.booked'), align: 'right', render: (r) => formatBn(r.issued, locale) },
    { key: 'waiting', label: t('reports.dashboard.waiting'), align: 'right', render: (r) => formatBn(r.checked_in, locale) },
    { key: 'completed', label: t('reports.column.completed'), align: 'right', render: (r) => formatBn(r.completed, locale) },
    { key: 'no_show', label: t('reports.column.no_show'), align: 'right', render: (r) => formatBn(r.no_show, locale) },
  ];

  return (
    <Stack spacing={2}>
      <ReportTabs current="dashboard" scope={scope} filters={filters} />
      <FilterBar report="dashboard" filters={filters} options={options} scope={scope} generatedAt={live.generated_at} cached={live.cached} />

      <Typography variant="overline" color="text.secondary">{t('reports.dashboard.today', { date: today.date })}</Typography>

      <Grid container spacing={1.5}>
        <Tile><StatCard label={t('reports.column.booked')} value={formatBn(today.appointments.booked, locale)} note={t('reports.note.booked')} /></Tile>
        <Tile><StatCard label={t('reports.dashboard.arrived')} value={formatBn(arrived, locale)} note={t('reports.note.arrived')} /></Tile>
        <Tile><StatCard label={t('reports.column.completed')} value={formatBn(today.appointments.completed, locale)} tone="success" /></Tile>
        <Tile>
          <StatCard
            label={t('reports.column.no_show')} value={formatBn(today.appointments.no_show, locale)}
            hint={today.appointments.no_show_rate === null ? undefined : `${formatBn(today.appointments.no_show_rate, locale)}%`}
            note={t('reports.note.no_show')} tone={today.appointments.no_show > 0 ? 'warning' : 'default'}
          />
        </Tile>
        <Tile>
          <StatCard
            label={t('reports.column.wait_avg')} value={today.waits.wait_avg_minutes === null ? '—' : formatBn(today.waits.wait_avg_minutes, locale)}
            unit={t('reports.unit.minutes')} note={t('reports.note.wait')}
            hint={t('reports.dashboard.samples', { count: today.waits.wait_samples })}
          />
        </Tile>
        {scope.financial ? (
          <Tile>
            <StatCard
              label={t('reports.dashboard.collected')} value={formatBdt(today.money?.net_paisa ?? 0, locale)}
              note={t('reports.note.collection')}
              hint={t('reports.dashboard.payments', { count: today.money?.payment_count ?? 0 })}
            />
          </Tile>
        ) : null}
      </Grid>

      <Grid container spacing={1.5}>
        <Tile><StatCard label={t('reports.dashboard.waiting')} value={formatBn(today.live.waiting, locale)} note={t('reports.note.live')} /></Tile>
        <Tile><StatCard label={t('reports.dashboard.in_consultation')} value={formatBn(today.live.in_consultation, locale)} /></Tile>
        <Tile><StatCard label={t('reports.dashboard.not_arrived')} value={formatBn(today.live.not_arrived, locale)} /></Tile>
        <Tile><StatCard label={t('reports.column.new')} value={formatBn(today.patients.new, locale)} note={t('reports.note.new_patient')} /></Tile>
        <Tile><StatCard label={t('reports.column.returning')} value={formatBn(today.patients.returning, locale)} /></Tile>
        <Tile>
          <StatCard
            label={t('reports.column.consult_avg')} value={today.waits.consult_avg_minutes === null ? '—' : formatBn(today.waits.consult_avg_minutes, locale)}
            unit={t('reports.unit.minutes')} note={t('reports.note.consult')}
          />
        </Tile>
      </Grid>

      <ChartCard
        title={t('reports.dashboard.trend')}
        subtitle={t('reports.export.subtitle', { from: filters.from, to: filters.to })}
        chart={
          <LazyChart height={240} empty={trendRows.length === 0} emptyLabel={t('reports.empty')}>
            <TrendAreaChart rows={trendRows} />
          </LazyChart>
        }
      />

      <ChartCard title={t('reports.dashboard.sessions')}>
        {today.sessions.length === 0 ? (
          <Alert severity="info" variant="outlined">{t('reports.dashboard.no_sessions')}</Alert>
        ) : (
          <DataTable columns={sessionColumns} rows={today.sessions} rowKey={(r) => r.public_id} />
        )}
      </ChartCard>

      <Footnotes keys={['reports.note.today', 'reports.note.no_show', 'reports.note.wait', 'reports.note.consult']} />
    </Stack>
  );
}

function Tile({ children }: { children: ReactNode }) {
  return <Grid size={{ xs: 6, sm: 4, md: 2 }}>{children}</Grid>;
}

Dashboard.layout = (page: ReactNode) => <PanelLayout title="reports.dashboard.title">{page}</PanelLayout>;
