// The small desk dialogs: collect fee, cancel with a reason code, device registration, kiosk QR.
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Dialog from '@mui/material/Dialog';
import DialogTitle from '@mui/material/DialogTitle';
import DialogContent from '@mui/material/DialogContent';
import DialogActions from '@mui/material/DialogActions';
import Button from '@mui/material/Button';
import TextField from '@mui/material/TextField';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Alert from '@mui/material/Alert';
import Typography from '@mui/material/Typography';
import Box from '@mui/material/Box';
import { QRCodeSVG } from 'qrcode.react';
import { formatBdt, parseBdt } from '@shared/format/money';
import { getLocale } from '@shared/locale';
import type { DeskSerial } from '@shared/types/models';

// ---- collect fee ----------------------------------------------------------------------------------------------------

export interface CollectFeeDialogProps { open: boolean; serial: DeskSerial | null; offline: boolean; busy: boolean; error: string | null; onClose(): void; onCollect(amountPaisa: number, note: string | null): void }

export function CollectFeeDialog({ open, serial, offline, busy, error, onClose, onCollect }: CollectFeeDialogProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [amount, setAmount] = useState('');
  const [note, setNote] = useState('');
  useEffect(() => { if (open) { setAmount(serial?.appointment ? String(serial.appointment.fee_paisa / 100) : ''); setNote(''); } }, [open, serial]);
  const paisa = parseBdt(amount);
  return (
    <Dialog open={open} onClose={busy ? undefined : onClose} fullWidth maxWidth="xs" onKeyDown={(e) => { if (e.key === 'Enter' && paisa !== null) onCollect(paisa, note || null); }}>
      <DialogTitle>{t('reception.board.collect_fee')} — {serial?.display_code}</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          {offline ? <Alert severity="info">{t('reception.fee.offline_notice')}</Alert> : null}
          {error ? <Alert severity="error">{error}</Alert> : null}
          <Typography variant="body2">{t('reception.fee.due', { fee: formatBdt(serial?.appointment?.fee_paisa ?? 0, locale) })}</Typography>
          <TextField label={t('reception.fee.amount')} value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus inputMode="decimal" error={amount !== '' && paisa === null} />
          <TextField label={t('reception.fee.note')} value={note} onChange={(e) => setNote(e.target.value)} />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={busy}>{t('common.actions.cancel')}</Button>
        <Button variant="contained" disabled={busy || paisa === null} onClick={() => onCollect(paisa ?? 0, note || null)}>{t('reception.fee.collect')}</Button>
      </DialogActions>
    </Dialog>
  );
}

// ---- cancel with reason code ---------------------------------------------------------------------------------------

export const CANCEL_REASONS = ['patient_request', 'doctor_unavailable', 'duplicate', 'no_payment', 'other'] as const;

export interface CancelDialogProps { open: boolean; serial: DeskSerial | null; busy: boolean; error: string | null; onClose(): void; onCancel(reason: string, note: string | null): void }

export function CancelDialog({ open, serial, busy, error, onClose, onCancel }: CancelDialogProps) {
  const { t } = useTranslation();
  const [reason, setReason] = useState<string>('patient_request');
  const [note, setNote] = useState('');
  useEffect(() => { if (open) { setReason('patient_request'); setNote(''); } }, [open]);
  return (
    <Dialog open={open} onClose={busy ? undefined : onClose} fullWidth maxWidth="xs">
      <DialogTitle>{t('reception.cancel.title')} — {serial?.display_code}</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          {error ? <Alert severity="error">{error}</Alert> : null}
          <TextField select label={t('reception.cancel.reason')} value={reason} onChange={(e) => setReason(e.target.value)} autoFocus>
            {CANCEL_REASONS.map((r) => <MenuItem key={r} value={r}>{t(`reception.cancel.reasons.${r}`)}</MenuItem>)}
          </TextField>
          <TextField label={t('reception.fee.note')} value={note} onChange={(e) => setNote(e.target.value)} />
          {serial?.appointment?.payment_status === 'paid' ? <Alert severity="warning">{t('reception.cancel.refund_notice')}</Alert> : null}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={busy}>{t('common.actions.back')}</Button>
        <Button color="error" variant="contained" disabled={busy} onClick={() => onCancel(reason, note || null)}>{t('reception.cancel.confirm')}</Button>
      </DialogActions>
    </Dialog>
  );
}

// ---- device registration -------------------------------------------------------------------------------------------

export interface DeviceRegistrationDialogProps { open: boolean; busy: boolean; error: string | null; branchName: string; onClose(): void; onRegister(name: string): void }

export function DeviceRegistrationDialog({ open, busy, error, branchName, onClose, onRegister }: DeviceRegistrationDialogProps) {
  const { t } = useTranslation();
  const [name, setName] = useState('');
  useEffect(() => { if (open) setName(''); }, [open]);
  return (
    <Dialog open={open} onClose={busy ? undefined : onClose} fullWidth maxWidth="xs" onKeyDown={(e) => { if (e.key === 'Enter' && name.trim()) onRegister(name.trim()); }}>
      <DialogTitle>{t('reception.device.register_title')}</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          <Typography variant="body2" color="text.secondary">{t('reception.device.register_help', { branch: branchName })}</Typography>
          {error ? <Alert severity="error">{error}</Alert> : null}
          <TextField label={t('reception.device.name')} value={name} onChange={(e) => setName(e.target.value)} autoFocus placeholder={t('reception.device.name_placeholder')} />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={busy}>{t('common.actions.cancel')}</Button>
        <Button variant="contained" disabled={busy || name.trim() === ''} onClick={() => onRegister(name.trim())}>{t('reception.device.register')}</Button>
      </DialogActions>
    </Dialog>
  );
}

// ---- kiosk QR -------------------------------------------------------------------------------------------------------

export interface KioskQrDialogProps { open: boolean; url: string | null; hours: number; onClose(): void }

export function KioskQrDialog({ open, url, hours, onClose }: KioskQrDialogProps) {
  const { t } = useTranslation();
  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="xs">
      <DialogTitle>{t('reception.kiosk.title')}</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ alignItems: 'center', pt: 1 }}>
          <Typography variant="body2" color="text.secondary" sx={{ textAlign: 'center' }}>{t('reception.kiosk.help', { hours })}</Typography>
          {url ? <Box sx={{ p: 2, bgcolor: 'background.paper' }}><QRCodeSVG value={url} size={240} includeMargin /></Box> : <Typography>{t('common.loading')}</Typography>}
          {url ? <Typography variant="caption" sx={{ wordBreak: 'break-all' }}>{url}</Typography> : null}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={() => window.print()}>{t('common.actions.print')}</Button>
        <Button onClick={onClose} variant="contained">{t('common.actions.close')}</Button>
      </DialogActions>
    </Dialog>
  );
}
