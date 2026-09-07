// The detail drawer: one card per delivery attempt with the sanitised request, the raw provider response, the
// error code and the latency. This is the screen an admin opens when a patient says "I never got the SMS" — so it
// shows exactly what left the building and exactly what the gateway said back.
import { useEffect, useState } from 'react';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import Drawer from '@mui/material/Drawer';
import IconButton from '@mui/material/IconButton';
import LinearProgress from '@mui/material/LinearProgress';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import CloseIcon from '@mui/icons-material/Close';
import { useTranslation } from 'react-i18next';
import { fetchNotification } from '@panel/api/notifications';
import { formatDhaka } from '@shared/format/date';
import { formatBn } from '@shared/format/number';
import type { Locale } from '@shared/types/shared-props';
import type { NotificationAttempt, NotificationRow } from '@shared/types/models';

interface Props {
  notificationId: number | null;
  locale: Locale;
  onClose: () => void;
}

const STATUS_COLOR: Record<string, 'default' | 'success' | 'error' | 'warning' | 'info'> = {
  sent: 'info',
  delivered: 'success',
  failed: 'warning',
  rejected: 'error',
};

export function AttemptDrawer({ notificationId, locale, onClose }: Props) {
  const { t } = useTranslation();
  const [row, setRow] = useState<NotificationRow | null>(null);
  const [attempts, setAttempts] = useState<NotificationAttempt[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(false);

  useEffect(() => {
    if (notificationId === null) return undefined;

    const controller = new AbortController();
    setBusy(true);
    setError(false);
    fetchNotification(notificationId, controller.signal)
      .then((detail) => {
        setRow(detail.data);
        setAttempts(detail.attempts);
      })
      .catch(() => setError(true))
      .finally(() => setBusy(false));

    return () => controller.abort();
  }, [notificationId]);

  return (
    <Drawer anchor="right" open={notificationId !== null} onClose={onClose} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 520 }, p: 2 } } }}>
      <Stack direction="row" sx={{ alignItems: 'center', justifyContent: 'space-between', mb: 1 }}>
        <Typography variant="h6">{t('notifications.detail.title')}</Typography>
        <IconButton onClick={onClose} aria-label={t('notifications.detail.close')} size="small">
          <CloseIcon fontSize="small" />
        </IconButton>
      </Stack>

      {busy ? <LinearProgress /> : null}
      {error ? <Alert severity="error">{t('common.status.error')}</Alert> : null}

      {row ? (
        <Stack spacing={1.5}>
          <Box>
            <Typography variant="overline" color="text.secondary">
              {t('notifications.detail.body')}
            </Typography>
            <Typography lang={row.locale} sx={{ whiteSpace: 'pre-wrap', fontSize: 15 }}>
              {row.body_redacted ? t('notifications.index.body_redacted') : row.body}
            </Typography>
          </Box>
          <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', gap: 0.5 }}>
            <Chip size="small" label={t(`notifications.event.${row.event_key}`)} />
            <Chip size="small" variant="outlined" label={t(`notifications.channel.${row.channel}`)} />
            <Chip size="small" variant="outlined" label={t(`notifications.status.${row.status}`)} />
            <Chip size="small" variant="outlined" label={row.recipient} />
          </Stack>
          <Divider />
        </Stack>
      ) : null}

      {!busy && attempts.length === 0 ? (
        <Alert severity="info" sx={{ mt: 2 }}>
          {t('notifications.detail.no_attempts')}
        </Alert>
      ) : null}

      <Stack spacing={1.5} sx={{ mt: 2 }}>
        {attempts.map((attempt) => (
          <Box key={attempt.id} sx={{ border: 1, borderColor: 'divider', borderRadius: 1, p: 1.5 }}>
            <Stack direction="row" spacing={1} sx={{ alignItems: 'center', mb: 1, flexWrap: 'wrap', gap: 0.5 }}>
              <Typography variant="subtitle2">{t('notifications.detail.attempt', { n: formatBn(attempt.attempt_no, locale) })}</Typography>
              <Chip size="small" color={STATUS_COLOR[attempt.status] ?? 'default'} label={attempt.status} />
              <Chip size="small" variant="outlined" label={`${t('notifications.detail.provider')}: ${attempt.provider}`} />
              {attempt.latency_ms !== null ? (
                <Chip size="small" variant="outlined" label={`${t('notifications.detail.latency')}: ${formatBn(attempt.latency_ms, locale)} ms`} />
              ) : null}
              <Typography variant="caption" color="text.secondary" sx={{ ml: 'auto' }}>
                {formatDhaka(attempt.created_at, 'D MMM, h:mm a', locale)}
              </Typography>
            </Stack>

            {attempt.error_code ? (
              <Alert severity={attempt.status === 'rejected' ? 'error' : 'warning'} sx={{ mb: 1 }}>
                {t('notifications.detail.error')}: {attempt.error_code}
              </Alert>
            ) : null}

            {attempt.provider_message_id ? (
              <Typography variant="caption" component="div" sx={{ fontFamily: 'monospace', mb: 1 }}>
                {t('notifications.detail.message_id')}: {attempt.provider_message_id}
              </Typography>
            ) : null}

            <Payload title={t('notifications.detail.request')} value={attempt.request} />
            <Payload title={t('notifications.detail.response')} value={attempt.response} />
          </Box>
        ))}
      </Stack>
    </Drawer>
  );
}

function Payload({ title, value }: { title: string; value: Record<string, unknown> | null }) {
  if (value === null) return null;

  return (
    <Box sx={{ mt: 1 }}>
      <Typography variant="overline" color="text.secondary">
        {title}
      </Typography>
      <Box
        component="pre"
        sx={{ m: 0, p: 1, bgcolor: 'action.hover', borderRadius: 1, fontSize: 12, overflowX: 'auto', maxHeight: 200 }}
      >
        {JSON.stringify(value, null, 2)}
      </Box>
    </Box>
  );
}
