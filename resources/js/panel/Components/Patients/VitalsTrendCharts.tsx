// BRIEF §5.H asks the mini-EMR for vitals TREND CHARTS, not a list of the last few readings. One chart per
// measurement, drawn only from the readings that actually carry it, on a real time axis (`scale="time"`) so two
// readings a year apart are not drawn next to each other — a categorical axis would make an old reading look
// recent — and with straight segments, because a smoothed curve invents a shape between two readings weeks apart. Honest about thin data: a metric with no reading says so, and a single reading is shown as a value with
// the date rather than as a "trend" of one point. The numbers themselves are in the table underneath, because a
// chart nobody can check against the figures is decoration. Temperature is charted and tabled in °F — the rows
// carry the stored °C, `@shared/format/temperature` converts once when the series is built.
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Typography from '@mui/material/Typography';
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip as RTooltip, XAxis, YAxis } from 'recharts';
import { ChartCard } from '@panel/Components/Reports/ChartCard';
import { useChartTheme } from '@panel/Components/Reports/useChartTheme';
import { formatBn } from '@shared/format/number';
import { cToF, formatTemperatureF, temperatureUnit } from '@shared/format/temperature';
import { formatDateDhaka, formatDhaka } from '@shared/format/date';
import type { Locale } from '@shared/types/shared-props';
import type { VitalsTrendPoint } from '@shared/types/models';

type MetricKey = 'bp' | 'pulse_bpm' | 'temperature_f' | 'spo2_percent' | 'weight_kg';

interface Row { at: number; recorded_at: string; a: number | null; b: number | null }

interface MetricSpec {
  key: MetricKey;
  titleKey: string;
  /** the unit as a person reads it — a symbol with a Bangla form (°ফা) follows the locale */
  unit: (locale: Locale) => string;
  /** the second series exists only for blood pressure (systolic over diastolic) */
  pair: boolean;
  domain?: [number | 'auto', number | 'auto'];
  read: (p: VitalsTrendPoint) => [number | null, number | null];
}

const METRICS: readonly MetricSpec[] = [
  { key: 'bp', titleKey: 'patients.vitals.bp', unit: () => 'mmHg', pair: true, read: (p) => [p.bp_systolic, p.bp_diastolic] },
  { key: 'pulse_bpm', titleKey: 'patients.vitals.pulse', unit: () => 'bpm', pair: false, read: (p) => [p.pulse_bpm, null] },
  { key: 'weight_kg', titleKey: 'patients.vitals.weight', unit: () => 'kg', pair: false, read: (p) => [p.weight_kg, null] },
  { key: 'temperature_f', titleKey: 'patients.vitals.temperature', unit: temperatureUnit, pair: false, domain: ['auto', 'auto'], read: (p) => [p.temperature_c === null ? null : cToF(p.temperature_c), null] },
  { key: 'spo2_percent', titleKey: 'patients.vitals.spo2', unit: () => '%', pair: false, domain: [85, 100], read: (p) => [p.spo2_percent, null] },
];

export interface VitalsTrendChartsProps {
  points: VitalsTrendPoint[];
  locale: Locale;
}

export function VitalsTrendCharts({ points, locale }: VitalsTrendChartsProps) {
  const { t } = useTranslation();
  const chart = useChartTheme();

  // Oldest first (the query already returns that order); a bad timestamp must not silently reorder the series.
  const ordered = useMemo(
    () => [...points].filter((p) => !Number.isNaN(Date.parse(p.recorded_at))).sort((x, y) => Date.parse(x.recorded_at) - Date.parse(y.recorded_at)),
    [points],
  );

  const series = useMemo(() => {
    const built = new Map<MetricKey, Row[]>();
    for (const metric of METRICS) {
      const rows: Row[] = [];
      for (const point of ordered) {
        const [a, b] = metric.read(point);
        if (a === null && b === null) continue;
        rows.push({ at: Date.parse(point.recorded_at), recorded_at: point.recorded_at, a, b });
      }
      built.set(metric.key, rows);
    }
    return built;
  }, [ordered]);

  if (ordered.length === 0) {
    return <Typography variant="body2" color="text.secondary">{t('patients.vitals.none')}</Typography>;
  }

  const tickDate = (value: number): string => formatBn(formatDateDhaka(new Date(value), locale), locale);
  const tooltipLabel = (value: unknown): string => {
    const at = Number(value);
    return Number.isFinite(at) ? formatBn(formatDhaka(new Date(at), 'D MMM YYYY, h:mm a', locale), locale) : '';
  };

  return (
    <Stack spacing={2}>
      <Typography variant="body2" color="text.secondary">
        {t('patients.vitals.window', {
          count: formatBn(ordered.length, locale),
          from: formatBn(formatDateDhaka(ordered[0]?.recorded_at ?? '', locale), locale),
          to: formatBn(formatDateDhaka(ordered[ordered.length - 1]?.recorded_at ?? '', locale), locale),
        })}
      </Typography>

      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: 'repeat(2, minmax(0, 1fr))' } }}>
        {METRICS.map((metric) => {
          const rows = series.get(metric.key) ?? [];
          const last = rows[rows.length - 1];
          const unit = metric.unit(locale);

          return (
            <ChartCard
              key={metric.key}
              title={t(metric.titleKey)}
              subtitle={rows.length === 0 ? undefined : t('patients.vitals.readings', { count: formatBn(rows.length, locale) })}
              action={last === undefined ? undefined : (
                <Chip
                  size="small"
                  label={metric.pair
                    ? `${formatBn(`${last.a ?? '—'}/${last.b ?? '—'}`, locale)} ${unit}`
                    : `${formatBn(last.a ?? 0, locale)} ${unit}`}
                />
              )}
            >
              {rows.length === 0 ? (
                <Typography variant="body2" color="text.secondary">{t('patients.vitals.no_metric')}</Typography>
              ) : rows.length === 1 ? (
                <Stack spacing={0.25}>
                  <Typography variant="h5">
                    {metric.pair ? formatBn(`${last?.a ?? '—'}/${last?.b ?? '—'}`, locale) : formatBn(last?.a ?? 0, locale)}
                    <Typography component="span" variant="body2" color="text.secondary"> {unit}</Typography>
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {formatBn(formatDateDhaka(rows[0]?.recorded_at ?? '', locale), locale)} · {t('patients.vitals.single_reading')}
                  </Typography>
                </Stack>
              ) : (
                <Box sx={{ height: 168 }} data-testid={`vitals-chart-${metric.key}`}>
                  <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={rows} margin={{ top: 14, right: 12, left: 0, bottom: 0 }}>
                      <CartesianGrid strokeDasharray="3 3" stroke={chart.grid} vertical={false} />
                      <XAxis dataKey="at" type="number" scale="time" domain={['dataMin', 'dataMax']} tickFormatter={tickDate} tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} minTickGap={24} />
                      <YAxis domain={metric.domain ?? ['auto', 'auto']} tickFormatter={(v: number) => formatBn(v, locale)} tick={{ fontSize: 11, fill: chart.axis }} stroke={chart.grid} width={56} />
                      <RTooltip contentStyle={chart.tooltip} labelFormatter={tooltipLabel} formatter={(value) => `${formatBn(typeof value === 'number' ? value : String(value ?? ''), locale)} ${unit}`} />
                      {metric.pair ? <Legend wrapperStyle={{ fontSize: 12 }} /> : null}
                      <Line type="linear" dataKey="a" name={metric.pair ? t('patients.vitals.systolic') : t(metric.titleKey)} stroke={chart.series[0]} strokeWidth={2} dot={{ r: 2 }} connectNulls={false} isAnimationActive={false} />
                      {metric.pair ? <Line type="linear" dataKey="b" name={t('patients.vitals.diastolic')} stroke={chart.series[1]} strokeWidth={2} dot={{ r: 2 }} connectNulls={false} isAnimationActive={false} /> : null}
                    </LineChart>
                  </ResponsiveContainer>
                </Box>
              )}
            </ChartCard>
          );
        })}
      </Box>

      <Box sx={{ overflowX: 'auto' }}>
        <Table size="small" aria-label={t('patients.show.vitals_trend')}>
          <TableHead>
            <TableRow>
              <TableCell>{t('patients.vitals.recorded_at')}</TableCell>
              <TableCell align="right">{t('patients.vitals.bp')}</TableCell>
              <TableCell align="right">{t('patients.vitals.pulse')}</TableCell>
              <TableCell align="right">{t('patients.vitals.weight')}</TableCell>
              <TableCell align="right">{t('patients.vitals.temperature')} ({temperatureUnit(locale)})</TableCell>
              <TableCell align="right">{t('patients.vitals.spo2')}</TableCell>
              <TableCell align="right">BMI</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {[...ordered].reverse().map((p) => (
              <TableRow key={p.id} hover>
                <TableCell sx={{ whiteSpace: 'nowrap' }}>{formatBn(formatDhaka(p.recorded_at, 'D MMM YYYY, h:mm a', locale), locale)}</TableCell>
                <TableCell align="right">{p.bp_systolic !== null && p.bp_diastolic !== null ? formatBn(`${p.bp_systolic}/${p.bp_diastolic}`, locale) : '—'}</TableCell>
                <TableCell align="right">{p.pulse_bpm !== null ? formatBn(p.pulse_bpm, locale) : '—'}</TableCell>
                <TableCell align="right">{p.weight_kg !== null ? formatBn(p.weight_kg, locale) : '—'}</TableCell>
                <TableCell align="right">{p.temperature_c !== null ? formatTemperatureF(p.temperature_c, locale) : '—'}</TableCell>
                <TableCell align="right">{p.spo2_percent !== null ? formatBn(p.spo2_percent, locale) : '—'}</TableCell>
                <TableCell align="right">{p.bmi !== null ? formatBn(p.bmi, locale) : '—'}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Box>
    </Stack>
  );
}

export default VitalsTrendCharts;
