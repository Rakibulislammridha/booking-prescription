// Every payment the platform received or attempted, with the gateway reference and who recorded it — the list
// the finance person reconciles against the bank statement, which is why the reference is monospaced and the
// export carries the idempotency key too.
import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import DownloadIcon from '@mui/icons-material/Download';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import { PAYMENT_METHOD_LABELS, PaymentStatusChip } from '@panel/Components/Super/Billing/InvoiceStatusChip';
import { Pager } from '@panel/Components/Super/Billing/Pager';
import type { BillingPaymentRow, ConsoleMeta } from '@panel/Components/Super/Billing/types';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

interface Filters { q: string; method: string; status: string; tenant: string; from: string; to: string }

type Props = PageProps<{
  payments: BillingPaymentRow[];
  meta: ConsoleMeta;
  filters: Filters;
  methods: string[];
  statuses: string[];
  method_totals: Record<string, number>;
}>;

const STATUS_LABEL: Record<string, string> = {
  pending: 'super.billing.payment_status.pending', succeeded: 'super.billing.payment_status.succeeded',
  failed: 'super.billing.payment_status.failed', refunded: 'super.billing.payment_status.refunded',
};

export default function Payments({ payments, meta, filters, methods, statuses, method_totals }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [q, setQ] = useState(filters.q);

  const go = (next: Partial<Filters> & { page?: number }): void => {
    const merged = { ...filters, ...next };
    const query: Record<string, string | number> = {};
    for (const key of ['q', 'method', 'status', 'tenant', 'from', 'to'] as const) if (merged[key] !== '') query[key] = merged[key];
    if (next.page !== undefined && next.page > 1) query.page = next.page;
    router.get(route('super.billing.payments.index'), query, { preserveState: true, replace: true, preserveScroll: true });
  };

  const exportHref = (): string => {
    const query: Record<string, string> = {};
    for (const key of ['q', 'method', 'status', 'tenant', 'from', 'to'] as const) if (filters[key] !== '') query[key] = filters[key];
    return route('super.billing.export', { kind: 'payments', ...query });
  };

  const columns: SuperColumn<BillingPaymentRow>[] = [
    { key: 'paid_at', label: t('super.billing.column.paid_at'), render: (row) => ((row.paid_at ?? row.created_at) === null ? '—' : formatDhaka(row.paid_at ?? row.created_at ?? '', 'D MMM YYYY, h:mm a', locale)) },
    {
      key: 'clinic',
      label: t('super.tenants.column.clinic'),
      bn: true,
      render: (row) => (row.tenant === null ? '—' : (
        <Box>
          {hasRoute('super.tenants.show') ? (
            <Box component={RouterLink} href={route('super.tenants.show', { tenant: row.tenant.public_id })} sx={{ color: 'primary.main', fontWeight: 600, textDecoration: 'none' }}>{row.tenant.name}</Box>
          ) : <Typography variant="body2" sx={{ fontWeight: 600 }}>{row.tenant.name}</Typography>}
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{row.tenant.slug}</Typography>
        </Box>
      )),
    },
    {
      key: 'invoice',
      label: t('super.billing.column.number'),
      render: (row) => (row.invoice === null ? '—' : (
        hasRoute('super.billing.invoices.index')
          ? <Box component={RouterLink} href={route('super.billing.invoices.index', { q: row.invoice.number })} sx={{ fontFamily: 'monospace', color: 'primary.main', textDecoration: 'none' }}>{row.invoice.number}</Box>
          : <Typography variant="body2" sx={{ fontFamily: 'monospace' }}>{row.invoice.number}</Typography>
      )),
    },
    { key: 'method', label: t('super.billing.method'), render: (row) => t(PAYMENT_METHOD_LABELS[row.method] ?? row.method) },
    { key: 'status', label: t('super.billing.column.status'), render: (row) => <PaymentStatusChip status={row.status} /> },
    { key: 'reference', label: t('super.billing.reference'), render: (row) => <Typography variant="body2" sx={{ fontFamily: 'monospace', wordBreak: 'break-all' }}>{row.gateway_txn_id ?? '—'}</Typography> },
    { key: 'amount', label: t('super.billing.column.amount'), align: 'right', render: (row) => <Typography variant="body2" sx={{ fontWeight: 600, fontVariantNumeric: 'tabular-nums' }}>{formatBdt(row.amount_paisa, locale)}</Typography> },
    { key: 'by', label: t('super.billing.column.recorded_by'), render: (row) => row.recorded_by ?? t('super.audit.system') },
  ];

  return (
    <Box>
      <Stack spacing={2}>
        <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap', alignItems: 'center' }}>
          <Typography variant="caption" color="text.secondary">{t('super.billing.payments.month_by_method')}</Typography>
          {methods.map((method) => (
            <Chip
              key={method}
              size="small"
              variant={filters.method === method ? 'filled' : 'outlined'}
              color={filters.method === method ? 'primary' : 'default'}
              label={`${t(PAYMENT_METHOD_LABELS[method] ?? method)} · ${formatBdt(method_totals[method] ?? 0, locale)}`}
              onClick={() => go({ method: filters.method === method ? '' : method, page: 1 })}
            />
          ))}
        </Stack>

        <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ alignItems: { md: 'center' } }} useFlexGap>
          <Box component="form" onSubmit={(e) => { e.preventDefault(); go({ q, page: 1 }); }} sx={{ flexGrow: 1, minWidth: 200 }}>
            <TextField size="small" fullWidth value={q} onChange={(e) => setQ(e.target.value)} label={t('super.billing.search')} placeholder={t('super.billing.payments.search_placeholder')} />
          </Box>
          <TextField select size="small" value={filters.status} label={t('super.billing.column.status')} onChange={(e) => go({ status: e.target.value, page: 1 })} sx={{ minWidth: 170 }}>
            <MenuItem value="">{t('super.tenants.status_any')}</MenuItem>
            {statuses.map((status) => <MenuItem key={status} value={status}>{t(STATUS_LABEL[status] ?? status)}</MenuItem>)}
          </TextField>
          <TextField size="small" type="date" value={filters.from} label={t('super.billing.from')} onChange={(e) => go({ from: e.target.value, page: 1 })} slotProps={{ inputLabel: { shrink: true } }} sx={{ minWidth: 160 }} />
          <TextField size="small" type="date" value={filters.to} label={t('super.billing.to')} onChange={(e) => go({ to: e.target.value, page: 1 })} slotProps={{ inputLabel: { shrink: true } }} sx={{ minWidth: 160 }} />
          {filters.tenant !== '' ? <Chip size="small" color="info" variant="outlined" label={t('super.audit.filtered_tenant', { name: payments.find((p) => p.tenant !== null)?.tenant?.name ?? filters.tenant })} onDelete={() => go({ tenant: '', page: 1 })} /> : null}
          {hasRoute('super.billing.export') ? (
            <Button size="small" startIcon={<DownloadIcon />} component="a" href={exportHref()}>{t('super.billing.export_csv')}</Button>
          ) : null}
        </Stack>

        <Card variant="outlined">
          <SuperTable columns={columns} rows={payments} rowKey={(row) => row.public_id} empty={t('super.billing.payments_empty')} label={t('super.billing.nav.payments')} />
          <Pager meta={meta} onPage={(page) => go({ page })} />
        </Card>
      </Stack>
    </Box>
  );
}

Payments.layout = (page: ReactNode) => <PanelLayout title="super.billing.page_title">{page}</PanelLayout>;
