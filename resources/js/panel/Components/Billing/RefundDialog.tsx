// Refund one payment with a reason code (BRIEF §5.F). The amount defaults to everything still refundable and
// can never be raised above it — the server enforces the same bound under the payment row lock.
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { formatBdt, parseBdt } from '@shared/format/money';
import { getLocale } from '@shared/locale';
import type { BillingPayment, RefundReason } from '@shared/types/models';

const REASONS: RefundReason[] = ['doctor_absent', 'patient_cancelled', 'duplicate', 'service_not_rendered', 'goodwill', 'other'];

export interface RefundDialogProps {
  open: boolean;
  payment: BillingPayment | null;
  busy: boolean;
  error: string | null;
  explanation: string | null;
  onClose(): void;
  onRefund(amountPaisa: number, reason: RefundReason, note: string | null): void;
}

export function RefundDialog({ open, payment, busy, error, explanation, onClose, onRefund }: RefundDialogProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState<RefundReason>('patient_cancelled');
  const [note, setNote] = useState('');

  useEffect(() => {
    if (open) {
      setAmount(payment ? String(payment.refundable_paisa / 100) : '');
      setReason('patient_cancelled');
      setNote('');
    }
  }, [open, payment]);

  const paisa = parseBdt(amount);
  const max = payment?.refundable_paisa ?? 0;
  const valid = paisa !== null && paisa > 0 && paisa <= max;

  return (
    <Dialog open={open} onClose={busy ? undefined : onClose} fullWidth maxWidth="xs" onKeyDown={(e) => { if (e.key === 'Enter' && valid && !busy) onRefund(paisa, reason, note || null); }}>
      <DialogTitle>{t('billing.refund.title')}</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          {error ? <Alert severity="error">{error}</Alert> : null}
          {explanation ? <Alert severity="info">{explanation}</Alert> : null}
          <Typography variant="body2" color="text.secondary">
            {t('billing.refund.refundable', { amount: formatBdt(max, locale) })}
          </Typography>
          <TextField
            autoFocus
            label={t('billing.refund.amount')}
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            error={amount !== '' && !valid}
            helperText={amount !== '' && !valid ? t('billing.refund.amount_invalid') : ' '}
            slotProps={{ htmlInput: { inputMode: 'decimal', 'aria-label': t('billing.refund.amount') } }}
          />
          <TextField select label={t('billing.refund.reason')} value={reason} onChange={(e) => setReason(e.target.value as RefundReason)}>
            {REASONS.map((r) => <MenuItem key={r} value={r}>{t(`billing.refund_reason.${r}`)}</MenuItem>)}
          </TextField>
          <TextField label={t('billing.refund.note')} value={note} onChange={(e) => setNote(e.target.value)} slotProps={{ htmlInput: { maxLength: 255, lang: 'bn' } }} />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={busy}>{t('common.actions.cancel')}</Button>
        <Button variant="contained" color="error" disabled={busy || !valid} onClick={() => valid && onRefund(paisa, reason, note || null)}>{t('billing.refund.submit')}</Button>
      </DialogActions>
    </Dialog>
  );
}
