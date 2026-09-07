// Notifications/PushSubscriptions — which browsers this clinic can reach, plus the subscribe button that performs
// the service-worker half of the Web Push handshake (fetch the VAPID key → pushManager.subscribe → POST the
// endpoint). Nothing here can subscribe on someone else's behalf: the server binds the row to the signed-in user.
import { useEffect, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Typography from '@mui/material/Typography';
import NotificationsActiveIcon from '@mui/icons-material/NotificationsActive';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { fetchPushKey, subscribeToPush } from '@panel/api/notifications';
import { route } from '@shared/routes';
import { formatDhaka } from '@shared/format/date';
import { formatBn } from '@shared/format/number';
import type { PageProps } from '@shared/types/inertia';
import type { Locale } from '@shared/types/shared-props';
import type { PushSubscriptionRow } from '@shared/types/models';

type Props = PageProps<{
  subscriptions: PushSubscriptionRow[];
  vapid_configured: boolean;
}>;

/** base64url → Uint8Array, the form `applicationServerKey` requires. */
function decodeKey(base64Url: string): ArrayBuffer {
  const padded = base64Url.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(base64Url.length / 4) * 4, '=');
  const raw = window.atob(padded);
  const buffer = new ArrayBuffer(raw.length);
  const bytes = new Uint8Array(buffer);
  for (let i = 0; i < raw.length; i += 1) bytes[i] = raw.charCodeAt(i);
  return buffer;
}

function encodeKey(buffer: ArrayBuffer | null): string {
  if (buffer === null) return '';
  const bytes = new Uint8Array(buffer);
  let binary = '';
  bytes.forEach((byte) => {
    binary += String.fromCharCode(byte);
  });
  return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export default function PushSubscriptions({ subscriptions, vapid_configured }: Props) {
  const { t, i18n } = useTranslation();
  const locale: Locale = i18n.language === 'bn' ? 'bn' : 'en';
  const [state, setState] = useState<'idle' | 'busy' | 'subscribed' | 'blocked'>('idle');

  useEffect(() => {
    if (typeof window === 'undefined' || !('Notification' in window)) return;
    if (window.Notification.permission === 'denied') setState('blocked');
  }, []);

  const enable = async (): Promise<void> => {
    setState('busy');
    try {
      const permission = await window.Notification.requestPermission();
      if (permission !== 'granted') {
        setState('blocked');
        return;
      }

      const key = await fetchPushKey();
      const registration = await navigator.serviceWorker.ready;
      if (key === null) {
        setState('idle');
        return;
      }

      const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: decodeKey(key),
      });

      await subscribeToPush({
        endpoint: subscription.endpoint,
        keys: { p256dh: encodeKey(subscription.getKey('p256dh')), auth: encodeKey(subscription.getKey('auth')) },
      });

      setState('subscribed');
      router.reload({ only: ['subscriptions'] });
    } catch {
      setState('idle');
    }
  };

  return (
    <Stack spacing={2}>
      <Box>
        <Typography variant="h5">{t('notifications.push.title')}</Typography>
        <Typography variant="body2" color="text.secondary">
          {t('notifications.push.subtitle')}
        </Typography>
      </Box>

      {!vapid_configured ? <Alert severity="warning">{t('notifications.push.not_configured')}</Alert> : null}
      {state === 'blocked' ? <Alert severity="info">{t('notifications.push.blocked')}</Alert> : null}
      {state === 'subscribed' ? <Alert severity="success">{t('notifications.push.enabled')}</Alert> : null}

      {vapid_configured && state !== 'subscribed' && state !== 'blocked' ? (
        <Box>
          <Button variant="contained" startIcon={<NotificationsActiveIcon />} disabled={state === 'busy'} onClick={() => void enable()}>
            {t('notifications.push.enable')}
          </Button>
        </Box>
      ) : null}

      <Card>
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{t('notifications.push.col_device')}</TableCell>
                <TableCell>{t('notifications.push.col_owner')}</TableCell>
                <TableCell>{t('notifications.push.col_last_used')}</TableCell>
                <TableCell align="right">{t('notifications.push.col_failures')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {subscriptions.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={5}>
                    <Typography variant="body2" color="text.secondary" sx={{ py: 3, textAlign: 'center' }}>
                      {t('notifications.push.empty')}
                    </Typography>
                  </TableCell>
                </TableRow>
              ) : (
                subscriptions.map((row) => (
                  <TableRow key={row.id} hover>
                    <TableCell>
                      <Typography variant="body2">{row.endpoint_host}</Typography>
                      <Typography variant="caption" color="text.secondary">
                        {row.user_agent ?? row.endpoint_digest}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      {row.subscriber_type} #{formatBn(row.subscriber_id, locale)}
                    </TableCell>
                    <TableCell>{row.last_used_at ? formatDhaka(row.last_used_at, 'D MMM, h:mm a', locale) : '—'}</TableCell>
                    <TableCell align="right">{formatBn(row.failed_count, locale)}</TableCell>
                    <TableCell align="right">
                      <Button size="small" color="error" onClick={() => router.delete(route('panel.notifications.push.destroy', { subscription: row.id }))}>
                        {t('notifications.push.remove')}
                      </Button>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </Box>
      </Card>
    </Stack>
  );
}

PushSubscriptions.layout = (page: ReactNode) => <PanelLayout title="notifications.push.title">{page}</PanelLayout>;
