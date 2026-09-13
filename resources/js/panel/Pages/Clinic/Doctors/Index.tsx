// Clinic/Doctors/Index — the doctor roster. Server-side search and filters, because this is the list that grows
// with the clinic. Each row links to the four things a doctor needs configured: profile, schedule, pad, compounders.
import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Avatar from '@mui/material/Avatar';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import AddIcon from '@mui/icons-material/Add';
import DescriptionIcon from '@mui/icons-material/Description';
import GroupsIcon from '@mui/icons-material/Groups';
import ScheduleIcon from '@mui/icons-material/CalendarMonth';
import SearchIcon from '@mui/icons-material/Search';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { Pager } from '@panel/Components/Clinic/Pager';
import { route } from '@shared/routes';
import { formatBdt } from '@shared/format/money';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { ClinicDepartment, ClinicDoctor, ClinicSpecialty, Paginated } from '@shared/types/models';

/**
 * The two per-row abilities the server decides for us. `can.manage` is one flag for the whole page and cannot
 * answer them: DoctorPolicy::designPad and ::manageCompounders both read "an admin administers every row, a doctor
 * administers their OWN", and this roster is the only doctors screen a doctor without `clinic.doctors.manage` can
 * open. The page used to mirror that policy in TypeScript for the compounders link and draw the Pad button with no
 * gate at all, which handed every doctor a button to every colleague's pad and a 403 when they pressed it.
 *
 * They live here rather than on `ClinicDoctor` (shared/types/models.d.ts, which owns the server-shaped types) only
 * because that block has not been touched for them yet — move them there when it next is.
 */
type RosterDoctor = ClinicDoctor & { can_design_pad: boolean; can_manage_compounders: boolean };

type Props = PageProps<{
  doctors: Paginated<RosterDoctor>;
  filters: { q: string; department: number | null; specialty: number | null; status: string };
  departments: ClinicDepartment[];
  specialties: ClinicSpecialty[];
  can: { manage: boolean; schedule: boolean };
}>;

export default function Index({ doctors, filters, departments, specialties, can }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [q, setQ] = useState(filters.q);

  const reload = (patch: Record<string, string | number | null>): void => {
    const next: Record<string, string | number | null> = {
      q, department: filters.department, specialty: filters.specialty, status: filters.status, ...patch,
    };
    router.get(route('panel.clinic.doctors.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && String(v) !== '')), {
      preserveState: true, replace: true, only: ['doctors', 'filters'],
    });
  };

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ alignItems: { md: 'center' } }}>
        <TextField
          value={q}
          onChange={(e) => setQ(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') reload({ q: (e.target as HTMLInputElement).value }); }}
          onBlur={(e) => reload({ q: e.target.value })}
          size="small" fullWidth placeholder={t('clinic.doctors.search_placeholder')}
          slotProps={{
            htmlInput: { 'aria-label': t('common.actions.search'), lang: 'bn' },
            input: { startAdornment: <InputAdornment position="start"><SearchIcon /></InputAdornment> },
          }}
        />
        <TextField select size="small" label={t('clinic.doctors.filters.department')} value={filters.department ?? ''} sx={{ minWidth: 180 }} onChange={(e) => reload({ department: e.target.value === '' ? null : Number(e.target.value) })}>
          <MenuItem value="">{t('clinic.doctors.filters.all')}</MenuItem>
          {departments.map((d) => <MenuItem key={d.id} value={String(d.id)}>{d.name}</MenuItem>)}
        </TextField>
        <TextField select size="small" label={t('clinic.doctors.filters.specialty')} value={filters.specialty ?? ''} sx={{ minWidth: 180 }} onChange={(e) => reload({ specialty: e.target.value === '' ? null : Number(e.target.value) })}>
          <MenuItem value="">{t('clinic.doctors.filters.all')}</MenuItem>
          {specialties.map((s) => <MenuItem key={s.id} value={String(s.id)}>{s.name}</MenuItem>)}
        </TextField>
        {can.manage ? (
          <Button component={RouterLink} href={route('panel.clinic.doctors.create')} variant="contained" startIcon={<AddIcon />} sx={{ flexShrink: 0 }}>
            {t('clinic.doctors.add')}
          </Button>
        ) : null}
      </Stack>

      <Card>
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small" aria-label={t('clinic.doctors.title')}>
            <TableHead>
              <TableRow>
                <TableCell>{t('clinic.doctors.columns.doctor')}</TableCell>
                <TableCell>{t('clinic.doctors.columns.specialties')}</TableCell>
                <TableCell>{t('clinic.doctors.columns.fees')}</TableCell>
                <TableCell>{t('clinic.doctors.columns.free_followup')}</TableCell>
                <TableCell>{t('clinic.doctors.columns.room')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {doctors.data.length === 0 ? (
                <TableRow><TableCell colSpan={6}><Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('clinic.doctors.empty')}</Typography></TableCell></TableRow>
              ) : doctors.data.map((doctor) => (
                <TableRow key={doctor.public_id} hover>
                  <TableCell>
                    <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center' }}>
                      <Avatar src={doctor.photo_url ?? undefined} sx={{ width: 36, height: 36 }}>{doctor.name.slice(0, 1)}</Avatar>
                      <Box>
                        <Typography variant="body2" sx={{ fontWeight: 600 }}>
                          {doctor.name}
                          {!doctor.is_active ? <Chip size="small" label={t('clinic.status.inactive')} sx={{ ml: 1 }} /> : null}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          {doctor.code} · {doctor.department_name ?? t('common.status.none')}
                          {doctor.profile?.bmdc_reg_no ? ` · BMDC ${doctor.profile.bmdc_reg_no}` : ''}
                        </Typography>
                      </Box>
                    </Stack>
                  </TableCell>
                  <TableCell>
                    <Stack direction="row" spacing={0.5} sx={{ flexWrap: 'wrap', gap: 0.5 }}>
                      {(doctor.specialties ?? []).map((s) => <Chip key={s.id} size="small" variant="outlined" label={s.name} />)}
                    </Stack>
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2">{formatBdt(doctor.profile?.new_fee_paisa ?? 0, locale)}</Typography>
                    <Typography variant="caption" color="text.secondary">{t('clinic.doctors.followup_fee_short', { amount: formatBdt(doctor.profile?.followup_fee_paisa ?? 0, locale) })}</Typography>
                  </TableCell>
                  <TableCell>
                    {(doctor.profile?.free_followup_within_days ?? 0) > 0
                      ? <Chip size="small" color="success" label={t('clinic.doctors.free_followup.chip', { days: formatBn(doctor.profile?.free_followup_within_days ?? 0, locale) })} />
                      : <Typography variant="caption" color="text.secondary">{t('clinic.doctors.free_followup.off')}</Typography>}
                  </TableCell>
                  <TableCell>{doctor.room_label ?? '—'}</TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={0.5} sx={{ justifyContent: 'flex-end' }}>
                      {can.schedule ? (
                        <Button size="small" startIcon={<ScheduleIcon />} component={RouterLink} href={route('panel.scheduling.index', { doctor: doctor.id })}>
                          {t('clinic.doctors.schedule')}
                        </Button>
                      ) : null}
                      {doctor.can_design_pad ? (
                        <Button size="small" startIcon={<DescriptionIcon />} component={RouterLink} href={route('panel.clinic.doctors.pad.edit', { doctor: doctor.public_id })}>
                          {t('clinic.doctors.pad')}
                        </Button>
                      ) : null}
                      {doctor.can_manage_compounders ? (
                        <Button size="small" startIcon={<GroupsIcon />} component={RouterLink} href={route('panel.clinic.doctors.compounders.index', { doctor: doctor.public_id })}>
                          {t('clinic.compounders.link')}
                        </Button>
                      ) : null}
                      {can.manage ? (
                        <Button size="small" component={RouterLink} href={route('panel.clinic.doctors.edit', { doctor: doctor.public_id })}>{t('common.actions.edit')}</Button>
                      ) : null}
                    </Stack>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
        <Pager page={doctors} />
      </Card>
    </Stack>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="clinic.doctors.title">{page}</PanelLayout>;
