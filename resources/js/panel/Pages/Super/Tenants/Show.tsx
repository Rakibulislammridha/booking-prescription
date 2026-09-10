// One clinic, in full — and every operational action the platform team has over it.
//
// Six tabs rather than one long page, because the six jobs are genuinely separate: looking at the account,
// negotiating an exception, chasing money, fixing DNS, moving data, and reading what was done. Everything
// destructive (suspend, cancel, void, restore) sits behind a dialog that asks for a typed reason, because the
// reason is what the audit row will carry and "why is this clinic suspended" is the question support gets asked.
import { lazy, useEffect, useState, type FormEvent, type ReactNode, type SyntheticEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Checkbox from '@mui/material/Checkbox';
import Chip from '@mui/material/Chip';
import Collapse from '@mui/material/Collapse';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogContentText from '@mui/material/DialogContentText';
import DialogTitle from '@mui/material/DialogTitle';
import Divider from '@mui/material/Divider';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import Link from '@mui/material/Link';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutlined';
import DownloadIcon from '@mui/icons-material/Download';
import EditIcon from '@mui/icons-material/Edit';
import LaunchIcon from '@mui/icons-material/Launch';
import LoginIcon from '@mui/icons-material/Login';
import RestartAltIcon from '@mui/icons-material/RestartAlt';
import StarIcon from '@mui/icons-material/Star';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { LazyChart } from '@panel/Components/Charts/LazyChart';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import { StatusChip, HealthChip } from '@panel/Components/Super/StatusChip';
import { UsageBars, bytesParts } from '@panel/Components/Super/UsageBars';
import { DeleteTenantCard } from '@panel/Components/Super/Tenants/DeleteTenantCard';
import { ChangePlanDialog, ReactivateDialog } from '@panel/Components/Super/Tenants/LifecycleDialogs';
import { RevealDialog } from '@panel/Components/Super/Tenants/RevealDialog';
import { StaffTab } from '@panel/Components/Super/Tenants/StaffTab';
import { TenantBillingCard } from '@panel/Components/Super/TenantBillingCard';
import type { TenantBilling } from '@panel/Components/Super/Billing/types';
import type {
  ConsoleDomainRow, ConsoleTenantDetail, DeletionState, RevealPayload, StaffRow,
} from '@panel/Components/Super/Tenants/types';
import type {
  AuditTenantRef, BackupRow, InvoiceRow, SuperPlan, TenantAuditEntry, TenantDetail, UsagePoint,
} from '@panel/Components/Super/types';
import { useSharedProps } from '@shared/inertia';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { Locale } from '@shared/types/shared-props';

type Props = PageProps<{
  tenant: ConsoleTenantDetail;
  plans: SuperPlan[];
  feature_labels: Record<string, string>;
  metric_labels: Record<string, string>;
  toggles: string[];
  limit_keys: string[];
  history: { appointments: UsagePoint[]; sms_credits: UsagePoint[]; prescriptions: UsagePoint[] };
  invoices: InvoiceRow[];
  billing: TenantBilling;
  domains: ConsoleDomainRow[];
  backups: BackupRow[];
  audit: TenantAuditEntry[];
  staff: StaffRow[];
  roles: string[];
  reveal: RevealPayload | null;
  deletion: DeletionState;
  links: { panel: string };
}>;

type TabKey = 'overview' | 'entitlements' | 'billing' | 'domains' | 'staff' | 'data' | 'audit';

const SSL_TONE: Record<ConsoleDomainRow['ssl_status'], 'default' | 'warning' | 'success' | 'error'> = {
  none: 'default', pending: 'warning', issued: 'success', failed: 'error',
};

const DATE = 'D MMM YYYY';
const DATETIME = 'D MMM YYYY, h:mm a';

function when(iso: string | null, locale: Locale, format = DATE): string {
  return iso === null ? '—' : formatDhaka(iso, format, locale);
}

/** A label above its value — the console's whole layout vocabulary for "here is a fact". */
function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <Box sx={{ minWidth: 0 }}>
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{label}</Typography>
      <Typography variant="body2" component="div" sx={{ wordBreak: 'break-word' }}>{children}</Typography>
    </Box>
  );
}

function CopyBlock({ label, value }: { label: string; value: string }) {
  const { t } = useTranslation();
  const [copied, setCopied] = useState(false);

  const copy = (): void => {
    void navigator.clipboard?.writeText(value).then(() => {
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    }).catch(() => undefined);
  };

  return (
    <Box>
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{label}</Typography>
      <Stack direction="row" spacing={0.5} sx={{ alignItems: 'center' }}>
        <Box sx={{ fontFamily: 'monospace', fontSize: 13, bgcolor: 'action.hover', px: 1, py: 0.5, borderRadius: 1, overflowX: 'auto', flexGrow: 1 }}>
          {value}
        </Box>
        <Tooltip title={copied ? t('super.actions.copied') : t('super.actions.copy')}>
          <IconButton size="small" onClick={copy} aria-label={t('super.actions.copy')}>
            <ContentCopyIcon fontSize="small" />
          </IconButton>
        </Tooltip>
      </Stack>
    </Box>
  );
}

function JsonBlock({ label, value }: { label: string; value: unknown }) {
  if (value === null || value === undefined) return null;

  return (
    <Box sx={{ mt: 0.5 }}>
      <Typography variant="caption" color="text.secondary">{label}</Typography>
      <Box
        component="pre"
        sx={{ m: 0, p: 1, bgcolor: 'action.hover', borderRadius: 1, fontSize: 11, overflowX: 'auto', maxHeight: 240 }}
      >
        {JSON.stringify(value, null, 2)}
      </Box>
    </Box>
  );
}

// ── Destructive dialogs ─────────────────────────────────────────────────────────────────────────────────

function SuspendDialog({ open, tenant, onClose }: { open: boolean; tenant: TenantDetail; onClose: () => void }) {
  const { t } = useTranslation();
  const form = useForm({ reason: '' });

  useEffect(() => { if (open) { form.setData('reason', ''); form.clearErrors(); } /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [open]);

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    form.post(route('super.tenants.suspend', { tenant: tenant.public_id }), { preserveScroll: true, onSuccess: onClose });
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>{t('super.tenants.suspend_title')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <DialogContentText>{t('super.tenants.suspend_body', { name: tenant.name })}</DialogContentText>
          <TextField
            label={t('super.tenants.reason')}
            value={form.data.reason}
            onChange={(e) => form.setData('reason', e.target.value)}
            error={Boolean(form.errors.reason)}
            helperText={form.errors.reason ?? t('super.tenants.reason_help')}
            required
            autoFocus
            multiline
            minRows={2}
            slotProps={{ htmlInput: { maxLength: 255 } }}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>{t('super.actions.cancel')}</Button>
          <Button type="submit" color="error" variant="contained" disabled={form.processing || form.data.reason.trim() === ''}>
            {t('super.tenants.suspend')}
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}

function CancelDialog({ open, tenant, onClose }: { open: boolean; tenant: TenantDetail; onClose: () => void }) {
  const { t } = useTranslation();
  const form = useForm({ reason: '', immediately: false });

  useEffect(() => { if (open) { form.setData({ reason: '', immediately: false }); form.clearErrors(); } /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [open]);

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    form.post(route('super.tenants.cancel', { tenant: tenant.public_id }), { preserveScroll: true, onSuccess: onClose });
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>{t('super.tenants.cancel_title')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <DialogContentText>{t('super.tenants.cancel_body', { name: tenant.name })}</DialogContentText>
          <TextField
            label={t('super.tenants.reason')}
            value={form.data.reason}
            onChange={(e) => form.setData('reason', e.target.value)}
            error={Boolean(form.errors.reason)}
            helperText={form.errors.reason}
            required
            autoFocus
            multiline
            minRows={2}
            slotProps={{ htmlInput: { maxLength: 255 } }}
          />
          <FormControlLabel
            control={<Checkbox checked={form.data.immediately} onChange={(e) => form.setData('immediately', e.target.checked)} />}
            label={t('super.tenants.cancel_immediately')}
          />
          <Typography variant="caption" color="text.secondary">{t('super.tenants.cancel_immediately_help')}</Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>{t('super.actions.close')}</Button>
          <Button type="submit" color="error" variant="contained" disabled={form.processing || form.data.reason.trim() === ''}>
            {t('super.tenants.cancel_subscription')}
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}

function RestoreDialog({ tenant, backup, onClose }: { tenant: TenantDetail; backup: BackupRow | null; onClose: () => void }) {
  const { t } = useTranslation();
  const open = backup !== null;
  const form = useForm({ confirm: false });

  useEffect(() => { if (open) { form.setData('confirm', false); form.clearErrors(); } /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [open]);

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    if (backup === null) return;
    form.post(route('super.tenants.archives.restore', { tenant: tenant.public_id, backup: backup.id }), { preserveScroll: true, onSuccess: onClose });
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>{t('super.data.restore_title')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <Alert severity="warning">{t('super.data.restore_warning', { name: tenant.name })}</Alert>
          <FormControlLabel
            control={<Checkbox checked={form.data.confirm} onChange={(e) => form.setData('confirm', e.target.checked)} />}
            label={t('super.data.restore_confirm')}
          />
          {form.errors.confirm ? <Typography variant="caption" color="error">{form.errors.confirm}</Typography> : null}
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>{t('super.actions.cancel')}</Button>
          <Button type="submit" color="error" variant="contained" disabled={form.processing || !form.data.confirm}>
            {t('super.data.restore')}
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}

function AddDomainDialog({ open, tenant, onClose }: { open: boolean; tenant: TenantDetail; onClose: () => void }) {
  const { t } = useTranslation();
  const form = useForm({ domain: '' });

  useEffect(() => { if (open) { form.setData('domain', ''); form.clearErrors(); } /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [open]);

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    form.post(route('super.tenants.domains.store', { tenant: tenant.public_id }), { preserveScroll: true, onSuccess: onClose });
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>{t('super.domains.add_title')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <DialogContentText>{t('super.tenants.domains_subdomain_hint', { host: tenant.host })}</DialogContentText>
          <TextField
            label={t('super.domains.hostname')}
            value={form.data.domain}
            onChange={(e) => form.setData('domain', e.target.value)}
            error={Boolean(form.errors.domain)}
            helperText={form.errors.domain ?? t('super.domains.hostname_help')}
            required
            autoFocus
            slotProps={{ htmlInput: { maxLength: 253, spellCheck: false, autoCapitalize: 'none', 'data-testid': 'domain-input' } }}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>{t('super.actions.cancel')}</Button>
          <Button type="submit" variant="contained" disabled={form.processing || form.data.domain.trim() === ''} data-testid="add-domain-submit">
            {t('super.actions.add')}
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}

// ── Entitlement rows ────────────────────────────────────────────────────────────────────────────────────

function LimitRow({ tenant, featureKey, label, effective, overridden }: {
  tenant: TenantDetail; featureKey: string; label: string; effective: number | null; overridden: boolean;
}) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [value, setValue] = useState(effective === null ? '' : String(effective));

  useEffect(() => { setValue(effective === null ? '' : String(effective)); }, [effective]);

  const save = (): void => {
    const trimmed = value.trim();
    const parsed = trimmed === '' ? null : Number(trimmed);
    if (parsed !== null && (!Number.isFinite(parsed) || parsed < 0)) return;
    router.post(route('super.tenants.limits', { tenant: tenant.public_id }), { limits: { [featureKey]: parsed } }, { preserveScroll: true });
  };
  const clear = (): void => {
    router.post(route('super.tenants.limits', { tenant: tenant.public_id }), { clear: [featureKey] }, { preserveScroll: true });
  };

  const bytes = featureKey === 'storage_bytes' && value.trim() !== '' && Number.isFinite(Number(value)) ? bytesParts(Number(value), locale) : null;

  return (
    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} sx={{ alignItems: { sm: 'center' }, py: 0.5 }}>
      <Box sx={{ flexGrow: 1, minWidth: 0 }}>
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
          <Typography variant="body2">{label}</Typography>
          {overridden ? <Chip size="small" color="info" variant="outlined" label={t('super.entitlements.overridden')} /> : null}
        </Stack>
        <Typography variant="caption" color="text.secondary">
          {bytes === null ? t('super.entitlements.limit_help') : t(bytes.key, { size: bytes.size })}
        </Typography>
      </Box>
      <TextField
        size="small"
        value={value}
        onChange={(e) => setValue(e.target.value)}
        placeholder={t('super.entitlements.unlimited_placeholder')}
        sx={{ width: { xs: '100%', sm: 180 } }}
        slotProps={{ htmlInput: { inputMode: 'numeric', 'aria-label': label } }}
      />
      <Button size="small" variant="outlined" onClick={save}>{t('super.actions.save')}</Button>
      <Button size="small" color="inherit" onClick={clear} disabled={!overridden}>{t('super.entitlements.clear_override')}</Button>
    </Stack>
  );
}

// ── Page ────────────────────────────────────────────────────────────────────────────────────────────────

// recharts is ~97 KB gzip and this screen is six tabs of forms and tables — the trend is one card on one of
// them. Lazily loaded, and skipped entirely for a clinic with no history yet (Components/Charts/LazyChart.tsx).
const TenantHistoryChart = lazy(() => import('@panel/Components/Charts/SuperCharts').then((m) => ({ default: m.TenantHistoryChart })));

export default function Show({ tenant, plans, feature_labels, metric_labels, toggles, limit_keys, history, billing, domains, backups, audit, staff, roles, reveal, deletion, links }: Props) {
  const { t } = useTranslation();
  const shared = useSharedProps();
  const locale = getLocale();
  // A freshly created clinic opens on its staff, which is where the owner's credential and "log in as" live.
  const [tab, setTab] = useState<TabKey>(reveal !== null ? 'staff' : 'overview');
  const [suspendOpen, setSuspendOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [reactivateOpen, setReactivateOpen] = useState(false);
  const [planChoice, setPlanChoice] = useState<{ plan: string; billing_cycle: 'monthly' | 'yearly' } | null>(null);
  const [addDomainOpen, setAddDomainOpen] = useState(false);
  const [restoreBackup, setRestoreBackup] = useState<BackupRow | null>(null);
  const [openAudit, setOpenAudit] = useState<number | null>(null);
  const [shownReveal, setShownReveal] = useState<RevealPayload | null>(reveal);

  // The page is already mounted when a staff create/reset redirects back to it, so the one-time credential
  // arrives as a PROP CHANGE, not a mount — `useState(reveal)` alone would never open the dialog again.
  useEffect(() => { if (reveal !== null) { setShownReveal(reveal); setTab('staff'); } }, [reveal]);

  const subscription = tenant.subscription;
  const overrides = subscription?.feature_overrides ?? {};
  const planForm = useForm({ plan: subscription?.plan_code ?? '', billing_cycle: subscription?.billing_cycle ?? 'monthly' });
  const n = (value: number): string => formatNumber(value, locale);

  const changePlan = (event: FormEvent): void => {
    event.preventDefault();
    setPlanChoice({ plan: planForm.data.plan, billing_cycle: planForm.data.billing_cycle });
  };
  const setFeature = (key: string, enabled: boolean): void => {
    router.post(route('super.tenants.features', { tenant: tenant.public_id }), { feature: key, enabled }, { preserveScroll: true });
  };
  const resetFeature = (key: string): void => {
    // No `enabled` key at all: ToggleTenantFeature reads that as "drop the override, go back to the plan".
    router.post(route('super.tenants.features', { tenant: tenant.public_id }), { feature: key }, { preserveScroll: true });
  };
  const simplePost = (name: string, params: Record<string, string | number>): void => {
    router.post(route(name, params), {}, { preserveScroll: true });
  };

  const backupColumns: SuperColumn<BackupRow>[] = [
    { key: 'type', label: t('super.data.column.type'), render: (row) => t(`super.data.type.${row.type}`, { defaultValue: row.type }) },
    {
      key: 'status',
      label: t('super.data.column.status'),
      render: (row) => (
        <Chip
          size="small"
          color={row.status === 'completed' ? 'success' : row.status === 'failed' ? 'error' : 'warning'}
          variant={row.status === 'completed' ? 'filled' : 'outlined'}
          label={t(`super.data.status.${row.status}`, { defaultValue: row.status })}
        />
      ),
    },
    {
      key: 'size',
      label: t('super.data.column.size'),
      align: 'right',
      render: (row) => {
        if (row.size_bytes === null) return '—';
        const parts = bytesParts(row.size_bytes, locale);
        return t(parts.key, { size: parts.size });
      },
    },
    { key: 'completed', label: t('super.data.column.completed'), render: (row) => when(row.completed_at, locale, DATETIME) },
    { key: 'expires', label: t('super.data.column.expires'), render: (row) => when(row.expires_at, locale) },
    {
      key: 'actions',
      label: t('super.billing.column.actions'),
      align: 'right',
      render: (row) => (
        <Stack direction="row" spacing={0.5} sx={{ justifyContent: 'flex-end' }}>
          {row.status === 'completed' && hasRoute('super.tenants.archives.download') ? (
            <Button
              size="small"
              component="a"
              href={route('super.tenants.archives.download', { tenant: tenant.public_id, backup: row.id })}
              startIcon={<DownloadIcon />}
            >
              {t('super.data.download')}
            </Button>
          ) : null}
          {row.status === 'completed' && row.type !== 'export' ? (
            <Button size="small" color="error" startIcon={<RestartAltIcon />} onClick={() => setRestoreBackup(row)}>{t('super.data.restore')}</Button>
          ) : null}
          {row.error ? <Tooltip title={row.error}><Chip size="small" color="error" variant="outlined" label={t('super.data.failed')} /></Tooltip> : null}
        </Stack>
      ),
    },
  ];

  const tenantRef: AuditTenantRef = { public_id: tenant.public_id, name: tenant.name, slug: tenant.slug };

  return (
    <Box>
      <Stack spacing={2}>
        <Card variant="outlined">
          <CardContent>
            <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ alignItems: { md: 'flex-start' } }}>
              <Box sx={{ flexGrow: 1, minWidth: 0 }}>
                <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }} useFlexGap>
                  <Typography variant="h5" component="h2" lang="bn">{tenant.name}</Typography>
                  <StatusChip status={tenant.status} />
                  <HealthChip health={tenant.health} />
                </Stack>
                <Stack direction="row" spacing={2} sx={{ mt: 1, flexWrap: 'wrap' }} useFlexGap>
                  <Field label={t('super.tenants.field.host')}>
                    <Link href={links.panel} target="_blank" rel="noopener noreferrer" underline="hover">
                      {tenant.host} <LaunchIcon sx={{ fontSize: 12, verticalAlign: 'middle' }} />
                    </Link>
                  </Field>
                  <Field label={t('super.tenants.field.owner')}>
                    <span lang="bn">{tenant.owner.name ?? '—'}</span>
                    <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
                      {tenant.owner.email ?? '—'} · {tenant.owner.mobile ?? '—'}
                    </Typography>
                  </Field>
                  <Field label={t('super.tenants.field.plan')}>{tenant.plan_name}</Field>
                  <Field label={t('super.tenants.field.schema')}><Box component="span" sx={{ fontFamily: 'monospace' }}>{tenant.schema_name}</Box></Field>
                  <Field label={t('super.tenants.field.created')}>{when(tenant.created_at, locale)}</Field>
                </Stack>
              </Box>
              <Stack spacing={1} sx={{ flexShrink: 0, alignItems: { md: 'flex-end' } }}>
                {hasRoute('super.tenants.impersonate') ? (
                  // A plain HTML form, not an Inertia post: the server answers with `redirect()->away()` to the
                  // clinic's own host, and only a real browser navigation can follow that.
                  <Box component="form" method="post" action={route('super.tenants.impersonate', { tenant: tenant.public_id })}>
                    <input type="hidden" name="_token" value={shared.csrf_token} />
                    <Button type="submit" variant="contained" startIcon={<LoginIcon />} disabled={tenant.status === 'cancelled'} data-testid="impersonate-owner">
                      {t('super.tenants.impersonate')}
                    </Button>
                    <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5, maxWidth: 260 }}>
                      {t('super.tenants.impersonate_help')}
                    </Typography>
                  </Box>
                ) : null}
                <Button component={RouterLink} href={route('super.tenants.edit', { tenant: tenant.public_id })} variant="outlined" startIcon={<EditIcon />} data-testid="edit-tenant">
                  {t('super.tenants.edit')}
                </Button>
              </Stack>
            </Stack>
            {tenant.platform_notes ? (
              <Alert severity="info" icon={false} sx={{ mt: 2, whiteSpace: 'pre-wrap' }}>
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('super.tenants.notes_label')}</Typography>
                {tenant.platform_notes}
              </Alert>
            ) : null}
            {tenant.status === 'suspended' && tenant.suspension_reason ? (
              <Alert severity="warning" sx={{ mt: 2 }}>
                {t('super.tenants.suspended_since', { date: when(tenant.suspended_at, locale, DATETIME), reason: tenant.suspension_reason })}
              </Alert>
            ) : null}
          </CardContent>
        </Card>

        <Box sx={{ borderBottom: 1, borderColor: 'divider' }}>
          <Tabs value={tab} onChange={(_event: SyntheticEvent, value: TabKey) => setTab(value)} variant="scrollable" scrollButtons="auto" allowScrollButtonsMobile>
            <Tab value="overview" label={t('super.tenants.tab.overview')} sx={{ textTransform: 'none' }} />
            <Tab value="entitlements" label={t('super.tenants.tab.entitlements')} sx={{ textTransform: 'none' }} />
            <Tab value="billing" label={t('super.tenants.tab.billing')} sx={{ textTransform: 'none' }} />
            <Tab value="domains" label={t('super.tenants.tab.domains')} sx={{ textTransform: 'none' }} data-testid="tab-domains" />
            <Tab value="staff" label={t('super.tenants.tab.staff')} sx={{ textTransform: 'none' }} data-testid="tab-staff" />
            <Tab value="data" label={t('super.tenants.tab.data')} sx={{ textTransform: 'none' }} data-testid="tab-data" />
            <Tab value="audit" label={t('super.tenants.tab.audit')} sx={{ textTransform: 'none' }} />
          </Tabs>
        </Box>

        {tab === 'overview' ? (
          <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', lg: '1fr 1fr' } }}>
            <Card variant="outlined">
              <CardContent>
                <Typography variant="subtitle1" component="h3" gutterBottom>{t('super.tenants.usage_title')}</Typography>
                <UsageBars usage={tenant.usage} metric_labels={metric_labels} />
              </CardContent>
            </Card>

            <Card variant="outlined">
              <CardContent>
                <Typography variant="subtitle1" component="h3" gutterBottom>{t('super.tenants.appointments_trend')}</Typography>
                <LazyChart height={220} empty={history.appointments.length === 0}>
                  <TenantHistoryChart points={history.appointments} label={metric_labels.appointments ?? 'appointments'} />
                </LazyChart>
              </CardContent>
            </Card>

            <Card variant="outlined" sx={{ gridColumn: { lg: '1 / -1' } }}>
              <CardContent>
                <Typography variant="subtitle1" component="h3" gutterBottom>{t('super.tenants.subscription_title')}</Typography>
                {subscription === null ? (
                  <Alert severity="info">{t('super.tenants.no_subscription')}</Alert>
                ) : (
                  <Stack direction="row" spacing={3} sx={{ flexWrap: 'wrap', mb: 2 }} useFlexGap>
                    <Field label={t('super.tenants.field.plan')}>{subscription.plan_name}</Field>
                    <Field label={t('super.tenants.field.status')}><StatusChip status={subscription.status} /></Field>
                    <Field label={t('super.tenants.field.cycle')}>{t(`super.cycle.${subscription.billing_cycle}`, { defaultValue: subscription.billing_cycle })}</Field>
                    <Field label={t('super.tenants.field.price')}>{formatBdt(subscription.price_paisa, locale)}</Field>
                    <Field label={t('super.tenants.field.period_end')}>{when(subscription.current_period_end, locale)}</Field>
                    <Field label={t('super.tenants.field.trial_end')}>{when(subscription.trial_ends_at, locale)}</Field>
                    <Field label={t('super.tenants.field.grace')}>{when(subscription.grace_until, locale)}</Field>
                    <Field label={t('super.tenants.field.renewal')}>
                      {subscription.cancel_at_period_end ? (
                        <Chip size="small" color="warning" label={t('super.tenants.cancels_at_period_end')} />
                      ) : subscription.auto_renew ? t('super.tenants.auto_renew_on') : t('super.tenants.auto_renew_off')}
                    </Field>
                  </Stack>
                )}

                {tenant.addons.length > 0 ? (
                  <Stack direction="row" spacing={1} sx={{ mb: 2, flexWrap: 'wrap' }} useFlexGap>
                    <Typography variant="caption" color="text.secondary">{t('super.tenants.addons')}</Typography>
                    {tenant.addons.map((addon) => (
                      <Chip key={addon.plan_code} size="small" variant="outlined" label={`${addon.plan_name} · ${t(`super.status.${addon.status}`, { defaultValue: addon.status })}`} />
                    ))}
                  </Stack>
                ) : null}

                <Divider sx={{ my: 2 }} />

                <Box component="form" onSubmit={changePlan} noValidate>
                  <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'flex-start' } }}>
                    <TextField
                      select size="small" label={t('super.tenants.change_plan')} value={planForm.data.plan}
                      onChange={(e) => planForm.setData('plan', e.target.value)}
                      error={Boolean(planForm.errors.plan)} helperText={planForm.errors.plan}
                      sx={{ minWidth: 220 }}
                    >
                      {plans.map((plan) => (
                        <MenuItem key={plan.code} value={plan.code}>
                          {plan.name}{plan.is_addon ? ` · ${t('super.plans.addon')}` : ''}
                        </MenuItem>
                      ))}
                    </TextField>
                    <TextField
                      select size="small" label={t('super.tenants.field.cycle')} value={planForm.data.billing_cycle}
                      onChange={(e) => planForm.setData('billing_cycle', e.target.value as 'monthly' | 'yearly')}
                      sx={{ minWidth: 160 }}
                    >
                      <MenuItem value="monthly">{t('super.cycle.monthly')}</MenuItem>
                      <MenuItem value="yearly">{t('super.cycle.yearly')}</MenuItem>
                    </TextField>
                    <Button type="submit" variant="contained" disabled={planForm.processing || planForm.data.plan === ''}>
                      {t('super.tenants.apply_plan')}
                    </Button>
                  </Stack>
                </Box>

                <Divider sx={{ my: 2 }} />

                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' }, flexWrap: 'wrap' }} useFlexGap>
                  <Button color="error" variant="outlined" onClick={() => setSuspendOpen(true)} disabled={tenant.status === 'suspended'}>
                    {t('super.tenants.suspend')}
                  </Button>
                  <Button color="success" variant="outlined" onClick={() => setReactivateOpen(true)} disabled={tenant.status === 'active' || tenant.status === 'trial'}>
                    {t('super.tenants.reactivate')}
                  </Button>
                  <Button color="error" onClick={() => setCancelOpen(true)} disabled={tenant.status === 'cancelled'}>
                    {t('super.tenants.cancel_subscription')}
                  </Button>
                </Stack>
              </CardContent>
            </Card>
          </Box>
        ) : null}

        {tab === 'entitlements' ? (
          <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', lg: '1fr 1fr' } }}>
            <Card variant="outlined">
              <CardContent>
                <Typography variant="subtitle1" component="h3">{t('super.entitlements.modules')}</Typography>
                <Typography variant="caption" color="text.secondary">{t('super.entitlements.modules_help', { plan: tenant.entitlements.plan_name })}</Typography>
                <Divider sx={{ my: 1.5 }} />
                {subscription === null ? <Alert severity="info">{t('super.tenants.no_subscription')}</Alert> : null}
                <Stack divider={<Divider flexItem />}>
                  {toggles.map((key) => {
                    const overridden = Object.prototype.hasOwnProperty.call(overrides, key);
                    const enabled = tenant.entitlements.toggles[key] ?? false;

                    return (
                      <Stack key={key} direction="row" spacing={1} sx={{ alignItems: 'center', py: 0.5 }}>
                        <Box sx={{ flexGrow: 1, minWidth: 0 }}>
                          <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                            <Typography variant="body2">{feature_labels[key] ?? key}</Typography>
                            {overridden ? <Chip size="small" color="info" variant="outlined" label={t('super.entitlements.overridden')} /> : null}
                          </Stack>
                        </Box>
                        <Switch
                          checked={enabled}
                          disabled={subscription === null}
                          onChange={(e) => setFeature(key, e.target.checked)}
                          slotProps={{ input: { 'aria-label': feature_labels[key] ?? key } }}
                        />
                        <Button size="small" color="inherit" onClick={() => resetFeature(key)} disabled={!overridden}>
                          {t('super.entitlements.reset')}
                        </Button>
                      </Stack>
                    );
                  })}
                </Stack>
              </CardContent>
            </Card>

            <Card variant="outlined">
              <CardContent>
                <Typography variant="subtitle1" component="h3">{t('super.entitlements.limits')}</Typography>
                <Typography variant="caption" color="text.secondary">{t('super.entitlements.limits_help')}</Typography>
                <Divider sx={{ my: 1.5 }} />
                <Stack divider={<Divider flexItem />}>
                  {limit_keys.map((key) => (
                    <LimitRow
                      key={key}
                      tenant={tenant}
                      featureKey={key}
                      label={feature_labels[key] ?? key}
                      effective={tenant.entitlements.limits[key] ?? null}
                      overridden={Object.prototype.hasOwnProperty.call(overrides, key)}
                    />
                  ))}
                </Stack>
              </CardContent>
            </Card>
          </Box>
        ) : null}

        {tab === 'billing' ? (
          <TenantBillingCard tenant={{ public_id: tenant.public_id, name: tenant.name }} billing={billing} plans={plans} />
        ) : null}

        {tab === 'domains' ? (
          <Stack spacing={2}>
            <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'space-between' }}>
              <Typography variant="subtitle1" component="h3">{t('super.domains.title')}</Typography>
              <Button variant="contained" onClick={() => setAddDomainOpen(true)} data-testid="add-domain">{t('super.domains.add')}</Button>
            </Stack>
            {domains.length === 0 ? (
              <Alert severity="info">{t('super.domains.empty')}</Alert>
            ) : domains.map((domain) => (
              <Card key={domain.id} variant="outlined">
                <CardContent>
                  <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
                    <Box sx={{ flexGrow: 1, minWidth: 0 }}>
                      <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }} useFlexGap>
                        <Typography variant="subtitle2" sx={{ fontFamily: 'monospace' }}>{domain.domain}</Typography>
                        <Chip
                          size="small"
                          color={domain.verification_status === 'verified' ? 'success' : domain.verification_status === 'failed' ? 'error' : 'warning'}
                          label={t(`super.domains.status.${domain.verification_status}`, { defaultValue: domain.verification_status })}
                        />
                        {domain.is_primary ? <Chip size="small" color="primary" icon={<StarIcon />} label={t('super.domains.primary')} /> : null}
                        <Chip size="small" variant="outlined" label={t(`super.domains.type.${domain.type}`, { defaultValue: domain.type })} />
                        {domain.type === 'custom' ? (
                          <Tooltip title={domain.ssl_expires_at ? t('super.tenants.ssl_expires', { date: when(domain.ssl_expires_at, locale) }) : ''}>
                            <Chip size="small" variant="outlined" color={SSL_TONE[domain.ssl_status]} label={`${t('super.tenants.ssl_label')}: ${t(`super.tenants.ssl.${domain.ssl_status}`, { defaultValue: domain.ssl_status })}`} />
                          </Tooltip>
                        ) : null}
                      </Stack>
                      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
                        {t('super.domains.checked', { verified: when(domain.verified_at, locale, DATETIME), checked: when(domain.last_checked_at, locale, DATETIME) })}
                      </Typography>

                      <Stack spacing={1} sx={{ mt: 1.5 }}>
                        <CopyBlock label={t('super.domains.txt_name')} value={domain.instructions.txt_name} />
                        <CopyBlock label={t('super.domains.txt_value')} value={domain.instructions.txt_value} />
                        <Typography variant="caption" color="text.secondary">
                          {t('super.domains.record_help', {
                            kind: domain.instructions.record_kind,
                            name: domain.instructions.record_name,
                            value: domain.instructions.record_value === '' ? t('super.domains.record_value_missing') : domain.instructions.record_value,
                          })}
                        </Typography>
                      </Stack>
                    </Box>

                    <Stack spacing={1} sx={{ flexShrink: 0 }}>
                      <Button size="small" variant="outlined" onClick={() => simplePost('super.tenants.domains.verify', { tenant: tenant.public_id, domain: domain.id })} data-testid={`verify-domain-${domain.id}`}>
                        {t('super.actions.verify')}
                      </Button>
                      <Button size="small" onClick={() => simplePost('super.tenants.domains.primary', { tenant: tenant.public_id, domain: domain.id })} disabled={domain.is_primary || domain.verification_status !== 'verified'}>
                        {t('super.domains.make_primary')}
                      </Button>
                      <Button
                        size="small"
                        color="error"
                        startIcon={<DeleteOutlineIcon />}
                        onClick={() => router.delete(route('super.tenants.domains.destroy', { tenant: tenant.public_id, domain: domain.id }), { preserveScroll: true })}
                        disabled={domain.is_primary}
                      >
                        {t('super.actions.remove')}
                      </Button>
                    </Stack>
                  </Stack>
                </CardContent>
              </Card>
            ))}
          </Stack>
        ) : null}

        {tab === 'staff' ? <StaffTab tenant={tenant} staff={staff} roles={roles} /> : null}

        {tab === 'data' ? (
          <Stack spacing={2}>
          <Card variant="outlined">
            <CardContent>
              <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ justifyContent: 'space-between' }}>
                <Stack direction="row" spacing={3} sx={{ flexWrap: 'wrap' }} useFlexGap>
                  <Field label={t('super.data.last_backup')}>{when(tenant.last_backup_at, locale, DATETIME)}</Field>
                  <Field label={t('super.data.last_export')}>{when(tenant.data_export_requested_at, locale, DATETIME)}</Field>
                  <Field label={t('super.data.provisioned')}>{when(tenant.provisioned_at, locale, DATETIME)}</Field>
                </Stack>
                <Stack direction="row" spacing={1} sx={{ flexShrink: 0, alignItems: 'flex-start' }}>
                  <Button variant="contained" onClick={() => simplePost('super.tenants.backups.store', { tenant: tenant.public_id })}>
                    {t('super.data.run_backup')}
                  </Button>
                  <Button variant="outlined" onClick={() => simplePost('super.tenants.exports.store', { tenant: tenant.public_id })}>
                    {t('super.data.build_export')}
                  </Button>
                </Stack>
              </Stack>
            </CardContent>
            <SuperTable
              columns={backupColumns}
              rows={backups}
              rowKey={(row) => String(row.id)}
              empty={t('super.data.empty')}
              label={t('super.tenants.tab.data')}
            />
          </Card>
          <DeleteTenantCard tenant={tenant} deletion={deletion} />
          </Stack>
        ) : null}

        {tab === 'audit' ? (
          <Card variant="outlined">
            <CardContent>
              <Typography variant="subtitle1" component="h3" gutterBottom>{t('super.audit.tenant_title', { name: tenantRef.name })}</Typography>
              {audit.length === 0 ? (
                <Typography variant="body2" color="text.secondary">{t('super.audit.empty')}</Typography>
              ) : (
                <Stack divider={<Divider flexItem />}>
                  {audit.map((entry, index) => (
                    <Box key={`${entry.occurred_at ?? 'na'}-${index}`} sx={{ py: 1 }}>
                      <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }} useFlexGap>
                        <Chip size="small" variant="outlined" label={t(`super.audit.action.${entry.action}`, { defaultValue: entry.action })} />
                        <Typography variant="body2" sx={{ fontWeight: 600 }}>{entry.actor ?? t('super.audit.system')}</Typography>
                        <Typography variant="caption" color="text.secondary">{when(entry.occurred_at, locale, DATETIME)}</Typography>
                        {entry.ip ? <Typography variant="caption" color="text.secondary" sx={{ fontFamily: 'monospace' }}>{entry.ip}</Typography> : null}
                        {entry.auditable_type ? <Typography variant="caption" color="text.secondary">{entry.auditable_type}</Typography> : null}
                        <Button size="small" onClick={() => setOpenAudit(openAudit === index ? null : index)}>
                          {openAudit === index ? t('super.audit.hide_diff') : t('super.audit.show_diff')}
                        </Button>
                      </Stack>
                      <Collapse in={openAudit === index} unmountOnExit>
                        <JsonBlock label={t('super.audit.before')} value={entry.before} />
                        <JsonBlock label={t('super.audit.after')} value={entry.after} />
                      </Collapse>
                    </Box>
                  ))}
                </Stack>
              )}
            </CardContent>
          </Card>
        ) : null}
      </Stack>

      <SuspendDialog open={suspendOpen} tenant={tenant} onClose={() => setSuspendOpen(false)} />
      <CancelDialog open={cancelOpen} tenant={tenant} onClose={() => setCancelOpen(false)} />
      <ReactivateDialog open={reactivateOpen} tenant={tenant} onClose={() => setReactivateOpen(false)} />
      <ChangePlanDialog tenant={tenant} choice={planChoice} plans={plans} onClose={() => setPlanChoice(null)} />
      <RevealDialog reveal={shownReveal} onClose={() => setShownReveal(null)} />
      <AddDomainDialog open={addDomainOpen} tenant={tenant} onClose={() => setAddDomainOpen(false)} />
      <RestoreDialog tenant={tenant} backup={restoreBackup} onClose={() => setRestoreBackup(null)} />
    </Box>
  );
}

Show.layout = (page: ReactNode) => <PanelLayout title="super.tenants.show_title">{page}</PanelLayout>;
