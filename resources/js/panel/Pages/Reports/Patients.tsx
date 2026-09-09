// New vs returning patients and the follow-up compliance funnel (BRIEF §5.L). Both metrics are ambiguous until
// somebody writes the definition down, so both carry theirs on screen: "new" is new to the CLINIC, and the
// compliance rate is measured only on follow-ups whose date has already passed.
import { lazy, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Grid from '@mui/material/Grid';
import LinearProgress from '@mui/material/LinearProgress';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
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
import type { PatientMixReport, PatientMixTotals, ReportFilterState, ReportOptionsProps, ReportScopeProps } from '@shared/types/models';

type DoctorRow = PatientMixTotals & { doctor_id: number; doctor_name: string | null };

type Props = PageProps<{
  report: 'patients';
  filters: ReportFilterState;
  scope: ReportScopeProps;
  options: ReportOptionsProps;
  data: PatientMixReport;
  generated_at: string;
  cached: boolean;
}>;

// The funnel and both tables are plain MUI; only the mix chart costs recharts' ~97 KB gzip, and only when the
// range has periods to draw (Components/Charts/LazyChart.tsx).
const MixAreaChart = lazy(() => import('@panel/Components/Charts/PatientMixCharts').then((m) => ({ default: m.MixAreaChart })));

export default function Patients({ filters, scope, options, data, generated_at, cached }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const totals = data.totals;
  const follow = data.follow_up;
  const pct = (value: number | null): string => (value === null ? '—' : `${formatBn(value, locale)}%`);

  const doctorColumns: Column<DoctorRow>[] = [
    { key: 'doctor', label: t('reports.column.doctor'), bn: true, render: (r) => r.doctor_name ?? '—' },
    { key: 'patients', label: t('reports.column.patients'), align: 'right', render: (r) => formatBn(r.patients, locale) },
    { key: 'new', label: t('reports.column.new'), align: 'right', render: (r) => formatBn(r.new, locale) },
    { key: 'returning', label: t('reports.column.returning'), align: 'right', render: (r) => formatBn(r.returning, locale) },
    { key: 'rate', label: t('reports.column.new_rate'), align: 'right', render: (r) => pct(r.new_rate) },
  ];

  const funnel: { key: string; value: number; of: number }[] = [
    { key: 'advised', value: follow.advised, of: follow.advised },
    { key: 'due', value: follow.due, of: follow.advised },
    { key: 'booked', value: follow.booked_due, of: follow.due },
    { key: 'kept', value: follow.kept_due, of: follow.due },
  ];

  return (
    <Stack spacing={2}>
      <ReportTabs current="patients" scope={scope} filters={filters} />
      <FilterBar
        report="patients" filters={filters} options={options} scope={scope}
        generatedAt={generated_at} cached={cached}
        action={scope.can_export ? <ExportMenu report="patients" query={query(filters)} /> : null}
      />

      <Grid container spacing={1.5}>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.column.patients')} value={formatBn(totals.patients, locale)} note={t('reports.note.distinct_patients')} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.column.new')} value={formatBn(totals.new, locale)} hint={pct(totals.new_rate)} note={t('reports.note.new_patient')} tone="success" /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.column.returning')} value={formatBn(totals.returning, locale)} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.patients.compliance')} value={pct(follow.compliance_rate)} note={t('reports.note.follow_up')} hint={t('reports.patients.of_due', { count: follow.due })} /></Grid>
      </Grid>

      <ChartCard
        title={t('reports.patients.mix')}
        subtitle={`${t(`reports.granularity.${data.granularity}`)} · ${t('reports.note.patient_trend')}`}
        chart={
          <LazyChart height={260} empty={data.by_period.length === 0} emptyLabel={t('reports.empty')}>
            <MixAreaChart rows={data.by_period} />
          </LazyChart>
        }
      >
        <DataTable
          columns={[
            { key: 'period', label: t('reports.column.period'), render: (r) => r.period },
            { key: 'patients', label: t('reports.column.patients'), align: 'right', render: (r) => formatBn(r.patients, locale) },
            { key: 'new', label: t('reports.column.new'), align: 'right', render: (r) => formatBn(r.new, locale) },
            { key: 'returning', label: t('reports.column.returning'), align: 'right', render: (r) => formatBn(r.returning, locale) },
            { key: 'rate', label: t('reports.column.new_rate'), align: 'right', render: (r) => pct(r.new_rate) },
          ]}
          rows={data.by_period}
          rowKey={(r) => r.period}
        />
      </ChartCard>

      <ChartCard title={t('reports.patients.follow_up')} subtitle={t('reports.patients.follow_up_hint', { date: follow.as_of })}>
        <Stack spacing={1.5} sx={{ mb: 2 }}>
          {funnel.map((step) => (
            <Stack key={step.key} spacing={0.5}>
              <Stack direction="row" sx={{ justifyContent: 'space-between' }}>
                <Typography variant="body2">{t(`reports.follow_up.${step.key}`)}</Typography>
                <Typography variant="body2" sx={{ fontVariantNumeric: 'tabular-nums' }}>{formatBn(step.value, locale)}</Typography>
              </Stack>
              <LinearProgress variant="determinate" value={step.of > 0 ? Math.min(100, (step.value / step.of) * 100) : 0} sx={{ height: 8, borderRadius: 4 }} />
            </Stack>
          ))}
        </Stack>
        <DataTable
          columns={[
            { key: 'metric', label: t('reports.column.metric'), render: (r: { key: string; value: string }) => r.key },
            { key: 'value', label: t('reports.column.value'), align: 'right', render: (r: { key: string; value: string }) => r.value },
          ]}
          rows={[
            { key: t('reports.follow_up.advised'), value: formatBn(follow.advised, locale) },
            { key: t('reports.follow_up.due'), value: formatBn(follow.due, locale) },
            { key: t('reports.follow_up.booked'), value: formatBn(follow.booked_due, locale) },
            { key: t('reports.follow_up.kept'), value: formatBn(follow.kept_due, locale) },
            { key: t('reports.follow_up.booking_rate'), value: pct(follow.booking_rate) },
            { key: t('reports.follow_up.rate'), value: pct(follow.compliance_rate) },
          ]}
          rowKey={(r) => r.key}
        />
      </ChartCard>

      <ChartCard title={t('reports.patients.by_doctor')} subtitle={t('reports.note.doctor_overlap')}>
        <DataTable columns={doctorColumns} rows={data.by_doctor} rowKey={(r) => String(r.doctor_id)} />
      </ChartCard>

      <Footnotes keys={['reports.note.distinct_patients', 'reports.note.new_patient', 'reports.note.patient_trend', 'reports.note.doctor_overlap', 'reports.note.follow_up', 'reports.note.follow_up_draft']} />
    </Stack>
  );
}

Patients.layout = (page: ReactNode) => <PanelLayout title="reports.patients.title">{page}</PanelLayout>;
