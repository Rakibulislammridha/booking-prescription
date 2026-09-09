// The two super-admin drawings: the platform-wide usage series (Super/Usage) and one tenant's appointment
// history (Super/Tenants/Show). They read the MUI palette directly, exactly as the pages did, because neither
// belongs to the Reports module's chart theme. Lazily loaded (Components/Charts/LazyChart.tsx).
import { useTheme } from '@mui/material/styles';
import { Bar, BarChart, CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip as RTooltip, XAxis, YAxis } from 'recharts';
import type { UsagePoint, UsageSeriesPoint } from '@panel/Components/Super/types';

export interface UsageSeriesChartProps {
  series: UsageSeriesPoint[];
  /** The metric's own label, already resolved from the server's `metric_labels`. */
  label: string;
}

export function UsageSeriesChart({ series, label }: UsageSeriesChartProps) {
  const theme = useTheme();

  return (
    <ResponsiveContainer width="100%" height="100%">
      <BarChart data={series} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
        <CartesianGrid stroke={theme.palette.divider} strokeDasharray="3 3" />
        <XAxis dataKey="period" tick={{ fill: theme.palette.text.secondary, fontSize: 11 }} />
        <YAxis tick={{ fill: theme.palette.text.secondary, fontSize: 11 }} width={56} allowDecimals={false} />
        <RTooltip
          contentStyle={{
            backgroundColor: theme.palette.background.paper,
            border: `1px solid ${theme.palette.divider}`,
            borderRadius: 6,
            color: theme.palette.text.primary,
            fontSize: 12,
          }}
        />
        <Bar dataKey="total" name={label} fill={theme.palette.primary.main} radius={[3, 3, 0, 0]} />
      </BarChart>
    </ResponsiveContainer>
  );
}

export interface TenantHistoryChartProps {
  points: UsagePoint[];
  label: string;
}

export function TenantHistoryChart({ points, label }: TenantHistoryChartProps) {
  const theme = useTheme();

  return (
    <ResponsiveContainer width="100%" height="100%">
      <LineChart data={points} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
        <CartesianGrid stroke={theme.palette.divider} strokeDasharray="3 3" />
        <XAxis dataKey="period" tick={{ fill: theme.palette.text.secondary, fontSize: 11 }} />
        <YAxis tick={{ fill: theme.palette.text.secondary, fontSize: 11 }} width={46} allowDecimals={false} />
        <RTooltip
          contentStyle={{
            backgroundColor: theme.palette.background.paper,
            border: `1px solid ${theme.palette.divider}`,
            borderRadius: 6,
            color: theme.palette.text.primary,
            fontSize: 12,
          }}
        />
        <Line type="monotone" dataKey="value" name={label} stroke={theme.palette.primary.main} strokeWidth={2} dot={false} />
      </LineChart>
    </ResponsiveContainer>
  );
}
