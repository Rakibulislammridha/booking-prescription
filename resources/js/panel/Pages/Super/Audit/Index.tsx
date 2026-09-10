// `public.audit_logs_central`, newest first: who did what to which clinic, from where. Read-only by design —
// the only action on this page is exporting it, and that export is itself a row in the log.
//
// Filters are server-side and live in the URL (clinic, operator, action, a Dhaka date range, free text on the
// target), so a link to "everything about invoice 4410 last week" can be pasted to a colleague. A row opens the
// detail drawer: request context and the before/after as a diff.
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
import DownloadIcon from '@mui/icons-material/Download';
import SearchIcon from '@mui/icons-material/Search';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { AuditDetailDrawer } from '@panel/Components/Super/AuditDetailDrawer';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import type { AuditDetailRow, AuditFilterOptions, AuditFilters, ConsoleMeta } from '@panel/Components/Super/types';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  logs: AuditDetailRow[];
  meta: ConsoleMeta;
  filters: AuditFilters;
  actions: string[];
  options: AuditFilterOptions;
  export_limit: number;
}>;

const SEARCH_DEBOUNCE_MS = 350;

function query(filters: AuditFilters, page?: number): Record<string, string | number> {
  const out: Record<string, string | number> = {};
  for (const key of ['tenant', 'admin', 'action', 'from', 'to', 'q'] as const) {
    if (filters[key] !== '') out[key] = filters[key];
  }
  if (page !== undefined && page > 1) out.page = page;
  return out;
}

export default function Index({ logs, meta, filters, actions, options, export_limit }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [q, setQ] = useState(filters.q);
  const [open, setOpen] = useState<AuditDetailRow | null>(null);
  const typed = useRef(false);

  const go = (next: Partial<AuditFilters>, page?: number): void => {
    router.get(route('super.audit.index'), query({ ...filters, ...next }, page), { preserveState: true, replace: true, preserveScroll: true });
  };

  // Debounce only the free-text field; a select navigates at once because it is a deliberate click.
  useEffect(() => {
    if (!typed.current) return;
    const timer = window.setTimeout(() => go({ q }), SEARCH_DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q]);

  const active = (['tenant', 'admin', 'action', 'from', 'to', 'q'] as const).filter((key) => filters[key] !== '').length;
  const exportHref = route('super.audit.export', query(filters));
  const actionLabel = (action: string): string => t(`super.audit.action.${action}`, { defaultValue: action });

  const columns: SuperColumn<AuditDetailRow>[] = [
    {
      key: 'when',
      label: t('super.audit.column.when'),
      render: (row) => (
        <Typography variant="body2" sx={{ whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums' }}>
          {row.occurred_at === null ? '—' : formatDhaka(row.occurred_at, 'D MMM YYYY, h:mm a', locale)}
        </Typography>
      ),
    },
    { key: 'action', label: t('super.audit.column.action'), render: (row) => <Chip size="small" variant="outlined" label={actionLabel(row.action)} /> },
    { key: 'actor', label: t('super.audit.column.actor'), bn: true, render: (row) => <Typography variant="body2" sx={{ fontWeight: 600 }}>{row.actor ?? t('super.audit.system')}</Typography> },
    {
      key: 'tenant',
      label: t('super.audit.column.tenant'),
      bn: true,
      render: (row) => (row.tenant === null
        ? <Typography variant="body2" color="text.secondary">{t('super.audit.no_tenant')}</Typography>
        : <Box><Typography variant="body2">{row.tenant.name}</Typography><Typography variant="caption" color="text.secondary">{row.tenant.slug}</Typography></Box>),
    },
    {
      key: 'target',
      label: t('super.audit.column.target'),
      render: (row) => (row.auditable_type ? (
        <Typography variant="body2" color="text.secondary" sx={{ fontFamily: 'monospace', fontSize: 12 }}>
          {row.auditable_type.split('\\').pop()}{row.auditable_id === null ? '' : ` #${formatNumber(row.auditable_id, locale)}`}
        </Typography>
      ) : '—'),
    },
    { key: 'ip', label: t('super.audit.detail.ip'), render: (row) => <Typography variant="caption" sx={{ fontFamily: 'monospace' }}>{row.ip ?? '—'}</Typography> },
  ];

  return (
    <Box>
      <SuperNav />

      <Stack spacing={2}>
        <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(6, 1fr)' } }}>
          <TextField
            select size="small" value={filters.action} label={t('super.audit.filter_action')}
            onChange={(e) => go({ action: e.target.value })}
          >
            <MenuItem value="">{t('super.audit.any_action')}</MenuItem>
            {actions.map((action) => <MenuItem key={action} value={action}>{actionLabel(action)}</MenuItem>)}
          </TextField>
          <TextField
            select size="small" value={filters.admin} label={t('super.audit.filter.admin')}
            onChange={(e) => go({ admin: e.target.value })}
          >
            <MenuItem value="">{t('super.audit.filter.any_admin')}</MenuItem>
            {options.admins.map((admin) => <MenuItem key={admin.id} value={String(admin.id)}>{admin.name}{admin.is_active ? '' : ` · ${t('super.admins.inactive')}`}</MenuItem>)}
          </TextField>
          <TextField
            select size="small" value={filters.tenant} label={t('super.audit.filter.tenant')}
            onChange={(e) => go({ tenant: e.target.value })}
            slotProps={{ select: { renderValue: (value) => options.tenants.find((tenant) => tenant.public_id === value)?.name ?? String(value) } }}
          >
            <MenuItem value="">{t('super.audit.filter.any_tenant')}</MenuItem>
            {options.tenants.map((tenant) => <MenuItem key={tenant.public_id} value={tenant.public_id} lang="bn">{tenant.name} · {tenant.slug}</MenuItem>)}
          </TextField>
          <TextField
            size="small" type="date" label={t('super.audit.filter.from')} value={filters.from}
            onChange={(e) => go({ from: e.target.value })} slotProps={{ inputLabel: { shrink: true }, htmlInput: { max: filters.to || undefined } }}
          />
          <TextField
            size="small" type="date" label={t('super.audit.filter.to')} value={filters.to}
            onChange={(e) => go({ to: e.target.value })} slotProps={{ inputLabel: { shrink: true }, htmlInput: { min: filters.from || undefined } }}
          />
          <TextField
            size="small" value={q} placeholder={t('super.audit.filter.q_placeholder')}
            onChange={(e) => { typed.current = true; setQ(e.target.value); }}
            slotProps={{
              htmlInput: { 'aria-label': t('super.audit.filter.q') },
              input: { startAdornment: <InputAdornment position="start"><SearchIcon fontSize="small" /></InputAdornment> },
            }}
          />
        </Box>

        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' } }}>
          <Typography variant="body2" color="text.secondary" sx={{ flexGrow: 1 }}>
            {t('super.audit.total', { count: formatNumber(meta.total, locale) })}
            {active > 0 ? ` · ${t('super.audit.filter.active', { count: formatNumber(active, locale) })}` : ''}
          </Typography>
          {active > 0 ? (
            <Button size="small" onClick={() => { typed.current = false; setQ(''); go({ tenant: '', admin: '', action: '', from: '', to: '', q: '' }); }}>
              {t('super.audit.filter.clear')}
            </Button>
          ) : null}
          <Button
            size="small" variant="outlined" startIcon={<DownloadIcon />} component="a" href={exportHref}
            disabled={meta.total === 0} data-testid="audit-export"
          >
            {t('super.audit.export')}
          </Button>
        </Stack>
        {meta.total > export_limit ? <Typography variant="caption" color="text.secondary">{t('super.audit.export_capped', { limit: formatNumber(export_limit, locale) })}</Typography> : null}

        <Card variant="outlined">
          <SuperTable
            columns={columns}
            rows={logs}
            rowKey={(row) => String(row.id)}
            empty={t('super.audit.empty')}
            label={t('super.audit.title')}
            onRowClick={(row) => setOpen(row)}
          />

          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'flex-end', p: 1.5 }}>
            <Typography variant="body2" color="text.secondary">
              {t('super.tenants.page_of', {
                current: formatNumber(meta.current_page, locale),
                last: formatNumber(meta.last_page, locale),
                total: formatNumber(meta.total, locale),
              })}
            </Typography>
            <Button size="small" startIcon={<ChevronLeftIcon />} disabled={meta.current_page <= 1} onClick={() => go({}, meta.current_page - 1)}>
              {t('super.actions.prev')}
            </Button>
            <Button size="small" endIcon={<ChevronRightIcon />} disabled={meta.current_page >= meta.last_page} onClick={() => go({}, meta.current_page + 1)}>
              {t('super.actions.next')}
            </Button>
          </Stack>
        </Card>
      </Stack>

      <AuditDetailDrawer row={open} onClose={() => setOpen(null)} />
    </Box>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="super.audit.title">{page}</PanelLayout>;
