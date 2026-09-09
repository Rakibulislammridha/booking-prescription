// Reports/Dashboard's trend strip. Lazily loaded (Components/Charts/LazyChart.tsx): recharts is ~97 KB gzip and
// the owner's dashboard is mostly tiles and a session table, both of which must paint before this arrives.
import { useTranslation } from 'react-i18next';
import { Area, AreaChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip as RTooltip, XAxis, YAxis } from 'recharts';
import { useChartTheme } from '@panel/Components/Reports/useChartTheme';
import type { ReportVolumeByPeriod } from '@shared/types/models';

export interface TrendAreaChartProps {
  rows: ReportVolumeByPeriod[];
}

export function TrendAreaChart({ rows }: TrendAreaChartProps) {
  const { t } = useTranslation();
  const chart = useChartTheme();

  return (
    <ResponsiveContainer width="100%" height="100%">
      <AreaChart data={rows} margin={{ top: 8, right: 8, left: -18, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke={chart.grid} vertical={false} />
        <XAxis dataKey="period" tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} minTickGap={24} />
        <YAxis tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} allowDecimals={false} />
        <RTooltip contentStyle={chart.tooltip} />
        <Legend wrapperStyle={{ fontSize: 12 }} />
        <Area type="monotone" dataKey="booked" name={t('reports.column.booked')} stroke={chart.series[0]} fill={chart.series[0]} fillOpacity={0.14} />
        <Area type="monotone" dataKey="completed" name={t('reports.column.completed')} stroke={chart.good} fill={chart.good} fillOpacity={0.12} />
        <Area type="monotone" dataKey="no_show" name={t('reports.column.no_show')} stroke={chart.bad} fill={chart.bad} fillOpacity={0.12} />
      </AreaChart>
    </ResponsiveContainer>
  );
}
