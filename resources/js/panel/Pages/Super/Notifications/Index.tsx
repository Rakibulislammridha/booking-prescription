// Super/Notifications/Index — the platform's own notification defaults (BRIEF §5.J, seen from the platform):
// the outgoing email identity, the SMS gateway a clinic inherits until it enters its own, the platform → owner
// mail templates (subject/body, en + bn, placeholders, preview with sample data, reset to default), a test send
// through the real drivers, and the outbound ledger. Every value is a `notifications`-screen key of the platform
// registry, saved through the same audited action as the Platform settings page.
import { useState, type FormEvent, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import { previewTemplate } from '@panel/api/super';
import type { ConsoleMeta, PlatformMessageRow, PlatformSettingRow, TemplatePreview, TemplateRows } from '@panel/Components/Super/types';
import { isApiError } from '@shared/http';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  identity: PlatformSettingRow[];
  effective_identity: { name: string; address: string; reply_to: string };
  sms: PlatformSettingRow[];
  sms_summary: { provider: string; sender_id: string; configured: boolean };
  templates: TemplateRows;
  placeholders: Record<string, string[]>;
  admin_email: string | null;
  log: PlatformMessageRow[];
  log_meta: ConsoleMeta;
  log_filters: { kind: string; channel: string };
  log_kinds: string[];
}>;

type Values = Record<string, string | number | boolean | null>;

interface SettingsForm {
  values: Values;
  [key: string]: Values;
}

function initial(rows: PlatformSettingRow[]): Values {
  return Object.fromEntries(rows.map((row) => [row.key, row.secret ? '' : row.value]));
}

/** One card of registry rows saved together (`PUT notifications/settings {values}`). */
function SettingsCard({ title, help, rows, extra }: { title: string; help?: string; rows: PlatformSettingRow[]; extra?: ReactNode }) {
  const { t } = useTranslation();
  const form = useForm<SettingsForm>({ values: initial(rows) });
  const errors = form.errors as Record<string, string | undefined>;

  const submit = (e: FormEvent): void => {
    e.preventDefault();
    // A secret left blank means "keep": it is not sent at all, so the audit log records nothing for it.
    const values = Object.fromEntries(Object.entries(form.data.values).filter(([key, value]) => !(rows.find((r) => r.key === key)?.secret && value === '')));
    form.transform(() => ({ values }));
    form.put(route('super.notifications.update'), { preserveScroll: true });
  };

  return (
    <Card component="section" variant="outlined">
      <CardContent>
        <Typography variant="h6" component="h2" gutterBottom>{title}</Typography>
        {help ? <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{help}</Typography> : null}
        {extra}
        <Box component="form" onSubmit={submit} noValidate sx={{ display: 'grid', gap: 2, maxWidth: 640 }}>
          {rows.map((row) => (
            row.options ? (
              <TextField key={row.key} select size="small" label={row.label} value={String(form.data.values[row.key] ?? '')} onChange={(e) => form.setData('values', { ...form.data.values, [row.key]: e.target.value })} helperText={row.description}>
                {row.options.map((o) => <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>)}
              </TextField>
            ) : (
              <TextField
                key={row.key}
                size="small"
                type={row.secret ? 'password' : (row.input ?? 'text')}
                label={row.label}
                value={String(form.data.values[row.key] ?? '')}
                placeholder={row.secret && row.is_set ? String(row.value ?? '') : undefined}
                onChange={(e) => form.setData('values', { ...form.data.values, [row.key]: e.target.value })}
                helperText={errors[`values.${row.key}`] ?? (row.secret ? `${row.description} ${t('super.settings.secret_keep')}` : row.description)}
                error={Boolean(errors[`values.${row.key}`])}
                autoComplete={row.secret ? 'new-password' : 'off'}
                slotProps={{ htmlInput: { maxLength: 500 } }}
              />
            )
          ))}
          {errors.domain || errors.values ? <Alert severity="error">{errors.domain ?? errors.values}</Alert> : null}
          <Box>
            <Button type="submit" variant="contained" disabled={form.processing}>{t('super.notifications.save')}</Button>
          </Box>
        </Box>
      </CardContent>
    </Card>
  );
}

/** One template in one locale: subject + body, its placeholders, preview, reset. */
function TemplateEditor({ template, locale, rows, placeholders }: { template: string; locale: 'en' | 'bn'; rows: { subject: PlatformSettingRow; body: PlatformSettingRow }; placeholders: string[] }) {
  const { t } = useTranslation();
  const form = useForm<SettingsForm>({ values: { [rows.subject.key]: rows.subject.value, [rows.body.key]: rows.body.value } });
  const [preview, setPreview] = useState<TemplatePreview | null>(null);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const errors = form.errors as Record<string, string | undefined>;
  const isDefault = !rows.subject.is_set && !rows.body.is_set;

  const submit = (e: FormEvent): void => {
    e.preventDefault();
    form.put(route('super.notifications.update'), { preserveScroll: true });
  };

  const showPreview = (): void => {
    setPreviewError(null);
    previewTemplate(route('super.notifications.templates.preview'), { template, locale, subject: String(form.data.values[rows.subject.key] ?? ''), body: String(form.data.values[rows.body.key] ?? '') })
      .then(setPreview)
      .catch((err: unknown) => setPreviewError(isApiError(err) && err.message !== '' ? err.message : t('super.notifications.templates.preview_failed')));
  };

  const reset = (): void => {
    if (!window.confirm(t('super.notifications.templates.reset_confirm'))) return;
    router.delete(route('super.notifications.templates.reset', { key: rows.subject.key }), {
      preserveScroll: true,
      onSuccess: () => router.delete(route('super.notifications.templates.reset', { key: rows.body.key }), { preserveScroll: true }),
    });
  };

  return (
    <Box component="form" onSubmit={submit} noValidate sx={{ display: 'grid', gap: 1.5 }}>
      <Stack direction="row" spacing={0.5} useFlexGap sx={{ flexWrap: 'wrap', alignItems: 'center' }}>
        <Typography variant="caption" color="text.secondary">{t('super.notifications.templates.placeholders')}</Typography>
        {placeholders.map((p) => <Chip key={p} size="small" variant="outlined" label={`:${p}`} sx={{ fontFamily: 'monospace' }} />)}
        <Box sx={{ flexGrow: 1 }} />
        {isDefault ? <Chip size="small" label={t('super.settings.using_default')} /> : <Chip size="small" color="info" label={t('super.notifications.templates.customised')} />}
      </Stack>
      <TextField size="small" label={t('super.notifications.templates.subject')} value={String(form.data.values[rows.subject.key] ?? '')} onChange={(e) => form.setData('values', { ...form.data.values, [rows.subject.key]: e.target.value })} error={Boolean(errors[`values.${rows.subject.key}`])} helperText={errors[`values.${rows.subject.key}`]} slotProps={{ htmlInput: { lang: locale, maxLength: 200 } }} />
      <TextField size="small" multiline minRows={4} label={t('super.notifications.templates.body')} value={String(form.data.values[rows.body.key] ?? '')} onChange={(e) => form.setData('values', { ...form.data.values, [rows.body.key]: e.target.value })} error={Boolean(errors[`values.${rows.body.key}`])} helperText={errors[`values.${rows.body.key}`]} slotProps={{ htmlInput: { lang: locale, maxLength: 4000 } }} />
      {errors.domain ? <Alert severity="error">{errors.domain}</Alert> : null}
      <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap' }}>
        <Button type="submit" variant="contained" size="small" disabled={form.processing}>{t('super.notifications.save')}</Button>
        <Button variant="outlined" size="small" onClick={showPreview}>{t('super.notifications.templates.preview')}</Button>
        <Button size="small" color="warning" onClick={reset} disabled={isDefault}>{t('super.notifications.templates.reset')}</Button>
      </Stack>
      {previewError ? <Alert severity="error">{previewError}</Alert> : null}
      {preview ? (
        <Card variant="outlined" sx={{ bgcolor: 'action.hover' }}>
          <CardContent>
            <Typography variant="caption" color="text.secondary">{t('super.notifications.templates.preview_title')}</Typography>
            <Typography variant="subtitle2" lang={locale}>{preview.subject}</Typography>
            <Typography variant="body2" lang={locale} sx={{ whiteSpace: 'pre-wrap', mt: 1 }}>{preview.body}</Typography>
          </CardContent>
        </Card>
      ) : null}
    </Box>
  );
}

function TestSend({ adminEmail, smsConfigured }: { adminEmail: string | null; smsConfigured: boolean }) {
  const { t } = useTranslation();
  const form = useForm<{ channel: 'email' | 'sms'; recipient: string; message: string; [key: string]: string }>({ channel: 'email', recipient: adminEmail ?? '', message: '' });

  const submit = (e: FormEvent): void => {
    e.preventDefault();
    form.post(route('super.notifications.test'), { preserveScroll: true });
  };

  return (
    <Card component="section" variant="outlined">
      <CardContent>
        <Typography variant="h6" component="h2" gutterBottom>{t('super.notifications.test.title')}</Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t('super.notifications.test.help')}</Typography>
        <Box component="form" onSubmit={submit} noValidate sx={{ display: 'grid', gap: 2, maxWidth: 640 }}>
          <TextField select size="small" label={t('super.notifications.test.channel')} value={form.data.channel} onChange={(e) => { const channel = e.target.value as 'email' | 'sms'; form.setData({ ...form.data, channel, recipient: channel === 'email' ? (adminEmail ?? '') : '' }); }}>
            <MenuItem value="email">{t('super.notifications.test.email')}</MenuItem>
            <MenuItem value="sms" disabled={!smsConfigured}>{t('super.notifications.test.sms')}{smsConfigured ? '' : ` — ${t('super.notifications.test.sms_unconfigured')}`}</MenuItem>
          </TextField>
          <TextField size="small" type={form.data.channel === 'email' ? 'email' : 'tel'} label={form.data.channel === 'email' ? t('super.notifications.test.recipient_email') : t('super.notifications.test.recipient_mobile')} value={form.data.recipient} onChange={(e) => form.setData('recipient', e.target.value)} error={Boolean(form.errors.recipient)} helperText={form.errors.recipient} />
          <TextField size="small" multiline minRows={2} label={t('super.notifications.test.message')} value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} slotProps={{ htmlInput: { maxLength: 500 } }} />
          {(form.errors as Record<string, string | undefined>).domain ? <Alert severity="error">{(form.errors as Record<string, string | undefined>).domain}</Alert> : null}
          <Box>
            <Button type="submit" variant="contained" disabled={form.processing}>{t('super.notifications.test.send')}</Button>
          </Box>
        </Box>
      </CardContent>
    </Card>
  );
}

export default function Index({ identity, effective_identity, sms, sms_summary, templates, placeholders, admin_email, log, log_meta, log_filters, log_kinds }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [template, setTemplate] = useState<string>(Object.keys(templates)[0] ?? 'dunning');
  const [templateLocale, setTemplateLocale] = useState<'en' | 'bn'>('en');
  const n = (v: number): string => formatNumber(v, locale);

  const goLog = (params: { kind?: string; channel?: string; page?: number }): void => {
    const query: Record<string, string | number> = {};
    const kind = params.kind ?? log_filters.kind;
    const channel = params.channel ?? log_filters.channel;
    if (kind !== '') query.kind = kind;
    if (channel !== '') query.channel = channel;
    if (params.page !== undefined && params.page > 1) query.page = params.page;
    router.get(route('super.notifications.index'), query, { preserveState: true, replace: true, preserveScroll: true, only: ['log', 'log_meta', 'log_filters'] });
  };

  const logColumns: SuperColumn<PlatformMessageRow>[] = [
    { key: 'at', label: t('super.notifications.log.column.at'), render: (m) => (m.created_at ? formatDhaka(m.created_at, 'D MMM YYYY, h:mm a', locale) : '—') },
    { key: 'channel', label: t('super.notifications.log.column.channel'), render: (m) => <Chip size="small" variant="outlined" label={t(`super.notifications.test.${m.channel}`)} /> },
    { key: 'kind', label: t('super.notifications.log.column.kind'), render: (m) => t(`super.notifications.log.kind.${m.kind}`, { defaultValue: m.kind }) },
    { key: 'tenant', label: t('super.notifications.log.column.tenant'), bn: true, render: (m) => m.tenant?.name ?? '—' },
    { key: 'recipient', label: t('super.notifications.log.column.recipient'), render: (m) => <Box sx={{ fontFamily: 'monospace', fontSize: 12 }}>{m.recipient}</Box> },
    { key: 'subject', label: t('super.notifications.log.column.subject'), bn: true, render: (m) => m.subject ?? '—' },
    { key: 'status', label: t('super.notifications.log.column.status'), render: (m) => (<Stack direction="row" spacing={0.5} sx={{ alignItems: 'center' }}><Chip size="small" color={m.status === 'sent' ? 'success' : 'error'} variant="outlined" label={t(`super.notifications.log.status.${m.status}`)} />{m.error ? <Typography variant="caption" color="error.main">{m.error}</Typography> : null}</Stack>) },
    { key: 'by', label: t('super.notifications.log.column.by'), render: (m) => m.sent_by ?? t('super.notifications.log.system') },
  ];

  const current = templates[template];

  return (
    <Box>
      <SuperNav />

      <Stack spacing={3}>
        <Typography variant="body2" color="text.secondary">{t('super.notifications.intro')}</Typography>

        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', lg: '1fr 1fr' }, alignItems: 'start' }}>
          <SettingsCard
            title={t('super.notifications.identity.title')}
            help={t('super.notifications.identity.help')}
            rows={identity}
            extra={<Alert severity="info" sx={{ mb: 2 }}>{t('super.notifications.identity.effective', { name: effective_identity.name, address: effective_identity.address })}{effective_identity.reply_to ? ` · ${t('super.notifications.identity.reply_to', { address: effective_identity.reply_to })}` : ''}</Alert>}
          />
          <SettingsCard
            title={t('super.notifications.sms.title')}
            help={t('super.notifications.sms.help')}
            rows={sms}
            extra={<Alert severity={sms_summary.configured ? 'success' : 'warning'} sx={{ mb: 2 }}>{sms_summary.configured ? t('super.notifications.sms.configured', { provider: sms_summary.provider, sender: sms_summary.sender_id || '—' }) : t('super.notifications.sms.not_configured')}</Alert>}
          />
        </Box>

        <Card component="section" variant="outlined">
          <CardContent>
            <Typography variant="h6" component="h2" gutterBottom>{t('super.notifications.templates.title')}</Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>{t('super.notifications.templates.help')}</Typography>
            <Tabs value={template} onChange={(_e, v: string) => setTemplate(v)} variant="scrollable" scrollButtons="auto" sx={{ borderBottom: 1, borderColor: 'divider' }}>
              {Object.keys(templates).map((name) => <Tab key={name} value={name} sx={{ textTransform: 'none' }} label={t(`super.notifications.templates.names.${name}`, { defaultValue: name })} />)}
            </Tabs>
            <Tabs value={templateLocale} onChange={(_e, v: 'en' | 'bn') => setTemplateLocale(v)} sx={{ mb: 2 }}>
              <Tab value="en" label="English" sx={{ textTransform: 'none' }} />
              <Tab value="bn" label="বাংলা" sx={{ textTransform: 'none' }} lang="bn" />
            </Tabs>
            {current ? (
              <TemplateEditor
                key={`${template}:${templateLocale}:${current[templateLocale].subject.value}:${current[templateLocale].body.value}`}
                template={template}
                locale={templateLocale}
                rows={current[templateLocale]}
                placeholders={placeholders[template] ?? []}
              />
            ) : null}
          </CardContent>
        </Card>

        <TestSend adminEmail={admin_email} smsConfigured={sms_summary.configured} />

        <Card component="section" variant="outlined">
          <CardContent sx={{ pb: 0 }}>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} sx={{ alignItems: { sm: 'center' } }}>
              <Box sx={{ flexGrow: 1 }}>
                <Typography variant="h6" component="h2">{t('super.notifications.log.title')}</Typography>
                <Typography variant="caption" color="text.secondary">{t('super.notifications.log.help')}</Typography>
              </Box>
              <TextField select size="small" label={t('super.notifications.log.column.channel')} value={log_filters.channel} onChange={(e) => goLog({ channel: e.target.value, page: 1 })} sx={{ minWidth: 140 }}>
                <MenuItem value="">{t('super.reconciliation.filter.all')}</MenuItem>
                <MenuItem value="email">{t('super.notifications.test.email')}</MenuItem>
                <MenuItem value="sms">{t('super.notifications.test.sms')}</MenuItem>
              </TextField>
              <TextField select size="small" label={t('super.notifications.log.column.kind')} value={log_filters.kind} onChange={(e) => goLog({ kind: e.target.value, page: 1 })} sx={{ minWidth: 180 }}>
                <MenuItem value="">{t('super.reconciliation.filter.all')}</MenuItem>
                {log_kinds.map((k) => <MenuItem key={k} value={k}>{t(`super.notifications.log.kind.${k}`, { defaultValue: k })}</MenuItem>)}
              </TextField>
            </Stack>
          </CardContent>
          <SuperTable columns={logColumns} rows={log} rowKey={(m) => String(m.id)} empty={t('super.notifications.log.empty')} label={t('super.notifications.log.title')} />
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'flex-end', p: 1 }}>
            <Typography variant="caption" color="text.secondary">{t('super.tenants.page_of', { current: n(log_meta.current_page), last: n(log_meta.last_page), total: n(log_meta.total) })}</Typography>
            <Button size="small" startIcon={<ChevronLeftIcon />} disabled={log_meta.current_page <= 1} onClick={() => goLog({ page: log_meta.current_page - 1 })}>{t('super.actions.prev')}</Button>
            <Button size="small" endIcon={<ChevronRightIcon />} disabled={log_meta.current_page >= log_meta.last_page} onClick={() => goLog({ page: log_meta.current_page + 1 })}>{t('super.actions.next')}</Button>
          </Stack>
        </Card>
      </Stack>
    </Box>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="super.notifications.title">{page}</PanelLayout>;
