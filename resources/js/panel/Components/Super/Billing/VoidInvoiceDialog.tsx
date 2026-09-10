// Void an unpaid invoice. Paid or partly paid ones cannot be voided (a correction is a new row — the server
// refuses and the reason lands in `errors.domain`); the typed reason is what the audit row carries.
import { useEffect, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogContentText from '@mui/material/DialogContentText';
import DialogTitle from '@mui/material/DialogTitle';
import TextField from '@mui/material/TextField';
import type { BillingInvoiceRow } from './types';

export interface VoidInvoiceDialogProps {
  invoice: BillingInvoiceRow | null;
  action: (invoice: BillingInvoiceRow) => string;
  onClose: () => void;
}

export function VoidInvoiceDialog({ invoice, action, onClose }: VoidInvoiceDialogProps) {
  const { t } = useTranslation();
  const form = useForm({ reason: '' });
  const open = invoice !== null;

  useEffect(() => { if (open) { form.setData('reason', ''); form.clearErrors(); } /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [open]);

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    if (invoice === null || form.data.reason.trim() === '') return;
    form.post(action(invoice), { preserveScroll: true, onSuccess: onClose });
  };

  return (
    <Dialog open={open} onClose={form.processing ? undefined : onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>{t('super.billing.void_title')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <DialogContentText>{t('super.billing.void_body', { number: invoice?.number ?? '' })}</DialogContentText>
          <TextField
            label={t('super.tenants.reason')}
            value={form.data.reason}
            onChange={(e) => form.setData('reason', e.target.value)}
            error={Boolean(form.errors.reason)}
            helperText={form.errors.reason ?? t('super.tenants.reason_help')}
            required
            autoFocus
            slotProps={{ htmlInput: { maxLength: 255 } }}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose} disabled={form.processing}>{t('super.actions.cancel')}</Button>
          <Button type="submit" color="error" variant="contained" disabled={form.processing || form.data.reason.trim() === ''}>{t('super.billing.void')}</Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}
