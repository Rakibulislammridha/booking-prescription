// Conditions tab: chronic / active problem list + add dialog; DELETE marks a condition resolved (never deleted).
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
import type { ConditionStatus, PatientCondition } from '@shared/types/models';
import type { Locale } from '@shared/types/shared-props';
import { CONDITION_STATUSES } from './labels';

export interface ConditionsTabProps {
  patient: string;
  conditions: PatientCondition[];
  canManage: boolean;
}

export function ConditionsTab({ patient, conditions, canManage }: ConditionsTabProps) {
  const { t, i18n } = useTranslation();
  const locale: Locale = i18n.language === 'bn' ? 'bn' : 'en';
  const [open, setOpen] = useState(false);
  const form = useForm<{ condition_name: string; icd10_code: string; status: ConditionStatus; onset_date: string; notes: string }>({
    condition_name: '', icd10_code: '', status: 'active', onset_date: '', notes: '',
  });

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    form.post(route('panel.patients.conditions.store', { patient }), { preserveScroll: true, onSuccess: () => { form.reset(); setOpen(false); } });
  };

  const resolve = (condition: PatientCondition): void => {
    router.delete(route('panel.patients.conditions.destroy', { patient, condition: condition.id }), { preserveScroll: true });
  };

  return (
    <Stack spacing={2}>
      {canManage ? <Button startIcon={<AddIcon />} variant="outlined" size="small" onClick={() => setOpen(true)} sx={{ alignSelf: 'flex-start' }}>{t('patients.conditions.add')}</Button> : null}
      {conditions.length === 0 ? (
        <Typography variant="body2" color="text.secondary">{t('patients.conditions.empty')}</Typography>
      ) : (
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{t('patients.conditions.name')}</TableCell>
                <TableCell>{t('patients.conditions.icd10')}</TableCell>
                <TableCell>{t('patients.conditions.status')}</TableCell>
                <TableCell>{t('patients.conditions.onset')}</TableCell>
                <TableCell>{t('patients.conditions.resolved')}</TableCell>
                <TableCell align="right" />
              </TableRow>
            </TableHead>
            <TableBody>
              {conditions.map((c) => (
                <TableRow key={c.id} sx={{ opacity: c.status === 'resolved' ? 0.55 : 1 }}>
                  <TableCell>{c.condition_name}</TableCell>
                  <TableCell>{c.icd10_code ?? '—'}</TableCell>
                  <TableCell><Chip size="small" color={c.status === 'chronic' ? 'warning' : c.status === 'active' ? 'error' : 'default'} label={t(`patients.conditions.status.${c.status}`)} /></TableCell>
                  <TableCell>{c.onset_date ? formatDateDhaka(c.onset_date, locale) : '—'}</TableCell>
                  <TableCell>{c.resolved_date ? formatDateDhaka(c.resolved_date, locale) : '—'}</TableCell>
                  <TableCell align="right">
                    {canManage && c.status !== 'resolved' ? <Button size="small" color="inherit" onClick={() => resolve(c)}>{t('patients.conditions.resolve')}</Button> : null}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
      )}

      <Dialog open={open} onClose={() => setOpen(false)} fullWidth maxWidth="sm">
        <form onSubmit={submit} noValidate>
        <DialogTitle>{t('patients.conditions.add')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: '8px !important' }}>
          <TextField size="small" label={t('patients.conditions.name')} value={form.data.condition_name} onChange={(e) => form.setData('condition_name', e.target.value)} error={Boolean(form.errors.condition_name)} helperText={form.errors.condition_name} required autoFocus />
          <TextField size="small" label={t('patients.conditions.icd10')} value={form.data.icd10_code} onChange={(e) => form.setData('icd10_code', e.target.value.toUpperCase())} error={Boolean(form.errors.icd10_code)} helperText={form.errors.icd10_code} slotProps={{ htmlInput: { maxLength: 8 } }} placeholder="I10" />
          <TextField select size="small" label={t('patients.conditions.status')} value={form.data.status} onChange={(e) => form.setData('status', e.target.value as ConditionStatus)}>
            {CONDITION_STATUSES.map((x) => <MenuItem key={x} value={x}>{t(`patients.conditions.status.${x}`)}</MenuItem>)}
          </TextField>
          <TextField size="small" type="date" label={t('patients.conditions.onset')} value={form.data.onset_date} onChange={(e) => form.setData('onset_date', e.target.value)} error={Boolean(form.errors.onset_date)} helperText={form.errors.onset_date} slotProps={{ inputLabel: { shrink: true } }} />
          <TextField size="small" label={t('patients.conditions.notes')} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} multiline minRows={2} />
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
