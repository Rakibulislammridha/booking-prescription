// Clinic/Doctors/Compounders — who works this doctor's desk (BRIEF: "a doctor can assign a compounder and he can
// manage those specific doctor's patients").
//
// A sibling of Pad.tsx in every sense: same per-doctor shell (the doctor's name, one way back to the profile), same
// "a doctor administers their own row" ability, and the same rule that the screen configures a thing the server
// already enforces. Nothing here grants a permission — the `compounder` role carries the four it has, and this list
// only decides WHICH doctors they apply to. That is why the note at the top spells out the two limits the product
// owner asked for in words, rather than leaving them to be discovered: no other doctor's patients, no serial number.
//
// The pool is deliberately not a "create a compounder" form. Making a staff account is the staff screen's job, so
// this screen can never half-turn a receptionist into a compounder by accident; when the pool is empty it says so
// and points at the screen that fills it.
import { useState, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogContentText from '@mui/material/DialogContentText';
import DialogTitle from '@mui/material/DialogTitle';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import DeleteIcon from '@mui/icons-material/DeleteOutlined';
import PersonAddIcon from '@mui/icons-material/PersonAdd';
import StaffIcon from '@mui/icons-material/Groups';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { route, hasRoute } from '@shared/routes';
import { useSharedProps } from '@shared/inertia';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { ClinicCompounder, ClinicStaffUser } from '@shared/types/models';

type Props = PageProps<{
  /** No `id`: every link and form on this page addresses the doctor by `public_id` (CONVENTIONS §5). */
  doctor: { public_id: string; name: string; name_bn: string | null; code: string };
  compounders: ClinicCompounder[];
  /** Active staff holding the compounder role who are not already on this desk. */
  available: ClinicStaffUser[];
  can: { manage: boolean };
}>;

export default function Compounders({ doctor, compounders, available, can }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const shared = useSharedProps();
  // The staff account is named by its public ULID too — the pool is UserResource, which already carries one, so
  // the bigint the form used to post was an internal key in a browser for nothing (StoreDoctorCompounderRequest).
  const form = useForm<{ user_public_id: string }>({ user_public_id: '' });
  const [removing, setRemoving] = useState<ClinicCompounder | null>(null);
  // Only an admin can make the account this screen would send them to make; a doctor administering their own row
  // is told what is missing without being handed a link that would 403 on them.
  const canReachStaff = (shared.auth.user?.permissions.includes('clinic.users.manage') ?? false) && hasRoute('panel.clinic.staff.index');

  const assign = (): void => {
    if (form.data.user_public_id === '') return;
    form.post(route('panel.clinic.doctors.compounders.store', { doctor: doctor.public_id }), {
      preserveScroll: true,
      onSuccess: () => form.setData('user_public_id', ''),
    });
  };

  const remove = (): void => {
    if (!removing) return;
    router.delete(route('panel.clinic.doctors.compounders.destroy', { doctor: doctor.public_id, compounder: removing.public_id }), {
      preserveScroll: true,
      onFinish: () => setRemoving(null),
    });
  };

  return (
    <Stack spacing={2} sx={{ maxWidth: 900 }}>
      <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
        <Typography variant="h6" sx={{ flexGrow: 1 }}>{t('clinic.compounders.for_doctor', { name: locale === 'bn' && doctor.name_bn ? doctor.name_bn : doctor.name })}</Typography>
        <Button component={RouterLink} href={route('panel.clinic.doctors.edit', { doctor: doctor.public_id })} size="small">
          {t('clinic.pad.back_to_doctor')}
        </Button>
      </Stack>

      <Alert severity="info">{t('clinic.compounders.scope_note')}</Alert>

      {can.manage ? (
        <Card>
          <CardContent>
            <Typography variant="subtitle2" gutterBottom>{t('clinic.compounders.assign')}</Typography>
            {available.length === 0 ? (
              <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
                <Typography variant="body2" color="text.secondary" sx={{ flexGrow: 1 }}>{t('clinic.compounders.pool_empty')}</Typography>
                {canReachStaff ? (
                  <Button size="small" variant="outlined" startIcon={<StaffIcon />} component={RouterLink} href={route('panel.clinic.staff.index')}>
                    {t('clinic.compounders.create_account')}
                  </Button>
                ) : null}
              </Stack>
            ) : (
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'flex-start' } }}>
                <TextField
                  select size="small" fullWidth label={t('clinic.compounders.pick_user')}
                  value={form.data.user_public_id}
                  onChange={(e) => form.setData('user_public_id', e.target.value)}
                  error={Boolean(form.errors.user_public_id)}
                  helperText={form.errors.user_public_id ?? t('clinic.compounders.pick_user_help')}
                >
                  {available.map((u) => <MenuItem key={u.public_id} value={u.public_id}>{u.name} — {u.email}</MenuItem>)}
                </TextField>
                <Button
                  variant="contained" startIcon={<PersonAddIcon />} sx={{ flexShrink: 0 }}
                  disabled={form.processing || form.data.user_public_id === ''}
                  onClick={assign}
                >
                  {t('clinic.compounders.assign')}
                </Button>
              </Stack>
            )}
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small" aria-label={t('clinic.compounders.title')}>
            <TableHead>
              <TableRow>
                <TableCell>{t('clinic.compounders.columns.name')}</TableCell>
                <TableCell>{t('clinic.compounders.columns.status')}</TableCell>
                <TableCell>{t('clinic.compounders.columns.assigned')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {compounders.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={4}>
                    <Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('clinic.compounders.empty')}</Typography>
                  </TableCell>
                </TableRow>
              ) : compounders.map((c) => (
                <TableRow key={c.public_id} hover>
                  <TableCell>
                    <Typography variant="body2" sx={{ fontWeight: 600 }} lang="bn">{c.name}</Typography>
                    <Typography variant="caption" color="text.secondary">{[c.email, c.mobile].filter(Boolean).join(' · ')}</Typography>
                  </TableCell>
                  <TableCell>
                    <Chip size="small" color={c.is_active ? 'success' : 'default'} label={t(c.is_active ? 'clinic.status.active' : 'clinic.status.inactive')} />
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2">{c.assigned_at ? formatDhaka(c.assigned_at, 'D MMM YYYY', locale) : '—'}</Typography>
                    {c.assigned_by ? <Typography variant="caption" color="text.secondary">{t('clinic.compounders.assigned_by', { name: c.assigned_by })}</Typography> : null}
                  </TableCell>
                  <TableCell align="right">
                    {can.manage ? (
                      <Button size="small" color="error" startIcon={<DeleteIcon />} onClick={() => setRemoving(c)}>{t('clinic.compounders.remove')}</Button>
                    ) : null}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
      </Card>

      {/* Removing a desk is not a delete of anything, but it takes a board, a patient list and a fee screen away
          from someone who may be mid-shift — so it is confirmed, and the confirmation says exactly that. */}
      <Dialog open={removing !== null} onClose={() => setRemoving(null)} maxWidth="xs" fullWidth>
        <DialogTitle>{t('clinic.compounders.remove')}</DialogTitle>
        <DialogContent>
          <DialogContentText>{t('clinic.compounders.remove_confirm', { name: removing?.name ?? '' })}</DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setRemoving(null)}>{t('common.actions.cancel')}</Button>
          <Button color="error" variant="contained" onClick={remove}>{t('clinic.compounders.remove')}</Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}

Compounders.layout = (page: ReactNode) => <PanelLayout title="clinic.compounders.title">{page}</PanelLayout>;
