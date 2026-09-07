// Safety alerts (PRESCRIPTION.md §5.2). Grading drives everything: `info` is a grey line, `warning` an amber row
// that never blocks, `critical` a red row that turns the Issue button into "Review N critical" until it is
// overridden with a typed reason. Non-overridable codes (custom_brand.unlinked, catalog.ref_missing, parse.error)
// are terminal — no override control at all, but a concrete route to fix them.
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import AlertTitle from '@mui/material/AlertTitle';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Collapse from '@mui/material/Collapse';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import IconButton from '@mui/material/IconButton';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import ExpandLessIcon from '@mui/icons-material/ExpandLess';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import { hasRoute, route } from '@shared/routes';
import type { SafetyAlert } from '@shared/types/models';
import { OVERRIDE_MIN_REASON } from '@panel/lib/prescription/store/writerStore';

const SEVERITY_ORDER: Record<SafetyAlert['severity'], number> = { critical: 0, warning: 1, info: 2 };

export interface AlertsStripProps {
  alerts: SafetyAlert[];
  lang: 'bn' | 'en';
  focused: string | null;
  onOverride: (fingerprint: string, reason: string) => boolean;
  /** "Re-pick this drug" for a terminal catalog reference: clears the chip so the doctor chooses again. */
  onRepick: (itemKeys: string[]) => void;
  onFocusItem: (itemKey: string) => void;
}

export function AlertsStrip({ alerts, lang, focused, onOverride, onRepick, onFocusItem }: AlertsStripProps) {
  const { t } = useTranslation();
  // Density is the point: an info-only strip opens collapsed (one line), anything actionable opens expanded.
  const [collapsed, setCollapsed] = useState<boolean | null>(null);
  const [overriding, setOverriding] = useState<SafetyAlert | null>(null);
  const [reason, setReason] = useState('');

  const sorted = useMemo(() => [...alerts].sort((a, b) => SEVERITY_ORDER[a.severity] - SEVERITY_ORDER[b.severity]), [alerts]);
  const critical = sorted.filter((a) => a.severity === 'critical');
  const warnings = sorted.filter((a) => a.severity === 'warning');
  const infos = sorted.filter((a) => a.severity === 'info');
  const open = collapsed === null ? warnings.length > 0 : !collapsed;

  if (sorted.length === 0) return null;

  const submit = (): void => {
    if (overriding === null) return;
    if (onOverride(overriding.fingerprint, reason)) {
      setOverriding(null);
      setReason('');
    }
  };

  const severityOf = (alert: SafetyAlert): 'error' | 'warning' | 'info' => (alert.severity === 'critical' ? 'error' : alert.severity === 'warning' ? 'warning' : 'info');

  const row = (alert: SafetyAlert) => (
    <Alert
      key={alert.fingerprint}
      severity={severityOf(alert)}
      variant={alert.severity === 'critical' && alert.overridden === null ? 'filled' : 'standard'}
      data-testid={`alert-${alert.fingerprint}`}
      sx={{ py: 0.25, alignItems: 'flex-start', outline: focused === alert.fingerprint ? '2px solid' : 'none', outlineColor: 'primary.main' }}
      action={
        alert.severity !== 'info' && alert.overridden === null ? (
          alert.overridable ? (
            <Button
              size="small"
              color="inherit"
              onClick={() => {
                setOverriding(alert);
                setReason('');
              }}
            >
              {t('prescriptions.alerts.override')}
            </Button>
          ) : alert.key === 'custom_brand' && hasRoute('panel.catalog.custom-brands.index') ? (
            <Button size="small" color="inherit" href={route('panel.catalog.custom-brands.index')}>
              {t('prescriptions.alerts.fix_custom_brand')}
            </Button>
          ) : (
            <Button size="small" color="inherit" onClick={() => onRepick(alert.item_keys)}>
              {t('prescriptions.alerts.repick_drug')}
            </Button>
          )
        ) : null
      }
    >
      <AlertTitle sx={{ fontSize: 13, mb: 0 }}>{alert.title}</AlertTitle>
      <Typography variant="caption" component="div">
        {lang === 'bn' && alert.message_bn !== '' ? alert.message_bn : alert.message}
      </Typography>
      {alert.overridden !== null ? (
        <Typography variant="caption" component="div" sx={{ fontStyle: 'italic' }}>
          {t('prescriptions.alerts.overridden_with', { reason: alert.overridden.reason })}
        </Typography>
      ) : null}
      {!alert.overridable && alert.severity === 'critical' ? (
        <Typography variant="caption" component="div" sx={{ fontWeight: 700 }}>
          {t('prescriptions.alerts.not_overridable')}
        </Typography>
      ) : null}
      {alert.item_keys.length > 0 ? (
        <Stack direction="row" spacing={0.5} sx={{ mt: 0.25 }}>
          {alert.item_keys.map((key) => (
            <Chip key={key} size="small" variant="outlined" sx={{ height: 18, fontSize: 11 }} label={t('prescriptions.alerts.go_to_line')} onClick={() => onFocusItem(key)} />
          ))}
        </Stack>
      ) : null}
    </Alert>
  );

  return (
    <Paper variant="outlined" sx={{ position: 'sticky', top: 0, zIndex: 3, mb: 1, borderColor: critical.length > 0 ? 'error.main' : 'divider' }}>
      <Box sx={{ display: 'flex', alignItems: 'center', px: 1, py: 0.25, gap: 1 }}>
        <Typography variant="caption" sx={{ fontWeight: 700 }}>
          {t('prescriptions.alerts.title')}
        </Typography>
        {critical.length > 0 ? <Chip size="small" color="error" label={t('prescriptions.alerts.count_critical', { count: critical.length })} sx={{ height: 18, fontSize: 11 }} /> : null}
        {warnings.length > 0 ? <Chip size="small" color="warning" label={t('prescriptions.alerts.count_warning', { count: warnings.length })} sx={{ height: 18, fontSize: 11 }} /> : null}
        {infos.length > 0 ? <Chip size="small" variant="outlined" label={t('prescriptions.alerts.count_info', { count: infos.length })} sx={{ height: 18, fontSize: 11 }} /> : null}
        <Box sx={{ flexGrow: 1 }} />
        <IconButton size="small" onClick={() => setCollapsed(open)} aria-label={t('prescriptions.alerts.toggle')} aria-expanded={open}>
          {open ? <ExpandLessIcon fontSize="inherit" /> : <ExpandMoreIcon fontSize="inherit" />}
        </IconButton>
      </Box>

      {/* Critical alerts are never hidden by the collapse — they are what stops the Issue button. */}
      <Stack spacing={0.25} sx={{ px: 0.5, pb: critical.length > 0 ? 0.5 : 0 }}>{critical.map(row)}</Stack>
      <Collapse in={open}>
        <Stack spacing={0.25} sx={{ px: 0.5, pb: 0.5 }}>
          {warnings.map(row)}
          {infos.map(row)}
        </Stack>
      </Collapse>

      <Dialog open={overriding !== null} onClose={() => setOverriding(null)} maxWidth="sm" fullWidth>
        <DialogTitle>{overriding?.title}</DialogTitle>
        <DialogContent>
          <Typography variant="body2" sx={{ mb: 2 }}>
            {overriding !== null && lang === 'bn' && overriding.message_bn !== '' ? overriding.message_bn : overriding?.message}
          </Typography>
          <TextField
            autoFocus
            fullWidth
            multiline
            minRows={2}
            label={t('prescriptions.alerts.override_reason')}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            error={reason.length > 0 && reason.trim().length < OVERRIDE_MIN_REASON}
            helperText={t('prescriptions.alerts.override_hint')}
            slotProps={{ htmlInput: { 'aria-label': t('prescriptions.alerts.override_reason'), maxLength: 500 } }}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setOverriding(null)}>{t('common.actions.cancel')}</Button>
          <Button variant="contained" color="error" disabled={reason.trim().length < OVERRIDE_MIN_REASON} onClick={submit}>
            {t('prescriptions.alerts.override')}
          </Button>
        </DialogActions>
      </Dialog>
    </Paper>
  );
}

export default AlertsStrip;
