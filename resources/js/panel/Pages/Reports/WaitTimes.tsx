// Average wait, average consultation and session overrun (BRIEF §5.L). The mean is shown next to p50 and p90,
// because a queue is staffed for its bad days, not for its average one — and the number of samples the
// consultation clamp excluded is on screen, so the exclusion is visible rather than hidden.
import { lazy, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Grid from '@mui/material/Grid';
import Stack from '@mui/material/Stack';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { LazyChart } from '@panel/Components/Charts/LazyChart';
import { ChartCard } from '@panel/Components/Reports/ChartCard';
import { DataTable, type Column } from '@panel/Components/Reports/DataTable';
import { ExportMenu } from '@panel/Components/Reports/ExportMenu';
import { FilterBar, query } from '@panel/Components/Reports/FilterBar';
import { Footnotes } from '@panel/Components/Reports/Footnotes';
import { ReportTabs } from '@panel/Components/Reports/ReportTabs';
import { StatCard } from '@panel/Components/Reports/StatCard';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type {
  ReportFilterState, ReportOptionsProps, ReportOverrunStats, ReportScopeProps, ReportWaitStats, WaitTimeReport,
} from '@shared/types/models';

type DoctorWait = ReportWaitStats & { doctor_id: number; doctor_name: string | null };
type DoctorOverrun = ReportOverrunStats & { doctor_id: number; doctor_name: string | null };

type Props = PageProps<{
  report: 'wait-times';
  filters: ReportFilterState;
  scope: ReportScopeProps;
  options: ReportOptionsProps;
  data: WaitTimeReport;
  generated_at: string;
  cached: boolean;
}>;

// The two tables underneath carry the same numbers, so the ~97 KB gzip of recharts arrives after first paint and
// only when the range actually has periods (Components/Charts/LazyChart.tsx).
const WaitTrendChart = lazy(() => import('@panel/Components/Charts/WaitTimeCharts').then((m) => ({ default: m.WaitTrendChart })));

export default function WaitTimes({ filters, scope, options, data, generated_at, cached }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const mins = (value: number | null): string => (value === null ? '—' : formatBn(value, locale));
  const totals = data.totals;

  const waitColumns: Column<DoctorWait>[] = [
    { key: 'doctor', label: t('reports.column.doctor'), bn: true, render: (r) => r.doctor_name ?? '—' },
    { key: 'wait_n', label: t('reports.column.wait_samples'), align: 'right', render: (r) => formatBn(r.wait_samples, locale) },
    { key: 'wait_avg', label: t('reports.column.wait_avg'), align: 'right', render: (r) => mins(r.wait_avg_minutes) },
    { key: 'wait_p50', label: t('reports.column.wait_p50'), align: 'right', render: (r) => mins(r.wait_p50_minutes) },
    { key: 'wait_p90', label: t('reports.column.wait_p90'), align: 'right', render: (r) => mins(r.wait_p90_minutes) },
    { key: 'consult_n', label: t('reports.column.consult_samples'), align: 'right', render: (r) => formatBn(r.consult_samples, locale) },
    { key: 'consult_avg', label: t('reports.column.consult_avg'), align: 'right', render: (r) => mins(r.consult_avg_minutes) },
    { key: 'consult_p90', label: t('reports.column.consult_p90'), align: 'right', render: (r) => mins(r.consult_p90_minutes) },
    { key: 'excluded', label: t('reports.column.excluded'), align: 'right', render: (r) => formatBn(r.consult_excluded, locale) },
  ];

  const overrunColumns: Column<DoctorOverrun>[] = [
    { key: 'doctor', label: t('reports.column.doctor'), bn: true, render: (r) => r.doctor_name ?? '—' },
    { key: 'sessions', label: t('reports.column.sessions'), align: 'right', render: (r) => formatBn(r.sessions, locale) },
    { key: 'avg', label: t('reports.column.overrun_avg'), align: 'right', render: (r) => mins(r.overrun_avg_minutes) },
    { key: 'max', label: t('reports.column.overrun_max'), align: 'right', render: (r) => mins(r.overrun_max_minutes) },
    { key: 'overran', label: t('reports.column.overran'), align: 'right', render: (r) => `${formatBn(r.overran, locale)}${r.overran_rate === null ? '' : ` (${formatBn(r.overran_rate, locale)}%)`}` },
    { key: 'late', label: t('reports.column.late_start_avg'), align: 'right', render: (r) => mins(r.late_start_avg_minutes) },
  ];

  return (
    <Stack spacing={2}>
      <ReportTabs current="wait-times" scope={scope} filters={filters} />
      <FilterBar
        report="wait-times" filters={filters} options={options} scope={scope}
        generatedAt={generated_at} cached={cached}
        action={scope.can_export ? <ExportMenu report="wait-times" query={query(filters)} /> : null}
      />

      <Grid container spacing={1.5}>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.column.wait_avg')} value={mins(totals.wait_avg_minutes)} unit={t('reports.unit.minutes')} note={t('reports.note.wait')} hint={t('reports.dashboard.samples', { count: totals.wait_samples })} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.column.wait_p90')} value={mins(totals.wait_p90_minutes)} unit={t('reports.unit.minutes')} note={t('reports.note.p90')} tone="warning" /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.column.consult_avg')} value={mins(totals.consult_avg_minutes)} unit={t('reports.unit.minutes')} note={t('reports.note.consult')} hint={t('reports.wait_times.excluded', { count: totals.consult_excluded })} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.column.overrun_avg')} value={mins(data.overrun.totals.overrun_avg_minutes)} unit={t('reports.unit.minutes')} note={t('reports.note.overrun')} hint={t('reports.wait_times.sessions', { count: data.overrun.totals.sessions })} /></Grid>
      </Grid>

      <ChartCard
        title={t('reports.wait_times.trend')}
        subtitle={t(`reports.granularity.${data.granularity}`)}
        chart={
          <LazyChart height={260} empty={data.by_period.length === 0} emptyLabel={t('reports.empty')}>
            <WaitTrendChart rows={data.by_period} />
          </LazyChart>
        }
      />

      <ChartCard title={t('reports.wait_times.by_doctor')}>
        <DataTable columns={waitColumns} rows={data.by_doctor} rowKey={(r) => String(r.doctor_id)} />
      </ChartCard>

      <ChartCard title={t('reports.wait_times.overrun')} subtitle={t('reports.wait_times.overrun_hint')}>
        <DataTable columns={overrunColumns} rows={data.overrun.by_doctor} rowKey={(r) => String(r.doctor_id)} />
      </ChartCard>

      <Footnotes keys={['reports.note.wait', 'reports.note.consult', 'reports.note.p90', 'reports.note.overrun']} />
    </Stack>
  );
}

WaitTimes.layout = (page: ReactNode) => <PanelLayout title="reports.wait_times.title">{page}</PanelLayout>;
