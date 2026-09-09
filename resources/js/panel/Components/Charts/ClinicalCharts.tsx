// Reports/Clinical's two trend lines (top diagnoses, top drugs) — the same component twice, because the two
// cards differ only in their rows and labels. The long-form → wide pivot stays in @panel/lib/reports/trend so
// the page can decide whether there is anything to draw WITHOUT loading recharts.
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip as RTooltip, XAxis, YAxis } from 'recharts';
import { useChartTheme } from '@panel/Components/Reports/useChartTheme';
import type { TrendRow } from '@panel/lib/reports/trend';

export interface TrendLineChartProps {
  rows: TrendRow[];
  /** One line per series key, in the server's order. */
  seriesKeys: string[];
  /** Series key → the human label (a diagnosis title, a generic name); falls back to the key. */
  labels: Map<string, string>;
}

export function TrendLineChart({ rows, seriesKeys, labels }: TrendLineChartProps) {
  const chart = useChartTheme();

  return (
    <ResponsiveContainer width="100%" height="100%">
      <LineChart data={rows} margin={{ top: 8, right: 8, left: -18, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke={chart.grid} vertical={false} />
        <XAxis dataKey="period" tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} minTickGap={20} />
        <YAxis tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} allowDecimals={false} />
        <RTooltip contentStyle={chart.tooltip} />
        <Legend wrapperStyle={{ fontSize: 12 }} />
        {seriesKeys.map((key, i) => (
          <Line key={key} type="monotone" dataKey={key} name={labels.get(key) ?? key} stroke={chart.series[i % chart.series.length]} dot={false} strokeWidth={2} />
        ))}
      </LineChart>
    </ResponsiveContainer>
  );
}
