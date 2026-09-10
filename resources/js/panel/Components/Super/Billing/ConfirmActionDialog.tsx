// One confirmation dialog for every lifecycle button on the billing desk. A destructive action asks for a typed
// reason because the reason is what the audit row carries and what support reads back; a benign one (end a
// trial, run dunning) only asks "are you sure" and says exactly what will happen.
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogContentText from '@mui/material/DialogContentText';
import DialogTitle from '@mui/material/DialogTitle';
import TextField from '@mui/material/TextField';
import type { ButtonProps } from '@mui/material/Button';

export interface ConfirmActionDialogProps {
  open: boolean;
  title: string;
  body: ReactNode;
  confirmLabel: string;
  color?: ButtonProps['color'];
  /** Ask for a typed reason and pass it to onConfirm; the button stays disabled until one is typed. */
  requireReason?: boolean;
  reasonLabel?: string;
  reasonHelp?: string;
  /** Anything else the caller wants inside the dialog (a checkbox, a warning). */
  children?: ReactNode;
  busy?: boolean;
  error?: string | null;
  onClose: () => void;
  onConfirm: (reason: string) => void;
}

export function ConfirmActionDialog({
  open, title, body, confirmLabel, color = 'primary', requireReason = false, reasonLabel, reasonHelp, children, busy = false, error = null, onClose, onConfirm,
}: ConfirmActionDialogProps) {
  const { t } = useTranslation();
  const [reason, setReason] = useState('');

  useEffect(() => { if (open) setReason(''); }, [open]);

  const ready = !busy && (!requireReason || reason.trim() !== '');
  const submit = (event: FormEvent): void => {
    event.preventDefault();
    if (ready) onConfirm(reason.trim());
  };

  return (
    <Dialog open={open} onClose={busy ? undefined : onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>{title}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          {typeof body === 'string' ? <DialogContentText>{body}</DialogContentText> : body}
          {requireReason ? (
            <TextField
              label={reasonLabel ?? t('super.tenants.reason')}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              helperText={error ?? reasonHelp ?? t('super.tenants.reason_help')}
              error={Boolean(error)}
              required
              autoFocus
              slotProps={{ htmlInput: { maxLength: 255 } }}
            />
          ) : error ? <DialogContentText color="error">{error}</DialogContentText> : null}
          {children}
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose} disabled={busy}>{t('super.actions.cancel')}</Button>
          <Button type="submit" variant="contained" color={color} disabled={!ready}>{confirmLabel}</Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}
