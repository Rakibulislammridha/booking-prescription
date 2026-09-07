// Apply a discount (with a reason) or redeem a coupon on one invoice. Above the clinic's approval threshold the
// server refuses without an approver — the dialog surfaces that as a plain error rather than pretending.
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
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import type { DiscountReason, DiscountType } from '@shared/types/models';

const REASONS: DiscountReason[] = ['staff', 'poor_fund', 'followup', 'doctor_waiver', 'promo', 'other'];

export interface DiscountDialogProps {
  open: boolean;
  busy: boolean;
  error: string | null;
  onClose(): void;
  onDiscount(type: DiscountType, value: string, reason: DiscountReason, note: string | null): void;
  onCoupon(code: string): void;
}

export function DiscountDialog({ open, busy, error, onClose, onDiscount, onCoupon }: DiscountDialogProps) {
  const { t } = useTranslation();
  const [tab, setTab] = useState(0);
  const [type, setType] = useState<DiscountType>('fixed');
  const [value, setValue] = useState('');
  const [reason, setReason] = useState<DiscountReason>('poor_fund');
  const [note, setNote] = useState('');
  const [code, setCode] = useState('');

  useEffect(() => {
    if (open) {
      setTab(0);
      setType('fixed');
      setValue('');
      setReason('poor_fund');
      setNote('');
      setCode('');
    }
  }, [open]);

  const discountValid = value.trim() !== '' && Number(value) > 0;
  const couponValid = code.trim().length > 0;
  const submit = () => {
    if (tab === 0 && discountValid) onDiscount(type, value.trim(), reason, note || null);
    if (tab === 1 && couponValid) onCoupon(code.trim().toUpperCase());
  };

  return (
    <Dialog open={open} onClose={busy ? undefined : onClose} fullWidth maxWidth="xs" onKeyDown={(e) => { if (e.key === 'Enter' && !busy) submit(); }}>
      <DialogTitle>{t('billing.discount.title')}</DialogTitle>
      <DialogContent>
        <Tabs value={tab} onChange={(_, v: number) => setTab(v)} sx={{ mb: 2 }}>
          <Tab label={t('billing.discount.tab_discount')} />
          <Tab label={t('billing.discount.tab_coupon')} />
        </Tabs>
        <Stack spacing={2}>
          {error ? <Alert severity="error">{error}</Alert> : null}
          {tab === 0 ? (
            <>
              <TextField select label={t('billing.discount.type')} value={type} onChange={(e) => setType(e.target.value as DiscountType)}>
                <MenuItem value="fixed">{t('billing.discount.type_fixed')}</MenuItem>
                <MenuItem value="percentage">{t('billing.discount.type_percentage')}</MenuItem>
              </TextField>
              <TextField autoFocus label={type === 'fixed' ? t('billing.discount.value_taka') : t('billing.discount.value_percent')} value={value} onChange={(e) => setValue(e.target.value)} slotProps={{ htmlInput: { inputMode: 'decimal' } }} />
              <TextField select label={t('billing.discount.reason')} value={reason} onChange={(e) => setReason(e.target.value as DiscountReason)}>
                {REASONS.map((r) => <MenuItem key={r} value={r}>{t(`billing.discount_reason.${r}`)}</MenuItem>)}
              </TextField>
              <TextField label={t('billing.discount.note')} value={note} onChange={(e) => setNote(e.target.value)} slotProps={{ htmlInput: { maxLength: 255, lang: 'bn' } }} />
            </>
          ) : (
            <TextField autoFocus label={t('billing.discount.coupon_code')} value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} slotProps={{ htmlInput: { maxLength: 32, style: { textTransform: 'uppercase' } } }} />
          )}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={busy}>{t('common.actions.cancel')}</Button>
        <Button variant="contained" disabled={busy || (tab === 0 ? !discountValid : !couponValid)} onClick={submit}>{t('common.actions.save')}</Button>
      </DialogActions>
    </Dialog>
  );
}
