// Collect a counter payment against one invoice (BRIEF §5.I). Controlled and stateless about the request:
// the page owns `busy`/`error`, exactly like the desk's own dialogs.
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
import type { BillingInvoice, BillingPaymentMethod } from '@shared/types/models';

const METHODS: BillingPaymentMethod[] = ['cash', 'card', 'other'];

export interface CollectPaymentDialogProps {
  open: boolean;
  invoice: BillingInvoice | null;
  busy: boolean;
  error: string | null;
  onClose(): void;
  onCollect(amountPaisa: number, method: BillingPaymentMethod, note: string | null): void;
}

export function CollectPaymentDialog({ open, invoice, busy, error, onClose, onCollect }: CollectPaymentDialogProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState<BillingPaymentMethod>('cash');
  const [note, setNote] = useState('');

  useEffect(() => {
    if (open) {
      setAmount(invoice ? String(invoice.due_paisa / 100) : '');
      setMethod('cash');
      setNote('');
    }
  }, [open, invoice]);

  const paisa = parseBdt(amount);
  const due = invoice?.due_paisa ?? 0;
  // The server checks this again under the invoice row lock; this is only so the button reads honestly.
  const valid = paisa !== null && paisa > 0 && paisa <= due;

  return (
    <Dialog open={open} onClose={busy ? undefined : onClose} fullWidth maxWidth="xs" onKeyDown={(e) => { if (e.key === 'Enter' && valid && !busy) onCollect(paisa, method, note || null); }}>
      <DialogTitle>{t('billing.collect.title')}</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          {error ? <Alert severity="error">{error}</Alert> : null}
          <Typography variant="body2" color="text.secondary">
            {t('billing.collect.due', { amount: formatBdt(due, locale) })}
          </Typography>
          <TextField
            autoFocus
            label={t('billing.collect.amount')}
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            error={amount !== '' && !valid}
            helperText={amount !== '' && !valid ? t('billing.collect.amount_invalid') : ' '}
            slotProps={{ htmlInput: { inputMode: 'decimal', 'aria-label': t('billing.collect.amount') } }}
          />
          <TextField select label={t('billing.collect.method')} value={method} onChange={(e) => setMethod(e.target.value as BillingPaymentMethod)}>
            {METHODS.map((m) => <MenuItem key={m} value={m}>{t(`billing.method.${m}`)}</MenuItem>)}
          </TextField>
          <TextField label={t('billing.collect.note')} value={note} onChange={(e) => setNote(e.target.value)} slotProps={{ htmlInput: { maxLength: 255, lang: 'bn' } }} />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={busy}>{t('common.actions.cancel')}</Button>
        <Button variant="contained" disabled={busy || !valid} onClick={() => valid && onCollect(paisa, method, note || null)}>{t('billing.collect.submit')}</Button>
      </DialogActions>
    </Dialog>
  );
}
