// Every clinic on the platform, densest-first. Search and the status filter are server-side and debounced, so a
// list of a few thousand tenants stays one query and the URL always describes what is on screen.
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import SearchIcon from '@mui/icons-material/Search';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import { HealthChip, StatusChip } from '@panel/Components/Super/StatusChip';
import type { ConsoleMeta, PlatformTotals, TenantRow } from '@panel/Components/Super/types';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  tenants: TenantRow[];
  meta: ConsoleMeta & { per_page: number };
  filters: { q: string; status: string };
  statuses: string[];
  totals: PlatformTotals;
}>;

const SEARCH_DEBOUNCE_MS = 300;

export default function Index({ tenants, meta, filters, statuses, totals }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [q, setQ] = useState(filters.q);
  const [status, setStatus] = useState(filters.status);
  const typed = useRef(false);
  const n = (value: number): string => formatNumber(value, locale);

  const go = (params: { q?: string; status?: string; page?: number }): void => {
    const query: Record<string, string | number> = {};
    const nextQ = params.q ?? q;
    const nextStatus = params.status ?? status;
    if (nextQ !== '') query.q = nextQ;
    if (nextStatus !== '') query.status = nextStatus;
    if (params.page !== undefined && params.page > 1) query.page = params.page;
    router.get(route('super.tenants.index'), query, { preserveState: true, replace: true, preserveScroll: true });
  };

  // Debounce only the free-text field; the select navigates at once because it is a deliberate click.
  useEffect(() => {
    if (!typed.current) return;
    const timer = window.setTimeout(() => go({ q, page: 1 }), SEARCH_DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q]);

  const open = (tenant: TenantRow): void => {
    router.visit(route('super.tenants.show', { tenant: tenant.public_id }));
  };

  const cap = (used: number, limit: number | null): string => `${n(used)} / ${limit === null ? '∞' : n(limit)}`;

  const columns: SuperColumn<TenantRow>[] = [
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
    { key: 'health', label: t('super.tenants.column.health'), render: (row) => <HealthChip health={row.health} /> },
    { key: 'doctors', label: t('super.tenants.column.doctors'), align: 'right', render: (row) => cap(row.counts.doctors, row.limits.doctors) },
    { key: 'branches', label: t('super.tenants.column.branches'), align: 'right', render: (row) => cap(row.counts.branches, row.limits.branches) },
    { key: 'appointments', label: t('super.tenants.column.appointments'), align: 'right', render: (row) => cap(row.counts.appointments, row.limits.appointments) },
    {
      key: 'arrears',
      label: t('super.tenants.column.arrears'),
      align: 'right',
      render: (row) => (
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
        <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap' }}>
          {summary.map((item) => (
            <Chip key={item.key} size="small" variant="outlined" label={`${t(item.label)} · ${n(item.value)}`} />
          ))}
        </Stack>

        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' } }}>
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
          <TextField
            select
            size="small"
            value={status}
            label={t('super.tenants.status_filter')}
            onChange={(e) => { setStatus(e.target.value); go({ status: e.target.value, page: 1 }); }}
            sx={{ minWidth: 200 }}
          >
            <MenuItem value="">{t('super.tenants.status_any')}</MenuItem>
            {statuses.map((value) => <MenuItem key={value} value={value}>{t(`super.status.${value}`, { defaultValue: value })}</MenuItem>)}
          </TextField>
        </Stack>

        <Card variant="outlined">
          <SuperTable
            columns={columns}
            rows={tenants}
            rowKey={(row) => row.public_id}
            empty={t('super.tenants.empty')}
            label={t('super.tenants.title')}
            onRowClick={open}
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
