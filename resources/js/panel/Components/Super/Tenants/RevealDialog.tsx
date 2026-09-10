// The one-time hand-off. A temporary password or a set-password link exists on this screen and nowhere else —
// the server pulled it from the session and will not send it again — so the dialog says so, offers Copy, and
// refuses to be dismissed by a stray click outside.
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogContentText from '@mui/material/DialogContentText';
import DialogTitle from '@mui/material/DialogTitle';
import IconButton from '@mui/material/IconButton';
import Stack from '@mui/material/Stack';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import type { RevealPayload } from './types';

export interface RevealDialogProps {
  reveal: RevealPayload | null;
  onClose: () => void;
}

export function RevealDialog({ reveal, onClose }: RevealDialogProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [copied, setCopied] = useState(false);

  if (reveal === null) return null;

  const copy = (): void => {
    void navigator.clipboard?.writeText(reveal.value).then(() => {
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    }).catch(() => undefined);
  };

  return (
    <Dialog open onClose={(_event, reason) => { if (reason !== 'backdropClick') onClose(); }} fullWidth maxWidth="sm" data-testid="reveal-dialog">
      <DialogTitle>{t(reveal.kind === 'link' ? 'super.tenants.reveal.title_link' : 'super.tenants.reveal.title_password')}</DialogTitle>
      <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
        <DialogContentText>{t('super.tenants.reveal.body', { name: reveal.user_name, email: reveal.email })}</DialogContentText>
        <Stack direction="row" spacing={0.5} sx={{ alignItems: 'center' }}>
          <Box
            sx={{ fontFamily: 'monospace', fontSize: 15, bgcolor: 'action.hover', px: 1.5, py: 1, borderRadius: 1, overflowX: 'auto', flexGrow: 1, wordBreak: 'break-all' }}
            data-testid="reveal-value"
          >
            {reveal.value}
          </Box>
          <Tooltip title={copied ? t('super.actions.copied') : t('super.actions.copy')}>
            <IconButton onClick={copy} aria-label={t('super.actions.copy')}>
              <ContentCopyIcon fontSize="small" />
            </IconButton>
          </Tooltip>
        </Stack>
        {reveal.expires_at ? (
          <Typography variant="caption" color="text.secondary">{t('super.tenants.reveal.expires', { date: formatDhaka(reveal.expires_at, 'D MMM YYYY, h:mm a', locale) })}</Typography>
        ) : null}
        <Alert severity="warning">{t('super.tenants.reveal.once')}</Alert>
        <Typography variant="body2" color="text.secondary">{t('super.tenants.reveal.must_change')}</Typography>
      </DialogContent>
      <DialogActions>
        <Button variant="contained" onClick={onClose} data-testid="reveal-done">{t('super.tenants.reveal.done')}</Button>
      </DialogActions>
    </Dialog>
  );
}
