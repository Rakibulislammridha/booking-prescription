// Appointments booked / completed / no-show by doctor, day and source (BRIEF §5.L). Every chart has the same
// numbers as a table under it, and every ambiguous metric states its definition in a footnote.
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
  AppointmentVolumeReport, ReportFilterState, ReportOptionsProps, ReportScopeProps,
  ReportVolumeByDoctor, ReportVolumeBySource,
} from '@shared/types/models';

type Props = PageProps<{
  report: 'appointments';
  filters: ReportFilterState;
  scope: ReportScopeProps;
  options: ReportOptionsProps;
  data: AppointmentVolumeReport;
  generated_at: string;
  cached: boolean;
}>;

// One chunk for all three drawings, fetched after first paint and only for the cards that have rows
// (Components/Charts/LazyChart.tsx): recharts is ~97 KB gzip, more than half of what this page used to cost.
const ByPeriodChart = lazy(() => import('@panel/Components/Charts/AppointmentCharts').then((m) => ({ default: m.ByPeriodChart })));
const ByDoctorChart = lazy(() => import('@panel/Components/Charts/AppointmentCharts').then((m) => ({ default: m.ByDoctorChart })));
const BySourceChart = lazy(() => import('@panel/Components/Charts/AppointmentCharts').then((m) => ({ default: m.BySourceChart })));

export default function Appointments({ filters, scope, options, data, generated_at, cached }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const totals = data.totals;
  const pct = (value: number | null): string => (value === null ? '—' : `${formatBn(value, locale)}%`);
  const channelSlices = data.by_channel_group.map((row) => ({ ...row, label: t(`reports.channel.${row.channel_group}`) }));

  const doctorColumns: Column<ReportVolumeByDoctor>[] = [
    { key: 'doctor', label: t('reports.column.doctor'), bn: true, render: (r) => r.doctor_name ?? '—' },
    { key: 'booked', label: t('reports.column.booked'), align: 'right', render: (r) => formatBn(r.booked, locale) },
    { key: 'completed', label: t('reports.column.completed'), align: 'right', render: (r) => formatBn(r.completed, locale) },
    { key: 'no_show', label: t('reports.column.no_show'), align: 'right', render: (r) => formatBn(r.no_show, locale) },
    { key: 'cancelled', label: t('reports.column.cancelled'), align: 'right', render: (r) => formatBn(r.cancelled, locale) },
    { key: 'expected', label: t('reports.column.expected'), align: 'right', render: (r) => formatBn(r.expected, locale) },
    { key: 'no_show_rate', label: t('reports.column.no_show_rate'), align: 'right', render: (r) => pct(r.no_show_rate) },
    { key: 'completion_rate', label: t('reports.column.completion_rate'), align: 'right', render: (r) => pct(r.completion_rate) },
  ];

  const sourceColumns: Column<ReportVolumeBySource>[] = [
    { key: 'source', label: t('reports.column.source'), render: (r) => t(`reports.source.${r.source}`, { defaultValue: r.source }) },
    { key: 'group', label: t('reports.column.channel'), render: (r) => t(`reports.channel.${r.channel_group}`) },
    { key: 'booked', label: t('reports.column.booked'), align: 'right', render: (r) => formatBn(r.booked, locale) },
    { key: 'completed', label: t('reports.column.completed'), align: 'right', render: (r) => formatBn(r.completed, locale) },
    { key: 'no_show', label: t('reports.column.no_show'), align: 'right', render: (r) => formatBn(r.no_show, locale) },
    { key: 'no_show_rate', label: t('reports.column.no_show_rate'), align: 'right', render: (r) => pct(r.no_show_rate) },
  ];

  return (
    <Stack spacing={2}>
      <ReportTabs current="appointments" scope={scope} filters={filters} />
      <FilterBar
        report="appointments" filters={filters} options={options} scope={scope}
        generatedAt={generated_at} cached={cached}
        action={scope.can_export ? <ExportMenu report="appointments" query={query(filters)} /> : null}
      />

      <Grid container spacing={1.5}>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.column.booked')} value={formatBn(totals.booked, locale)} note={t('reports.note.booked')} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.column.completed')} value={formatBn(totals.completed, locale)} hint={pct(totals.completion_rate)} tone="success" /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.column.no_show')} value={formatBn(totals.no_show, locale)} hint={pct(totals.no_show_rate)} note={t('reports.note.no_show_rate')} tone={totals.no_show > 0 ? 'warning' : 'default'} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.column.cancelled')} value={formatBn(totals.cancelled, locale)} note={t('reports.note.transfer')} /></Grid>
      </Grid>

      <ChartCard
        title={t('reports.appointments.by_period')}
        subtitle={t(`reports.granularity.${data.granularity}`)}
        chart={
          <LazyChart height={260} empty={data.by_period.length === 0} emptyLabel={t('reports.empty')}>
            <ByPeriodChart rows={data.by_period} />
          </LazyChart>
        }
      >
        <DataTable
          columns={[
            { key: 'period', label: t('reports.column.period'), render: (r) => r.period },
            { key: 'booked', label: t('reports.column.booked'), align: 'right', render: (r) => formatBn(r.booked, locale) },
            { key: 'completed', label: t('reports.column.completed'), align: 'right', render: (r) => formatBn(r.completed, locale) },
            { key: 'no_show', label: t('reports.column.no_show'), align: 'right', render: (r) => formatBn(r.no_show, locale) },
            { key: 'rate', label: t('reports.column.no_show_rate'), align: 'right', render: (r) => pct(r.no_show_rate) },
          ]}
          rows={data.by_period}
          rowKey={(r) => r.period}
        />
      </ChartCard>

      <ChartCard
        title={t('reports.appointments.by_doctor')}
        chart={
          <LazyChart height={Math.max(180, data.by_doctor.length * 42)} empty={data.by_doctor.length === 0} emptyLabel={t('reports.empty')}>
            <ByDoctorChart rows={data.by_doctor} />
          </LazyChart>
        }
      >
        <DataTable columns={doctorColumns} rows={data.by_doctor} rowKey={(r) => String(r.doctor_id)} />
      </ChartCard>

      <ChartCard
        title={t('reports.appointments.by_source')}
        subtitle={t('reports.appointments.by_source_hint')}
        chart={
          <LazyChart height={220} empty={channelSlices.length === 0} emptyLabel={t('reports.empty')}>
            <BySourceChart slices={channelSlices} />
          </LazyChart>
        }
      >
        <DataTable columns={sourceColumns} rows={data.by_source} rowKey={(r) => r.source} />
      </ChartCard>

      <Footnotes keys={['reports.note.booked', 'reports.note.no_show', 'reports.note.no_show_rate', 'reports.note.transfer', 'reports.note.source']} />
    </Stack>
  );
}

Appointments.layout = (page: ReactNode) => <PanelLayout title="reports.appointments.title">{page}</PanelLayout>;
