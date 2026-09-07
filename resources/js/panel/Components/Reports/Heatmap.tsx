// The peak-hour heatmap (BRIEF §5.L, "for staffing decisions"). A 7 × 24 grid of weekday × clinic-local hour;
// the colour is a single-hue lightness ramp, so it stays readable for a colour-blind reader and in either
// theme. The cells carry a title AND a screen-reader label, because a heatmap that only speaks in colour is
// not a report for everyone.
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Stack from '@mui/material/Stack';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { useChartTheme } from './useChartTheme';

export interface HeatmapProps {
  grid: number[][];
  max: number;
  /** Hours outside the clinic's opening window are dropped so the grid is not 40 % empty. */
  fromHour?: number;
  toHour?: number;
}

export function Heatmap({ grid, max, fromHour, toHour }: HeatmapProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const chart = useChartTheme();

  // Show only the hours that ever carry a patient, padded by one on each side, with a sane default.
  const busy = grid.flatMap((row) => row.map((value, hour) => (value > 0 ? hour : -1))).filter((h) => h >= 0);
  const start = fromHour ?? (busy.length > 0 ? Math.max(0, Math.min(...busy) - 1) : 7);
  const end = toHour ?? (busy.length > 0 ? Math.min(23, Math.max(...busy) + 1) : 21);
  const hours = Array.from({ length: Math.max(1, end - start + 1) }, (_, i) => start + i);

  return (
    <Box sx={{ overflowX: 'auto' }}>
      <Box sx={{ display: 'grid', gridTemplateColumns: `minmax(64px, auto) repeat(${hours.length}, minmax(26px, 1fr))`, gap: '2px', minWidth: hours.length * 28 + 64 }}>
        <Box />
        {hours.map((hour) => (
          <Typography key={`h${hour}`} variant="caption" color="text.secondary" sx={{ textAlign: 'center', fontVariantNumeric: 'tabular-nums' }}>
            {formatBn(hour, locale)}
          </Typography>
        ))}
        {grid.map((row, weekday) => (
          <Stack key={`d${weekday}`} sx={{ display: 'contents' }}>
            <Typography variant="caption" color="text.secondary" sx={{ pr: 1, alignSelf: 'center', whiteSpace: 'nowrap' }}>
              {t(`reports.weekday.${weekday}`)}
            </Typography>
            {hours.map((hour) => {
              const value = row[hour] ?? 0;
              const label = t('reports.peak_hours.cell', { weekday: t(`reports.weekday.${weekday}`), hour, count: value });
              return (
                <Tooltip key={`${weekday}-${hour}`} title={label} enterTouchDelay={0}>
                  <Box
                    role="img"
                    aria-label={label}
                    sx={{
                      height: 26, borderRadius: 0.5, bgcolor: chart.heat(max > 0 ? value / max : 0),
                      display: 'flex', alignItems: 'center', justifyContent: 'center',
                      fontSize: 10, color: max > 0 && value / max > 0.6 ? 'common.white' : 'text.secondary',
                      fontVariantNumeric: 'tabular-nums',
                    }}
                  >
                    {value > 0 ? formatBn(value, locale) : ''}
                  </Box>
                </Tooltip>
              );
            })}
          </Stack>
        ))}
      </Box>
    </Box>
  );
}
