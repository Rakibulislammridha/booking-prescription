// Notifications/Templates — the whole event × channel × language matrix in one table, each row either the clinic's
// own wording or the built-in default. The editor renders a live server preview with sample values and the Bangla
// segment counter, and refuses to save a placeholder the event does not define.
import { useEffect, useMemo, useState, type ReactNode } from 'react';
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
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { SegmentMeter } from '@panel/Components/Notifications/SegmentMeter';
import { previewTemplate } from '@panel/api/notifications';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { Locale } from '@shared/types/shared-props';
import type {
  NotificationChannel,
  NotificationEventKey,
  NotificationTemplatePreview,
  NotificationTemplateRow,
  SmsSegmentCount,
} from '@shared/types/models';

interface DefaultRow {
  event_key: NotificationEventKey;
  channel: NotificationChannel;
  locale: 'bn' | 'en';
  subject: string | null;
  body: string;
  preview: string;
  segments: SmsSegmentCount;
}

type Props = PageProps<{
  templates: NotificationTemplateRow[];
  defaults: DefaultRow[];
  catalogue: Record<string, string[]>;
  options: { channels: string[]; events: string[]; locales: string[] };
}>;

interface EditorState {
  event_key: NotificationEventKey;
  channel: NotificationChannel;
  locale: 'bn' | 'en';
  subject: string;
  body: string;
  provider_template_id: string;
  is_active: boolean;
  templateId: number | null;
}

export default function Templates({ templates, defaults, catalogue, options }: Props) {
  const { t, i18n } = useTranslation();
  const uiLocale: Locale = i18n.language === 'bn' ? 'bn' : 'en';
  const [editor, setEditor] = useState<EditorState | null>(null);
  const [channel, setChannel] = useState<string>('sms');
  const [locale, setLocale] = useState<string>('bn');

  const customised = useMemo(() => {
    const map = new Map<string, NotificationTemplateRow>();
    templates.forEach((row) => map.set(`${row.event_key}|${row.channel}|${row.locale}`, row));
    return map;
  }, [templates]);

  const rows = defaults.filter((row) => row.channel === channel && row.locale === locale);

  return (
    <Stack spacing={2}>
      <Box>
        <Typography variant="h5">{t('notifications.templates.title')}</Typography>
        <Typography variant="body2" color="text.secondary">
          {t('notifications.templates.subtitle')}
        </Typography>
      </Box>

      <Card sx={{ p: 2 }}>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5}>
          <TextField select size="small" label={t('notifications.index.filter_channel')} value={channel} onChange={(e) => setChannel(e.target.value)} slotProps={{ select: { native: true } }} sx={{ minWidth: 160 }}>
            {options.channels.map((value) => (
              <option key={value} value={value}>
                {t(`notifications.channel.${value}`)}
              </option>
            ))}
          </TextField>
          <TextField select size="small" label={t('notifications.templates.locale')} value={locale} onChange={(e) => setLocale(e.target.value)} slotProps={{ select: { native: true } }} sx={{ minWidth: 140 }}>
            {options.locales.map((value) => (
              <option key={value} value={value}>
                {value === 'bn' ? 'বাংলা' : 'English'}
              </option>
            ))}
          </TextField>
        </Stack>
      </Card>

      <Card>
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{t('notifications.index.col_event')}</TableCell>
                <TableCell>{t('notifications.templates.body')}</TableCell>
                <TableCell align="right">{t('notifications.segments.label')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {rows.map((row) => {
                const key = `${row.event_key}|${row.channel}|${row.locale}`;
                const own = customised.get(key);
                const body = own?.body ?? row.body;

                return (
                  <TableRow key={key} hover>
                    <TableCell sx={{ whiteSpace: 'nowrap' }}>
                      {t(`notifications.event.${row.event_key}`)}
                      <Chip size="small" sx={{ ml: 1 }} variant="outlined" color={own ? 'primary' : 'default'} label={own ? t('notifications.templates.customised') : t('notifications.templates.built_in')} />
                    </TableCell>
                    <TableCell lang={row.locale} sx={{ maxWidth: 460, fontSize: 13 }}>
                      {body}
                    </TableCell>
                    <TableCell align="right">{row.segments.segments}</TableCell>
                    <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                      <Button
                        size="small"
                        onClick={() =>
                          setEditor({
                            event_key: row.event_key,
                            channel: row.channel,
                            locale: row.locale,
                            subject: own?.subject ?? row.subject ?? '',
                            body,
                            provider_template_id: own?.provider_template_id ?? '',
                            is_active: own?.is_active ?? true,
                            templateId: own?.id ?? null,
                          })
                        }
                      >
                        {t('notifications.templates.edit')}
                      </Button>
                      {own?.id ? (
                        <Button size="small" color="warning" onClick={() => router.delete(route('panel.notifications.templates.destroy', { template: own.id as number }))}>
                          {t('notifications.templates.reset')}
                        </Button>
                      ) : null}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </Box>
      </Card>

      {editor ? <TemplateEditor state={editor} variables={catalogue[editor.event_key] ?? []} uiLocale={uiLocale} onClose={() => setEditor(null)} /> : null}
    </Stack>
  );
}

function TemplateEditor({
  state,
  variables,
  uiLocale,
  onClose,
}: {
  state: EditorState;
  variables: string[];
  uiLocale: Locale;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const form = useForm({
    event_key: state.event_key,
    channel: state.channel,
    locale: state.locale,
    subject: state.subject,
    body: state.body,
    provider_template_id: state.provider_template_id,
    is_active: state.is_active,
  });
  const [preview, setPreview] = useState<NotificationTemplatePreview | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    const timer = setTimeout(() => {
      previewTemplate(
        { event_key: form.data.event_key, channel: form.data.channel, locale: form.data.locale, body: form.data.body, subject: form.data.subject || null },
        controller.signal,
      )
        .then(setPreview)
        .catch(() => undefined);
    }, 300);

    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [form.data.body, form.data.subject, form.data.channel, form.data.locale, form.data.event_key]);

  const insert = (name: string): void => form.setData('body', `${form.data.body}{{${name}}}`);

  return (
    <Dialog open fullWidth maxWidth="md" onClose={onClose}>
      <DialogTitle>{t(`notifications.event.${state.event_key}`)}</DialogTitle>
      <DialogContent dividers>
        <Stack spacing={2}>
          {state.channel === 'email' || state.channel === 'push' ? (
            <TextField label={t('notifications.templates.subject')} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} error={Boolean(form.errors.subject)} helperText={form.errors.subject} fullWidth size="small" />
          ) : null}

          <TextField
            label={t('notifications.templates.body')}
            value={form.data.body}
            onChange={(e) => form.setData('body', e.target.value)}
            error={Boolean(form.errors.body)}
            helperText={form.errors.body}
            multiline
            minRows={4}
            fullWidth
            slotProps={{ htmlInput: { lang: state.locale } }}
          />

          <SegmentMeter body={preview?.body ?? form.data.body} locale={uiLocale} server={preview?.segments ?? null} />

          {preview && preview.unknown_placeholders.length > 0 ? (
            <Alert severity="error">{t('notifications.templates.unknown_placeholder', { names: preview.unknown_placeholders.join(', ') })}</Alert>
          ) : null}

          <Box>
            <Typography variant="overline" color="text.secondary">
              {t('notifications.templates.variables')}
            </Typography>
            <Stack direction="row" spacing={0.5} sx={{ flexWrap: 'wrap', gap: 0.5 }}>
              {variables.map((name) => (
                <Chip key={name} size="small" label={`{{${name}}}`} onClick={() => insert(name)} />
              ))}
            </Stack>
          </Box>

          {preview ? (
            <Box>
              <Typography variant="overline" color="text.secondary">
                {t('notifications.templates.preview')}
              </Typography>
              <Box sx={{ p: 1.5, bgcolor: 'action.hover', borderRadius: 1 }}>
                <Typography lang={state.locale} sx={{ whiteSpace: 'pre-wrap' }}>
                  {preview.body}
                </Typography>
              </Box>
            </Box>
          ) : null}

          {state.channel === 'whatsapp' || state.channel === 'ivr' ? (
            <TextField
              label={t('notifications.templates.provider_template_id')}
              helperText={t('notifications.templates.provider_template_help')}
              value={form.data.provider_template_id}
              onChange={(e) => form.setData('provider_template_id', e.target.value)}
              fullWidth
              size="small"
            />
          ) : null}

          <FormControlLabel control={<Switch checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />} label={t('notifications.templates.active')} />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>{t('common.actions.cancel')}</Button>
        <Button
          variant="contained"
          disabled={form.processing}
          onClick={() => form.post(route('panel.notifications.templates.store'), { preserveScroll: true, onSuccess: onClose })}
        >
          {t('notifications.templates.save')}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

Templates.layout = (page: ReactNode) => <PanelLayout title="notifications.templates.title">{page}</PanelLayout>;
