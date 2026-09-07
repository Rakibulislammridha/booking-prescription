// Session-day view (Inertia::render('Scheduling/SessionDay')): every session of a doctor/branch/date with its serials,
// status chips and the drag-reorder queue. Mutations go through the panel JSON endpoints and reload the page props.
import { type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import MenuItem from '@mui/material/MenuItem';
import Typography from '@mui/material/Typography';
import Button from '@mui/material/Button';
import Box from '@mui/material/Box';
import CalendarIcon from '@mui/icons-material/CalendarMonth';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { SessionCard } from '@panel/Components/Scheduling/SessionCard';
import { route } from '@shared/routes';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { DoctorOption, SchedulingBranchOption, SessionInstance } from '@shared/types/models';

type Props = PageProps<{
  doctors: DoctorOption[];
  branches: SchedulingBranchOption[];
  selected: { doctor_id: number; branch_id: number; date: string };
  sessions: SessionInstance[];
  permissions: { reorder: boolean; call_next: boolean; issue: boolean };
}>;

export default function SessionDay({ doctors, branches, selected, sessions, permissions }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const select = (next: Partial<typeof selected>): void => {
    const params = { doctor: selected.doctor_id, branch: selected.branch_id, date: selected.date, ...Object.fromEntries(Object.entries(next).map(([k, v]) => [k === 'doctor_id' ? 'doctor' : k === 'branch_id' ? 'branch' : k, v])) };
    router.get(route('panel.scheduling.sessions.index'), params, { preserveState: true, replace: true });
  };
  const reload = (): void => router.reload({ only: ['sessions'] });

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ alignItems: { md: 'center' } }}>
        <TextField select size="small" label={t('scheduling.filter.doctor')} value={selected.doctor_id} onChange={(e) => select({ doctor_id: Number(e.target.value) })} sx={{ minWidth: 220 }}>
          {doctors.map((d) => <MenuItem key={d.id} value={d.id}>{locale === 'bn' && d.name_bn ? d.name_bn : d.name}</MenuItem>)}
        </TextField>
        <TextField select size="small" label={t('scheduling.filter.branch')} value={selected.branch_id} onChange={(e) => select({ branch_id: Number(e.target.value) })} sx={{ minWidth: 200 }}>
          {branches.map((b) => <MenuItem key={b.id} value={b.id}>{b.name}</MenuItem>)}
        </TextField>
        <TextField type="date" size="small" label={t('scheduling.filter.date')} value={selected.date} onChange={(e) => e.target.value && select({ date: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} />
        <Box sx={{ flexGrow: 1 }} />
        <Button variant="outlined" startIcon={<CalendarIcon />} onClick={() => router.get(route('panel.scheduling.index'), { doctor: selected.doctor_id, branch: selected.branch_id })}>{t('scheduling.nav.templates')}</Button>
      </Stack>

      {sessions.length === 0 ? (
        <Typography variant="body2" color="text.secondary">{t('scheduling.session.none')}</Typography>
      ) : (
        sessions.map((s) => <SessionCard key={s.public_id} session={s} permissions={permissions} onChanged={reload} />)
      )}
    </Stack>
  );
}

SessionDay.layout = (page: ReactNode) => <PanelLayout title="scheduling.session_day_title">{page}</PanelLayout>;
