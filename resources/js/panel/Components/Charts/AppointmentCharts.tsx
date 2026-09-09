// Reports/Appointments' three drawings: volume by period, the same split by doctor, and the booking-channel
// share. Lazily loaded through Components/Charts/LazyChart.tsx — one chunk, fetched once for all three.
import { useTranslation } from 'react-i18next';
import { Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip as RTooltip, XAxis, YAxis } from 'recharts';
import { useChartTheme } from '@panel/Components/Reports/useChartTheme';
import type { ReportVolumeByDoctor, ReportVolumeByGroup, ReportVolumeByPeriod } from '@shared/types/models';

export interface ByPeriodChartProps { rows: ReportVolumeByPeriod[] }

export function ByPeriodChart({ rows }: ByPeriodChartProps) {
  const { t } = useTranslation();
  const chart = useChartTheme();

  return (
    <ResponsiveContainer width="100%" height="100%">
      <BarChart data={rows} margin={{ top: 8, right: 8, left: -18, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke={chart.grid} vertical={false} />
        <XAxis dataKey="period" tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} minTickGap={20} />
        <YAxis tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} allowDecimals={false} />
        <RTooltip contentStyle={chart.tooltip} />
        <Legend wrapperStyle={{ fontSize: 12 }} />
        <Bar dataKey="completed" name={t('reports.column.completed')} stackId="a" fill={chart.good} />
        <Bar dataKey="no_show" name={t('reports.column.no_show')} stackId="a" fill={chart.bad} />
        <Bar dataKey="cancelled" name={t('reports.column.cancelled')} stackId="a" fill={chart.series[3]} />
        <Bar dataKey="open" name={t('reports.column.open')} stackId="a" fill={chart.series[1]} />
      </BarChart>
    </ResponsiveContainer>
  );
}

export interface ByDoctorChartProps { rows: ReportVolumeByDoctor[] }

export function ByDoctorChart({ rows }: ByDoctorChartProps) {
  const { t } = useTranslation();
  const chart = useChartTheme();

  return (
    <ResponsiveContainer width="100%" height="100%">
      <BarChart layout="vertical" data={rows} margin={{ top: 4, right: 16, left: 8, bottom: 4 }}>
        <CartesianGrid strokeDasharray="3 3" stroke={chart.grid} horizontal={false} />
        <XAxis type="number" tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} allowDecimals={false} />
        <YAxis type="category" dataKey="doctor_name" width={150} tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} />
        <RTooltip contentStyle={chart.tooltip} />
        <Legend wrapperStyle={{ fontSize: 12 }} />
        <Bar dataKey="completed" name={t('reports.column.completed')} stackId="a" fill={chart.good} />
        <Bar dataKey="no_show" name={t('reports.column.no_show')} stackId="a" fill={chart.bad} />
      </BarChart>
    </ResponsiveContainer>
  );
}

export type ChannelSlice = ReportVolumeByGroup & { label: string };

export interface BySourceChartProps { slices: ChannelSlice[] }

export function BySourceChart({ slices }: BySourceChartProps) {
  const chart = useChartTheme();

  return (
    <ResponsiveContainer width="100%" height="100%">
      <PieChart>
        <RTooltip contentStyle={chart.tooltip} />
        <Legend wrapperStyle={{ fontSize: 12 }} />
        {/* recharts reads the slice label from `nameKey`, not from a Cell's `name`, so the translated
            label has to be a field on the datum. */}
        <Pie data={slices} dataKey="booked" nameKey="label" innerRadius={45} outerRadius={80} paddingAngle={2}>
          {slices.map((row, i) => <Cell key={row.channel_group} fill={chart.series[i % chart.series.length]} />)}
        </Pie>
      </PieChart>
    </ResponsiveContainer>
  );
}
