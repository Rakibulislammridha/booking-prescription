// Reports/Revenue's two drawings: net collected per day, and the payment-method share. Money is formatted here
// exactly as it was in the page — paisa in, BDT on the axis and in the tooltip.
import { useTranslation } from 'react-i18next';
import { Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip as RTooltip, XAxis, YAxis } from 'recharts';
import { useChartTheme } from '@panel/Components/Reports/useChartTheme';
import { formatBdt } from '@shared/format/money';
import type { BillingCollectionBucket, BillingCollectionReport } from '@shared/types/models';
import type { Locale } from '@shared/types/shared-props';

export interface ByDayChartProps {
  rows: BillingCollectionReport['by_day'];
  locale: Locale;
}

export function ByDayChart({ rows, locale }: ByDayChartProps) {
  const { t } = useTranslation();
  const chart = useChartTheme();

  return (
    <ResponsiveContainer width="100%" height="100%">
      <BarChart data={rows.map((d) => ({ ...d, net: d.net_paisa / 100 }))} margin={{ top: 8, right: 8, left: -8, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke={chart.grid} vertical={false} />
        <XAxis dataKey="date" tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} minTickGap={20} />
        <YAxis tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} />
        <RTooltip contentStyle={chart.tooltip} formatter={(value) => formatBdt(Math.round(Number(value ?? 0) * 100), locale)} />
        <Legend wrapperStyle={{ fontSize: 12 }} />
        <Bar dataKey="net" name={t('reports.column.net')} fill={chart.series[0]} />
      </BarChart>
    </ResponsiveContainer>
  );
}

export type MethodSlice = BillingCollectionBucket & { label: string };

export interface ByMethodChartProps {
  slices: MethodSlice[];
  locale: Locale;
}

export function ByMethodChart({ slices, locale }: ByMethodChartProps) {
  const chart = useChartTheme();

  return (
    <ResponsiveContainer width="100%" height="100%">
      <PieChart>
        <RTooltip contentStyle={chart.tooltip} formatter={(value) => formatBdt(Math.round(Number(value ?? 0)), locale)} />
        <Legend wrapperStyle={{ fontSize: 12 }} />
        {/* The slice label comes from `nameKey`, so the translated method name is a field. */}
        <Pie data={slices} dataKey="net_paisa" nameKey="label" innerRadius={45} outerRadius={80} paddingAngle={2}>
          {slices.map((row, i) => <Cell key={row.key ?? String(i)} fill={chart.series[i % chart.series.length]} />)}
        </Pie>
      </PieChart>
    </ResponsiveContainer>
  );
}
