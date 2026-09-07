// Merge a duplicate INTO the current patient (patients.merge): the loser is closed and its rows repointed.
import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Dialog from '@mui/material/Dialog';
import DialogTitle from '@mui/material/DialogTitle';
import DialogContent from '@mui/material/DialogContent';
import DialogActions from '@mui/material/DialogActions';
import TextField from '@mui/material/TextField';
import Button from '@mui/material/Button';
import Alert from '@mui/material/Alert';
import { route } from '@shared/routes';
import type { PatientRecord } from '@shared/types/models';

export interface MergeDialogProps {
  open: boolean;
  onClose: () => void;
  patient: PatientRecord;
}

export function MergeDialog({ open, onClose, patient }: MergeDialogProps) {
  const { t } = useTranslation();
  const form = useForm({ loser_public_id: '', reason: '' });
  const domainError = form.errors['domain' as keyof typeof form.errors];

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    form.post(route('panel.patients.merge', { patient: patient.public_id }), { onSuccess: () => { form.reset(); onClose(); } });
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <form onSubmit={submit} noValidate>
      <DialogTitle>{t('patients.merge.title')}</DialogTitle>
      <DialogContent sx={{ display: 'grid', gap: 2, pt: '8px !important' }}>
        <Alert severity="warning">{t('patients.merge.help')}</Alert>
        {domainError ? <Alert severity="error">{domainError}</Alert> : null}
        <TextField
          label={t('patients.merge.loser')}
          value={form.data.loser_public_id}
          onChange={(e) => form.setData('loser_public_id', e.target.value.trim().toUpperCase())}
          error={Boolean(form.errors.loser_public_id)}
          helperText={form.errors.loser_public_id}
          slotProps={{ htmlInput: { maxLength: 26, style: { fontFamily: 'monospace' } } }}
          autoFocus
          required
          size="small"
        />
        <TextField label={t('patients.merge.reason')} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} error={Boolean(form.errors.reason)} helperText={form.errors.reason} size="small" />
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} color="inherit">{t('common.actions.cancel')}</Button>
        <Button type="submit" variant="contained" color="warning" disabled={form.processing || form.data.loser_public_id.length !== 26}>{t('patients.merge.confirm')}</Button>
      </DialogActions>
      </form>
    </Dialog>
  );
}
