// A year of the clinic's calendar in twelve small month grids. Session materialisation skips these days
// (SCHEMA §3.1), so seeing the whole year at once is the point: a clinic plans Eid, Victory Day and its own
// closures together, not one date at a time.
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Grid from '@mui/material/Grid';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { ClinicHoliday } from '@shared/types/models';

interface Props {
  year: number;
  holidays: ClinicHoliday[];
  today: string;
  onPick: (date: string) => void;
}

const WEEK_START = 0; // Sunday — the Bangladeshi working week runs Sunday to Thursday.

function daysInMonth(year: number, month: number): number {
  return new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
}

function iso(year: number, month: number, day: number): string {
  return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

export function HolidayCalendar({ year, holidays, today, onPick }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();

  const byDate = useMemo(() => {
    const map = new Map<string, ClinicHoliday[]>();
    holidays.forEach((holiday) => {
      const list = map.get(holiday.holiday_date) ?? [];
      list.push(holiday);
      map.set(holiday.holiday_date, list);
    });
    return map;
  }, [holidays]);

  const weekdayLabels = [0, 1, 2, 3, 4, 5, 6].map((d) => t(`clinic.holidays.weekday_short.${(d + WEEK_START) % 7}`));

  return (
    <Grid container spacing={2}>
      {Array.from({ length: 12 }, (_, month) => {
        const first = new Date(Date.UTC(year, month, 1)).getUTCDay();
        const offset = (first - WEEK_START + 7) % 7;
        const total = daysInMonth(year, month);

        return (
          <Grid key={month} size={{ xs: 12, sm: 6, md: 4, lg: 3 }}>
            <Card variant="outlined">
              <CardContent sx={{ p: 1.5, '&:last-child': { pb: 1.5 } }}>
                <Typography variant="subtitle2" gutterBottom>{t(`clinic.holidays.month.${month}`)}</Typography>
                <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: '2px' }}>
                  {weekdayLabels.map((label, index) => (
                    <Box key={`${label}-${index}`} sx={{ textAlign: 'center', fontSize: 10, color: 'text.secondary', pb: 0.5 }}>{label}</Box>
                  ))}
                  {Array.from({ length: offset }, (_, i) => <Box key={`pad-${i}`} />)}
                  {Array.from({ length: total }, (_, i) => {
                    const day = i + 1;
                    const date = iso(year, month, day);
                    const marked = byDate.get(date);
                    const isToday = date === today;
                    const label = marked?.map((h) => (locale === 'bn' && h.name_bn ? h.name_bn : h.name)).join(' · ') ?? '';

                    return (
                      <Tooltip key={date} title={label} disableHoverListener={!marked}>
                        <Box
                          component="button"
                          type="button"
                          onClick={() => onPick(date)}
                          aria-label={marked ? t('clinic.holidays.day_marked_aria', { date, name: label }) : t('clinic.holidays.day_aria', { date })}
                          sx={{
                            border: isToday ? '1px solid' : '1px solid transparent',
                            borderColor: isToday ? 'primary.main' : 'transparent',
                            borderRadius: 1,
                            cursor: 'pointer',
                            font: 'inherit',
                            fontSize: 11,
                            lineHeight: '22px',
                            height: 22,
                            textAlign: 'center',
                            bgcolor: marked ? 'error.light' : 'transparent',
                            color: marked ? 'error.contrastText' : 'text.primary',
                            '&:hover': { bgcolor: marked ? 'error.main' : 'action.hover' },
                          }}
                        >
                          {formatBn(day, locale)}
                        </Box>
                      </Tooltip>
                    );
                  })}
                </Box>
              </CardContent>
            </Card>
          </Grid>
        );
      })}
    </Grid>
  );
}
