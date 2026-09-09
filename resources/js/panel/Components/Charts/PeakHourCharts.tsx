// Reports/PeakHours' hour-of-day bars. The heatmap above it is plain MUI and stays in the page chunk — only the
// recharts drawing is lazy (Components/Charts/LazyChart.tsx).
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip as RTooltip, XAxis, YAxis } from 'recharts';
import { useChartTheme } from '@panel/Components/Reports/useChartTheme';

export interface ByHourChartProps {
  rows: { hour: string; count: number }[];
  /** Already translated: `reports.metric.<arrivals|bookings|consultations>`. */
  metricLabel: string;
}

export function ByHourChart({ rows, metricLabel }: ByHourChartProps) {
  const chart = useChartTheme();

  return (
    <ResponsiveContainer width="100%" height="100%">
      <BarChart data={rows} margin={{ top: 8, right: 8, left: -18, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke={chart.grid} vertical={false} />
        <XAxis dataKey="hour" tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} interval={1} />
        <YAxis tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} allowDecimals={false} />
        <RTooltip contentStyle={chart.tooltip} />
        <Bar dataKey="count" name={metricLabel} fill={chart.series[0]} />
      </BarChart>
    </ResponsiveContainer>
  );
}
