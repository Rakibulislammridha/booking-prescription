// The peak-hour heatmap for staffing (BRIEF §5.L). Weekday × clinic-local hour, defaulting to ARRIVALS — the
// moment a patient is physically at the desk is the one a rota is written against.
import { lazy, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Grid from '@mui/material/Grid';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import { router } from '@inertiajs/react';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { LazyChart } from '@panel/Components/Charts/LazyChart';
import { ChartCard } from '@panel/Components/Reports/ChartCard';
import { DataTable, type Column } from '@panel/Components/Reports/DataTable';
import { ExportMenu } from '@panel/Components/Reports/ExportMenu';
import { FilterBar, query } from '@panel/Components/Reports/FilterBar';
import { Footnotes } from '@panel/Components/Reports/Footnotes';
import { Heatmap } from '@panel/Components/Reports/Heatmap';
import { ReportTabs } from '@panel/Components/Reports/ReportTabs';
import { StatCard } from '@panel/Components/Reports/StatCard';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { PeakHourReport, PeakMetric, ReportFilterState, ReportOptionsProps, ReportScopeProps } from '@shared/types/models';

type DailyPeak = { weekday: number; hour: number | null; count: number; total: number };

type Props = PageProps<{
  report: 'peak-hours';
  filters: ReportFilterState;
  scope: ReportScopeProps;
  options: ReportOptionsProps;
  data: PeakHourReport;
  generated_at: string;
  cached: boolean;
}>;

const METRICS: PeakMetric[] = ['arrivals', 'bookings', 'consultations'];

// The heatmap above is plain MUI and keeps working on its own; only the hour-of-day bars need recharts, so its
// ~97 KB gzip lands after first paint and never at all for a range with nothing in it (LazyChart).
const ByHourChart = lazy(() => import('@panel/Components/Charts/PeakHourCharts').then((m) => ({ default: m.ByHourChart })));

export default function PeakHours({ filters, scope, options, data, generated_at, cached }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();

  const metricSelect = (
    <TextField
      select size="small" sx={{ minWidth: 180 }} label={t('reports.filter.metric')} value={filters.metric}
      onChange={(e) => router.get(route('panel.reports.show', { report: 'peak-hours' }), query(filters, { metric: e.target.value }), { preserveState: true, replace: true })}
    >
      {METRICS.map((m) => <MenuItem key={m} value={m}>{t(`reports.metric.${m}`)}</MenuItem>)}
    </TextField>
  );

  const hourly = Array.from({ length: 24 }, (_, hour) => ({
    hour: String(hour).padStart(2, '0'),
    count: data.grid.reduce((sum, row) => sum + (row[hour] ?? 0), 0),
  }));

  const dailyColumns: Column<DailyPeak>[] = [
    { key: 'weekday', label: t('reports.column.weekday'), render: (r) => t(`reports.weekday.${r.weekday}`) },
    { key: 'total', label: t('reports.column.total'), align: 'right', render: (r) => formatBn(r.total, locale) },
    { key: 'peak_hour', label: t('reports.peak_hours.peak_hour'), align: 'right', render: (r) => (r.hour === null ? '—' : `${String(r.hour).padStart(2, '0')}:00`) },
    { key: 'peak_count', label: t('reports.peak_hours.peak_count'), align: 'right', render: (r) => formatBn(r.count, locale) },
    {
      key: 'per_day', label: t('reports.peak_hours.per_occurrence'), align: 'right',
      render: (r) => {
        const days = data.weekday_occurrences[r.weekday] ?? 0;
        return days > 0 ? formatBn(Math.round((r.total / days) * 10) / 10, locale) : '—';
      },
    },
  ];

  return (
    <Stack spacing={2}>
      <ReportTabs current="peak-hours" scope={scope} filters={filters} />
      <FilterBar
        report="peak-hours" filters={filters} options={options} scope={scope}
        generatedAt={generated_at} cached={cached} extra={metricSelect}
        action={scope.can_export ? <ExportMenu report="peak-hours" query={query(filters)} /> : null}
      />

      <Grid container spacing={1.5}>
        <Grid size={{ xs: 6, md: 4 }}><StatCard label={t(`reports.metric.${data.metric}`)} value={formatBn(data.total, locale)} note={t('reports.note.peak_metric')} /></Grid>
        <Grid size={{ xs: 6, md: 4 }}>
          <StatCard
            label={t('reports.peak_hours.busiest')}
            value={data.busiest === null ? '—' : `${t(`reports.weekday.${data.busiest.weekday}`)} ${String(data.busiest.hour).padStart(2, '0')}:00`}
            hint={data.busiest === null ? undefined : t('reports.peak_hours.busiest_hint', { count: data.busiest.count })}
            note={t('reports.note.local_time')}
          />
        </Grid>
        <Grid size={{ xs: 12, md: 4 }}><StatCard label={t('reports.peak_hours.max_cell')} value={formatBn(data.max, locale)} /></Grid>
      </Grid>

      <ChartCard title={t('reports.peak_hours.heatmap')} subtitle={t('reports.peak_hours.heatmap_hint')}>
        {data.total === 0 ? (
          <Alert severity="info" variant="outlined">{t('reports.empty')}</Alert>
        ) : (
          <Heatmap grid={data.grid} max={data.max} />
        )}
      </ChartCard>

      <ChartCard
        title={t('reports.peak_hours.by_hour')}
        chart={
          <LazyChart height={220} empty={data.total === 0} emptyLabel={t('reports.empty')}>
            <ByHourChart rows={hourly} metricLabel={t(`reports.metric.${data.metric}`)} />
          </LazyChart>
        }
      >
        <DataTable columns={dailyColumns} rows={data.daily_peak} rowKey={(r) => String(r.weekday)} />
      </ChartCard>

      <Footnotes keys={['reports.note.peak_metric', 'reports.note.local_time', 'reports.note.weekday_occurrences']} />
    </Stack>
  );
}

PeakHours.layout = (page: ReactNode) => <PanelLayout title="reports.peak_hours.title">{page}</PanelLayout>;
