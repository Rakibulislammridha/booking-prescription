// Every clinic on the platform, densest-first. Search, filters and sort are server-side (one query, however many
// tenants), the URL always describes what is on screen, and there are deliberately NO row checkboxes: nothing
// destructive exists in bulk here — a clinic is suspended, cancelled or deleted one at a time, from its own page,
// with the reason typed. The `deleted` filter lists soft-deleted clinics with their last export for download.
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import AddIcon from '@mui/icons-material/Add';
import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import DownloadIcon from '@mui/icons-material/Download';
import SearchIcon from '@mui/icons-material/Search';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import { HealthChip, StatusChip } from '@panel/Components/Super/StatusChip';
import type { PlanOption, TenantListFilters, TenantListRow } from '@panel/Components/Super/Tenants/types';
import type { ConsoleMeta, PlatformTotals } from '@panel/Components/Super/types';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  tenants: TenantListRow[];
  meta: ConsoleMeta & { per_page: number };
  filters: TenantListFilters;
  statuses: string[];
  plans: PlanOption[];
  attention: string[];
  sorts: string[];
  totals: PlatformTotals;
}>;

const SEARCH_DEBOUNCE_MS = 300;
const DEFAULT_DIR: Record<string, 'asc' | 'desc'> = { created: 'desc', name: 'asc', slug: 'asc', status: 'asc', trial_ends: 'asc', arrears: 'desc' };

export default function Index({ tenants, meta, filters, statuses, plans, attention, sorts, totals }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [q, setQ] = useState(filters.q);
  const typed = useRef(false);
  const n = (value: number): string => formatNumber(value, locale);
  const deleted = filters.status === 'deleted';
  const dir = filters.dir === '' ? (DEFAULT_DIR[filters.sort] ?? 'desc') : filters.dir;

  const go = (patch: Partial<TenantListFilters> & { page?: number }): void => {
    const next = { ...filters, q, ...patch };
    const query: Record<string, string | number> = {};
    (['q', 'status', 'plan', 'attention', 'sort', 'dir'] as const).forEach((key) => {
      if (next[key] !== '' && !(key === 'sort' && next[key] === 'created')) query[key] = next[key];
    });
    if (patch.page !== undefined && patch.page > 1) query.page = patch.page;
    router.get(route('super.tenants.index'), query, { preserveState: true, replace: true, preserveScroll: true });
  };

  // Debounce only the free-text field; the selects navigate at once because they are deliberate clicks.
  useEffect(() => {
    if (!typed.current) return;
    const timer = window.setTimeout(() => go({ q, page: 1 }), SEARCH_DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q]);

  const open = (tenant: TenantListRow): void => {
    if (deleted) return;
    router.visit(route('super.tenants.show', { tenant: tenant.public_id }));
  };

  const cap = (used: number, limit: number | null): string => `${n(used)} / ${limit === null ? '∞' : n(limit)}`;

  const columns: SuperColumn<TenantListRow>[] = [
    {
      key: 'clinic',
      label: t('super.tenants.column.clinic'),
      bn: true,
      render: (row) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>{row.name}</Typography>
          <Typography variant="caption" color="text.secondary">{row.slug} · {row.host}</Typography>
        </Box>
      ),
    },
    { key: 'status', label: t('super.tenants.column.status'), render: (row) => <StatusChip status={row.status} /> },
    {
      key: 'plan',
      label: t('super.tenants.column.plan'),
      render: (row) => (
        <Box>
          <Typography variant="body2">{row.plan_name}</Typography>
          {row.subscription_status ? (
            <Typography variant="caption" color="text.secondary">
              {t(`super.status.${row.subscription_status}`, { defaultValue: row.subscription_status })}
            </Typography>
          ) : null}
        </Box>
      ),
    },
    ...(deleted ? [
      { key: 'deleted', label: t('super.tenants.column.deleted'), render: (row: TenantListRow) => (row.deleted_at ? formatDhaka(row.deleted_at, 'D MMM YYYY, h:mm a', locale) : '—') },
      {
        key: 'export',
        label: t('super.tenants.column.export'),
        align: 'right' as const,
        render: (row: TenantListRow) => (row.last_export_id === null ? (
          <Typography variant="caption" color="text.secondary">{t('super.tenants.no_export')}</Typography>
        ) : (
          <Button size="small" component="a" href={route('super.tenants.archives.download', { tenant: row.public_id, backup: row.last_export_id })} startIcon={<DownloadIcon />}>
            {t('super.tenants.download_export')}
          </Button>
        )),
      },
    ] : [
      { key: 'health', label: t('super.tenants.column.health'), render: (row: TenantListRow) => <HealthChip health={row.health} /> },
      { key: 'doctors', label: t('super.tenants.column.doctors'), align: 'right' as const, render: (row: TenantListRow) => cap(row.counts.doctors, row.limits.doctors) },
      { key: 'branches', label: t('super.tenants.column.branches'), align: 'right' as const, render: (row: TenantListRow) => cap(row.counts.branches, row.limits.branches) },
      { key: 'appointments', label: t('super.tenants.column.appointments'), align: 'right' as const, render: (row: TenantListRow) => cap(row.counts.appointments, row.limits.appointments) },
      {
        key: 'trial',
        label: t('super.tenants.column.trial_ends'),
        render: (row: TenantListRow) => (row.status === 'trial' && row.trial_ends_at ? formatDhaka(row.trial_ends_at, 'D MMM YYYY', locale) : '—'),
      },
      {
        key: 'arrears',
        label: t('super.tenants.column.arrears'),
        align: 'right' as const,
        render: (row: TenantListRow) => (
          <Box sx={{ color: row.arrears_paisa > 0 ? 'error.main' : 'text.secondary' }}>
            {formatBdt(row.arrears_paisa, locale)}
            {row.arrears_invoices > 0 ? (
              <Typography variant="caption" component="div" color="error.main">
                {t('super.tenants.arrears_invoices', { count: n(row.arrears_invoices) })}
              </Typography>
            ) : null}
          </Box>
        ),
      },
    ]),
    { key: 'created', label: t('super.tenants.column.created'), render: (row) => (row.created_at ? formatDhaka(row.created_at, 'D MMM YYYY', locale) : '—') },
  ];

  const summary: Array<{ key: string; label: string; value: number }> = [
    { key: 'tenants', label: 'super.dashboard.tenants', value: totals.tenants },
    { key: 'trial', label: 'super.status.trial', value: totals.trial },
    { key: 'active', label: 'super.status.active', value: totals.active },
    { key: 'past_due', label: 'super.status.past_due', value: totals.past_due },
    { key: 'suspended', label: 'super.status.suspended', value: totals.suspended },
    { key: 'cancelled', label: 'super.status.cancelled', value: totals.cancelled },
  ];

  return (
    <Box>
      <SuperNav />

      <Stack spacing={2}>
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap' }} useFlexGap>
          <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap' }}>
            {summary.map((item) => (
              <Chip key={item.key} size="small" variant="outlined" label={`${t(item.label)} · ${n(item.value)}`} />
            ))}
          </Stack>
          <Button component={RouterLink} href={route('super.tenants.create')} variant="contained" startIcon={<AddIcon />} data-testid="create-tenant">
            {t('super.tenants.create')}
          </Button>
        </Stack>

        <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ alignItems: { md: 'center' } }} useFlexGap>
          <TextField
            value={q}
            onChange={(e) => { typed.current = true; setQ(e.target.value); }}
            size="small"
            fullWidth
            placeholder={t('super.tenants.search_placeholder')}
            slotProps={{
              htmlInput: { 'aria-label': t('super.tenants.search_placeholder') },
              input: { startAdornment: <InputAdornment position="start"><SearchIcon fontSize="small" /></InputAdornment> },
            }}
          />
          <TextField select size="small" value={filters.status} label={t('super.tenants.status_filter')} onChange={(e) => go({ status: e.target.value, page: 1 })} sx={{ minWidth: 170 }}>
            <MenuItem value="">{t('super.tenants.status_any')}</MenuItem>
            {statuses.map((value) => <MenuItem key={value} value={value}>{t(`super.status.${value}`, { defaultValue: value })}</MenuItem>)}
            <MenuItem value="deleted">{t('super.tenants.status_deleted')}</MenuItem>
          </TextField>
          <TextField select size="small" value={filters.plan} label={t('super.tenants.plan_filter')} onChange={(e) => go({ plan: e.target.value, page: 1 })} sx={{ minWidth: 150 }}>
            <MenuItem value="">{t('super.tenants.plan_any')}</MenuItem>
            {plans.map((plan) => <MenuItem key={plan.code} value={plan.code}>{plan.name}</MenuItem>)}
          </TextField>
          <TextField select size="small" value={filters.attention} label={t('super.tenants.attention_filter')} onChange={(e) => go({ attention: e.target.value, page: 1 })} sx={{ minWidth: 190 }}>
            <MenuItem value="">{t('super.tenants.attention_any')}</MenuItem>
            {attention.map((value) => <MenuItem key={value} value={value}>{t(`super.tenants.attention.${value}`, { defaultValue: value })}</MenuItem>)}
          </TextField>
          <Stack direction="row" spacing={0.5} sx={{ alignItems: 'center' }}>
            <TextField select size="small" value={filters.sort} label={t('super.tenants.sort')} onChange={(e) => go({ sort: e.target.value, dir: '', page: 1 })} sx={{ minWidth: 160 }}>
              {sorts.map((value) => <MenuItem key={value} value={value}>{t(`super.tenants.sort_by.${value}`, { defaultValue: value })}</MenuItem>)}
            </TextField>
            <Tooltip title={t(dir === 'asc' ? 'super.tenants.sort_dir_asc' : 'super.tenants.sort_dir_desc')}>
              <IconButton size="small" onClick={() => go({ dir: dir === 'asc' ? 'desc' : 'asc', page: 1 })} aria-label={t(dir === 'asc' ? 'super.tenants.sort_dir_asc' : 'super.tenants.sort_dir_desc')}>
                {dir === 'asc' ? <ArrowUpwardIcon fontSize="small" /> : <ArrowDownwardIcon fontSize="small" />}
              </IconButton>
            </Tooltip>
          </Stack>
        </Stack>

        <Card variant="outlined">
          <SuperTable
            columns={columns}
            rows={tenants}
            rowKey={(row) => row.public_id}
            empty={t(deleted ? 'super.tenants.empty_deleted' : 'super.tenants.empty')}
            label={t('super.tenants.title')}
            onRowClick={deleted ? undefined : open}
          />

          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'flex-end', p: 1.5 }}>
            <Typography variant="body2" color="text.secondary">
              {t('super.tenants.page_of', { current: n(meta.current_page), last: n(meta.last_page), total: n(meta.total) })}
            </Typography>
            <Button size="small" startIcon={<ChevronLeftIcon />} disabled={meta.current_page <= 1} onClick={() => go({ page: meta.current_page - 1 })}>
              {t('super.actions.prev')}
            </Button>
            <Button size="small" endIcon={<ChevronRightIcon />} disabled={meta.current_page >= meta.last_page} onClick={() => go({ page: meta.current_page + 1 })}>
              {t('super.actions.next')}
            </Button>
          </Stack>
        </Card>
      </Stack>
    </Box>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="super.tenants.title">{page}</PanelLayout>;
