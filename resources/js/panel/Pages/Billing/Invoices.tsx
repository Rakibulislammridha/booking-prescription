// Invoice list with status filters and the outstanding-dues total (BRIEF §5.I "due tracking").
import type { ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import SearchIcon from '@mui/icons-material/Search';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { InvoiceStatusChip } from '@panel/Components/Billing/InvoiceStatusChip';
import { route } from '@shared/routes';
import { formatBdt } from '@shared/format/money';
import { formatDateDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { BillingInvoice, InvoiceStatus, Paginated } from '@shared/types/models';

type Props = PageProps<{
  filters: { status: string; q: string };
  invoices: Paginated<BillingInvoice>;
  outstanding_paisa: number;
  statuses: InvoiceStatus[];
  can: Record<string, boolean>;
}>;

export default function Invoices({ filters, invoices, outstanding_paisa, statuses }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const go = (next: Partial<Props['filters']>) => router.get(route('panel.billing.index'), { ...filters, ...next }, { preserveState: true, replace: true });

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ alignItems: { sm: 'center' } }}>
        <Typography variant="h5" component="h1" sx={{ flexGrow: 1 }}>{t('billing.index.title')}</Typography>
        <Chip color={outstanding_paisa > 0 ? 'warning' : 'default'} label={t('billing.index.outstanding', { amount: formatBdt(outstanding_paisa, locale) })} />
      </Stack>

      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
        <TextField
          fullWidth
          size="small"
          defaultValue={filters.q}
          onKeyDown={(e) => { if (e.key === 'Enter') go({ q: (e.target as HTMLInputElement).value }); }}
          placeholder={t('billing.index.search')}
          slotProps={{ htmlInput: { 'aria-label': t('billing.index.search'), lang: 'bn' }, input: { startAdornment: <InputAdornment position="start"><SearchIcon /></InputAdornment> } }}
        />
        <TextField select size="small" sx={{ minWidth: 180 }} label={t('billing.index.status')} value={filters.status} onChange={(e) => go({ status: e.target.value })}>
          <MenuItem value="">{t('billing.index.all')}</MenuItem>
          <MenuItem value="due">{t('billing.index.only_due')}</MenuItem>
          {statuses.map((s) => <MenuItem key={s} value={s}>{t(`billing.status.${s}`)}</MenuItem>)}
        </TextField>
      </Stack>

      <Card variant="outlined">
        <CardContent>
          <Box sx={{ overflowX: 'auto' }}>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>{t('billing.index.number')}</TableCell>
                  <TableCell>{t('billing.index.patient')}</TableCell>
                  <TableCell>{t('billing.index.doctor')}</TableCell>
                  <TableCell>{t('billing.index.issued')}</TableCell>
                  <TableCell align="right">{t('billing.index.total')}</TableCell>
                  <TableCell align="right">{t('billing.index.due')}</TableCell>
                  <TableCell>{t('billing.index.status')}</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {invoices.data.length === 0 ? (
                  <TableRow><TableCell colSpan={7}><Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('billing.index.empty')}</Typography></TableCell></TableRow>
                ) : invoices.data.map((invoice) => (
                  <TableRow
                    key={invoice.public_id}
                    hover
                    sx={{ cursor: 'pointer' }}
                    tabIndex={0}
                    onClick={() => router.visit(route('panel.billing.invoices.show', { invoice: invoice.public_id }))}
                    onKeyDown={(e) => { if (e.key === 'Enter') router.visit(route('panel.billing.invoices.show', { invoice: invoice.public_id })); }}
                  >
                    <TableCell>{invoice.number}</TableCell>
                    <TableCell lang="bn">{invoice.patient?.name ?? '—'}</TableCell>
                    <TableCell lang="bn">{invoice.doctor?.name ?? '—'}</TableCell>
                    <TableCell>{invoice.issued_at ? formatDateDhaka(invoice.issued_at, locale) : '—'}</TableCell>
                    <TableCell align="right">{formatBdt(invoice.total_paisa, locale)}</TableCell>
                    <TableCell align="right">{formatBdt(invoice.due_paisa, locale)}</TableCell>
                    <TableCell><InvoiceStatusChip status={invoice.status} /></TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Box>
        </CardContent>
      </Card>
    </Stack>
  );
}

Invoices.layout = (page: ReactNode) => <PanelLayout title="billing.index.title">{page}</PanelLayout>;
