// Reports/Patients' new-vs-returning stack. Lazily loaded (Components/Charts/LazyChart.tsx).
import { useTranslation } from 'react-i18next';
import { Area, AreaChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip as RTooltip, XAxis, YAxis } from 'recharts';
import { useChartTheme } from '@panel/Components/Reports/useChartTheme';
import type { PatientMixTotals } from '@shared/types/models';

export interface MixAreaChartProps {
  rows: (PatientMixTotals & { period: string })[];
}

export function MixAreaChart({ rows }: MixAreaChartProps) {
  const { t } = useTranslation();
  const chart = useChartTheme();

  return (
    <ResponsiveContainer width="100%" height="100%">
      <AreaChart data={rows} margin={{ top: 8, right: 8, left: -18, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke={chart.grid} vertical={false} />
        <XAxis dataKey="period" tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} minTickGap={20} />
        <YAxis tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} allowDecimals={false} />
        <RTooltip contentStyle={chart.tooltip} />
        <Legend wrapperStyle={{ fontSize: 12 }} />
        <Area type="monotone" dataKey="new" name={t('reports.column.new')} stackId="p" stroke={chart.series[0]} fill={chart.series[0]} fillOpacity={0.2} />
        <Area type="monotone" dataKey="returning" name={t('reports.column.returning')} stackId="p" stroke={chart.series[1]} fill={chart.series[1]} fillOpacity={0.2} />
      </AreaChart>
    </ResponsiveContainer>
  );
}
