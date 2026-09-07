// Weekly template editor per doctor/branch (Inertia::render('Scheduling/Index')) with the upcoming overrides.
import { useMemo, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import MenuItem from '@mui/material/MenuItem';
import Button from '@mui/material/Button';
import Typography from '@mui/material/Typography';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import IconButton from '@mui/material/IconButton';
import Chip from '@mui/material/Chip';
import DeleteIcon from '@mui/icons-material/DeleteOutlined';
import EventIcon from '@mui/icons-material/EventBusy';
import TodayIcon from '@mui/icons-material/Today';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { WeeklyGrid } from '@panel/Components/Scheduling/WeeklyGrid';
import { ScheduleDialog } from '@panel/Components/Scheduling/ScheduleDialog';
import { OverrideDialog } from '@panel/Components/Scheduling/OverrideDialog';
import { route } from '@shared/routes';
import { formatDateDhaka } from '@shared/format/date';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { DoctorOption, DoctorSchedule, SchedulingBranchOption, ScheduleOverride } from '@shared/types/models';

type Props = PageProps<{
  doctors: DoctorOption[];
  branches: SchedulingBranchOption[];
  selected: { doctor_id: number; branch_id: number };
  schedules: DoctorSchedule[];
  overrides: ScheduleOverride[];
  today: string;
  can_manage: boolean;
}>;

export default function Index({ doctors, branches, selected, schedules, overrides, today, can_manage }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const weekdayLabels = useMemo(() => [0, 1, 2, 3, 4, 5, 6].map((d) => t(`scheduling.weekday.${d}`)), [t]);
  const [dialog, setDialog] = useState<{ open: boolean; weekday: number; schedule: DoctorSchedule | null }>({ open: false, weekday: 0, schedule: null });
  const [overrideOpen, setOverrideOpen] = useState(false);

  const select = (doctor: number, branch: number): void => {
    router.get(route('panel.scheduling.index'), { doctor, branch }, { preserveState: true, replace: true });
  };
  const removeOverride = (o: ScheduleOverride): void => {
    if (!window.confirm(t('scheduling.override.remove_confirm'))) return;
    router.delete(route('panel.scheduling.overrides.destroy', { override: o.id }), { preserveScroll: true });
  };
  const sessionCodes = Array.from(new Set(schedules.map((s) => s.session_code))).sort();

  return (
    <Stack spacing={3}>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ alignItems: { md: 'center' } }}>
        <TextField select size="small" label={t('scheduling.filter.doctor')} value={selected.doctor_id} onChange={(e) => select(Number(e.target.value), selected.branch_id)} sx={{ minWidth: 220 }}>
          {doctors.map((d) => <MenuItem key={d.id} value={d.id}>{locale === 'bn' && d.name_bn ? d.name_bn : d.name}</MenuItem>)}
        </TextField>
        <TextField select size="small" label={t('scheduling.filter.branch')} value={selected.branch_id} onChange={(e) => select(selected.doctor_id, Number(e.target.value))} sx={{ minWidth: 200 }}>
          {branches.map((b) => <MenuItem key={b.id} value={b.id}>{b.name}</MenuItem>)}
        </TextField>
        <Box sx={{ flexGrow: 1 }} />
        <Button variant="outlined" startIcon={<TodayIcon />} onClick={() => router.get(route('panel.scheduling.sessions.index'), { doctor: selected.doctor_id, branch: selected.branch_id, date: today })}>
          {t('scheduling.nav.session_day')}
        </Button>
        {can_manage ? (
          <Button variant="contained" startIcon={<EventIcon />} onClick={() => setOverrideOpen(true)}>{t('scheduling.override.add')}</Button>
        ) : null}
      </Stack>

      <WeeklyGrid
        schedules={schedules}
        weekdayLabels={weekdayLabels}
        canManage={can_manage}
        onAdd={(weekday) => setDialog({ open: true, weekday, schedule: null })}
        onEdit={(schedule) => setDialog({ open: true, weekday: schedule.weekday, schedule })}
      />

      <Box>
        <Typography variant="h6" component="h2" gutterBottom>{t('scheduling.override.list_title')}</Typography>
        {overrides.length === 0 ? (
          <Typography variant="body2" color="text.secondary">{t('scheduling.override.none')}</Typography>
        ) : (
          <Box sx={{ overflowX: 'auto' }}>
            <Table size="small" aria-label={t('scheduling.override.list_title')}>
              <TableHead>
                <TableRow>
                  <TableCell>{t('scheduling.override.date')}</TableCell>
                  <TableCell>{t('scheduling.override.session_code')}</TableCell>
                  <TableCell>{t('scheduling.override.type')}</TableCell>
                  <TableCell>{t('scheduling.override.details')}</TableCell>
                  <TableCell>{t('scheduling.override.reason')}</TableCell>
                  <TableCell>{t('scheduling.override.applied')}</TableCell>
                  <TableCell />
                </TableRow>
              </TableHead>
              <TableBody>
                {overrides.map((o) => (
                  <TableRow key={o.id}>
                    <TableCell>{formatDateDhaka(o.override_date)}</TableCell>
                    <TableCell>{o.session_code ?? t('scheduling.override.all_sessions')}</TableCell>
                    <TableCell><Chip size="small" label={t(`scheduling.override_type.${o.type}`)} color={o.type === 'cancelled' ? 'error' : 'default'} /></TableCell>
                    <TableCell>
                      {formatBn([
                        o.delay_minutes !== null ? `+${o.delay_minutes} ${t('scheduling.override.minutes')}` : null,
                        o.new_start_time || o.new_end_time ? `${o.new_start_time ?? '…'} – ${o.new_end_time ?? '…'}` : null,
                        o.new_max_serials !== null ? `${o.new_counter_quota}/${o.new_online_quota}/${o.new_buffer_quota}` : null,
                      ].filter(Boolean).join(' · '), locale)}
                    </TableCell>
                    <TableCell>{o.reason ?? '—'}</TableCell>
                    <TableCell>{o.applied_at ? <Chip size="small" color="success" label={t('scheduling.override.applied')} /> : <Chip size="small" label={t('scheduling.override.pending')} />}</TableCell>
                    <TableCell align="right">
                      {can_manage ? <IconButton size="small" aria-label={t('common.actions.delete')} onClick={() => removeOverride(o)}><DeleteIcon fontSize="small" /></IconButton> : null}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Box>
        )}
      </Box>

      <ScheduleDialog
        open={dialog.open}
        onClose={() => setDialog((d) => ({ ...d, open: false }))}
        doctorId={selected.doctor_id}
        branchId={selected.branch_id}
        weekday={dialog.weekday}
        schedule={dialog.schedule}
        weekdayLabels={weekdayLabels}
      />
      <OverrideDialog open={overrideOpen} onClose={() => setOverrideOpen(false)} doctorId={selected.doctor_id} branchId={selected.branch_id} today={today} sessionCodes={sessionCodes} />
    </Stack>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="scheduling.title">{page}</PanelLayout>;
