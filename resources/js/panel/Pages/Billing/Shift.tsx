// The cash drawer (BRIEF §5.F): open with a float, watch the live expected total, close with what was counted.
import { useState, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Grid from '@mui/material/Grid';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { route } from '@shared/routes';
import { formatBdt, parseBdt } from '@shared/format/money';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { BillingCashShift, BillingShiftTotals } from '@shared/types/models';

type Props = PageProps<{
  open_shift: BillingCashShift | null;
  live_totals: BillingShiftTotals | null;
  recent: BillingCashShift[];
  active_branch: { public_id: string; name: string } | null;
  can: { open: boolean };
}>;

function Stat({ label, value }: { label: string; value: string }) {
  return <Card variant="outlined"><CardContent><Typography variant="caption" color="text.secondary">{label}</Typography><Typography variant="h5">{value}</Typography></CardContent></Card>;
}

export default function Shift({ open_shift, live_totals, recent, active_branch, can }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [counted, setCounted] = useState('');
  const openForm = useForm({ opening_float_paisa: 0, branch: active_branch?.public_id ?? null });
  const closeForm = useForm({ counted_cash_paisa: 0, note: '' });

  const countedPaisa = parseBdt(counted);
  const expected = live_totals?.expected_cash_paisa ?? 0;
  const variance = countedPaisa === null ? null : countedPaisa - expected;

  return (
    <Stack spacing={2}>
      <Typography variant="h5" component="h1">{t('billing.shift.title')}{active_branch ? ` · ${active_branch.name}` : ''}</Typography>

      {open_shift === null ? (
        <Card variant="outlined">
          <CardContent>
            <Typography variant="subtitle1" sx={{ mb: 2 }}>{t('billing.shift.no_open')}</Typography>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ alignItems: { sm: 'center' } }}>
              <TextField
                label={t('billing.shift.opening_float')}
                size="small"
                onChange={(e) => openForm.setData('opening_float_paisa', parseBdt(e.target.value) ?? 0)}
                slotProps={{ htmlInput: { inputMode: 'decimal', 'aria-label': t('billing.shift.opening_float') } }}
              />
              <Button variant="contained" disabled={!can.open || openForm.processing} onClick={() => openForm.post(route('panel.billing.shift.open'))}>{t('billing.shift.open')}</Button>
            </Stack>
          </CardContent>
        </Card>
      ) : (
        <>
          <Grid container spacing={2}>
            <Grid size={{ xs: 6, md: 3 }}><Stat label={t('billing.shift.opening_float')} value={formatBdt(open_shift.opening_float_paisa, locale)} /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><Stat label={t('billing.shift.cash_in')} value={formatBdt(live_totals?.cash_in_paisa ?? 0, locale)} /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><Stat label={t('billing.shift.cash_refunds')} value={formatBdt(live_totals?.cash_refunds_paisa ?? 0, locale)} /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><Stat label={t('billing.shift.expected')} value={formatBdt(expected, locale)} /></Grid>
          </Grid>

          <Card variant="outlined">
            <CardContent>
              <Typography variant="subtitle1" sx={{ mb: 2 }}>{t('billing.shift.close_title')}</Typography>
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ alignItems: { sm: 'center' } }}>
                <TextField
                  label={t('billing.shift.counted')}
                  size="small"
                  value={counted}
                  onChange={(e) => { setCounted(e.target.value); closeForm.setData('counted_cash_paisa', parseBdt(e.target.value) ?? 0); }}
                  slotProps={{ htmlInput: { inputMode: 'decimal', 'aria-label': t('billing.shift.counted') } }}
                />
                <TextField label={t('billing.shift.note')} size="small" onChange={(e) => closeForm.setData('note', e.target.value)} slotProps={{ htmlInput: { maxLength: 255, lang: 'bn' } }} />
                <Button variant="contained" disabled={countedPaisa === null || closeForm.processing} onClick={() => closeForm.post(route('panel.billing.shift.close', { shift: open_shift.id }))}>{t('billing.shift.close')}</Button>
              </Stack>
              {variance !== null && variance !== 0 ? (
                <Alert severity={variance < 0 ? 'error' : 'warning'} sx={{ mt: 2 }}>
                  {t('billing.shift.variance_warning', { amount: formatBdt(variance, locale) })}
                </Alert>
              ) : null}
            </CardContent>
          </Card>
        </>
      )}

      <Card variant="outlined">
        <CardContent>
          <Typography variant="subtitle1" sx={{ mb: 1 }}>{t('billing.shift.recent')}</Typography>
          <Box sx={{ overflowX: 'auto' }}>
            <Table size="small">
              <TableHead><TableRow><TableCell>{t('billing.shift.user')}</TableCell><TableCell>{t('billing.shift.opened')}</TableCell><TableCell>{t('billing.shift.closed')}</TableCell><TableCell align="right">{t('billing.shift.expected')}</TableCell><TableCell align="right">{t('billing.shift.counted')}</TableCell><TableCell align="right">{t('billing.shift.variance')}</TableCell></TableRow></TableHead>
              <TableBody>
                {recent.length === 0 ? (
                  <TableRow><TableCell colSpan={6}><Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('billing.shift.empty')}</Typography></TableCell></TableRow>
                ) : recent.map((shift) => (
                  <TableRow key={shift.id}>
                    <TableCell lang="bn">{shift.user?.name ?? '—'}</TableCell>
                    <TableCell>{formatDhaka(shift.opened_at, 'D MMM, h:mm a', locale)}</TableCell>
                    <TableCell>{shift.closed_at ? formatDhaka(shift.closed_at, 'D MMM, h:mm a', locale) : '—'}</TableCell>
                    <TableCell align="right">{shift.expected_cash_paisa === null ? '—' : formatBdt(shift.expected_cash_paisa, locale)}</TableCell>
                    <TableCell align="right">{shift.counted_cash_paisa === null ? '—' : formatBdt(shift.counted_cash_paisa, locale)}</TableCell>
                    <TableCell align="right" sx={{ color: (shift.variance_paisa ?? 0) < 0 ? 'error.main' : undefined }}>{shift.variance_paisa === null ? '—' : formatBdt(shift.variance_paisa, locale)}</TableCell>
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

Shift.layout = (page: ReactNode) => <PanelLayout title="billing.shift.title">{page}</PanelLayout>;
