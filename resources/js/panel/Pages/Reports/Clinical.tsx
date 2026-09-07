// Top diagnoses and top prescribed drugs (BRIEF §5.L calls this data "genuinely valuable"), filterable by
// period, doctor and specialty, with counts and trends. Diagnoses come from the ICD-10-coded `visits.diagnoses`
// jsonb; drugs come from the prescription-line SNAPSHOTS, so last year's report keeps last year's brand names
// whatever the catalog has done since.
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Grid from '@mui/material/Grid';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import { router } from '@inertiajs/react';
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip as RTooltip, XAxis, YAxis } from 'recharts';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { ChartCard } from '@panel/Components/Reports/ChartCard';
import { DataTable, type Column } from '@panel/Components/Reports/DataTable';
import { ExportMenu } from '@panel/Components/Reports/ExportMenu';
import { FilterBar, query } from '@panel/Components/Reports/FilterBar';
import { Footnotes } from '@panel/Components/Reports/Footnotes';
import { ReportTabs } from '@panel/Components/Reports/ReportTabs';
import { StatCard } from '@panel/Components/Reports/StatCard';
import { useChartTheme } from '@panel/Components/Reports/useChartTheme';
import { pivotTrend } from '@panel/lib/reports/trend';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type {
  ClinicalReport, ReportFilterState, ReportOptionsProps, ReportScopeProps, TopDiagnosisRow, TopDrugRow,
} from '@shared/types/models';

type Props = PageProps<{
  report: 'clinical';
  filters: ReportFilterState;
  scope: ReportScopeProps;
  options: ReportOptionsProps;
  data: ClinicalReport;
  generated_at: string;
  cached: boolean;
}>;

export default function Clinical({ filters, scope, options, data, generated_at, cached }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const chart = useChartTheme();
  const [tab, setTab] = useState<'diagnoses' | 'generics' | 'brands'>('diagnoses');
  const dx = data.diagnoses;
  const drugs = data.drugs;

  const diagnosisColumns: Column<TopDiagnosisRow>[] = [
    { key: 'code', label: t('reports.column.code'), render: (r) => (r.coded ? r.icd10_code : <Chip size="small" variant="outlined" label={t('reports.clinical.uncoded')} />) },
    { key: 'title', label: t('reports.column.diagnosis'), bn: true, render: (r) => r.title },
    { key: 'visits', label: t('reports.column.visits'), align: 'right', render: (r) => formatBn(r.visits, locale) },
    { key: 'patients', label: t('reports.column.patients'), align: 'right', render: (r) => formatBn(r.patients, locale) },
    { key: 'share', label: t('reports.column.share'), align: 'right', render: (r) => (dx.total_visits > 0 ? `${formatBn(Math.round((r.visits / dx.total_visits) * 1000) / 10, locale)}%` : '—') },
  ];

  const drugColumns = (brand: boolean): Column<TopDrugRow>[] => [
    { key: 'name', label: t(brand ? 'reports.column.brand' : 'reports.column.generic'), bn: true, render: (r) => r.name },
    ...(brand ? [{ key: 'generic', label: t('reports.column.generic'), bn: true, render: (r: TopDrugRow) => r.generic_name ?? '—' }] : []),
    { key: 'items', label: t('reports.column.lines'), align: 'right' as const, render: (r: TopDrugRow) => formatBn(r.items, locale) },
    { key: 'prescriptions', label: t('reports.column.prescriptions'), align: 'right' as const, render: (r: TopDrugRow) => formatBn(r.prescriptions, locale) },
    { key: 'patients', label: t('reports.column.patients'), align: 'right' as const, render: (r: TopDrugRow) => formatBn(r.patients, locale) },
    ...(brand ? [{ key: 'custom', label: t('reports.column.source'), render: (r: TopDrugRow) => (r.is_custom ? t('reports.clinical.custom_brand') : t('reports.clinical.catalog_brand')) }] : []),
  ];

  const dxLabels = new Map(dx.rows.map((r) => [r.key, r.title]));
  const drugLabels = new Map(drugs.by_generic.map((r) => [r.key, r.name]));

  const specialtySelect = (
    <TextField
      select size="small" sx={{ minWidth: 180 }} label={t('reports.filter.specialty')} value={filters.specialty ?? ''}
      onChange={(e) => router.get(route('panel.reports.show', { report: 'clinical' }), query(filters, { specialty: e.target.value || null }), { preserveState: true, replace: true })}
    >
      <MenuItem value="">{t('reports.filter.all_specialties')}</MenuItem>
      {options.specialties.map((s) => <MenuItem key={s.slug} value={s.slug} lang="bn">{locale === 'bn' && s.name_bn ? s.name_bn : s.name}</MenuItem>)}
    </TextField>
  );

  return (
    <Stack spacing={2}>
      <ReportTabs current="clinical" scope={scope} filters={filters} />
      <FilterBar
        report="clinical" filters={filters} options={options} scope={scope}
        generatedAt={generated_at} cached={cached} extra={specialtySelect}
        action={scope.can_export ? <ExportMenu report="clinical" query={query(filters)} /> : null}
      />

      <Grid container spacing={1.5}>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.column.visits')} value={formatBn(dx.total_visits, locale)} note={t('reports.note.diagnosis_unit')} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.clinical.distinct_diagnoses')} value={formatBn(dx.rows.length, locale)} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.column.lines')} value={formatBn(drugs.totals.items, locale)} note={t('reports.note.issued_only')} hint={t('reports.clinical.prescriptions', { count: drugs.totals.prescriptions })} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.clinical.generic_only')} value={drugs.totals.generic_only_rate === null ? '—' : `${formatBn(drugs.totals.generic_only_rate, locale)}%`} note={t('reports.note.generic_only')} /></Grid>
      </Grid>

      <ChartCard
        title={t('reports.clinical.diagnosis_trend')}
        subtitle={t(`reports.granularity.${dx.granularity}`)}
        chart={
          <Box sx={{ height: 260 }}>
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={pivotTrend(dx.trend, dx.trend_keys, 'visits')} margin={{ top: 8, right: 8, left: -18, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke={chart.grid} vertical={false} />
                <XAxis dataKey="period" tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} minTickGap={20} />
                <YAxis tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} allowDecimals={false} />
                <RTooltip contentStyle={chart.tooltip} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                {dx.trend_keys.map((key, i) => (
                  <Line key={key} type="monotone" dataKey={key} name={dxLabels.get(key) ?? key} stroke={chart.series[i % chart.series.length]} dot={false} strokeWidth={2} />
                ))}
              </LineChart>
            </ResponsiveContainer>
          </Box>
        }
      />

      <ChartCard
        title={t('reports.clinical.tables')}
        action={
          <Tabs value={tab} onChange={(_, next: 'diagnoses' | 'generics' | 'brands') => setTab(next)} sx={{ minHeight: 36 }}>
            <Tab value="diagnoses" label={t('reports.clinical.diagnoses')} sx={{ minHeight: 36, py: 0 }} />
            <Tab value="generics" label={t('reports.clinical.generics')} sx={{ minHeight: 36, py: 0 }} />
            <Tab value="brands" label={t('reports.clinical.brands')} sx={{ minHeight: 36, py: 0 }} />
          </Tabs>
        }
      >
        {tab === 'diagnoses' ? <DataTable columns={diagnosisColumns} rows={dx.rows} rowKey={(r) => r.key} /> : null}
        {tab === 'generics' ? <DataTable columns={drugColumns(false)} rows={drugs.by_generic} rowKey={(r) => r.key} /> : null}
        {tab === 'brands' ? <DataTable columns={drugColumns(true)} rows={drugs.by_brand} rowKey={(r) => r.key} /> : null}
      </ChartCard>

      <ChartCard
        title={t('reports.clinical.drug_trend')}
        chart={
          <Box sx={{ height: 260 }}>
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={pivotTrend(drugs.trend, drugs.trend_keys, 'items')} margin={{ top: 8, right: 8, left: -18, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke={chart.grid} vertical={false} />
                <XAxis dataKey="period" tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} minTickGap={20} />
                <YAxis tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} allowDecimals={false} />
                <RTooltip contentStyle={chart.tooltip} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                {drugs.trend_keys.map((key, i) => (
                  <Line key={key} type="monotone" dataKey={key} name={drugLabels.get(key) ?? key} stroke={chart.series[i % chart.series.length]} dot={false} strokeWidth={2} />
                ))}
              </LineChart>
            </ResponsiveContainer>
          </Box>
        }
      />

      <Footnotes keys={['reports.note.diagnosis_unit', 'reports.note.uncoded', 'reports.note.issued_only', 'reports.note.snapshot', 'reports.note.generic_only']} />
    </Stack>
  );
}

Clinical.layout = (page: ReactNode) => <PanelLayout title="reports.clinical.title">{page}</PanelLayout>;
