// Revenue by doctor, branch and payment method (BRIEF §5.L). Every figure comes from Billing's own query
// objects — the same ones the billing screen reads — so the owner's dashboard and the accountant's screen can
// never quote different money for the same day. The deeper collection screen is linked, not rebuilt.
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Grid from '@mui/material/Grid';
import Stack from '@mui/material/Stack';
import OpenInNewIcon from '@mui/icons-material/OpenInNew';
import { Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip as RTooltip, XAxis, YAxis } from 'recharts';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { ChartCard } from '@panel/Components/Reports/ChartCard';
import { DataTable, type Column } from '@panel/Components/Reports/DataTable';
import { ExportMenu } from '@panel/Components/Reports/ExportMenu';
import { FilterBar, query } from '@panel/Components/Reports/FilterBar';
import { Footnotes } from '@panel/Components/Reports/Footnotes';
import { ReportTabs } from '@panel/Components/Reports/ReportTabs';
import { StatCard } from '@panel/Components/Reports/StatCard';
import { useChartTheme } from '@panel/Components/Reports/useChartTheme';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { formatBdt } from '@shared/format/money';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type {
  BillingCollectionBucket, BillingCommissionRow, ReportFilterState, ReportOptionsProps, ReportScopeProps, RevenueReport,
} from '@shared/types/models';

type Props = PageProps<{
  report: 'revenue';
  filters: ReportFilterState;
  scope: ReportScopeProps;
  options: ReportOptionsProps;
  data: RevenueReport;
  generated_at: string;
  cached: boolean;
}>;

export default function Revenue({ filters, scope, options, data, generated_at, cached }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const chart = useChartTheme();
  const collection = data.collection;
  const commission = data.commission;
  const methodSlices = collection.by_method.map((row) => ({ ...row, label: t(`billing.method.${row.key}`, { defaultValue: row.key ?? '—' }) }));

  const groupColumns = (labelHeader: string, translate?: (key: string) => string): Column<BillingCollectionBucket>[] => [
    { key: 'label', label: labelHeader, bn: true, render: (r) => (translate ? translate(r.key ?? '') : (r.label ?? r.key ?? '—')) },
    { key: 'gross', label: t('reports.column.gross'), align: 'right', render: (r) => formatBdt(r.gross_paisa, locale) },
    { key: 'refunds', label: t('reports.column.refunds'), align: 'right', render: (r) => formatBdt(r.refunds_paisa, locale) },
    { key: 'net', label: t('reports.column.net'), align: 'right', render: (r) => formatBdt(r.net_paisa, locale) },
    { key: 'count', label: t('reports.column.payments'), align: 'right', render: (r) => formatBn(r.count, locale) },
  ];

  const commissionColumns: Column<BillingCommissionRow>[] = [
    { key: 'doctor', label: t('reports.column.doctor'), bn: true, render: (r) => r.doctor_name ?? '—' },
    { key: 'branch', label: t('reports.filter.branch'), bn: true, render: (r) => r.branch_name ?? '—' },
    { key: 'type', label: t('reports.column.item_type'), render: (r) => t(`billing.item_type.${r.item_type}`, { defaultValue: r.item_type }) },
    { key: 'billed', label: t('reports.column.billed'), align: 'right', render: (r) => formatBdt(r.billed_paisa, locale) },
    { key: 'doctor_share', label: t('reports.column.doctor_share'), align: 'right', render: (r) => formatBdt(r.doctor_share_paisa, locale) },
    { key: 'clinic_share', label: t('reports.column.clinic_share'), align: 'right', render: (r) => formatBdt(r.clinic_share_paisa, locale) },
    { key: 'collected', label: t('reports.column.collected'), align: 'right', render: (r) => formatBdt(r.collected_paisa, locale) },
  ];

  return (
    <Stack spacing={2}>
      <ReportTabs current="revenue" scope={scope} filters={filters} />
      <FilterBar
        report="revenue" filters={filters} options={options} scope={scope}
        generatedAt={generated_at} cached={cached}
        action={
          <Stack direction="row" spacing={1}>
            {hasRoute('panel.billing.reports.index') ? (
              <Button size="small" startIcon={<OpenInNewIcon />} component={RouterLink} href={route('panel.billing.reports.index', { from: filters.from, to: filters.to })}>
                {t('reports.revenue.open_billing')}
              </Button>
            ) : null}
            {scope.can_export ? <ExportMenu report="revenue" query={query(filters)} /> : null}
          </Stack>
        }
      />

      <Grid container spacing={1.5}>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.column.gross')} value={formatBdt(collection.totals.gross_paisa, locale)} note={t('reports.note.collection')} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.column.refunds')} value={formatBdt(collection.totals.refunds_paisa, locale)} note={t('reports.note.refund_day')} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.column.net')} value={formatBdt(collection.totals.net_paisa, locale)} tone="success" /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label={t('reports.column.doctor_share')} value={formatBdt(commission.totals.doctor_share_paisa, locale)} note={t('reports.note.commission_frozen')} /></Grid>
      </Grid>

      <ChartCard
        title={t('reports.revenue.by_day')}
        chart={
          <Box sx={{ height: 260 }}>
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={collection.by_day.map((d) => ({ ...d, net: d.net_paisa / 100 }))} margin={{ top: 8, right: 8, left: -8, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke={chart.grid} vertical={false} />
                <XAxis dataKey="date" tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} minTickGap={20} />
                <YAxis tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} />
                <RTooltip contentStyle={chart.tooltip} formatter={(value) => formatBdt(Math.round(Number(value ?? 0) * 100), locale)} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                <Bar dataKey="net" name={t('reports.column.net')} fill={chart.series[0]} />
              </BarChart>
            </ResponsiveContainer>
          </Box>
        }
      >
        <DataTable
          columns={[
            { key: 'date', label: t('reports.column.date'), render: (r) => r.date },
            { key: 'gross', label: t('reports.column.gross'), align: 'right', render: (r) => formatBdt(r.gross_paisa, locale) },
            { key: 'refunds', label: t('reports.column.refunds'), align: 'right', render: (r) => formatBdt(r.refunds_paisa, locale) },
            { key: 'net', label: t('reports.column.net'), align: 'right', render: (r) => formatBdt(r.net_paisa, locale) },
            { key: 'count', label: t('reports.column.payments'), align: 'right', render: (r) => formatBn(r.count, locale) },
          ]}
          rows={collection.by_day}
          rowKey={(r) => r.date}
        />
      </ChartCard>

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, md: 6 }}>
          <ChartCard
            title={t('reports.revenue.by_method')}
            chart={
              <Box sx={{ height: 220 }}>
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <RTooltip contentStyle={chart.tooltip} formatter={(value) => formatBdt(Math.round(Number(value ?? 0)), locale)} />
                    <Legend wrapperStyle={{ fontSize: 12 }} />
                    {/* The slice label comes from `nameKey`, so the translated method name is a field. */}
                    <Pie data={methodSlices} dataKey="net_paisa" nameKey="label" innerRadius={45} outerRadius={80} paddingAngle={2}>
                      {methodSlices.map((row, i) => <Cell key={row.key ?? String(i)} fill={chart.series[i % chart.series.length]} />)}
                    </Pie>
                  </PieChart>
                </ResponsiveContainer>
              </Box>
            }
          >
            <DataTable columns={groupColumns(t('reports.column.method'), (k) => t(`billing.method.${k}`, { defaultValue: k }))} rows={collection.by_method} rowKey={(r, i) => r.key ?? String(i)} />
          </ChartCard>
        </Grid>
        <Grid size={{ xs: 12, md: 6 }}>
          <ChartCard title={t('reports.revenue.by_doctor')}>
            <DataTable columns={groupColumns(t('reports.column.doctor'))} rows={collection.by_doctor} rowKey={(r, i) => r.key ?? String(i)} />
          </ChartCard>
        </Grid>
      </Grid>

      <ChartCard title={t('reports.revenue.by_branch')}>
        <DataTable columns={groupColumns(t('reports.filter.branch'))} rows={collection.by_branch} rowKey={(r, i) => r.key ?? String(i)} />
      </ChartCard>

      <ChartCard title={t('reports.revenue.commission')} subtitle={t('reports.revenue.commission_hint')}>
        <DataTable columns={commissionColumns} rows={commission.rows} rowKey={(r, i) => `${r.doctor_id ?? 0}-${r.branch_id ?? 0}-${r.item_type}-${i}`} />
      </ChartCard>

      <Footnotes keys={['reports.note.collection', 'reports.note.refund_day', 'reports.note.commission_frozen', 'reports.note.collected_ratio']} />
    </Stack>
  );
}

Revenue.layout = (page: ReactNode) => <PanelLayout title="reports.revenue.title">{page}</PanelLayout>;
