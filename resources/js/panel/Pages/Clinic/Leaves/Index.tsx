// Clinic/Leaves/Index — planned leave and emergency cancellations across every doctor.
//
// Recording an emergency leave here is the whole cancellation chain: the sessions in range are cancelled and every
// booked patient is messaged. The row shows `notify_patients` so an admin can see, afterwards, whether the patients
// were told.
import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
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
import DeleteIcon from '@mui/icons-material/DeleteOutlined';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { Pager } from '@panel/Components/Clinic/Pager';
import { LeaveDialog } from '@panel/Components/Clinic/LeaveDialog';
import { route } from '@shared/routes';
import { formatDateDhaka } from '@shared/format/date';
import type { PageProps } from '@shared/types/inertia';
import type { ClinicBranch, ClinicDoctor, ClinicDoctorLeave, Paginated } from '@shared/types/models';

type Props = PageProps<{
  leaves: Paginated<ClinicDoctorLeave>;
  doctors: ClinicDoctor[];
  /** Named `branch_options`, not `branches`: SharedProps already owns `branches` (the switcher list). */
  branch_options: ClinicBranch[];
  filters: { doctor: number | null; scope: string };
  types: string[];
  today: string;
  can: { manage: boolean };
}>;

export default function Index({ leaves, doctors, branch_options, filters, types, today, can }: Props) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);

  const reload = (patch: Record<string, string | number | null>): void => {
    const next = { doctor: filters.doctor, scope: filters.scope, ...patch };
    router.get(route('panel.clinic.leaves.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && String(v) !== '')), { preserveState: true, replace: true });
  };
  const withdraw = (leave: ClinicDoctorLeave): void => {
    if (window.confirm(t('clinic.leaves.cancel_confirm'))) {
      router.delete(route('panel.clinic.leaves.destroy', { leave: leave.id }), { preserveScroll: true });
    }
  };

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' } }}>
        <TextField select size="small" label={t('clinic.leaves.filters.doctor')} value={filters.doctor ? String(filters.doctor) : ''} sx={{ minWidth: 220 }} onChange={(e) => reload({ doctor: e.target.value === '' ? null : Number(e.target.value) })}>
          <MenuItem value="">{t('clinic.leaves.filters.all_doctors')}</MenuItem>
          {doctors.map((d) => <MenuItem key={d.public_id} value={String(d.id)}>{d.name}</MenuItem>)}
        </TextField>
        <TextField select size="small" label={t('clinic.leaves.filters.scope')} value={filters.scope} sx={{ minWidth: 160 }} onChange={(e) => reload({ scope: e.target.value })}>
          <MenuItem value="upcoming">{t('clinic.leaves.scope.upcoming')}</MenuItem>
          <MenuItem value="past">{t('clinic.leaves.scope.past')}</MenuItem>
          <MenuItem value="all">{t('clinic.leaves.scope.all')}</MenuItem>
        </TextField>
        <Box sx={{ flexGrow: 1 }} />
        {can.manage ? <Button variant="contained" startIcon={<AddIcon />} onClick={() => setOpen(true)}>{t('clinic.leaves.add')}</Button> : null}
      </Stack>

      <Card>
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small" aria-label={t('clinic.leaves.title')}>
            <TableHead>
              <TableRow>
                <TableCell>{t('clinic.leaves.columns.doctor')}</TableCell>
                <TableCell>{t('clinic.leaves.columns.dates')}</TableCell>
                <TableCell>{t('clinic.leaves.columns.type')}</TableCell>
                <TableCell>{t('clinic.leaves.columns.branch')}</TableCell>
                <TableCell>{t('clinic.leaves.columns.notify')}</TableCell>
                <TableCell>{t('clinic.leaves.columns.reason')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {leaves.data.length === 0 ? (
                <TableRow><TableCell colSpan={7}><Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('clinic.leaves.empty')}</Typography></TableCell></TableRow>
              ) : leaves.data.map((leave) => (
                <TableRow key={leave.id} hover>
                  <TableCell>{leave.doctor_name ?? '—'}</TableCell>
                  <TableCell>{formatDateDhaka(leave.starts_on)} — {formatDateDhaka(leave.ends_on)}</TableCell>
                  <TableCell>
                    <Chip size="small" color={leave.type === 'emergency' ? 'warning' : 'default'} label={t(`clinic.leaves.type.${leave.type}`)} />
                    {leave.is_cancelled ? <Chip size="small" sx={{ ml: 0.5 }} label={t('clinic.leaves.withdrawn')} /> : null}
                  </TableCell>
                  <TableCell>{leave.branch_name ?? t('clinic.leaves.all_branches')}</TableCell>
                  <TableCell>{leave.notify_patients ? t('common.actions.yes') : t('common.actions.no')}</TableCell>
                  <TableCell>{leave.reason ?? '—'}</TableCell>
                  <TableCell align="right">
                    {can.manage && !leave.is_cancelled ? (
                      <IconButton size="small" aria-label={t('clinic.leaves.withdraw')} onClick={() => withdraw(leave)}><DeleteIcon fontSize="small" /></IconButton>
                    ) : null}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
        <Pager page={leaves} />
      </Card>

      <LeaveDialog open={open} onClose={() => setOpen(false)} doctors={doctors} branches={branch_options} types={types} today={today} />
    </Stack>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="clinic.leaves.title">{page}</PanelLayout>;
