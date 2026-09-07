// Issue confirmation (§6.1): a last look at exactly what is about to be frozen. Warnings are listed for a one-click
// acknowledgement; criticals block until overridden (the strip does that); the non-overridable ones are terminal.
// "Issue & Print" is focused on open, so Ctrl+Enter ⏎ issues.
import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Divider from '@mui/material/Divider';
import FormControlLabel from '@mui/material/FormControlLabel';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import type { SafetyAlert } from '@shared/types/models';
import { drugLabel } from '@panel/lib/prescription/context';
import type { RxItemDraft } from '@panel/lib/prescription/store/writerStore';

export interface IssueDialogProps {
  open: boolean;
  busy: boolean;
  lang: 'bn' | 'en';
  items: RxItemDraft[];
  investigationCount: number;
  adviceCount: number;
  followUpOn: string | null;
  alerts: SafetyAlert[];
  acknowledged: string[];
  blocking: SafetyAlert[];
  handwriting: boolean;
  onAcknowledge: (fingerprint: string) => void;
  onClose: () => void;
  onIssue: (print: boolean) => void;
}

export function IssueDialog({ open, busy, lang, items, investigationCount, adviceCount, followUpOn, alerts, acknowledged, blocking, handwriting, onAcknowledge, onClose, onIssue }: IssueDialogProps) {
  const { t } = useTranslation();
  const printButton = useRef<HTMLButtonElement | null>(null);
  const warnings = alerts.filter((a) => a.severity === 'warning');
  const filled = items.filter((i) => i.drug !== null);

  useEffect(() => {
    if (open) window.setTimeout(() => printButton.current?.focus(), 0);
  }, [open]);

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle sx={{ py: 1 }}>{t('prescriptions.issue.confirm_title')}</DialogTitle>
      <DialogContent dividers>
        {blocking.length > 0 ? (
          <Alert severity="error" sx={{ mb: 1 }}>
            <Typography variant="body2" sx={{ fontWeight: 700 }}>
              {t('prescriptions.alerts.review_critical', { count: blocking.length })}
            </Typography>
            {blocking.map((alert) => (
              <Typography key={alert.fingerprint} variant="caption" component="div">
                {alert.title}
                {!alert.overridable ? ` — ${t('prescriptions.alerts.not_overridable')}` : ''}
              </Typography>
            ))}
          </Alert>
        ) : null}

        {handwriting ? (
          <Alert severity="warning" sx={{ mb: 1 }}>
            <Typography variant="caption">{t('prescriptions.issue.handwriting_not_checked')}</Typography>
          </Alert>
        ) : null}

        <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
          {t('prescriptions.writer.zones.rx')}
        </Typography>
        <Stack spacing={0.25} sx={{ mb: 1 }}>
          {filled.length === 0 ? (
            <Typography variant="caption" color="text.secondary">
              {t('common.status.none')}
            </Typography>
          ) : (
            filled.map((item, index) => (
              <Box key={item.key} sx={{ display: 'flex', gap: 1, alignItems: 'baseline' }}>
                <Typography variant="caption" color="text.secondary" sx={{ width: 14 }}>
                  {index + 1}
                </Typography>
                <Typography variant="body2" sx={{ fontWeight: 600, minWidth: 0 }}>
                  {drugLabel(item.drug)}
                </Typography>
                <Typography variant="caption" color="text.secondary" sx={{ flexGrow: 1 }}>
                  {item.display?.[lang].interpretation ?? item.shorthand}
                </Typography>
              </Box>
            ))
          )}
        </Stack>

        <Stack direction="row" spacing={1} sx={{ mb: 1 }}>
          <Chip size="small" variant="outlined" label={t('prescriptions.issue.summary_tests', { count: investigationCount })} />
          <Chip size="small" variant="outlined" label={t('prescriptions.issue.summary_advice', { count: adviceCount })} />
          {followUpOn !== null ? <Chip size="small" variant="outlined" color="primary" label={t('prescriptions.issue.summary_follow_up', { date: followUpOn })} /> : null}
        </Stack>

        {warnings.length > 0 ? (
          <>
            <Divider sx={{ my: 1 }} />
            <Typography variant="caption" sx={{ fontWeight: 700, color: 'warning.main' }}>
              {t('prescriptions.issue.acknowledge_warnings')}
            </Typography>
            {warnings.map((alert) => (
              <FormControlLabel
                key={alert.fingerprint}
                control={<Checkbox size="small" checked={acknowledged.includes(alert.fingerprint)} onChange={() => onAcknowledge(alert.fingerprint)} />}
                label={
                  <Typography variant="caption">
                    <strong>{alert.title}</strong> — {lang === 'bn' && alert.message_bn !== '' ? alert.message_bn : alert.message}
                  </Typography>
                }
                sx={{ display: 'flex', alignItems: 'flex-start' }}
              />
            ))}
          </>
        ) : null}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>{t('common.actions.cancel')}</Button>
        <Button disabled={busy || blocking.length > 0} onClick={() => onIssue(false)}>
          {t('prescriptions.issue.action')}
        </Button>
        <Button ref={printButton} variant="contained" disabled={busy || blocking.length > 0} onClick={() => onIssue(true)}>
          {t('prescriptions.issue.and_print')}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

export default IssueDialog;
