// Medications tab: the long-term medication list (feeds the writer's interaction / duplicate checks).
// DELETE = stop (is_active false, ended_on today).
import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Table from '@mui/material/Table';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TableCell from '@mui/material/TableCell';
import TableBody from '@mui/material/TableBody';
import Chip from '@mui/material/Chip';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogTitle from '@mui/material/DialogTitle';
import DialogContent from '@mui/material/DialogContent';
import DialogActions from '@mui/material/DialogActions';
import TextField from '@mui/material/TextField';
import MenuItem from '@mui/material/MenuItem';
import Typography from '@mui/material/Typography';
import Stack from '@mui/material/Stack';
import Box from '@mui/material/Box';
import AddIcon from '@mui/icons-material/Add';
import { route } from '@shared/routes';
import { formatDateDhaka } from '@shared/format/date';
import type { MedicationSource, PatientMedication } from '@shared/types/models';
import type { Locale } from '@shared/types/shared-props';
import { MEDICATION_SOURCES } from './labels';

export interface MedicationsTabProps {
  patient: string;
  medications: PatientMedication[];
  canManage: boolean;
}

export function MedicationsTab({ patient, medications, canManage }: MedicationsTabProps) {
  const { t, i18n } = useTranslation();
  const locale: Locale = i18n.language === 'bn' ? 'bn' : 'en';
  const [open, setOpen] = useState(false);
  const form = useForm<{ generic_name: string; brand_name: string; dose_text: string; source: MedicationSource; started_on: string; notes: string }>({
    generic_name: '', brand_name: '', dose_text: '', source: 'reported', started_on: '', notes: '',
  });

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    form.post(route('panel.patients.medications.store', { patient }), { preserveScroll: true, onSuccess: () => { form.reset(); setOpen(false); } });
  };

  const stop = (medication: PatientMedication): void => {
    router.delete(route('panel.patients.medications.destroy', { patient, medication: medication.id }), { preserveScroll: true });
  };

  return (
    <Stack spacing={2}>
      {canManage ? <Button startIcon={<AddIcon />} variant="outlined" size="small" onClick={() => setOpen(true)} sx={{ alignSelf: 'flex-start' }}>{t('patients.medications.add')}</Button> : null}
      {medications.length === 0 ? (
        <Typography variant="body2" color="text.secondary">{t('patients.medications.empty')}</Typography>
      ) : (
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{t('patients.medications.generic')}</TableCell>
                <TableCell>{t('patients.medications.brand')}</TableCell>
                <TableCell>{t('patients.medications.dose')}</TableCell>
                <TableCell>{t('patients.medications.started')}</TableCell>
                <TableCell>{t('patients.medications.ended')}</TableCell>
                <TableCell />
                <TableCell align="right" />
              </TableRow>
            </TableHead>
            <TableBody>
              {medications.map((m) => (
                <TableRow key={m.id} sx={{ opacity: m.is_active ? 1 : 0.55 }}>
                  <TableCell>{m.generic_name}</TableCell>
                  <TableCell>{m.brand_name ?? '—'}</TableCell>
                  <TableCell>{m.dose_text ?? '—'}</TableCell>
                  <TableCell>{m.started_on ? formatDateDhaka(m.started_on, locale) : '—'}</TableCell>
                  <TableCell>{m.ended_on ? formatDateDhaka(m.ended_on, locale) : '—'}</TableCell>
                  <TableCell><Chip size="small" variant="outlined" label={t(`patients.medications.source.${m.source}`)} /></TableCell>
                  <TableCell align="right">
                    {m.is_active ? (
                      canManage ? <Button size="small" color="inherit" onClick={() => stop(m)}>{t('patients.medications.stop')}</Button> : <Chip size="small" color="success" label={t('patients.medications.active')} />
                    ) : (
                      <Chip size="small" label={t('patients.medications.stopped')} />
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
      )}

      <Dialog open={open} onClose={() => setOpen(false)} fullWidth maxWidth="sm">
        <form onSubmit={submit} noValidate>
        <DialogTitle>{t('patients.medications.add')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: '8px !important' }}>
          <TextField size="small" label={t('patients.medications.generic')} value={form.data.generic_name} onChange={(e) => form.setData('generic_name', e.target.value)} error={Boolean(form.errors.generic_name)} helperText={form.errors.generic_name} required autoFocus />
          <TextField size="small" label={t('patients.medications.brand')} value={form.data.brand_name} onChange={(e) => form.setData('brand_name', e.target.value)} error={Boolean(form.errors.brand_name)} helperText={form.errors.brand_name} />
          <TextField size="small" label={t('patients.medications.dose')} value={form.data.dose_text} onChange={(e) => form.setData('dose_text', e.target.value)} error={Boolean(form.errors.dose_text)} helperText={form.errors.dose_text} placeholder="1+0+1" />
          <TextField select size="small" label={t('patients.documents.type')} value={form.data.source} onChange={(e) => form.setData('source', e.target.value as MedicationSource)}>
            {MEDICATION_SOURCES.map((x) => <MenuItem key={x} value={x}>{t(`patients.medications.source.${x}`)}</MenuItem>)}
          </TextField>
          <TextField size="small" type="date" label={t('patients.medications.started')} value={form.data.started_on} onChange={(e) => form.setData('started_on', e.target.value)} error={Boolean(form.errors.started_on)} helperText={form.errors.started_on} slotProps={{ inputLabel: { shrink: true } }} />
          <TextField size="small" label={t('patients.medications.notes')} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} multiline minRows={2} />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setOpen(false)} color="inherit">{t('common.actions.cancel')}</Button>
          <Button type="submit" variant="contained" disabled={form.processing}>{t('common.actions.save')}</Button>
        </DialogActions>
        </form>
      </Dialog>
    </Stack>
  );
}
