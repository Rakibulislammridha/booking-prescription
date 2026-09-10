// Every platform invoice, across every clinic — the accounts-receivable ledger with the four things an operator
// does to a row (issue, record a payment, void, print). `past_due` is the accountant's filter: open AND late.
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
import DownloadIcon from '@mui/icons-material/Download';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { BillingNav } from '@panel/Components/Super/Billing/BillingNav';
import { InvoiceTable } from '@panel/Components/Super/Billing/InvoiceTable';
import { Pager } from '@panel/Components/Super/Billing/Pager';
import { RecordPaymentDialog } from '@panel/Components/Super/Billing/RecordPaymentDialog';
import { VoidInvoiceDialog } from '@panel/Components/Super/Billing/VoidInvoiceDialog';
import type { BillingInvoiceRow, ConsoleMeta } from '@panel/Components/Super/Billing/types';
import { formatNumber } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

interface Filters { q: string; status: string; tenant: string; from: string; to: string }

type Props = PageProps<{
  invoices: BillingInvoiceRow[];
  meta: ConsoleMeta;
  filters: Filters;
  statuses: string[];
  status_counts: Record<string, number>;
  methods: string[];
}>;

const STATUS_LABEL: Record<string, string> = {
  draft: 'saas.invoice.status.draft', issued: 'saas.invoice.status.issued', paid: 'saas.invoice.status.paid',
  overdue: 'saas.invoice.status.overdue', void: 'saas.invoice.status.void',
};

export default function Invoices({ invoices, meta, filters, statuses, status_counts }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const n = (value: number): string => formatNumber(value, locale);
  const [q, setQ] = useState(filters.q);
  const [paying, setPaying] = useState<BillingInvoiceRow | null>(null);
  const [voiding, setVoiding] = useState<BillingInvoiceRow | null>(null);

  const go = (next: Partial<Filters> & { page?: number }): void => {
    const merged = { ...filters, ...next };
    const query: Record<string, string | number> = {};
    for (const key of ['q', 'status', 'tenant', 'from', 'to'] as const) if (merged[key] !== '') query[key] = merged[key];
    if (next.page !== undefined && next.page > 1) query.page = next.page;
    router.get(route('super.billing.invoices.index'), query, { preserveState: true, replace: true, preserveScroll: true });
  };

  const exportHref = (): string => {
    const query: Record<string, string> = {};
    for (const key of ['q', 'status', 'tenant', 'from', 'to'] as const) if (filters[key] !== '') query[key] = filters[key];
    return route('super.billing.export', { kind: 'invoices', ...query });
  };

  const tenantName = invoices.find((row) => row.tenant !== null)?.tenant?.name ?? filters.tenant;

  return (
    <Box>
      <SuperNav />
      <BillingNav />

      <Stack spacing={2}>
        <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ alignItems: { md: 'center' } }} useFlexGap>
          <Box component="form" onSubmit={(e) => { e.preventDefault(); go({ q, page: 1 }); }} sx={{ flexGrow: 1, minWidth: 200 }}>
            <TextField size="small" fullWidth value={q} onChange={(e) => setQ(e.target.value)} label={t('super.billing.search')} placeholder={t('super.billing.invoices.search_placeholder')} />
          </Box>
          <TextField select size="small" value={filters.status} label={t('super.billing.column.status')} onChange={(e) => go({ status: e.target.value, page: 1 })} sx={{ minWidth: 190 }}>
            <MenuItem value="">{t('super.tenants.status_any')}</MenuItem>
            <MenuItem value="past_due">{t('super.billing.past_due')} ({n(status_counts.past_due ?? 0)})</MenuItem>
            {statuses.map((status) => (
              <MenuItem key={status} value={status}>{t(STATUS_LABEL[status] ?? status)} ({n(status_counts[status] ?? 0)})</MenuItem>
            ))}
          </TextField>
          <TextField size="small" type="date" value={filters.from} label={t('super.billing.from')} onChange={(e) => go({ from: e.target.value, page: 1 })} slotProps={{ inputLabel: { shrink: true } }} sx={{ minWidth: 160 }} />
          <TextField size="small" type="date" value={filters.to} label={t('super.billing.to')} onChange={(e) => go({ to: e.target.value, page: 1 })} slotProps={{ inputLabel: { shrink: true } }} sx={{ minWidth: 160 }} />
          {filters.tenant !== '' ? (
            <Chip size="small" color="info" variant="outlined" label={t('super.audit.filtered_tenant', { name: tenantName })} onDelete={() => go({ tenant: '', page: 1 })} />
          ) : null}
          {hasRoute('super.billing.export') ? (
            <Button size="small" startIcon={<DownloadIcon />} component="a" href={exportHref()}>{t('super.billing.export_csv')}</Button>
          ) : null}
        </Stack>

        <Card variant="outlined">
          <InvoiceTable
            invoices={invoices}
            showTenant
            empty={t('super.billing.invoices.empty')}
            onIssue={(invoice) => router.post(route('super.billing.invoices.issue', { invoice: invoice.public_id }), {}, { preserveScroll: true })}
            onPay={setPaying}
            onVoid={setVoiding}
          />
          <Pager meta={meta} onPage={(page) => go({ page })} />
        </Card>
      </Stack>

      <RecordPaymentDialog invoice={paying} action={(invoice) => route('super.billing.invoices.pay', { invoice: invoice.public_id })} onClose={() => setPaying(null)} />
      <VoidInvoiceDialog invoice={voiding} action={(invoice) => route('super.billing.invoices.void', { invoice: invoice.public_id })} onClose={() => setVoiding(null)} />
    </Box>
  );
}

Invoices.layout = (page: ReactNode) => <PanelLayout title="super.billing.page_title">{page}</PanelLayout>;
