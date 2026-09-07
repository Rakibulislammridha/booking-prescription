// Collection and commission reports with CSV export (BRIEF §5.L). Every number comes from the server's query
// objects; nothing is recomputed here.
import type { ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Grid from '@mui/material/Grid';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import DownloadIcon from '@mui/icons-material/Download';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { route } from '@shared/routes';
import { formatBdt } from '@shared/format/money';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { BillingCollectionReport, BillingCommissionReport } from '@shared/types/models';

type Filters = { from: string; to: string; branch: string | null; doctor: string | null; method: string | null };

type Props = PageProps<{
  filters: Filters;
  collection: BillingCollectionReport;
  commission: BillingCommissionReport;
  doctors: Array<{ public_id: string; name: string }>;
  branch_options: Array<{ public_id: string; name: string }>;
}>;

function Stat({ label, value }: { label: string; value: string }) {
  return <Card variant="outlined"><CardContent><Typography variant="caption" color="text.secondary">{label}</Typography><Typography variant="h5">{value}</Typography></CardContent></Card>;
}

export default function Reports({ filters, collection, commission, doctors, branch_options }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const go = (next: Partial<Filters>) => router.get(route('panel.billing.reports.index'), { ...filters, ...next }, { preserveState: true, replace: true });
  const exportUrl = (kind: 'collection' | 'commission') => route('panel.billing.reports.export', { ...filters, kind });

  return (
    <Stack spacing={2}>
      <Typography variant="h5" component="h1">{t('billing.reports.title')}</Typography>

      <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
        <TextField type="date" size="small" label={t('billing.reports.from')} value={filters.from} onChange={(e) => e.target.value && go({ from: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} />
        <TextField type="date" size="small" label={t('billing.reports.to')} value={filters.to} onChange={(e) => e.target.value && go({ to: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} />
        <TextField select size="small" sx={{ minWidth: 160 }} label={t('billing.reports.doctor')} value={filters.doctor ?? ''} onChange={(e) => go({ doctor: e.target.value || null })}>
          <MenuItem value="">{t('billing.reports.all')}</MenuItem>
          {doctors.map((d) => <MenuItem key={d.public_id} value={d.public_id}>{d.name}</MenuItem>)}
        </TextField>
        <TextField select size="small" sx={{ minWidth: 160 }} label={t('billing.reports.branch')} value={filters.branch ?? ''} onChange={(e) => go({ branch: e.target.value || null })}>
          <MenuItem value="">{t('billing.reports.all')}</MenuItem>
          {branch_options.map((b) => <MenuItem key={b.public_id} value={b.public_id}>{b.name}</MenuItem>)}
        </TextField>
      </Stack>

      <Grid container spacing={2}>
        <Grid size={{ xs: 6, md: 3 }}><Stat label={t('billing.reports.gross')} value={formatBdt(collection.totals.gross_paisa, locale)} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><Stat label={t('billing.reports.refunds')} value={formatBdt(collection.totals.refunds_paisa, locale)} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><Stat label={t('billing.reports.net')} value={formatBdt(collection.totals.net_paisa, locale)} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><Stat label={t('billing.reports.doctor_share')} value={formatBdt(commission.totals.doctor_share_paisa, locale)} /></Grid>
      </Grid>

      <Card variant="outlined">
        <CardContent>
          <Stack direction="row" sx={{ alignItems: 'center', mb: 1 }}>
            <Typography variant="subtitle1" sx={{ flexGrow: 1 }}>{t('billing.reports.by_day')}</Typography>
            <Button size="small" startIcon={<DownloadIcon />} href={exportUrl('collection')}>{t('billing.reports.export')}</Button>
          </Stack>
          <Box sx={{ overflowX: 'auto' }}>
            <Table size="small">
              <TableHead><TableRow><TableCell>{t('billing.reports.date')}</TableCell><TableCell align="right">{t('billing.reports.gross')}</TableCell><TableCell align="right">{t('billing.reports.refunds')}</TableCell><TableCell align="right">{t('billing.reports.net')}</TableCell></TableRow></TableHead>
              <TableBody>
                {collection.by_day.length === 0 ? (
                  <TableRow><TableCell colSpan={4}><Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('billing.reports.empty')}</Typography></TableCell></TableRow>
                ) : collection.by_day.map((row) => (
                  <TableRow key={row.date}>
                    <TableCell>{row.date}</TableCell>
                    <TableCell align="right">{formatBdt(row.gross_paisa, locale)}</TableCell>
                    <TableCell align="right">{formatBdt(row.refunds_paisa, locale)}</TableCell>
                    <TableCell align="right">{formatBdt(row.net_paisa, locale)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Box>
        </CardContent>
      </Card>

      <Card variant="outlined">
        <CardContent>
          <Typography variant="subtitle1" sx={{ mb: 1 }}>{t('billing.reports.by_method')}</Typography>
          <Box sx={{ overflowX: 'auto' }}>
            <Table size="small">
              <TableHead><TableRow><TableCell>{t('billing.reports.method')}</TableCell><TableCell align="right">{t('billing.reports.net')}</TableCell><TableCell align="right">{t('billing.reports.count')}</TableCell></TableRow></TableHead>
              <TableBody>
                {collection.by_method.map((row) => (
                  <TableRow key={row.key ?? 'unknown'}>
                    <TableCell>{row.key ? t(`billing.method.${row.key}`, { defaultValue: row.key }) : '—'}</TableCell>
                    <TableCell align="right">{formatBdt(row.net_paisa, locale)}</TableCell>
                    <TableCell align="right">{row.count}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Box>
        </CardContent>
      </Card>

      <Card variant="outlined">
        <CardContent>
          <Stack direction="row" sx={{ alignItems: 'center', mb: 1 }}>
            <Typography variant="subtitle1" sx={{ flexGrow: 1 }}>{t('billing.reports.commission')}</Typography>
            <Button size="small" startIcon={<DownloadIcon />} href={exportUrl('commission')}>{t('billing.reports.export')}</Button>
          </Stack>
          <Box sx={{ overflowX: 'auto' }}>
            <Table size="small">
              <TableHead><TableRow><TableCell>{t('billing.reports.doctor')}</TableCell><TableCell>{t('billing.reports.branch')}</TableCell><TableCell>{t('billing.reports.item_type')}</TableCell><TableCell align="right">{t('billing.reports.billed')}</TableCell><TableCell align="right">{t('billing.reports.doctor_share')}</TableCell><TableCell align="right">{t('billing.reports.clinic_share')}</TableCell></TableRow></TableHead>
              <TableBody>
                {commission.rows.length === 0 ? (
                  <TableRow><TableCell colSpan={6}><Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('billing.reports.empty')}</Typography></TableCell></TableRow>
                ) : commission.rows.map((row, i) => (
                  <TableRow key={`${row.doctor_id ?? 0}-${row.branch_id ?? 0}-${row.item_type}-${i}`}>
                    <TableCell lang="bn">{row.doctor_name ?? '—'}</TableCell>
                    <TableCell lang="bn">{row.branch_name ?? '—'}</TableCell>
                    <TableCell>{t(`billing.item_type.${row.item_type}`)}</TableCell>
                    <TableCell align="right">{formatBdt(row.billed_paisa, locale)}</TableCell>
                    <TableCell align="right">{formatBdt(row.doctor_share_paisa, locale)}</TableCell>
                    <TableCell align="right">{formatBdt(row.clinic_share_paisa, locale)}</TableCell>
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

Reports.layout = (page: ReactNode) => <PanelLayout title="billing.reports.title">{page}</PanelLayout>;
