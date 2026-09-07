// Recording a doctor's leave. Choosing **emergency** is not a label change: CreateDoctorLeave raises
// DoctorLeaveCreated, Scheduling cancels every open session instance in range, and Notifications messages every
// booked patient. The dialog says so in as many words before the button is pressed, because it cannot be undone
// by deleting the leave afterwards.
import { useEffect, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import Grid from '@mui/material/Grid';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import { route } from '@shared/routes';
import type { ClinicBranch, ClinicDoctor } from '@shared/types/models';

interface Props {
  open: boolean;
  onClose: () => void;
  doctors: ClinicDoctor[];
  branches: ClinicBranch[];
  types: string[];
  today: string;
  /** Pre-selected (and locked) doctor when opened from that doctor's own page. */
  doctorId?: number;
}

interface LeaveFormData {
  doctor_id: string;
  branch_id: string;
  starts_on: string;
  ends_on: string;
  type: string;
  reason: string;
  notify_patients: boolean;
}

export function LeaveDialog({ open, onClose, doctors, branches, types, today, doctorId }: Props): ReactNode {
  const { t } = useTranslation();
  const form = useForm<LeaveFormData>({
    doctor_id: doctorId ? String(doctorId) : '',
    branch_id: '',
    starts_on: today,
    ends_on: today,
    type: 'planned',
    reason: '',
    notify_patients: true,
  });

  useEffect(() => {
    if (open) {
      form.clearErrors();
      form.setData((current) => ({ ...current, doctor_id: doctorId ? String(doctorId) : current.doctor_id, starts_on: today, ends_on: today }));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, doctorId, today]);

  const emergency = form.data.type === 'emergency';
  // bootstrap/app.php renders a DomainException as `withErrors(['domain' => …])`, so it is not a field error.
  const domainError = (form.errors as Record<string, string | undefined>).domain;

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <form onSubmit={(e) => { e.preventDefault(); form.post(route('panel.clinic.leaves.store'), { preserveScroll: true, onSuccess: onClose }); }}>
        <DialogTitle>{t('clinic.leaves.add')}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            <ToggleButtonGroup exclusive size="small" value={form.data.type} onChange={(_, value) => { if (value) form.setData('type', String(value)); }} aria-label={t('clinic.leaves.fields.type')}>
              {types.map((type) => <ToggleButton key={type} value={type}>{t(`clinic.leaves.type.${type}`)}</ToggleButton>)}
            </ToggleButtonGroup>

            {emergency ? <Alert severity="warning">{t('clinic.leaves.emergency_warning')}</Alert> : null}

            <TextField
              select label={t('clinic.leaves.fields.doctor')} value={form.data.doctor_id} required fullWidth
              disabled={Boolean(doctorId)}
              onChange={(e) => form.setData('doctor_id', e.target.value)}
              error={Boolean(form.errors.doctor_id)} helperText={form.errors.doctor_id}
            >
              {doctors.map((doctor) => <MenuItem key={doctor.public_id} value={String(doctor.id)}>{doctor.name}</MenuItem>)}
            </TextField>

            <TextField
              select label={t('clinic.leaves.fields.branch')} value={form.data.branch_id} fullWidth
              onChange={(e) => form.setData('branch_id', e.target.value)}
              helperText={t('clinic.leaves.fields.branch_help')}
            >
              <MenuItem value="">{t('clinic.leaves.all_branches')}</MenuItem>
              {branches.map((branch) => <MenuItem key={branch.public_id} value={String(branch.id)}>{branch.name}</MenuItem>)}
            </TextField>

            <Grid container spacing={2}>
              <Grid size={{ xs: 6 }}>
                <TextField
                  label={t('clinic.leaves.fields.starts_on')} type="date" value={form.data.starts_on} required fullWidth
                  onChange={(e) => form.setData('starts_on', e.target.value)}
                  error={Boolean(form.errors.starts_on)} helperText={form.errors.starts_on}
                  slotProps={{ inputLabel: { shrink: true } }}
                />
              </Grid>
              <Grid size={{ xs: 6 }}>
                <TextField
                  label={t('clinic.leaves.fields.ends_on')} type="date" value={form.data.ends_on} required fullWidth
                  onChange={(e) => form.setData('ends_on', e.target.value)}
                  error={Boolean(form.errors.ends_on)} helperText={form.errors.ends_on}
                  slotProps={{ inputLabel: { shrink: true }, htmlInput: { min: form.data.starts_on } }}
                />
              </Grid>
            </Grid>

            <TextField
              label={t('clinic.leaves.fields.reason')} value={form.data.reason} fullWidth
              onChange={(e) => form.setData('reason', e.target.value)}
              error={Boolean(form.errors.reason)} helperText={form.errors.reason}
              slotProps={{ htmlInput: { lang: 'bn', maxLength: 255 } }}
            />

            <FormControlLabel
              control={<Switch checked={form.data.notify_patients} onChange={(e) => form.setData('notify_patients', e.target.checked)} />}
              label={t('clinic.leaves.fields.notify_patients')}
            />
            {domainError ? <Alert severity="error">{domainError}</Alert> : null}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>{t('common.actions.cancel')}</Button>
          <Button type="submit" variant="contained" color={emergency ? 'warning' : 'primary'} disabled={form.processing}>
            {emergency ? t('clinic.leaves.confirm_emergency') : t('common.actions.save')}
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  );
}
