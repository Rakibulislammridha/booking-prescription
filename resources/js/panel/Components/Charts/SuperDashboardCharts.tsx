// The super dashboard's thirty-day strip: sign-ups and appointments per day across the platform. Lazily loaded
// (Components/Charts/LazyChart.tsx) like every other recharts drawing; reads the MUI palette directly, as
// SuperCharts.tsx does, because the console is not a Reports screen.
import { useTranslation } from 'react-i18next';
import { useTheme } from '@mui/material/styles';
import { Area, AreaChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip as RTooltip, XAxis, YAxis } from 'recharts';
import type { TrendDay } from '@panel/Components/Super/types';

export interface PlatformTrendChartProps {
  days: TrendDay[];
}

export function PlatformTrendChart({ days }: PlatformTrendChartProps) {
  const { t } = useTranslation();
  const theme = useTheme();
  const rows = days.map((d) => ({ ...d, label: d.day.slice(5) }));
  const tick = { fill: theme.palette.text.secondary, fontSize: 11 };

  return (
    <ResponsiveContainer width="100%" height="100%">
      <AreaChart data={rows} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
        <CartesianGrid stroke={theme.palette.divider} strokeDasharray="3 3" vertical={false} />
        <XAxis dataKey="label" tick={tick} minTickGap={24} />
        <YAxis yAxisId="appointments" tick={tick} width={48} allowDecimals={false} />
        <YAxis yAxisId="signups" orientation="right" tick={tick} width={36} allowDecimals={false} />
        <RTooltip
          contentStyle={{
            backgroundColor: theme.palette.background.paper,
            border: `1px solid ${theme.palette.divider}`,
            borderRadius: 6,
            color: theme.palette.text.primary,
            fontSize: 12,
          }}
          labelFormatter={(label, payload) => {
            const first = Array.isArray(payload) && payload.length > 0 ? (payload[0]?.payload as TrendDay | undefined) : undefined;
            return first?.day ?? String(label);
          }}
        />
        <Legend wrapperStyle={{ fontSize: 12 }} />
        <Area
          yAxisId="appointments" type="monotone" dataKey="appointments" name={t('super.dashboard.trend.appointments')}
          stroke={theme.palette.primary.main} fill={theme.palette.primary.main} fillOpacity={0.14} strokeWidth={2}
        />
        <Area
          yAxisId="signups" type="step" dataKey="signups" name={t('super.dashboard.trend.signups')}
          stroke={theme.palette.success.main} fill={theme.palette.success.main} fillOpacity={0.12} strokeWidth={2}
        />
      </AreaChart>
    </ResponsiveContainer>
  );
}
