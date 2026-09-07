// Seven weekday columns, one card per template session (A/B/C…), with add/edit/remove.
import { useTranslation } from 'react-i18next';
import { router } from '@inertiajs/react';
import Box from '@mui/material/Box';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import CardActions from '@mui/material/CardActions';
import Typography from '@mui/material/Typography';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import IconButton from '@mui/material/IconButton';
import AddIcon from '@mui/icons-material/Add';
import DeleteIcon from '@mui/icons-material/DeleteOutlined';
import { route } from '@shared/routes';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { DoctorSchedule } from '@shared/types/models';

export interface WeeklyGridProps {
  schedules: DoctorSchedule[];
  weekdayLabels: string[];
  canManage: boolean;
  onAdd: (weekday: number) => void;
  onEdit: (schedule: DoctorSchedule) => void;
}

export function WeeklyGrid({ schedules, weekdayLabels, canManage, onAdd, onEdit }: WeeklyGridProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const remove = (schedule: DoctorSchedule): void => {
    if (!window.confirm(t('scheduling.template.remove_confirm', { code: schedule.session_code }))) return;
    router.delete(route('panel.scheduling.schedules.destroy', { schedule: schedule.id }), { preserveScroll: true });
  };

  return (
    <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)', lg: 'repeat(7, 1fr)' } }}>
      {weekdayLabels.map((label, weekday) => {
        const rows = schedules.filter((s) => s.weekday === weekday).sort((a, b) => a.start_time.localeCompare(b.start_time));
        return (
          <Card key={weekday} variant="outlined" data-testid={`weekday-${weekday}`}>
            <CardContent sx={{ pb: 1 }}>
              <Typography variant="subtitle2" color="text.secondary" gutterBottom>{label}</Typography>
              <Stack spacing={1}>
                {rows.length === 0 ? <Typography variant="body2" color="text.secondary">{t('scheduling.template.off_day')}</Typography> : null}
                {rows.map((s) => (
                  <Box key={s.id} sx={{ p: 1, borderRadius: 1, bgcolor: 'action.hover', cursor: canManage ? 'pointer' : 'default' }} onClick={() => canManage && onEdit(s)} role={canManage ? 'button' : undefined}>
                    <Stack direction="row" sx={{ alignItems: 'center', justifyContent: 'space-between' }}>
                      <Typography variant="subtitle1" sx={{ fontWeight: 700 }}>{s.session_code}{s.session_label ? ` · ${s.session_label}` : ''}</Typography>
                      {canManage ? (
                        <IconButton size="small" aria-label={t('common.actions.delete')} onClick={(e) => { e.stopPropagation(); remove(s); }}><DeleteIcon fontSize="small" /></IconButton>
                      ) : null}
                    </Stack>
                    <Typography variant="body2">{formatBn(`${s.start_time} – ${s.end_time}`, locale)}</Typography>
                    <Stack direction="row" spacing={0.5} sx={{ mt: 0.5, flexWrap: 'wrap' }}>
                      <Chip size="small" label={`${t('scheduling.pool.counter')} ${formatBn(String(s.counter_quota), locale)}`} />
                      <Chip size="small" label={`${t('scheduling.pool.online')} ${formatBn(String(s.online_quota), locale)}`} />
                      <Chip size="small" label={`${t('scheduling.pool.buffer')} ${formatBn(String(s.buffer_quota), locale)}`} />
                      <Chip size="small" variant="outlined" label={t(`scheduling.mode.${s.mode}`)} />
                    </Stack>
                  </Box>
                ))}
              </Stack>
            </CardContent>
            {canManage ? (
              <CardActions>
                <Button size="small" startIcon={<AddIcon />} onClick={() => onAdd(weekday)}>{t('scheduling.template.add_session')}</Button>
              </CardActions>
            ) : null}
          </Card>
        );
      })}
    </Box>
  );
}

export default WeeklyGrid;
