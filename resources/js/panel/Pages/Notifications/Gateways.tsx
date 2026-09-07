// Notifications/Gateways — per-tenant provider credentials with a "send test message" action.
// Credentials are write-only by design: the form shows which keys are stored, never their values, and a blank
// field on save keeps what is already encrypted in the row.
import { useState, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import AddIcon from '@mui/icons-material/Add';
import SendIcon from '@mui/icons-material/Send';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { SegmentMeter } from '@panel/Components/Notifications/SegmentMeter';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { Locale } from '@shared/types/shared-props';
import type { SmsGatewayRow } from '@shared/types/models';

type Props = PageProps<{
  gateways: SmsGatewayRow[];
  options: { channels: string[]; providers: Record<string, string[]> };
  can: { manage: boolean };
}>;

/** The credential fields each provider family expects; anything else takes the generic set. */
const GENERIC_FIELDS: string[] = ['url', 'api_key', 'username', 'password'];

const CREDENTIAL_FIELDS: Record<string, string[]> = {
  ssl_wireless: ['url', 'api_token', 'sid'],
  whatsapp_cloud: ['access_token', 'phone_number_id'],
};

function fieldsFor(provider: string): string[] {
  return CREDENTIAL_FIELDS[provider] ?? GENERIC_FIELDS;
}

export default function Gateways({ gateways, options, can }: Props) {
  const { t, i18n } = useTranslation();
  const locale: Locale = i18n.language === 'bn' ? 'bn' : 'en';
  const [editing, setEditing] = useState<SmsGatewayRow | null | 'new'>(null);
  const [testing, setTesting] = useState<SmsGatewayRow | null>(null);

  return (
    <Stack spacing={2}>
      <Stack direction="row" spacing={2} sx={{ alignItems: 'flex-start' }}>
        <Box sx={{ flexGrow: 1 }}>
          <Typography variant="h5">{t('notifications.gateways.title')}</Typography>
          <Typography variant="body2" color="text.secondary">
            {t('notifications.gateways.subtitle')}
          </Typography>
        </Box>
        {can.manage ? (
          <Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing('new')}>
            {t('notifications.gateways.add')}
          </Button>
        ) : null}
      </Stack>

      {gateways.length === 0 ? <Alert severity="info">{t('notifications.gateways.empty')}</Alert> : null}

      <Card>
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{t('notifications.gateways.name')}</TableCell>
                <TableCell>{t('notifications.gateways.channel')}</TableCell>
                <TableCell>{t('notifications.gateways.provider')}</TableCell>
                <TableCell>{t('notifications.gateways.sender_id')}</TableCell>
                <TableCell>{t('notifications.gateways.credentials')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {gateways.map((gateway) => (
                <TableRow key={gateway.id} hover>
                  <TableCell>
                    {gateway.name}
                    {gateway.is_default ? <Chip size="small" sx={{ ml: 1 }} color="primary" variant="outlined" label={t('notifications.gateways.is_default')} /> : null}
                    {!gateway.is_active ? <Chip size="small" sx={{ ml: 1 }} label={t('common.status.none')} /> : null}
                  </TableCell>
                  <TableCell>{t(`notifications.channel.${gateway.channel}`)}</TableCell>
                  <TableCell sx={{ fontFamily: 'monospace' }}>{gateway.provider}</TableCell>
                  <TableCell>{gateway.sender_id ?? '—'}</TableCell>
                  <TableCell>
                    <Stack direction="row" spacing={0.5} sx={{ flexWrap: 'wrap', gap: 0.5 }}>
                      {gateway.credential_keys.map((key) => (
                        <Chip key={key} size="small" variant="outlined" label={`${key}: ${t('notifications.gateways.credential_set')}`} />
                      ))}
                    </Stack>
                  </TableCell>
                  <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                    {can.manage ? (
                      <>
                        <Button size="small" startIcon={<SendIcon />} onClick={() => setTesting(gateway)}>
                          {t('notifications.gateways.test')}
                        </Button>
                        <Button size="small" onClick={() => setEditing(gateway)}>
                          {t('common.actions.edit')}
                        </Button>
                        <Button
                          size="small"
                          color="error"
                          onClick={() => {
                            if (window.confirm(t('notifications.gateways.delete_confirm'))) {
                              router.delete(route('panel.notifications.gateways.destroy', { gateway: gateway.id }));
                            }
                          }}
                        >
                          {t('notifications.gateways.delete')}
                        </Button>
                      </>
                    ) : null}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
      </Card>

      {editing !== null ? <GatewayForm gateway={editing === 'new' ? null : editing} options={options} onClose={() => setEditing(null)} /> : null}
      {testing !== null ? <TestDialog gateway={testing} locale={locale} onClose={() => setTesting(null)} /> : null}
    </Stack>
  );
}

function GatewayForm({ gateway, options, onClose }: { gateway: SmsGatewayRow | null; options: Props['options']; onClose: () => void }) {
  const { t } = useTranslation();
  const form = useForm({
    channel: (gateway?.channel ?? 'sms') as string,
    provider: (gateway?.provider ?? 'ssl_wireless') as string,
    name: gateway?.name ?? '',
    sender_id: gateway?.sender_id ?? '',
    priority: gateway?.priority ?? 10,
    is_default: gateway?.is_default ?? false,
    is_active: gateway?.is_active ?? true,
    options: { unicode: (gateway?.options?.unicode as boolean | undefined) ?? true },
    credentials: {} as Record<string, string>,
  });

  const providers = options.providers[form.data.channel] ?? [];

  const submit = (): void => {
    if (gateway) {
      form.patch(route('panel.notifications.gateways.update', { gateway: gateway.id }), { preserveScroll: true, onSuccess: onClose });
    } else {
      form.post(route('panel.notifications.gateways.store'), { preserveScroll: true, onSuccess: onClose });
    }
  };

  return (
    <Dialog open fullWidth maxWidth="sm" onClose={onClose}>
      <DialogTitle>{gateway ? gateway.name : t('notifications.gateways.add')}</DialogTitle>
      <DialogContent dividers>
        <Stack spacing={2}>
          <TextField label={t('notifications.gateways.name')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} error={Boolean(form.errors.name)} helperText={form.errors.name} size="small" fullWidth />

          <TextField
            select
            label={t('notifications.gateways.channel')}
            value={form.data.channel}
            onChange={(e) => form.setData('channel', e.target.value)}
            size="small"
            fullWidth
          >
            {options.channels.map((channel) => (
              <MenuItem key={channel} value={channel}>
                {t(`notifications.channel.${channel}`)}
              </MenuItem>
            ))}
          </TextField>

          <TextField select label={t('notifications.gateways.provider')} value={form.data.provider} onChange={(e) => form.setData('provider', e.target.value)} size="small" fullWidth>
            {providers.map((provider) => (
              <MenuItem key={provider} value={provider}>
                {provider}
              </MenuItem>
            ))}
          </TextField>

          <TextField label={t('notifications.gateways.sender_id')} value={form.data.sender_id ?? ''} onChange={(e) => form.setData('sender_id', e.target.value)} size="small" fullWidth />
          <TextField type="number" label={t('notifications.gateways.priority')} value={form.data.priority} onChange={(e) => form.setData('priority', Number(e.target.value))} size="small" fullWidth />

          <Box>
            <Typography variant="overline" color="text.secondary">
              {t('notifications.gateways.credentials')}
            </Typography>
            <Typography variant="caption" color="text.secondary" component="div" sx={{ mb: 1 }}>
              {t('notifications.gateways.credential_keep')}
            </Typography>
            <Stack spacing={1.5}>
              {fieldsFor(form.data.provider).map((field) => (
                <TextField
                  key={field}
                  label={field}
                  type={field.includes('password') || field.includes('token') || field.includes('key') ? 'password' : 'text'}
                  autoComplete="new-password"
                  value={form.data.credentials[field] ?? ''}
                  onChange={(e) => form.setData('credentials', { ...form.data.credentials, [field]: e.target.value })}
                  size="small"
                  fullWidth
                />
              ))}
            </Stack>
          </Box>

          <FormControlLabel control={<Switch checked={form.data.options.unicode} onChange={(e) => form.setData('options', { unicode: e.target.checked })} />} label={t('notifications.gateways.unicode')} />
          <FormControlLabel control={<Switch checked={form.data.is_default} onChange={(e) => form.setData('is_default', e.target.checked)} />} label={t('notifications.gateways.is_default')} />
          <FormControlLabel control={<Switch checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />} label={t('notifications.gateways.is_active')} />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>{t('common.actions.cancel')}</Button>
        <Button variant="contained" disabled={form.processing} onClick={submit}>
          {t('notifications.gateways.save')}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

function TestDialog({ gateway, locale, onClose }: { gateway: SmsGatewayRow; locale: Locale; onClose: () => void }) {
  const { t } = useTranslation();
  const form = useForm({ recipient: '', body: 'পরীক্ষামূলক বার্তা — test message' });

  return (
    <Dialog open fullWidth maxWidth="xs" onClose={onClose}>
      <DialogTitle>{t('notifications.gateways.test')}</DialogTitle>
      <DialogContent dividers>
        <Stack spacing={2}>
          <TextField
            label={t('notifications.gateways.test_recipient')}
            value={form.data.recipient}
            onChange={(e) => form.setData('recipient', e.target.value)}
            error={Boolean(form.errors.recipient)}
            helperText={form.errors.recipient}
            size="small"
            fullWidth
            autoFocus
          />
          <TextField label={t('notifications.gateways.test_body')} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} multiline minRows={3} size="small" fullWidth />
          <SegmentMeter body={form.data.body} locale={locale} />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>{t('common.actions.cancel')}</Button>
        <Button
          variant="contained"
          disabled={form.processing}
          onClick={() => form.post(route('panel.notifications.gateways.test', { gateway: gateway.id }), { preserveScroll: true, onSuccess: onClose })}
        >
          {t('notifications.gateways.test')}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

Gateways.layout = (page: ReactNode) => <PanelLayout title="notifications.gateways.title">{page}</PanelLayout>;
