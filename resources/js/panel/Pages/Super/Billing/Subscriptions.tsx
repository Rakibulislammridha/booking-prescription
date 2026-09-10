// Every subscription on the platform, with the buttons that move one: change plan, end a trial, run dunning,
// suspend, reactivate, cancel (at period end or now), raise an invoice. Each button opens a confirmation that
// says what will happen and — where the audit log needs it — asks for a typed reason; each one calls the same
// Action the scheduled sweeps call, so a hand-moved subscription lands in the state the clock would have put it in.
import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Checkbox from '@mui/material/Checkbox';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import DownloadIcon from '@mui/icons-material/Download';
import MoreVertIcon from '@mui/icons-material/MoreVert';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import { StatusChip } from '@panel/Components/Super/StatusChip';
import { BillingNav } from '@panel/Components/Super/Billing/BillingNav';
import { ChangePlanDialog } from '@panel/Components/Super/Billing/ChangePlanDialog';
import { ConfirmActionDialog } from '@panel/Components/Super/Billing/ConfirmActionDialog';
import { Pager } from '@panel/Components/Super/Billing/Pager';
import type { BillingSubscriptionRow, ConsoleMeta, ConsolePlan } from '@panel/Components/Super/Billing/types';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { Locale } from '@shared/types/shared-props';

interface Filters { q: string; status: string; plan: string; cycle: string; sort: string }

type Props = PageProps<{
  subscriptions: BillingSubscriptionRow[];
  meta: ConsoleMeta;
  filters: Filters;
  statuses: string[];
  status_counts: Record<string, number>;
  cycles: string[];
  plans: ConsolePlan[];
}>;

type ActionKind = 'plan' | 'end_trial' | 'dun' | 'suspend' | 'reactivate' | 'cancel' | 'raise';

const STATUS_LABEL: Record<string, string> = {
  trialing: 'super.status.trialing', active: 'super.status.active', past_due: 'super.status.past_due',
  suspended: 'super.status.suspended', cancelled: 'super.status.cancelled', expired: 'super.status.expired',
};

function when(iso: string | null, locale: Locale): string {
  return iso === null ? '—' : formatDhaka(iso, 'D MMM YYYY', locale);
}

export default function Subscriptions({ subscriptions, meta, filters, statuses, status_counts, cycles, plans }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const n = (value: number): string => formatNumber(value, locale);
  const [q, setQ] = useState(filters.q);
  const [menu, setMenu] = useState<{ anchor: HTMLElement; row: BillingSubscriptionRow } | null>(null);
  const [action, setAction] = useState<{ kind: ActionKind; row: BillingSubscriptionRow } | null>(null);
  const [immediately, setImmediately] = useState(false);
  const [force, setForce] = useState(false);
  const [busy, setBusy] = useState(false);

  const go = (next: Partial<Filters> & { page?: number }): void => {
    const merged = { ...filters, ...next };
    const query: Record<string, string | number> = {};
    for (const key of ['q', 'status', 'plan', 'cycle'] as const) if (merged[key] !== '') query[key] = merged[key];
    if (merged.sort !== '' && merged.sort !== 'renewal') query.sort = merged.sort;
    if (next.page !== undefined && next.page > 1) query.page = next.page;
    router.get(route('super.billing.subscriptions.index'), query, { preserveState: true, replace: true, preserveScroll: true });
  };

  const exportHref = (): string => {
    const query: Record<string, string> = {};
    for (const key of ['q', 'status', 'plan', 'cycle', 'sort'] as const) if (filters[key] !== '') query[key] = filters[key];
    return route('super.billing.export', { kind: 'subscriptions', ...query });
  };

  const openAction = (kind: ActionKind, row: BillingSubscriptionRow): void => {
    setMenu(null);
    setImmediately(false);
    setForce(false);
    setAction({ kind, row });
  };

  const post = (url: string, data: Record<string, string | number | boolean | null>): void => {
    setBusy(true);
    router.post(url, data, { preserveScroll: true, onFinish: () => { setBusy(false); setAction(null); } });
  };

  const confirm = (reason: string): void => {
    if (action === null) return;
    const tenant = action.row.tenant.public_id;
    switch (action.kind) {
      case 'end_trial': post(route('super.billing.subscriptions.end-trial', { tenant }), {}); break;
      case 'dun': post(route('super.billing.subscriptions.dun', { tenant }), {}); break;
      case 'suspend': post(route('super.billing.subscriptions.suspend', { tenant }), { reason }); break;
      case 'reactivate': post(route('super.billing.subscriptions.reactivate', { tenant }), { reason, force }); break;
      case 'cancel': post(route('super.billing.subscriptions.cancel', { tenant }), { reason, immediately }); break;
      case 'raise': post(route('super.billing.invoices.store'), { tenant, issue: true }); break;
      default: break;
    }
  };

  const columns: SuperColumn<BillingSubscriptionRow>[] = [
    {
      key: 'clinic',
      label: t('super.tenants.column.clinic'),
      bn: true,
      render: (row) => (
        <Box>
          {hasRoute('super.tenants.show') ? (
            <Box component={RouterLink} href={route('super.tenants.show', { tenant: row.tenant.public_id })} sx={{ color: 'primary.main', fontWeight: 600, textDecoration: 'none' }}>{row.tenant.name}</Box>
          ) : <Typography variant="body2" sx={{ fontWeight: 600 }}>{row.tenant.name}</Typography>}
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{row.tenant.slug}</Typography>
        </Box>
      ),
    },
    {
      key: 'plan',
      label: t('super.tenants.column.plan'),
      render: (row) => (
        <Stack direction="row" spacing={0.5} sx={{ alignItems: 'center' }}>
          <Typography variant="body2">{row.plan_name}</Typography>
          {row.is_addon ? <Chip size="small" variant="outlined" color="info" label={t('super.plans.addon')} /> : null}
          {row.cancel_at_period_end ? <Chip size="small" color="warning" label={t('super.tenants.cancels_at_period_end')} /> : null}
        </Stack>
      ),
    },
    { key: 'status', label: t('super.tenants.column.status'), render: (row) => <StatusChip status={row.status} /> },
    { key: 'cycle', label: t('super.tenants.field.cycle'), render: (row) => (row.billing_cycle === 'yearly' ? t('super.cycle.yearly') : t('super.cycle.monthly')) },
    { key: 'price', label: t('super.tenants.field.price'), align: 'right', render: (row) => formatBdt(row.price_paisa, locale) },
    {
      key: 'renewal',
      label: t('super.billing.column.next_renewal'),
      render: (row) => (
        <Box>
          <Typography variant="body2">{row.status === 'trialing' ? when(row.trial_ends_at, locale) : when(row.current_period_end, locale)}</Typography>
          {row.status === 'trialing' ? <Typography variant="caption" color="text.secondary">{t('super.billing.trial_ends')}</Typography> : null}
          {row.grace_until && row.status === 'past_due' ? <Typography variant="caption" color="warning.main" sx={{ display: 'block' }}>{t('super.billing.grace_until', { date: when(row.grace_until, locale) })}</Typography> : null}
        </Box>
      ),
    },
    {
      key: 'arrears',
      label: t('super.tenants.column.arrears'),
      align: 'right',
      render: (row) => (
        <Box>
          <Typography variant="body2" sx={{ color: row.arrears_paisa > 0 ? 'error.main' : 'text.secondary', fontVariantNumeric: 'tabular-nums', fontWeight: row.arrears_paisa > 0 ? 600 : 400 }}>
            {formatBdt(row.arrears_paisa, locale)}
          </Typography>
          {row.arrears_invoices > 0 ? <Typography variant="caption" color="text.secondary">{t('super.tenants.arrears_invoices', { count: n(row.arrears_invoices) })}</Typography> : null}
        </Box>
      ),
    },
    {
      key: 'actions',
      label: t('super.billing.column.actions'),
      align: 'right',
      render: (row) => (row.is_current ? (
        <IconButton size="small" aria-label={t('super.billing.row_actions', { clinic: row.tenant.name })} onClick={(e) => setMenu({ anchor: e.currentTarget, row })}>
          <MoreVertIcon fontSize="small" />
        </IconButton>
      ) : <Typography variant="caption" color="text.secondary">{t('super.billing.addon_row')}</Typography>),
    },
  ];

  const row = menu?.row ?? null;
  const dialogTitle: Record<ActionKind, string> = {
    plan: '', end_trial: t('super.billing.actions.end_trial'), dun: t('super.billing.dunning.run_title', { clinic: action?.row.tenant.name ?? '' }),
    suspend: t('super.tenants.suspend_title'), reactivate: t('super.tenants.reactivate'), cancel: t('super.tenants.cancel_title'), raise: t('super.billing.raise_title'),
  };
  const dialogBody: Record<ActionKind, string> = {
    plan: '',
    end_trial: t('super.billing.actions.end_trial_body', { clinic: action?.row.tenant.name ?? '', amount: formatBdt(action?.row.price_paisa ?? 0, locale) }),
    dun: t('super.billing.dunning.run_body'),
    suspend: t('super.tenants.suspend_body', { name: action?.row.tenant.name ?? '' }),
    reactivate: t('super.billing.actions.reactivate_body', { clinic: action?.row.tenant.name ?? '' }),
    cancel: t('super.tenants.cancel_body', { name: action?.row.tenant.name ?? '' }),
    raise: t('super.billing.raise_body', { clinic: action?.row.tenant.name ?? '', amount: formatBdt(action?.row.price_paisa ?? 0, locale) }),
  };

  return (
    <Box>
      <SuperNav />
      <BillingNav />

      <Stack spacing={2}>
        <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ alignItems: { md: 'center' } }} useFlexGap>
          <Box component="form" onSubmit={(e) => { e.preventDefault(); go({ q, page: 1 }); }} sx={{ flexGrow: 1, minWidth: 200 }}>
            <TextField size="small" fullWidth value={q} onChange={(e) => setQ(e.target.value)} label={t('super.billing.search')} placeholder={t('super.tenants.search_placeholder')} />
          </Box>
          <TextField select size="small" value={filters.status} label={t('super.tenants.status_filter')} onChange={(e) => go({ status: e.target.value, page: 1 })} sx={{ minWidth: 180 }}>
            <MenuItem value="">{t('super.tenants.status_any')}</MenuItem>
            {statuses.map((status) => (
              <MenuItem key={status} value={status}>{t(STATUS_LABEL[status] ?? status)} ({n(status_counts[status] ?? 0)})</MenuItem>
            ))}
          </TextField>
          <TextField select size="small" value={filters.plan} label={t('super.tenants.column.plan')} onChange={(e) => go({ plan: e.target.value, page: 1 })} sx={{ minWidth: 160 }}>
            <MenuItem value="">{t('super.billing.any_plan')}</MenuItem>
            {plans.map((plan) => <MenuItem key={plan.code} value={plan.code}>{plan.name}</MenuItem>)}
          </TextField>
          <TextField select size="small" value={filters.cycle} label={t('super.tenants.field.cycle')} onChange={(e) => go({ cycle: e.target.value, page: 1 })} sx={{ minWidth: 140 }}>
            <MenuItem value="">{t('super.billing.any_cycle')}</MenuItem>
            {cycles.map((cycle) => <MenuItem key={cycle} value={cycle}>{cycle === 'yearly' ? t('super.cycle.yearly') : t('super.cycle.monthly')}</MenuItem>)}
          </TextField>
          <TextField select size="small" value={filters.sort} label={t('super.billing.sort')} onChange={(e) => go({ sort: e.target.value, page: 1 })} sx={{ minWidth: 150 }}>
            <MenuItem value="renewal">{t('super.billing.sort_renewal')}</MenuItem>
            <MenuItem value="arrears">{t('super.billing.sort_arrears')}</MenuItem>
            <MenuItem value="price">{t('super.billing.sort_price')}</MenuItem>
            <MenuItem value="tenant">{t('super.billing.sort_tenant')}</MenuItem>
          </TextField>
          {hasRoute('super.billing.export') ? (
            <Button size="small" startIcon={<DownloadIcon />} component="a" href={exportHref()}>{t('super.billing.export_csv')}</Button>
          ) : null}
        </Stack>

        <Card variant="outlined">
          <SuperTable columns={columns} rows={subscriptions} rowKey={(r) => String(r.id)} empty={t('super.billing.subscriptions.empty')} label={t('super.billing.nav.subscriptions')} />
          <Pager meta={meta} onPage={(page) => go({ page })} />
        </Card>
      </Stack>

      <Menu open={menu !== null} anchorEl={menu?.anchor ?? null} onClose={() => setMenu(null)}>
        {row !== null ? [
          <MenuItem key="plan" onClick={() => openAction('plan', row)}>{t('super.tenants.apply_plan')}</MenuItem>,
          <MenuItem key="raise" onClick={() => openAction('raise', row)}>{t('super.billing.raise_invoice')}</MenuItem>,
          row.status === 'trialing' ? <MenuItem key="end_trial" onClick={() => openAction('end_trial', row)}>{t('super.billing.actions.end_trial')}</MenuItem> : null,
          row.arrears_invoices > 0 ? <MenuItem key="dun" onClick={() => openAction('dun', row)}>{t('super.billing.dunning.run_now')}</MenuItem> : null,
          row.tenant.status !== 'suspended' && row.tenant.status !== 'cancelled' ? <MenuItem key="suspend" onClick={() => openAction('suspend', row)} sx={{ color: 'error.main' }}>{t('super.tenants.suspend')}</MenuItem> : null,
          row.tenant.status !== 'active' && row.tenant.status !== 'trial' ? <MenuItem key="reactivate" onClick={() => openAction('reactivate', row)} sx={{ color: 'success.main' }}>{t('super.tenants.reactivate')}</MenuItem> : null,
          row.status !== 'cancelled' && row.status !== 'expired' ? <MenuItem key="cancel" onClick={() => openAction('cancel', row)} sx={{ color: 'error.main' }}>{t('super.tenants.cancel_subscription')}</MenuItem> : null,
        ] : null}
      </Menu>

      <ChangePlanDialog
        open={action?.kind === 'plan'}
        tenantName={action?.row.tenant.name ?? ''}
        current={action === null ? null : { plan_code: action.row.plan_code, billing_cycle: action.row.billing_cycle }}
        plans={plans}
        action={action === null ? '' : route('super.billing.subscriptions.plan', { tenant: action.row.tenant.public_id })}
        onClose={() => setAction(null)}
      />

      <ConfirmActionDialog
        open={action !== null && action.kind !== 'plan'}
        title={action === null ? '' : dialogTitle[action.kind]}
        body={action === null ? '' : dialogBody[action.kind]}
        confirmLabel={action === null ? '' : (action.kind === 'cancel' ? t('super.tenants.cancel_subscription') : action.kind === 'suspend' ? t('super.tenants.suspend') : action.kind === 'reactivate' ? t('super.tenants.reactivate') : action.kind === 'raise' ? t('super.billing.raise_invoice') : action.kind === 'dun' ? t('super.billing.dunning.run_now') : t('super.billing.actions.end_trial'))}
        color={action?.kind === 'suspend' || action?.kind === 'cancel' ? 'error' : action?.kind === 'dun' ? 'warning' : 'primary'}
        requireReason={action?.kind === 'suspend' || action?.kind === 'cancel' || action?.kind === 'reactivate'}
        busy={busy}
        onClose={() => setAction(null)}
        onConfirm={confirm}
      >
        {action?.kind === 'cancel' ? (
          <FormControlLabel
            control={<Checkbox checked={immediately} onChange={(e) => setImmediately(e.target.checked)} />}
            label={<Box><Typography variant="body2">{t('super.tenants.cancel_immediately')}</Typography><Typography variant="caption" color="text.secondary">{t('super.tenants.cancel_immediately_help')}</Typography></Box>}
          />
        ) : null}
        {action?.kind === 'reactivate' ? (
          <FormControlLabel control={<Checkbox checked={force} onChange={(e) => setForce(e.target.checked)} />} label={<Typography variant="body2">{t('super.tenants.force_help')}</Typography>} />
        ) : null}
      </ConfirmActionDialog>
    </Box>
  );
}

Subscriptions.layout = (page: ReactNode) => <PanelLayout title="super.billing.page_title">{page}</PanelLayout>;
