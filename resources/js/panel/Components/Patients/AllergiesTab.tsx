// Allergies tab: list + add dialog (POST panel.patients.allergies.store) + "mark inactive" (DELETE deactivates).
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
import type { AllergenType, AllergySeverity, PatientAllergy } from '@shared/types/models';
import { ALLERGEN_TYPES, SEVERITIES } from './labels';

export interface AllergiesTabProps {
  patient: string;
  allergies: PatientAllergy[];
  canManage: boolean;
}

const severityColor = (s: AllergySeverity): 'error' | 'warning' | 'default' => (s === 'severe' ? 'error' : s === 'moderate' ? 'warning' : 'default');

export function AllergiesTab({ patient, allergies, canManage }: AllergiesTabProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const form = useForm<{ allergen_type: AllergenType; allergen_name: string; generic_id: string; allergy_class_id: string; reaction: string; severity: AllergySeverity; notes: string }>({
    allergen_type: 'food', allergen_name: '', generic_id: '', allergy_class_id: '', reaction: '', severity: 'unknown', notes: '',
  });

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    form.post(route('panel.patients.allergies.store', { patient }), { preserveScroll: true, onSuccess: () => { form.reset(); setOpen(false); } });
  };

  const deactivate = (allergy: PatientAllergy): void => {
    router.delete(route('panel.patients.allergies.destroy', { patient, allergy: allergy.id }), { preserveScroll: true });
  };

  return (
    <Stack spacing={2}>
      {canManage ? <Button startIcon={<AddIcon />} variant="outlined" size="small" onClick={() => setOpen(true)} sx={{ alignSelf: 'flex-start' }}>{t('patients.allergies.add')}</Button> : null}
      {allergies.length === 0 ? (
        <Typography variant="body2" color="text.secondary">{t('patients.allergies.empty')}</Typography>
      ) : (
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{t('patients.allergies.allergen_name')}</TableCell>
                <TableCell>{t('patients.allergies.allergen_type')}</TableCell>
                <TableCell>{t('patients.allergies.reaction')}</TableCell>
                <TableCell>{t('patients.allergies.severity')}</TableCell>
                <TableCell>{t('patients.allergies.notes')}</TableCell>
                <TableCell align="right" />
              </TableRow>
            </TableHead>
            <TableBody>
              {allergies.map((a) => (
                <TableRow key={a.id} sx={{ opacity: a.is_active ? 1 : 0.55 }}>
                  <TableCell>{a.allergen_name}</TableCell>
                  <TableCell>{t(`patients.allergies.types.${a.allergen_type}`)}</TableCell>
                  <TableCell>{a.reaction ?? '—'}</TableCell>
                  <TableCell><Chip size="small" color={severityColor(a.severity)} label={t(`patients.allergies.severity.${a.severity}`)} /></TableCell>
                  <TableCell>{a.notes ?? '—'}</TableCell>
                  <TableCell align="right">
                    {a.is_active ? (
                      canManage ? <Button size="small" color="inherit" onClick={() => deactivate(a)}>{t('patients.allergies.remove')}</Button> : null
                    ) : (
                      <Chip size="small" variant="outlined" label={t('patients.allergies.inactive')} />
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
        <DialogTitle>{t('patients.allergies.add')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: '8px !important' }}>
          <TextField select size="small" label={t('patients.allergies.allergen_type')} value={form.data.allergen_type} onChange={(e) => form.setData('allergen_type', e.target.value as AllergenType)} error={Boolean(form.errors.allergen_type)} helperText={form.errors.allergen_type}>
            {ALLERGEN_TYPES.map((x) => <MenuItem key={x} value={x}>{t(`patients.allergies.types.${x}`)}</MenuItem>)}
          </TextField>
          <TextField size="small" label={t('patients.allergies.allergen_name')} value={form.data.allergen_name} onChange={(e) => form.setData('allergen_name', e.target.value)} error={Boolean(form.errors.allergen_name)} helperText={form.errors.allergen_name} required autoFocus />
          {form.data.allergen_type === 'generic' ? (
            <TextField size="small" type="number" label={t('patients.allergies.catalog_id')} value={form.data.generic_id} onChange={(e) => form.setData('generic_id', e.target.value)} error={Boolean(form.errors.generic_id)} helperText={form.errors.generic_id} required />
          ) : null}
          {form.data.allergen_type === 'allergy_class' ? (
            <TextField size="small" type="number" label={t('patients.allergies.catalog_id')} value={form.data.allergy_class_id} onChange={(e) => form.setData('allergy_class_id', e.target.value)} error={Boolean(form.errors.allergy_class_id)} helperText={form.errors.allergy_class_id} required />
          ) : null}
          <TextField size="small" label={t('patients.allergies.reaction')} value={form.data.reaction} onChange={(e) => form.setData('reaction', e.target.value)} error={Boolean(form.errors.reaction)} helperText={form.errors.reaction} />
          <TextField select size="small" label={t('patients.allergies.severity')} value={form.data.severity} onChange={(e) => form.setData('severity', e.target.value as AllergySeverity)}>
            {SEVERITIES.map((x) => <MenuItem key={x} value={x}>{t(`patients.allergies.severity.${x}`)}</MenuItem>)}
          </TextField>
          <TextField size="small" label={t('patients.allergies.notes')} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} multiline minRows={2} />
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
