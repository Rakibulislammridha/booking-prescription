// Record the money that arrived against a platform invoice. The operator types TAKA; the wire carries integer
// paisa (parseBdt — never a float past this component). The reference is required because it is the idempotency
// key: the same transfer pasted twice settles the invoice once, and the second click is harmless.
import { useEffect, useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogContentText from '@mui/material/DialogContentText';
import DialogTitle from '@mui/material/DialogTitle';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import { formatBdt, parseBdt } from '@shared/format/money';
import { getLocale } from '@shared/locale';
import { paisaToTaka } from '@panel/lib/super/planForm';
import { MANUAL_METHODS, PAYMENT_METHOD_LABELS } from './InvoiceStatusChip';
import type { BillingInvoiceRow } from './types';

export interface RecordPaymentDialogProps {
  /** The invoice being paid; null closes the dialog. */
  invoice: BillingInvoiceRow | null;
  /** Where to POST: the platform-wide or the tenant-scoped pay route, resolved by the caller. */
  action: (invoice: BillingInvoiceRow) => string;
  onClose: () => void;
}

interface PaymentForm {
  amount_paisa: number | null;
  method: string;
  reference: string;
  note: string;
}

export function RecordPaymentDialog({ invoice, action, onClose }: RecordPaymentDialogProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const open = invoice !== null;
  const due = invoice === null ? 0 : Math.max(0, invoice.due_paisa);
  const [taka, setTaka] = useState('');
  const form = useForm<PaymentForm>({ amount_paisa: null, method: 'bank_transfer', reference: '', note: '' });

  useEffect(() => {
    if (!open) return;
    setTaka(paisaToTaka(due));
    form.setData({ amount_paisa: due, method: 'bank_transfer', reference: '', note: '' });
    form.clearErrors();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, due]);

  const paisa = form.data.amount_paisa ?? 0;
  const valid = paisa > 0 && paisa <= due && form.data.reference.trim() !== '';

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    if (invoice === null || !valid || form.processing) return;
    form.post(action(invoice), { preserveScroll: true, onSuccess: onClose });
  };

  return (
    <Dialog open={open} onClose={form.processing ? undefined : onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>{t('super.billing.pay_title')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <DialogContentText>{t('super.billing.pay_body', { number: invoice?.number ?? '', amount: formatBdt(due, locale) })}</DialogContentText>
          <TextField
            label={t('super.billing.amount_taka')}
            value={taka}
            onChange={(e) => { setTaka(e.target.value); form.setData('amount_paisa', parseBdt(e.target.value)); }}
            error={Boolean(form.errors.amount_paisa) || paisa > due}
            helperText={form.errors.amount_paisa ?? (paisa > due ? t('super.billing.amount_over_due') : t('super.billing.amount_help', { amount: formatBdt(paisa, locale) }))}
            required
            autoFocus
            slotProps={{ htmlInput: { inputMode: 'decimal', 'aria-label': t('super.billing.amount_taka') } }}
          />
          <TextField select label={t('super.billing.method')} value={form.data.method} onChange={(e) => form.setData('method', e.target.value)} error={Boolean(form.errors.method)} helperText={form.errors.method}>
            {MANUAL_METHODS.map((method) => (
              <MenuItem key={method} value={method}>{t(PAYMENT_METHOD_LABELS[method] ?? method)}</MenuItem>
            ))}
          </TextField>
          <TextField
            label={t('super.billing.reference')}
            value={form.data.reference}
            onChange={(e) => form.setData('reference', e.target.value)}
            error={Boolean(form.errors.reference)}
            helperText={form.errors.reference ?? t('super.billing.reference_help')}
            required
            slotProps={{ htmlInput: { maxLength: 64, spellCheck: false } }}
          />
          <TextField
            label={t('super.billing.note')}
            value={form.data.note}
            onChange={(e) => form.setData('note', e.target.value)}
            error={Boolean(form.errors.note)}
            helperText={form.errors.note}
            slotProps={{ htmlInput: { maxLength: 255 } }}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose} disabled={form.processing}>{t('super.actions.cancel')}</Button>
          <Button type="submit" variant="contained" disabled={form.processing || !valid}>{t('super.billing.record_payment')}</Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}
