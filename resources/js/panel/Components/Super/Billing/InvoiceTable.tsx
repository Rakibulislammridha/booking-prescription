// The invoice ledger as a table — shared by the platform-wide Invoices screen and one clinic's billing card.
// Row actions follow the state machine exactly: a draft can be issued or voided, an open invoice takes a
// payment or a void (until money lands), a paid one is only ever printed.
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import IconButton from '@mui/material/IconButton';
import Stack from '@mui/material/Stack';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import PictureAsPdfIcon from '@mui/icons-material/PictureAsPdf';
import PrintIcon from '@mui/icons-material/Print';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SuperTable, type SuperColumn } from '../SuperTable';
import { InvoiceStatusChip } from './InvoiceStatusChip';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { Locale } from '@shared/types/shared-props';
import type { BillingInvoiceRow } from './types';

const DATE = 'D MMM YYYY';

function when(iso: string | null, locale: Locale): string {
  return iso === null ? '—' : formatDhaka(iso, DATE, locale);
}

export interface InvoiceTableProps {
  invoices: BillingInvoiceRow[];
  /** The platform-wide list names the clinic; a clinic's own card does not. */
  showTenant?: boolean;
  empty: string;
  onIssue: (invoice: BillingInvoiceRow) => void;
  onPay: (invoice: BillingInvoiceRow) => void;
  onVoid: (invoice: BillingInvoiceRow) => void;
}

export function canVoid(invoice: BillingInvoiceRow): boolean {
  return (invoice.status === 'draft' || invoice.status === 'issued' || invoice.status === 'overdue') && invoice.paid_paisa === 0;
}

export function canPay(invoice: BillingInvoiceRow): boolean {
  return (invoice.status === 'issued' || invoice.status === 'overdue') && invoice.due_paisa > 0;
}

export function InvoiceTable({ invoices, showTenant = false, empty, onIssue, onPay, onVoid }: InvoiceTableProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const n = (value: number): string => formatNumber(value, locale);
  const printable = hasRoute('super.billing.invoices.print');
  const pdf = hasRoute('super.billing.invoices.pdf');

  const columns: SuperColumn<BillingInvoiceRow>[] = [
    {
      key: 'number',
      label: t('super.billing.column.number'),
      render: (row) => (
        <Box>
          <Typography variant="body2" sx={{ fontFamily: 'monospace', fontWeight: 600 }}>{row.number}</Typography>
          {row.dunning_step > 0 ? (
            <Typography variant="caption" color="warning.main" sx={{ display: 'block' }}>{t('super.billing.dunning_step', { step: n(row.dunning_step) })}</Typography>
          ) : null}
        </Box>
      ),
    },
    ...(showTenant ? [{
      key: 'tenant',
      label: t('super.billing.column.clinic'),
      bn: true,
      render: (row: BillingInvoiceRow) => row.tenant === null ? '—' : (
        <Box>
          {hasRoute('super.tenants.show') ? (
            <Box component={RouterLink} href={route('super.tenants.show', { tenant: row.tenant.public_id })} sx={{ color: 'primary.main', fontWeight: 600, textDecoration: 'none' }}>
              {row.tenant.name}
            </Box>
          ) : <Typography variant="body2" sx={{ fontWeight: 600 }}>{row.tenant.name}</Typography>}
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{row.tenant.slug}</Typography>
        </Box>
      ),
    } satisfies SuperColumn<BillingInvoiceRow>] : []),
    { key: 'status', label: t('super.billing.column.status'), render: (row) => <InvoiceStatusChip status={row.status} pastDue={row.is_past_due} /> },
    {
      key: 'period',
      label: t('super.billing.column.period'),
      render: (row) => (row.period_start === null ? '—' : `${formatDhaka(row.period_start, DATE, locale)} – ${row.period_end === null ? '' : formatDhaka(row.period_end, DATE, locale)}`),
    },
    {
      key: 'total',
      label: t('super.billing.column.total'),
      align: 'right',
      render: (row) => (
        <Box>
          <Typography variant="body2" sx={{ fontVariantNumeric: 'tabular-nums', fontWeight: 600 }}>{formatBdt(row.total_paisa, locale)}</Typography>
          <Typography variant="caption" color={row.due_paisa > 0 && row.status !== 'void' && row.status !== 'draft' ? 'error.main' : 'text.secondary'} sx={{ display: 'block' }}>
            {row.status === 'paid' ? t('super.billing.paid_of', { paid: formatBdt(row.paid_paisa, locale) }) : t('super.billing.due_of', { due: formatBdt(row.due_paisa, locale) })}
          </Typography>
        </Box>
      ),
    },
    { key: 'issued_at', label: t('super.billing.column.issued_at'), render: (row) => when(row.issued_at, locale) },
    { key: 'due_at', label: t('super.billing.column.due_at'), render: (row) => when(row.due_at, locale) },
    {
      key: 'actions',
      label: t('super.billing.column.actions'),
      align: 'right',
      render: (row) => (
        <Stack direction="row" spacing={0.5} sx={{ justifyContent: 'flex-end', alignItems: 'center' }}>
          {row.status === 'draft' ? <Button size="small" onClick={() => onIssue(row)}>{t('super.billing.issue')}</Button> : null}
          {canPay(row) ? <Button size="small" variant="outlined" onClick={() => onPay(row)}>{t('super.billing.record_payment')}</Button> : null}
          {canVoid(row) ? <Button size="small" color="error" onClick={() => onVoid(row)}>{t('super.billing.void')}</Button> : null}
          {printable ? (
            <Tooltip title={t('super.billing.print')}>
              <IconButton size="small" component="a" href={route('super.billing.invoices.print', { invoice: row.public_id })} target="_blank" rel="noopener" aria-label={t('super.billing.print')}>
                <PrintIcon fontSize="small" />
              </IconButton>
            </Tooltip>
          ) : null}
          {pdf ? (
            <Tooltip title={t('super.billing.pdf')}>
              <IconButton size="small" component="a" href={route('super.billing.invoices.pdf', { invoice: row.public_id })} target="_blank" rel="noopener" aria-label={t('super.billing.pdf')}>
                <PictureAsPdfIcon fontSize="small" />
              </IconButton>
            </Tooltip>
          ) : null}
        </Stack>
      ),
    },
  ];

  return <SuperTable columns={columns} rows={invoices} rowKey={(row) => row.public_id} empty={empty} label={t('super.billing.title')} />;
}
