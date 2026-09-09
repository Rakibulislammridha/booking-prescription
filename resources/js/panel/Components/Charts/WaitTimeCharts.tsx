// Reports/WaitTimes' trend: mean wait, p90 wait and mean consultation on one axis, in minutes.
import { useTranslation } from 'react-i18next';
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip as RTooltip, XAxis, YAxis } from 'recharts';
import { useChartTheme } from '@panel/Components/Reports/useChartTheme';
import type { ReportWaitStats } from '@shared/types/models';

export interface WaitTrendChartProps {
  rows: (ReportWaitStats & { period: string })[];
}

export function WaitTrendChart({ rows }: WaitTrendChartProps) {
  const { t } = useTranslation();
  const chart = useChartTheme();

  return (
    <ResponsiveContainer width="100%" height="100%">
      <LineChart data={rows} margin={{ top: 8, right: 8, left: -18, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke={chart.grid} vertical={false} />
        <XAxis dataKey="period" tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} minTickGap={20} />
        <YAxis tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} unit={t('reports.unit.min_short')} />
        <RTooltip contentStyle={chart.tooltip} />
        <Legend wrapperStyle={{ fontSize: 12 }} />
        <Line type="monotone" dataKey="wait_avg_minutes" name={t('reports.column.wait_avg')} stroke={chart.series[0]} dot={false} strokeWidth={2} connectNulls />
        <Line type="monotone" dataKey="wait_p90_minutes" name={t('reports.column.wait_p90')} stroke={chart.warn} dot={false} strokeDasharray="4 3" connectNulls />
        <Line type="monotone" dataKey="consult_avg_minutes" name={t('reports.column.consult_avg')} stroke={chart.series[4]} dot={false} strokeWidth={2} connectNulls />
      </LineChart>
    </ResponsiveContainer>
  );
}
