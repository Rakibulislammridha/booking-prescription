// The doctor's device check, in MUI. Same report, same i18n keys and same failure taxonomy as the patient's card
// — only the widgets differ, because the two surfaces have different design systems.
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import type { PreflightReport } from '@site/Pages/Telemedicine/core/preflight';

const SEVERITY = { ready: 'success', 'no-camera': 'warning', idle: 'info', checking: 'info' } as const;

export interface PreflightPanelProps {
  report: PreflightReport;
  busy: boolean;
  onRun(video: boolean): void;
}

export function PreflightPanel({ report, busy, onRun }: PreflightPanelProps) {
  const { t } = useTranslation();
  const severity = (SEVERITY as Record<string, 'success' | 'warning' | 'info'>)[report.status] ?? 'error';
  const blocked = report.status !== 'idle' && report.status !== 'checking' && !report.canJoin;

  return (
    <Stack spacing={1} data-testid="preflight" data-status={report.status}>
      <Alert severity={severity} sx={{ py: 0.5 }}>
        <Typography variant="body2" data-testid="preflight-message">{t(report.messageKey)}</Typography>
        {report.status === 'ready' || report.status === 'no-camera' ? (
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
            {t('telemedicine.preflight.microphone')}: {report.hasMicrophone ? report.microphoneLabel || t('telemedicine.preflight.ok') : t('telemedicine.preflight.missing')}
            {' · '}
            {t('telemedicine.preflight.camera')}: {report.hasCamera ? report.cameraLabel || t('telemedicine.preflight.ok') : t('telemedicine.preflight.missing')}
          </Typography>
        ) : null}
      </Alert>
      <Stack direction="row" spacing={1}>
        <Button size="small" variant="outlined" disabled={busy} onClick={() => onRun(true)} data-testid="preflight-run">
          {busy ? t('telemedicine.preflight.checking_action') : report.status === 'idle' ? t('telemedicine.preflight.run') : t('telemedicine.preflight.retry')}
        </Button>
        {blocked ? (
          <Button size="small" disabled={busy} onClick={() => onRun(false)}>{t('telemedicine.preflight.audio_only')}</Button>
        ) : null}
      </Stack>
    </Stack>
  );
}
