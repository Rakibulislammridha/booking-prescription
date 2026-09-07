// Consents tab: the append-only consent / data-sharing log (SCHEMA §3.2) and the "record consent" dialog.
import { useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
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
import { formatDhaka } from '@shared/format/date';
import type { ConsentChannel, ConsentStatus, ConsentType, PatientConsent } from '@shared/types/models';
import type { Locale } from '@shared/types/shared-props';
import { CONSENT_CHANNELS, CONSENT_STATUSES, CONSENT_TYPES } from './labels';

export interface ConsentsTabProps {
  patient: string;
  consents: PatientConsent[];
  canRecord: boolean;
  policyVersion: string;
}

export function ConsentsTab({ patient, consents, canRecord, policyVersion }: ConsentsTabProps) {
  const { t, i18n } = useTranslation();
  const locale: Locale = i18n.language === 'bn' ? 'bn' : 'en';
  const [open, setOpen] = useState(false);
  const form = useForm<{ type: ConsentType; status: ConsentStatus; channel: ConsentChannel; policy_version: string; text_shown: string }>({
    type: 'sms', status: 'granted', channel: 'counter', policy_version: policyVersion, text_shown: '',
  });

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    form.post(route('panel.patients.consents.store', { patient }), { preserveScroll: true, onSuccess: () => { form.reset('text_shown'); setOpen(false); } });
  };

  return (
    <Stack spacing={2}>
      {canRecord ? <Button startIcon={<AddIcon />} variant="outlined" size="small" onClick={() => setOpen(true)} sx={{ alignSelf: 'flex-start' }}>{t('patients.consents.record')}</Button> : null}
      {consents.length === 0 ? (
        <Typography variant="body2" color="text.secondary">{t('patients.consents.empty')}</Typography>
      ) : (
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{t('patients.consents.type')}</TableCell>
                <TableCell>{t('patients.consents.status')}</TableCell>
                <TableCell>{t('patients.consents.channel')}</TableCell>
                <TableCell>{t('patients.consents.policy_version')}</TableCell>
                <TableCell>{t('patients.show.registered')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {consents.map((c) => (
                <TableRow key={c.id}>
                  <TableCell>{t(`patients.consents.types.${c.type}`)}</TableCell>
                  <TableCell><Chip size="small" color={c.status === 'granted' ? 'success' : 'default'} label={t(`patients.consents.status.${c.status}`)} /></TableCell>
                  <TableCell>{t(`patients.consents.channel.${c.channel}`)}</TableCell>
                  <TableCell>{c.policy_version}</TableCell>
                  <TableCell>{formatDhaka(c.occurred_at, 'D MMM YYYY, h:mm a', locale)}</TableCell>
                  <TableCell>{c.has_signature ? <Chip size="small" variant="outlined" label={t('patients.consents.signed')} /> : null}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
      )}

      <Dialog open={open} onClose={() => setOpen(false)} fullWidth maxWidth="sm">
        <form onSubmit={submit} noValidate>
        <DialogTitle>{t('patients.consents.record')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: '8px !important' }}>
          <TextField select size="small" label={t('patients.consents.type')} value={form.data.type} onChange={(e) => form.setData('type', e.target.value as ConsentType)} autoFocus>
            {CONSENT_TYPES.map((x) => <MenuItem key={x} value={x}>{t(`patients.consents.types.${x}`)}</MenuItem>)}
          </TextField>
          <TextField select size="small" label={t('patients.consents.status')} value={form.data.status} onChange={(e) => form.setData('status', e.target.value as ConsentStatus)}>
            {CONSENT_STATUSES.map((x) => <MenuItem key={x} value={x}>{t(`patients.consents.status.${x}`)}</MenuItem>)}
          </TextField>
          <TextField select size="small" label={t('patients.consents.channel')} value={form.data.channel} onChange={(e) => form.setData('channel', e.target.value as ConsentChannel)}>
            {CONSENT_CHANNELS.map((x) => <MenuItem key={x} value={x}>{t(`patients.consents.channel.${x}`)}</MenuItem>)}
          </TextField>
          <TextField size="small" label={t('patients.consents.policy_version')} value={form.data.policy_version} onChange={(e) => form.setData('policy_version', e.target.value)} error={Boolean(form.errors.policy_version)} helperText={form.errors.policy_version} required slotProps={{ htmlInput: { maxLength: 16 } }} />
          <TextField size="small" label={t('patients.consents.text_shown')} value={form.data.text_shown} onChange={(e) => form.setData('text_shown', e.target.value)} multiline minRows={2} />
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
